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

T::section('read_services / read_products');
$r = $exec('read_services', []);
T::eq('two services', 2, $r->data['count']);
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
