<?php
/**
 * Phase 3 — the customer-facing assistant.
 *
 * A customer surface over an AI that can read billing data is the highest
 * risk thing in this module, so the suite is mostly an isolation matrix:
 *
 *   - a signed-in customer reaches ONLY their own rows, whatever they ask;
 *   - no write tool is reachable in client scope, ever;
 *   - platform-wide tools (all clients, revenue metrics, diagnostics) are
 *     not in the customer agent's grant list and are refused if tried;
 *   - an anonymous visitor gets nothing;
 *   - every off switch produces an honest page, not a guess;
 *   - the customer can see what the AI did on their account (§33);
 *   - one customer cannot read another customer's AI activity.
 *
 * Isolation is asserted at the tool layer (where it is enforced) rather
 * than through the model, because the model is not the control.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Http/CustomerPortal.php';

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Settings;
use Ch247Ai\Http\CustomerPortal;
use Ch247Ai\Tools\Bootstrap;
use Ch247Ai\Tools\ToolExecutor;
use Ch247Ai\Tools\ToolRegistry;

ch247ai_boot();
ch247ai_freeze();
Bootstrap::register();

/** Put the session in "signed-in customer" state. */
function ch247ai_as_client($clientId)
{
    Identity::setAdmin(null);
    unset($_SESSION['adminid']);
    Identity::setClient($clientId);
}

function ch247ai_render_client($action = 'assistant')
{
    $_GET['action'] = $action;
    $out = (new CustomerPortal())->dispatch(['modulelink' => 'index.php?m=cloudhost247ai']);
    unset($_GET['action']);
    return $out;
}

// ---------------------------------------------------------------------------
T::section('The customer agent exists and is scoped by construction');

$agents = [];
foreach (AgentRegistry::all() as $def) {
    $agents[$def->slug] = $def;
}
T::ok('customer_assistant is registered', isset($agents['customer_assistant']));
$customer = $agents['customer_assistant'];
T::ok('it is interactive', $customer->runMode === 'interactive');
T::ok('it is disabled by default', $customer->defaultEnabled === false);

$platformTools = ['read_clients', 'read_metrics', 'read_products'];
foreach ($platformTools as $tool) {
    T::ok("customer agent cannot list {$tool}", !in_array($tool, $customer->tools, true));
}
foreach ($customer->tools as $toolName) {
    $tool = ToolRegistry::find($toolName);
    T::ok("granted tool {$toolName} exists", $tool !== null);
    if ($tool === null) {
        continue;
    }
    T::ok("granted tool {$toolName} is READ", $tool->risk === 'READ');
    T::ok("granted tool {$toolName} is client-bound", $tool->clientBound === true);
}
foreach (ToolRegistry::all() as $tool) {
    if ($tool->risk !== 'READ') {
        T::ok("write tool {$tool->name} is not granted to the customer agent",
            !in_array($tool->name, $customer->tools, true));
    }
    if (strpos($tool->name, 'read_diag') === 0) {
        T::ok("diagnostic tool {$tool->name} is not granted to the customer agent",
            !in_array($tool->name, $customer->tools, true));
    }
}

// ---------------------------------------------------------------------------
T::section('Isolation: a customer sees only their own rows');

ToolExecutor::allowAgent('customer_assistant');
ch247ai_as_client(11);

$res = ToolExecutor::execute('customer_assistant', 'read_invoices', [], 0);
$ids = [];
foreach ($res->data['invoices'] as $row) {
    $ids[] = (int) $row['id'];
    T::eq('invoice ' . $row['id'] . ' belongs to client 11', 11, (int) $row['userid']);
}
T::ok('client 11 sees their own invoice 101', in_array(101, $ids, true));
T::ok('client 11 does NOT see client 22 invoice 102', !in_array(102, $ids, true));
T::ok('client 11 does NOT see client 33 invoice 103', !in_array(103, $ids, true));

// The important case: explicitly asking for someone else's data.
$res = ToolExecutor::execute('customer_assistant', 'read_invoices', ['client_id' => 22], 0);
foreach ($res->data['invoices'] as $row) {
    T::eq('asking for client 22 still returns only client 11 rows', 11, (int) $row['userid']);
}
T::ok('the override did not expose another account',
    !in_array(102, array_map(function ($r) { return (int) $r['id']; }, $res->data['invoices']), true));

foreach (['read_services', 'read_domains', 'read_tickets', 'read_payments', 'read_orders'] as $toolName) {
    $out = ToolExecutor::execute('customer_assistant', $toolName, ['client_id' => 22], 0);
    $rows = [];
    foreach ($out->data as $key => $value) {
        if (is_array($value) && $key !== '_citations') {
            $rows = $value;
            break;
        }
    }
    foreach ($rows as $row) {
        $owner = isset($row['userid']) ? (int) $row['userid'] : 11;
        T::eq("{$toolName} row stays with client 11", 11, $owner);
    }
}

T::section('And the other customer sees their own, not the first one\'s');
ch247ai_as_client(22);
$res = ToolExecutor::execute('customer_assistant', 'read_invoices', [], 0);
$ids22 = array_map(function ($r) { return (int) $r['id']; }, $res->data['invoices']);
T::ok('client 22 sees invoice 102', in_array(102, $ids22, true));
T::ok('client 22 does not see invoice 101', !in_array(101, $ids22, true));
T::ok('client 22 does not see invoice 104', !in_array(104, $ids22, true));

// ---------------------------------------------------------------------------
T::section('Client scope cannot reach platform-wide or write tools');

ch247ai_as_client(11);
foreach (['read_clients', 'read_metrics', 'read_products'] as $toolName) {
    T::throws("client scope refused {$toolName}", function () use ($toolName) {
        ToolExecutor::execute('customer_assistant', $toolName, [], 0);
    }, ForbiddenException::class);
}
foreach (['write_ticket_reply', 'write_ticket_note', 'write_ticket_status', 'write_invoice_reminder'] as $toolName) {
    T::throws("client scope refused write tool {$toolName}", function () use ($toolName) {
        ToolExecutor::execute('customer_assistant', $toolName, ['ticket_id' => 401, 'message' => 'x', 'note' => 'x', 'status' => 'Closed', 'invoice_id' => 102], 0, 1);
    }, ForbiddenException::class);
}
T::eq('no ticket reply was created by any of that', 0,
    (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies')[0]['c']);

// ---------------------------------------------------------------------------
T::section('Page: anonymous visitors get nothing');

Settings::put('client_assistant_enabled', '1');
Identity::setClient(null);
Identity::setAdmin(null);
unset($_SESSION['adminid']);
$page = ch247ai_render_client('assistant');
T::eq('anonymous gets the sign-in template', 'templates/client/login_required', $page['templatefile']);
T::ok('anonymous page carries no account rows', !isset($page['templatevariables']['recent']));
$blob = json_encode($page);
T::ok('no invoice figures leak to an anonymous visitor', strpos($blob, '80.50') === false);
T::ok('no customer names leak to an anonymous visitor', stripos($blob, 'Ava') === false);

T::section('Page: the feature switch is honest');
ch247ai_as_client(11);
Settings::put('client_assistant_enabled', '0');
$page = ch247ai_render_client('assistant');
T::eq('disabled shows the unavailable template', 'templates/client/unavailable', $page['templatefile']);
T::ok('and explains itself', strpos((string) $page['templatevariables']['reason'], 'not enabled') !== false);

Settings::put('client_assistant_enabled', '1');
Settings::put('kill_switch', '1');
$page = ch247ai_render_client('assistant');
T::eq('kill switch shows the unavailable template', 'templates/client/unavailable', $page['templatefile']);
T::ok('kill switch message mentions being switched off',
    stripos((string) $page['templatevariables']['reason'], 'switched off') !== false);
Settings::put('kill_switch', '0');

T::section('Page: renders for a signed-in customer');
$page = ch247ai_render_client('assistant');
T::eq('assistant template', 'templates/client/assistant', $page['templatefile']);
T::ok('login is required by the envelope', $page['requirelogin'] === true);
T::ok('a csrf token is supplied', (string) $page['templatevariables']['csrf_token'] !== '');
T::ok('rate limit is disclosed', (int) $page['templatevariables']['rate_max'] > 0);
T::ok('no answer before asking', $page['templatevariables']['answer'] === null);

// ---------------------------------------------------------------------------
T::section('Asking with no model configured fails closed, honestly');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['ch247ai_question'] = 'Which of my invoices are unpaid?';
$_POST['ch247ai_csrf'] = \Ch247Ai\Core\Csrf::token();
$page = ch247ai_render_client('assistant');
$vars = $page['templatevariables'];
T::ok('no answer was invented', $vars['answer'] === null);
T::ok('an explanation is shown instead', (string) $vars['error'] !== '');
T::ok('the explanation does not pretend to know anything',
    stripos((string) $vars['error'], 'not configured') !== false
    || stripos((string) $vars['error'], 'could not answer') !== false);
T::ok('no fabricated invoice numbers in the page', strpos(json_encode($page), '80.50') === false);

T::section('A bad CSRF token is rejected');
$_POST['ch247ai_csrf'] = 'wrong-token';
$page = ch247ai_render_client('assistant');
T::ok('rejected', stripos((string) $page['templatevariables']['error'], 'session expired') !== false);

T::section('An empty question is rejected without a run');
$runsBefore = (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('runs'))[0]['c'];
$_POST['ch247ai_question'] = '   ';
$_POST['ch247ai_csrf'] = \Ch247Ai\Core\Csrf::token();
$page = ch247ai_render_client('assistant');
T::ok('asked to type something', stripos((string) $page['templatevariables']['error'], 'type a question') !== false);
T::eq('no run was recorded', $runsBefore, (int) Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('runs'))[0]['c']);

T::section('An over-long question is rejected');
$_POST['ch247ai_question'] = str_repeat('a', 2500);
$_POST['ch247ai_csrf'] = \Ch247Ai\Core\Csrf::token();
$page = ch247ai_render_client('assistant');
T::ok('length is explained', stripos((string) $page['templatevariables']['error'], 'too long') !== false);

unset($_POST['ch247ai_question'], $_POST['ch247ai_csrf']);
$_SERVER['REQUEST_METHOD'] = 'GET';

// ---------------------------------------------------------------------------
T::section('§33 activity page shows this customer their own AI history');

// Two runs owned by different customers.
$runA = Db::insert('runs', [
    'task_id' => null, 'agent' => 'customer_assistant', 'status' => 'success',
    'source' => 'interactive', 'actor_type' => 'client', 'actor_id' => 11,
    'input' => 'What do I owe?', 'output' => 'You have one unpaid invoice.',
    'citations' => '[]', 'started_at' => '2026-10-06 09:00:00', 'finished_at' => '2026-10-06 09:00:02',
]);
$runB = Db::insert('runs', [
    'task_id' => null, 'agent' => 'customer_assistant', 'status' => 'success',
    'source' => 'interactive', 'actor_type' => 'client', 'actor_id' => 22,
    'input' => 'SECRET QUESTION FROM CLIENT 22', 'output' => 'SECRET ANSWER FOR CLIENT 22',
    'citations' => '[]', 'started_at' => '2026-10-06 09:05:00', 'finished_at' => '2026-10-06 09:05:02',
]);
Db::insert('tool_calls', [
    'run_id' => $runA, 'agent' => 'customer_assistant', 'tool' => 'read_invoices',
    'arguments' => '{}', 'result_digest' => str_repeat('a', 64), 'permission_checked' => 1,
    'approval_id' => null, 'status' => 'executed', 'error' => null, 'duration_ms' => 4,
    'created_at' => '2026-10-06 09:00:01',
]);

ch247ai_as_client(11);
$page = ch247ai_render_client('activity');
T::eq('activity template', 'templates/client/activity', $page['templatefile']);
$runs = $page['templatevariables']['runs'];
T::ok('the customer sees their own run', count($runs) >= 1);
foreach ($runs as $run) {
    T::ok('run #' . $run['id'] . ' is not client 22\'s', strpos($run['question'], 'SECRET') === false);
}
$activityBlob = json_encode($page);
T::ok('client 22 question does not appear', strpos($activityBlob, 'SECRET QUESTION') === false);
T::ok('client 22 answer does not appear', strpos($activityBlob, 'SECRET ANSWER') === false);
T::ok('the tool trail is shown', strpos($activityBlob, 'read_invoices') !== false);

T::section('And the mirror case');
ch247ai_as_client(22);
$page22 = ch247ai_render_client('activity');
$blob22 = json_encode($page22);
T::ok('client 22 sees their own question', strpos($blob22, 'SECRET QUESTION') !== false);
T::ok('client 22 does not see client 11\'s question', strpos($blob22, 'What do I owe?') === false);

T::section('An admin session is not a customer session');
Identity::setClient(null);
Identity::setAdmin(1);
$_SESSION['adminid'] = 1;
$page = ch247ai_render_client('assistant');
T::eq('an admin with no client session gets the sign-in page', 'templates/client/login_required', $page['templatefile']);

// ---------------------------------------------------------------------------
T::section('Rate limiting protects the surface');

ch247ai_as_client(33);
$blocked = false;
for ($i = 0; $i < CustomerPortal::RATE_MAX + 3; $i++) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['ch247ai_question'] = 'question ' . $i;
    $_POST['ch247ai_csrf'] = \Ch247Ai\Core\Csrf::token();
    $page = ch247ai_render_client('assistant');
    $err = (string) $page['templatevariables']['error'];
    if (stripos($err, 'wait a few minutes') !== false) {
        $blocked = true;
        break;
    }
}
unset($_POST['ch247ai_question'], $_POST['ch247ai_csrf']);
$_SERVER['REQUEST_METHOD'] = 'GET';
T::ok('a customer is rate limited after repeated questions', $blocked);

// ---------------------------------------------------------------------------
T::section('Nothing on the customer surface leaks operator detail');

ch247ai_as_client(11);
Settings::put('client_assistant_enabled', '1');
$all = json_encode([ch247ai_render_client('assistant'), ch247ai_render_client('activity')]);
foreach (['CH247AI_API_KEY', 'kill_switch', 'Stack trace', 'mod_ch247ai_', 'SELECT ', 'tblinvoices'] as $leak) {
    T::ok("customer pages do not expose {$leak}", strpos($all, $leak) === false);
}

T::section('Templates escape everything they print');
// The PHP linter does not read .tpl files, so this invariant is asserted
// here: a customer-visible template must never interpolate a raw value.
$tplDir = dirname(__DIR__) . '/templates/client';
$tpls = glob($tplDir . '/*.tpl');
T::ok('customer templates are present', count($tpls) >= 4);
foreach ($tpls as $tpl) {
    $name = basename($tpl);
    $body = (string) file_get_contents($tpl);
    preg_match_all('/\{\$([a-zA-Z_][a-zA-Z0-9_.]*)\}/', $body, $m);
    $unescaped = array_values(array_diff(array_unique($m[1]), ['WEB_ROOT']));
    T::eq("{$name} interpolates nothing unescaped", [], $unescaped);
    T::ok("{$name} contains no inline script tag", stripos($body, '<script') === false);
    T::ok("{$name} contains no raw SQL", stripos($body, 'SELECT ') === false);
}

Identity::setClient(null);
ch247ai_as_super_admin();
T::finish();
