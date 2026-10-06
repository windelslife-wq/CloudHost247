<?php
/** Tool executor: fail-closed paths, dual RBAC, client isolation in SQL, redaction, audit. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\NotFoundException;
use Ch247Ai\Core\Rbac;
use Ch247Ai\Core\Redaction;
use Ch247Ai\Core\Settings;
use Ch247Ai\Tools\ToolExecutor;
use Ch247Ai\Tools\ToolRegistry;

ch247ai_boot();
ch247ai_freeze();

T::section('Unknown and disabled tools fail closed');
ch247ai_as_super_admin();
T::throws('unknown tool refused', function () {
    ToolExecutor::execute('admin_copilot', 'write_invoice', []);
}, NotFoundException::class);
T::throws('unknown agent cannot execute even known tools', function () {
    ToolExecutor::execute('nonexistent_agent', 'read_invoices', []);
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Agent allowlist is enforced (permissions live in the registry, not prompts)');
$def = \Ch247Ai\Agents\AgentRegistry::find('ssl_guardian');
T::ok('ssl_guardian may call ssl_checker', in_array('read_diag_ssl_checker', $def->tools, true));
T::ok('ssl_guardian may NOT read tickets', !in_array('read_tickets', $def->tools, true));
// admin_copilot has the grant; use it to prove allowed path works, then prove
// a tool outside its allowlist refuses.
T::throws('tool outside agent allowlist refuses', function () {
    ToolExecutor::execute('ssl_guardian', 'read_tickets', []);
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Actor authority (admin permission groups) is enforced');
// Admin #2 (role 2, no grants) cannot run tools even with agent allowlist ok.
Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
T::throws('admin without group refused', function () {
    ToolExecutor::execute('admin_copilot', 'read_invoices', []);
}, \Ch247Ai\Core\ForbiddenException::class);
Rbac::grant(2, Rbac::AI_READ);
T::ok('billing group still needed for invoices', false === (function () {
    try {
        ToolExecutor::execute('admin_copilot', 'read_invoices', ['limit' => 1]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
})());
Rbac::grant(2, Rbac::AI_CLIENT_READ);
$result = ToolExecutor::execute('admin_copilot', 'read_invoices', ['limit' => 2]);
T::ok('admin with grants executes', count($result->data['invoices']) === 2);
T::ok('citation carries the exact SQL', strpos($result->citations[0], 'FROM tblinvoices') !== false);

T::section('Kill switch and service flag stop everything');
Settings::put('kill_switch', '1');
T::throws('kill switch refuses', function () {
    ToolExecutor::execute('admin_copilot', 'read_invoices', []);
}, \Ch247Ai\Core\ForbiddenException::class);
Settings::put('kill_switch', '0');
Settings::put('service_enabled', '0');
T::throws('service disabled refuses', function () {
    ToolExecutor::execute('admin_copilot', 'read_invoices', []);
}, \Ch247Ai\Core\ForbiddenException::class);
Settings::put('service_enabled', '1');

T::section('Client isolation is enforced in SQL, not prompts');
Identity::setAdmin(null);
unset($_SESSION['adminid']);
Identity::setClient(11);
$_SESSION['uid'] = 11;
$result = ToolExecutor::execute('admin_copilot', 'read_invoices', []);
$seen = [];
foreach ($result->data['invoices'] as $invoice) {
    $seen[(int) $invoice['userid']] = true;
}
T::eq('client 11 sees only own invoices (101, 104)', [11 => true], $seen);
T::ok('WHERE clause contains the forced client filter', strpos($result->citations[0], 'userid = ?') !== false);

T::throws('client cannot read another client by id', function () {
    ToolExecutor::execute('admin_copilot', 'read_client_details', ['client_id' => 22]);
}, NotFoundException::class);

// read_clients is the admin directory tool — client scope refuses it outright
// (client-bound tools are the explicitly whitelisted set only).
T::throws('client scope refuses the admin client-directory tool', function () {
    ToolExecutor::execute('admin_copilot', 'read_clients', ['query' => 'a']);
}, \Ch247Ai\Core\ForbiddenException::class);

T::ok('client cannot run admin-only tools (metrics)', false === (function () {
    try {
        ToolExecutor::execute('admin_copilot', 'read_metrics', []);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
})());
T::ok('client cannot run diagnostics', false === (function () {
    try {
        ToolExecutor::execute('admin_copilot', 'read_diag_ssl_checker', ['domain' => 'example.com']);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
})());

T::section('Masquerading admins get no AI authority');
Identity::setAdmin(1);
$_SESSION['adminid'] = 1;
T::throws('masquerade blocks tools', function () {
    ToolExecutor::execute('admin_copilot', 'read_invoices', []);
}, \Ch247Ai\Core\ForbiddenException::class);
Identity::setAdmin(null);
unset($_SESSION['adminid']);
Identity::setClient(null);
unset($_SESSION['uid']);

T::section('Missing WHMCS tables fail closed with DATA_UNAVAILABLE');
Db::exec('DROP TABLE tblaccounts');
T::throws('payments reader refuses when table missing', function () {
    ch247ai_as_super_admin();
    ToolExecutor::execute('admin_copilot', 'read_payments', []);
}, \Ch247Ai\Core\ServiceUnavailableException::class);

T::section('Parameter validation and redaction');
ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();
T::throws('missing required parameter rejected', function () {
    ToolExecutor::execute('admin_copilot', 'read_client_details', []);
}, \Ch247Ai\Core\ValidationException::class);
T::throws('wrong parameter type rejected', function () {
    ToolExecutor::execute('admin_copilot', 'read_invoices', ['client_id' => 'abc']);
}, \Ch247Ai\Core\ValidationException::class);

// Secret in arguments never reaches the audit log at all (arguments are not
// audited — only citations and a result digest are), and Redaction::clean
// masks them wherever they ARE stored.
$before = Db::count('audit_log');
ToolExecutor::execute('admin_copilot', 'read_clients', ['query' => 'ava', 'api_key' => 'sk-super-secret-value']);
$context = (string) Db::first('audit_log', [], 'id DESC')['context'];
T::ok('secret arguments never appear in the audit log', strpos($context, 'sk-super-secret-value') === false);
T::ok('redaction marker applied where secrets are stored', Redaction::clean(['api_key' => 'x']) === ['api_key' => '[redacted]']);

T::section('Every execution and refusal is audited');
// Produce one explicit refusal in this (re-booted) database first.
try {
    ToolExecutor::execute('not_an_agent', 'read_invoices', []);
} catch (\Throwable $e) {
    // expected
}
T::ok('tool executions audited', Db::count('audit_log', ['action' => 'ai.tool.executed']) >= 1);
T::ok('tool refusals audited', Db::count('audit_log', ['action' => 'ai.tool.refused']) >= 1);

T::section('Audit hash chain detects tampering');
$chain = Audit::verifyChain();
T::ok('chain valid after honest appends', $chain['valid']);
$id = Db::first('audit_log', [], 'id ASC')['id'];
Db::exec('UPDATE ' . Db::t('audit_log') . ' SET action = ? WHERE id = ?', ['ai.tampered', (int) $id]);
$chain = Audit::verifyChain();
T::ok('tampering detected', !$chain['valid'] && (int) $chain['broken_at'] === (int) $id);

T::finish();
