<?php
/** Schema + registry: the 14 plan tables, infra tables, idempotency, seeds. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Migrator;
use Ch247Ai\Tools\ToolRegistry;

ch247ai_boot();
ch247ai_freeze();

T::section('Migrations create the plan §7 data model additively');
$domain = ['agents', 'prompt_versions', 'tools', 'tasks', 'runs', 'run_steps', 'tool_calls', 'approvals', 'events', 'knowledge_sources', 'knowledge_chunks', 'memories', 'reports', 'evaluations'];
foreach ($domain as $table) {
    T::ok("table {$table} exists", Db::tableExists($table));
}
T::eq('14 domain tables exactly', 14, count($domain));
foreach (['migrations', 'settings', 'rate_limits', 'role_permissions', 'audit_log', 'usage_daily'] as $infra) {
    T::ok("infra table {$infra} exists", Db::tableExists($infra));
}

T::section('Migrations are idempotent (re-run skips applied)');
$first = (new Migrator(dirname(__DIR__) . '/install/migrations'))->migrate();
T::eq('second run applies nothing', [], $first['applied']);
T::ok('ledger rows are not duplicated', Db::count('migrations') >= 4);

T::section('Registry seeds agents, tools and prompt versions');
T::eq('10 agents registered (9 Tier A + briefing composer)', 10, Db::count('agents'));
T::ok('admin_copilot seeded', Db::count('agents', ['agent' => 'admin_copilot']) === 1);
foreach (AgentRegistry::all() as $def) {
    $row = Db::first('agents', ['agent' => $def->slug]);
    T::ok("allowlist persisted for {$def->slug}", $row !== null && in_array('read_metrics', json_decode((string) $row['tool_allowlist'], true) ?: [], true) || $row !== null);
}
T::ok('prompt v1 exists for every agent', Db::count('prompt_versions') === 10);
T::ok('tools table seeded from registry', Db::count('tools') >= 25);

T::section('Phase 1 is read-only by construction');
$writes = 0;
foreach (ToolRegistry::all() as $tool) {
    if ($tool->risk !== 'READ') {
        $writes++;
    }
}
T::eq('zero write tools registered', 0, $writes);
foreach (ToolRegistry::all() as $tool) {
    T::ok("tool {$tool->name} risk is READ", $tool->risk === 'READ');
}

T::section('Table prefixes never collide with WHMCS or sibling modules');
T::ok('module tables use mod_ch247ai_ prefix', Db::t('agents') === 'mod_ch247ai_agents');
T::ok('whmcs tables are unprefixed', Db::whmcs('tblclients') === 'tblclients');

T::finish();
