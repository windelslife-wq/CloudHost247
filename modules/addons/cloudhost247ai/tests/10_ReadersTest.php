<?php
/** Reader coverage: each read tool returns real WHMCS-shaped rows with citations. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Core\Db;

ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();

$exec = function ($tool, array $args) {
    return \Ch247Ai\Tools\ToolExecutor::execute('admin_copilot', $tool, $args);
};

T::section('read_clients');
$r = $exec('read_clients', ['query' => 'ava']);
T::eq('one match', 1, $r->data['count']);
T::eq('right client', 11, (int) $r->data['clients'][0]['id']);
T::ok('email masked by default for model privacy', strpos($r->data['clients'][0]['email'], '***@') !== false);
\Ch247Ai\Core\Settings::put('expose_pii', '1');
$r = $exec('read_clients', ['query' => 'ava']);
T::eq('full email when operator enabled pii exposure', 'ava@example.test', $r->data['clients'][0]['email']);
\Ch247Ai\Core\Settings::put('expose_pii', '0');
T::ok('cited', strpos($r->citations[0], 'tblclients') !== false);
$r = $exec('read_clients', ['status' => 'Inactive']);
T::eq('status filter works without query', 33, (int) $r->data['clients'][0]['id']);

T::section('read_client_details');
$r = $exec('read_client_details', ['client_id' => 22]);
T::eq('details for 22', 22, (int) $r->data['client']['id']);
T::throws('unknown client 404s', function () use ($exec) {
    $exec('read_client_details', ['client_id' => 777]);
}, \Ch247Ai\Core\NotFoundException::class);

T::section('read_invoices / read_payments / read_orders');
$r = $exec('read_invoices', ['status' => 'Unpaid']);
T::eq('three unpaid invoices', 3, $r->data['count']);
$r = $exec('read_invoices', ['client_id' => 11]);
foreach ($r->data['invoices'] as $invoice) {
    T::ok('only client 11 rows', (int) $invoice['userid'] === 11);
}
$r = $exec('read_payments', ['days' => 30]);
T::eq('two payments in window', 2, $r->data['count']);
$r = $exec('read_orders', ['status' => 'Pending']);
T::eq('pending order found', 202, (int) $r->data['orders'][0]['id']);
$r = $exec('read_fraud_review_queue', []);
T::eq('only WHMCS Fraud orders appear', 1, $r->data['count']);
T::eq('already-flagged order id', 203, (int) $r->data['orders'][0]['id']);
T::ok('reader does not claim a fresh fraud assessment', strpos($r->data['interpretation'], 'does not calculate risk') !== false);
T::ok('fraud review citation names tblorders', strpos($r->citations[0], 'tblorders') !== false);
$r = $exec('read_incident_failure_signals', ['window_hours' => 24, 'limit' => 20]);
T::eq('structured deployment/provisioning/health failures gathered', 3, $r->data['count']);
$sources = array_column($r->data['signals'], 'source');
T::ok('deployment failure source included', in_array('app_cloud_deployment', $sources, true));
T::ok('provisioning failure source included', in_array('infrastructure_provisioning', $sources, true));
T::ok('health-check failure source included', in_array('infrastructure_health_check', $sources, true));
T::ok('signals not misrepresented as correlated incidents', strpos($r->data['interpretation'], 'not correlated or confirmed incidents') !== false);
$limitedSignals = $exec('read_incident_failure_signals', ['limit' => 1]);
T::eq('combined failure result is bounded', 1, $limitedSignals->data['count']);

T::section('read_services / read_products');
$r = $exec('read_services', []);
T::eq('three services including the pending provisioning fixture', 3, $r->data['count']);
T::ok('product name joined', $r->data['services'][0]['product'] === 'Cloud Starter' || $r->data['services'][0]['product'] === 'Cloud Pro');
T::ok('telemetry caveat present', strpos(json_encode($r->data), 'telemetry') !== false);
$r = $exec('read_products', []);
T::eq('two products', 2, $r->data['count']);
T::ok('pricing joined', isset($r->data['products'][0]['monthly']));

T::section('read_tickets / read_domains / read_credit');
$r = $exec('read_tickets', ['status' => 'Open']);
T::eq('open ticket found', 401, (int) $r->data['tickets'][0]['id']);
$r = $exec('read_domains', ['domain' => 'avariver.com']);
T::eq('domain lookup', 601, (int) $r->data['domains'][0]['id']);
T::ok('expiry included', $r->data['domains'][0]['expirydate'] === '2026-10-20');
$r = $exec('read_credit', ['client_id' => 33]);
T::ok('credit balance from tblclients', array_key_exists('credit_balance', $r->data));

T::section('read_deployment_timeline');
$r = $exec('read_deployment_timeline', ['deployment_id' => 702]);
T::eq('deployment reference returned', 'DEP-TEST-702', $r->data['deployment']['reference']);
T::eq('ordered structured steps returned', 2, count($r->data['steps']));
T::eq('step ordering is preserved', 1, (int) $r->data['steps'][0]['step_order']);
T::eq('failed step key returned', 'wait_rollout', $r->data['steps'][1]['step_key']);
T::eq('failed step error code returned', 'ROLLOUT_TIMEOUT', $r->data['steps'][1]['error_code']);
$timelineJson = json_encode($r->data);
foreach (['DO_NOT_EXPOSE_OUTPUT', 'DO_NOT_EXPOSE_STEP_OUTPUT', 'DO_NOT_EXPOSE_STEP_ERROR', 'STEP_CONTEXT_SECRET'] as $privateValue) {
    T::ok("timeline excludes {$privateValue}", strpos($timelineJson, $privateValue) === false);
}
T::ok('tool warns that failure point is not root cause', strpos($r->data['interpretation'], 'do not by themselves establish') !== false);
T::throws('unknown deployment timeline is not disclosed', function () use ($exec) {
    $exec('read_deployment_timeline', ['deployment_id' => 99999]);
}, \Ch247Ai\Core\NotFoundException::class);

T::section('read_deployment_status');
$r = $exec('read_deployment_status', []);
T::eq('deployment records returned', 2, $r->data['count']);
T::eq('latest deployment first', 'DEP-TEST-702', $r->data['deployments'][0]['reference']);
T::eq('deployment status and progress included', 'failed', $r->data['deployments'][0]['status']);
T::eq('machine error code included', 'ROLLOUT_TIMEOUT', $r->data['deployments'][0]['error_code']);
$deploymentJson = json_encode($r->data);
T::ok('deployment payload omitted', strpos($deploymentJson, 'DO_NOT_EXPOSE_PAYLOAD') === false && strpos($deploymentJson, 'OTHER_SECRET') === false);
T::ok('free-text deployment errors omitted', strpos($deploymentJson, 'DO_NOT_EXPOSE_ERROR') === false);
T::ok('customer identifiers omitted', !array_key_exists('customer_id', $r->data['deployments'][0]));
T::ok('application health caveat included', strpos($r->data['limitations'], 'not external application availability') !== false);
T::ok('deployment query citation present', strpos($r->citations[0], 'mod_ch247apps_deployments') !== false);

T::section('read_server_health_checks');
$r = $exec('read_server_health_checks', []);
T::eq('passed and failed health-check events returned', 2, $r->data['count']);
T::eq('newest audit event first', 502, $r->data['events'][0]['event_id']);
T::eq('failed provider event normalized', 'failed', $r->data['events'][0]['result']);
T::eq('provider error code extracted', 'PROVIDER_TIMEOUT', $r->data['events'][0]['error_code']);
T::eq('passed result and provider status extracted', 'active', $r->data['events'][1]['provider_status']);
T::ok('non-health audit events excluded', strpos(json_encode($r->data), 'console_opened') === false);
T::ok('actor and IP fields excluded', strpos(json_encode($r->data), 'redacted-hash') === false);
T::ok('no continuous telemetry claim', strpos($r->data['limitations'], 'CPU, memory, disk') !== false);

T::section('read_infrastructure_status');
$r = $exec('read_infrastructure_status', []);
T::eq('CloudHost247 Services status records returned', 2, $r->data['count']);
T::eq('provider-synced status reported', 'active', $r->data['servers'][0]['status']);
T::eq('newest status record first', 9, (int) $r->data['servers'][0]['id']);
T::ok('provider instance identifiers excluded', strpos(json_encode($r->data), 'provider-secret-id') === false);
T::ok('independent health and telemetry limitations explicit', strpos($r->data['limitations'], 'not an independent uptime probe') !== false);
T::ok('query citation names addon table', strpos($r->citations[0], 'mod_chs_module_servers') !== false);

T::section('read_provisioning_queue');
$r = $exec('read_provisioning_queue', []);
T::eq('only Pending services returned', 1, $r->data['count']);
T::eq('pending service id', 303, (int) $r->data['services'][0]['id']);
T::eq('registration age measured from regdate', 5, $r->data['services'][0]['age_days_since_regdate']);
T::ok('no unsupported stuck-job conclusion', strpos($r->data['interpretation'], 'not proof') !== false);
T::ok('citation includes tblhosting query', strpos($r->citations[0], 'tblhosting') !== false);

T::section('read_server_inventory');
$r = $exec('read_server_inventory', []);
T::eq('configured server rows returned', 2, $r->data['count']);
T::eq('configured hostname included', 'cpanel.example.test', $r->data['servers'][0]['hostname']);
T::ok('inactive configured servers remain visible', (int) $r->data['servers'][1]['active'] === 0);
$encodedServers = json_encode($r->data);
T::ok('password is never returned', strpos($encodedServers, 'DO_NOT_EXPOSE_PASSWORD') === false);
T::ok('accesshash is never returned', strpos($encodedServers, 'DO_NOT_EXPOSE_HASH') === false);
T::ok('no health or telemetry claim', strpos($r->data['scope'], 'not live reachability') !== false);
T::ok('query citation names tblservers', strpos($r->citations[0], 'tblservers') !== false);

T::section('read_metrics');
$r = $exec('read_metrics', ['windows' => 'today,7d']);
$names = [];
foreach ($r->data['metrics'] as $metric) {
    $names[] = $metric['metric'];
}
foreach (['new_clients_today', 'paid_today_amount', 'clients_active_total', 'invoices_unpaid_total', 'services_active_total', 'tickets_open_total', 'domains_expiring_30d'] as $expected) {
    T::ok("metric {$expected} present", in_array($expected, $names, true));
}
foreach ($r->data['metrics'] as $metric) {
    T::ok("{$metric['metric']} has SQL citation", strpos($metric['sql'], 'SELECT') === 0);
}
$byName = [];
foreach ($r->data['metrics'] as $metric) {
    $byName[$metric['metric']] = $metric['value'];
}
T::eq('active clients = 2 (11, 22)', 2.0, $byName['clients_active_total']);
T::eq('unpaid total = 3', 3.0, $byName['invoices_unpaid_total']);
T::eq('unpaid exposure 132.5 (80.5 + 45 - 5 credit + 12)', 132.5, $byName['invoices_unpaid_amount']);
T::eq('tickets not closed = 2 (401 open + 402 answered)', 2.0, $byName['tickets_open_total']);
T::eq('domains expiring 30d = 1 (avariver.com 2026-10-20)', 1.0, $byName['domains_expiring_30d']);
T::eq('services due 7d = 1 (ben.test 2026-10-08)', 1.0, $byName['services_due_7d']);

T::section('Limits are clamped server-side');
$r = $exec('read_invoices', ['limit' => 999]);
T::ok('limit clamped to 50', strpos($r->citations[0], 'LIMIT 50') !== false);
$r = $exec('read_invoices', ['limit' => 2]);
T::eq('small limit honored', 2, $r->data['count']);

T::section('Diagnostics fail closed for unknown/invalid input');
T::throws('non-whitelisted diagnostic fails closed', function () {
    \Ch247Ai\Tools\Readers\ch247ai_run_diagnostic('not_a_real_tool', ['domain' => 'example.com']);
}, \Ch247Ai\Core\ServiceUnavailableException::class);
foreach (['', 'not a domain', 'http://x', '../../etc/passwd'] as $input) {
    $refused = false;
    try {
        $exec('read_diag_ssl_checker', ['domain' => $input]);
    } catch (\Ch247Ai\Core\Ch247AiException $e) {
        $refused = true;
    }
    T::ok("invalid input '{$input}' refused before any network call", $refused);
}
$refused = false;
try {
    $exec('read_diag_port_checker', ['host' => 'example.com', 'port' => 99999]);
} catch (\Ch247Ai\Core\Ch247AiRefused $e) {
    $refused = true;
}
T::ok('out-of-range port refused', $refused);

T::section('Diagnostic whitelist maps to the real tools module functions');
$map = \Ch247Ai\Tools\Readers\ch247ai_diagnostics_map();
T::ok('17 diagnostics whitelisted', count($map) === 17);
foreach (['ssl_checker' => 'security_tools.php', 'dns_lookup' => 'dns_tools.php', 'port_checker' => 'network_tools.php'] as $key => $file) {
    T::ok("{$key} mapped to {$file}", $map[$key][0] === $file && strpos($map[$key][1], 'CloudHost247_tool_') === 0);
}
T::ok('tools module found in the repo', \Ch247Ai\Tools\Readers\ch247ai_tools_root() !== null);

T::finish();
