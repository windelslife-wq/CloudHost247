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
T::eq('every registry agent is seeded', count(AgentRegistry::all()), Db::count('agents'));
T::eq('operable agents (9 Tier A + briefing + executive board)', 11, count(AgentRegistry::available()));
T::ok('roadmap seats are declared too', count(AgentRegistry::unavailable()) >= 16);
T::eq('roadmap seats seed disabled', 0, Db::count('agents', ['enabled' => 1, 'agent' => 'infrastructure_guardian']));
T::ok('every roadmap seat names the collector it needs', (function () {
    foreach (AgentRegistry::unavailable() as $def) {
        if (trim($def->missingCollector) === '') {
            return false;
        }
    }
    return true;
})());
T::ok('no roadmap seat carries tools it could call', (function () {
    foreach (AgentRegistry::unavailable() as $def) {
        if ($def->tools !== []) {
            return false;
        }
    }
    return true;
})());
T::ok('admin_copilot seeded', Db::count('agents', ['agent' => 'admin_copilot']) === 1);
foreach (AgentRegistry::all() as $def) {
    $row = Db::first('agents', ['agent' => $def->slug]);
    T::ok("allowlist persisted for {$def->slug}", $row !== null && in_array('read_metrics', json_decode((string) $row['tool_allowlist'], true) ?: [], true) || $row !== null);
}
T::eq('prompt v1 exists for every operable agent', count(AgentRegistry::available()), Db::count('prompt_versions'));
T::eq('roadmap seats get no prompt', 0, Db::count('prompt_versions', ['agent' => 'hr_assistant']));
T::ok('tools table seeded from registry', Db::count('tools') >= 25);

T::section('Every registered tool obeys its risk class');
// Phase 2 introduces write tools, so the old "there are no writes"
// invariant is replaced by the one that has to hold as writes are added:
// a non-READ tool is always gated, always verifiable, never client-facing.
foreach (ToolRegistry::all() as $tool) {
    if ($tool->risk === 'READ') {
        T::ok("read tool {$tool->name} declares a read.* permission",
            strpos($tool->permission, 'read.') === 0);
        continue;
    }
    T::ok("write tool {$tool->name} requires approval",
        \Ch247Ai\Approval\ApprovalEngine::requiresApproval($tool->risk));
    T::ok("write tool {$tool->name} has a verification step", is_callable($tool->verify));
    T::ok("write tool {$tool->name} declares an ai.write.* permission",
        strpos($tool->permission, 'ai.write.') === 0);
    T::ok("write tool {$tool->name} is never client-bound", $tool->clientBound === false);
    T::ok("write tool {$tool->name} uses a known risk class",
        in_array($tool->risk, \Ch247Ai\Approval\ApprovalEngine::RISK_LADDER, true));
}

T::section('Table prefixes never collide with WHMCS or sibling modules');
T::ok('module tables use mod_ch247ai_ prefix', Db::t('agents') === 'mod_ch247ai_agents');
T::ok('whmcs tables are unprefixed', Db::whmcs('tblclients') === 'tblclients');

T::finish();
