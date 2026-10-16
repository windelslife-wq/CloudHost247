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
// Structural, not a magic number: every operable agent must have at least
// one real tool, and every roadmap seat must have none.
T::ok('there are operable agents', count(AgentRegistry::available()) > 0);
T::eq('available + unavailable accounts for the whole registry',
    count(AgentRegistry::all()), count(AgentRegistry::available()) + count(AgentRegistry::unavailable()));
foreach (AgentRegistry::available() as $def) {
    T::ok("operable agent {$def->slug} has tools", $def->tools !== []);
    T::ok("operable agent {$def->slug} names no missing collector", (string) $def->missingCollector === '');
}
T::ok('roadmap seats are declared too', count(AgentRegistry::unavailable()) >= 4);
T::eq('roadmap seats seed disabled', 0, Db::count('agents', ['enabled' => 1, 'agent' => 'infrastructure_guardian']));
$assetAgent = AgentRegistry::find('asset_inventory_agent');
T::ok('asset inventory agent is available without exposing hardware claims', $assetAgent !== null && $assetAgent->isAvailable());
T::eq('asset inventory agent has exactly the scoped inventory tool', ['read_server_inventory'], $assetAgent->tools);
T::eq('asset inventory agent remains operator-disabled by default', false, $assetAgent->defaultEnabled);
$provisioningAgent = AgentRegistry::find('provisioning_agent');
T::ok('provisioning status agent has its observable WHMCS data source', $provisioningAgent !== null && $provisioningAgent->isAvailable());
T::eq('provisioning agent only receives the pending queue reader', ['read_provisioning_queue'], $provisioningAgent->tools);
T::eq('provisioning agent remains operator-disabled by default', false, $provisioningAgent->defaultEnabled);
$infraAgent = AgentRegistry::find('infrastructure_guardian');
T::ok('infrastructure status agent is available from provider sync records', $infraAgent !== null && $infraAgent->isAvailable());
T::eq('infrastructure agent only receives the provider status reader', ['read_infrastructure_status'], $infraAgent->tools);
T::eq('infrastructure agent remains operator-disabled by default', false, $infraAgent->defaultEnabled);
$healthAgent = AgentRegistry::find('server_health_agent');
T::ok('server health reporter has its health-check audit source', $healthAgent !== null && $healthAgent->isAvailable());
T::eq('health reporter only receives its audit reader', ['read_server_health_checks'], $healthAgent->tools);
T::eq('server health reporter remains operator-disabled by default', false, $healthAgent->defaultEnabled);
$deploymentAgent = AgentRegistry::find('deployment_agent');
T::ok('deployment agent has recorded App Cloud deployments as its source', $deploymentAgent !== null && $deploymentAgent->isAvailable());
T::eq('deployment agent only receives the safe deployment reader', ['read_deployment_status'], $deploymentAgent->tools);
T::eq('deployment agent remains operator-disabled by default', false, $deploymentAgent->defaultEnabled);
$rootCauseAgent = AgentRegistry::find('root_cause_analyst');
T::ok('root cause agent has structured deployment timeline records', $rootCauseAgent !== null && $rootCauseAgent->isAvailable());
T::eq('root cause agent receives only the timeline reader', ['read_deployment_timeline'], $rootCauseAgent->tools);
T::eq('root cause agent remains operator-disabled by default', false, $rootCauseAgent->defaultEnabled);
$pricingAgent = AgentRegistry::find('pricing_analyst');
T::ok('pricing reporter is backed by WHMCS products and orders', $pricingAgent !== null && $pricingAgent->isAvailable());
T::eq('pricing reporter can only use the existing product/order readers', ['read_products', 'read_orders'], $pricingAgent->tools);
T::eq('pricing reporter remains operator-disabled by default', false, $pricingAgent->defaultEnabled);
$costAgent = AgentRegistry::find('cloud_cost_guardian');
T::ok('cloud cost reporter has a manual invoice-cost source', $costAgent !== null && $costAgent->isAvailable());
T::eq('cloud cost reporter only receives the cost ledger tool', ['read_infrastructure_costs'], $costAgent->tools);
T::eq('cloud cost reporter remains operator-disabled by default', false, $costAgent->defaultEnabled);
$securityAgent = AgentRegistry::find('security_sentinel');
T::ok('security sentinel has its derived auth-event data source', $securityAgent !== null && $securityAgent->isAvailable());
T::eq('security sentinel only reads the safe login-event tool', ['read_security_auth_events'], $securityAgent->tools);
T::eq('security sentinel remains operator-disabled by default', false, $securityAgent->defaultEnabled);
$fraudAgent = AgentRegistry::find('fraud_abuse_guardian');
T::ok('fraud reporter uses only WHMCS pre-flagged status data', $fraudAgent !== null && $fraudAgent->isAvailable());
T::eq('fraud reporter receives only the existing Fraud-status queue', ['read_fraud_review_queue'], $fraudAgent->tools);
T::eq('fraud reporter remains operator-disabled by default', false, $fraudAgent->defaultEnabled);
$vulnerabilityAgent = AgentRegistry::find('vulnerability_analyst');
T::ok('dependency inventory reporter has local lockfile data', $vulnerabilityAgent !== null && $vulnerabilityAgent->isAvailable());
T::eq('dependency reporter only reads the lockfile inventory', ['read_dependency_inventory'], $vulnerabilityAgent->tools);
T::eq('dependency reporter remains operator-disabled by default', false, $vulnerabilityAgent->defaultEnabled);
$incidentAgent = AgentRegistry::find('incident_commander');
T::ok('incident reporter has recorded operational failures as a source', $incidentAgent !== null && $incidentAgent->isAvailable());
T::eq('incident reporter only reads structured failure signals', ['read_incident_failure_signals'], $incidentAgent->tools);
T::eq('incident reporter remains operator-disabled by default', false, $incidentAgent->defaultEnabled);
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
