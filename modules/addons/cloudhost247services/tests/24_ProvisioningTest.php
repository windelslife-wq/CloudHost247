<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\InfraProviderRegistry;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Services\ServerOrderService;
use Chs\Workflow\Worker;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);
chs_seed_server_products($gateway);
$infra = chs_seed_infra();

function chs_place_order($gateway, $infra, $hostname = 'vps-01.example.com')
{
    $service = new ServerOrderService();
    return $service->createOrder(11, 101, 'monthly', $hostname, $infra['version_id'], 'x86_64', 0, $infra['region_id']);
}

T::section('Provisioning: happy path end-to-end');
$fake = chs_fake_infra_provider($infra['provider_id']);
$order = chs_place_order($gateway, $infra);
T::ok('order created', $order['invoice_id'] > 0);
T::eq('invoice starts unpaid', 'Unpaid', $gateway->invoiceStatus($order['invoice_id']));
$server = Db::first('module_servers', ['id' => $order['module_server_id']]);
T::eq('server pending payment', 'pending_payment', $server['status']);
$pj = Db::first('provisioning_jobs', ['id' => $order['provisioning_job_id']]);
T::eq('job queued', 'QUEUED', $pj['status']);
T::eq('job resolved image', $infra['image_id'], (int) $pj['os_image_id']);
T::eq('job resolved provider', $infra['provider_id'], (int) $pj['provider_id']);

$orders = new ServerOrderService();
T::ok('unpaid invoice does NOT enqueue', $orders->onInvoicePaid($order['invoice_id']) === false);
T::eq('still queued, nothing ran', 0, Db::count('jobs', ['status' => 'completed']));

// Pay through the platform gateway and fire the hook.
$gateway->invoices[$order['invoice_id']] = 'Paid';
T::ok('paid invoice enqueues', $orders->onInvoicePaid($order['invoice_id']) === true);
T::eq('server now provisioning', 'provisioning', Db::first('module_servers', ['id' => $order['module_server_id']])['status']);
T::eq('one queue job', 1, Db::count('jobs', ['type' => 'SERVER_PROVISION']));

$result = Worker::run();
T::eq('worker ran one job', 1, $result['ran']);
T::eq('worker completed it', 1, $result['completed']);

T::eq('provider created exactly one server', 1, count($fake->callsTo('createServer')));
$createSpec = $fake->callsTo('createServer')[0][0];
T::eq('spec hostname', 'vps-01.example.com', $createSpec['hostname']);
T::eq('spec image', 'img-ubuntu-2404', $createSpec['image_id']);
T::eq('spec region', 'fra', $createSpec['region']);
T::eq('spec architecture', 'x86_64', $createSpec['architecture']);

$pj = Db::first('provisioning_jobs', ['id' => $order['provisioning_job_id']]);
T::eq('job READY', 'READY', $pj['status']);
T::ok('completed_at set', !empty($pj['completed_at']));
$logs = json_decode($pj['logs'], true);
$stages = array_map(function ($l) {
    return $l['stage'];
}, $logs);
foreach (['ALLOCATING', 'CREATING', 'INSTALLING_OS', 'CONFIGURING', 'NETWORK_CONFIGURING', 'SECURITY_CONFIGURING', 'HEALTH_CHECK', 'READY'] as $stage) {
    T::ok('stage logged: ' . $stage, in_array($stage, $stages, true));
}

$server = Db::first('module_servers', ['id' => $order['module_server_id']]);
T::eq('server active', 'active', $server['status']);
T::eq('server IP stored', '203.0.113.10', $server['ip_address']);
T::ok('whmcs server record linked', (int) $server['whmcs_server_id'] > 0);
T::ok('provisioned_at set', !empty($server['provisioned_at']));
T::ok('hosting id linked', (int) $server['hosting_id'] > 0);

$hosting = $gateway->hostingDetail((int) $server['hosting_id']);
T::eq('WHMCS service hostname', 'vps-01.example.com', $hosting['domain']);
T::eq('WHMCS service Active', 'Active', $hosting['status']);
T::eq('WHMCS service server link', (int) $server['whmcs_server_id'], (int) $hosting['server']);
T::eq('tblservers record created', 1, count($gateway->callsTo('createServerRecord')));
$recordSpec = $gateway->callsTo('createServerRecord')[0]['fields'];
T::eq('tblservers ip', '203.0.113.10', $recordSpec['ipaddress']);

$notices = Db::all('notifications', ['client_id' => 11, 'type' => 'server']);
T::eq('ready notification sent', 1, count(array_filter($notices, function ($n) {
    return strpos($n['subject'], 'ready') !== false;
})));
T::ok('notification carries IP, no credentials', strpos($notices[0]['body'], '203.0.113.10') !== false);
T::eq('audit: image selected', 1, Db::count('audit_log', ['action' => 'server.os_image_selected']));
T::eq('audit: created at provider', 1, Db::count('audit_log', ['action' => 'server.created_at_provider']));
T::eq('audit: ready', 1, Db::count('audit_log', ['action' => 'server.ready']));

T::section('Provisioning: idempotency (no duplicate servers)');
T::ok('second payment hook is a no-op', $orders->onInvoicePaid($order['invoice_id']) === false);
$result = Worker::run();
T::eq('nothing left to run', 0, $result['ran']);
T::eq('still exactly one provider server', 1, count($fake->callsTo('createServer')));

T::section('Provisioning: worker crash resume never recreates');
$order2 = chs_place_order($gateway, $infra, 'vps-02.example.com');
$gateway->invoices[$order2['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order2['invoice_id']);
$pj2 = Db::first('provisioning_jobs', ['id' => $order2['provisioning_job_id']]);
// Simulate a crash AFTER the provider create but BEFORE the status was persisted.
$fake->servers['fake-99'] = [
    'status' => 'active', 'ip' => '203.0.113.99', 'image' => 'img-ubuntu-2404',
    'hostname' => 'vps-02.example.com', 'spec' => [], 'deleted' => false,
];
Db::update('provisioning_jobs', ['id' => $pj2['id']], [
    'status' => 'CREATING', 'stage' => 'ALLOCATING', 'provider_server_id' => 'fake-99',
]);
$before = count($fake->callsTo('createServer'));
Worker::run();
T::eq('no second createServer after crash', $before, count($fake->callsTo('createServer')));
$pj2 = Db::first('provisioning_jobs', ['id' => $order2['provisioning_job_id']]);
T::eq('resumed job READY', 'READY', $pj2['status']);
$server2 = Db::first('module_servers', ['id' => $order2['module_server_id']]);
T::eq('resumed server IP', '203.0.113.99', $server2['ip_address']);

T::section('Provisioning: unconfigured provider fails closed');
InfraProviderRegistry::$instanceOverride = null; // real registry → HTTP provider without credentials
$order3 = chs_place_order($gateway, $infra, 'vps-03.example.com');
$gateway->invoices[$order3['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order3['invoice_id']);
$result = Worker::run();
$pj3 = Db::first('provisioning_jobs', ['id' => $order3['provisioning_job_id']]);
T::eq('job FAILED', 'FAILED', $pj3['status']);
T::eq('error code', 'PROVIDER_NOT_CONFIGURED', $pj3['error_code']);
T::eq('permanent (no blind retry)', 0, (int) $pj3['retryable']);
T::eq('server marked failed', 'failed', Db::first('module_servers', ['id' => $order3['module_server_id']])['status']);
$failNotices = Db::all('notifications', ['client_id' => 11, 'type' => 'server']);
T::eq('failure notification', 1, count(array_filter($failNotices, function ($n) {
    return strpos($n['subject'], 'failed') !== false;
})));
T::eq('audit: provisioning failed', 1, Db::count('audit_log', ['action' => 'server.provisioning_failed']));
// Permanent failure: the queue job completed, not retried.
T::eq('queue job completed (no retry)', 1, Db::count('jobs', ['status' => 'completed', 'entity_id' => $order3['module_server_id']]));
T::eq('queue job not pending', 0, Db::count('jobs', ['status' => 'pending', 'entity_id' => $order3['module_server_id']]));
$fake = chs_fake_infra_provider($infra['provider_id']);

T::section('Provisioning: transient failure retries, then succeeds');
$order4 = chs_place_order($gateway, $infra, 'vps-04.example.com');
$gateway->invoices[$order4['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order4['invoice_id']);
$fake->failWith['createServer'] = ProviderFailure::timeout('provider slow');
Worker::run();
$pj4 = Db::first('provisioning_jobs', ['id' => $order4['provisioning_job_id']]);
T::eq('retryable failure recorded', 'FAILED', $pj4['status']);
T::eq('code PROVIDER_TIMEOUT', 'PROVIDER_TIMEOUT', $pj4['error_code']);
T::eq('marked retryable', 1, (int) $pj4['retryable']);
T::eq('queue retry scheduled', 1, Db::count('jobs', ['status' => 'pending', 'entity_id' => $order4['module_server_id']]));
chs_freeze('+3 minutes');
Worker::run();
$pj4 = Db::first('provisioning_jobs', ['id' => $order4['provisioning_job_id']]);
T::eq('retry resumed the job', 'READY', $pj4['status']);
$vps04Servers = array_filter($fake->servers, function ($s) {
    return $s['hostname'] === 'vps-04.example.com';
});
T::eq('exactly one provider server after retry', 1, count($vps04Servers));

T::section('Provisioning: poll continuation while OS installs');
$order5 = chs_place_order($gateway, $infra, 'vps-05.example.com');
$gateway->invoices[$order5['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order5['invoice_id']);
$fake->installingTicks = 1;
Worker::run();
$pj5 = Db::first('provisioning_jobs', ['id' => $order5['provisioning_job_id']]);
T::eq('still installing', 'INSTALLING_OS', $pj5['status']);
T::ok('continuation scheduled', !empty($pj5['next_poll_at']));
T::eq('one queue job done, one pending', 1, Db::count('jobs', ['status' => 'pending', 'entity_id' => $order5['module_server_id']]));
chs_freeze('+90 seconds');
Worker::run();
$pj5 = Db::first('provisioning_jobs', ['id' => $order5['provisioning_job_id']]);
T::eq('poll continuation reached READY', 'READY', $pj5['status']);
T::eq('IP assigned after boot', '203.0.113.10', Db::first('module_servers', ['id' => $order5['module_server_id']])['ip_address']);
$fake->installingTicks = 0;

T::section('Provisioning: deadline exceeded → PROVIDER_TIMEOUT');
$order6 = chs_place_order($gateway, $infra, 'vps-06.example.com');
$gateway->invoices[$order6['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order6['invoice_id']);
$pj6 = Db::first('provisioning_jobs', ['id' => $order6['provisioning_job_id']]);
$fake->installingTicks = 100; // the OS never finishes installing
$fake->servers['fake-stuck'] = [
    'status' => 'installing', 'ip' => null, 'image' => 'img-ubuntu-2404',
    'hostname' => 'vps-06.example.com', 'spec' => [], 'deleted' => false,
];
Db::update('provisioning_jobs', ['id' => $pj6['id']], [
    'status' => 'INSTALLING_OS', 'provider_server_id' => 'fake-stuck',
    'created_at' => Clock::ago(3600), // an hour old — deadline is 30 minutes
]);
Worker::run();
$pj6 = Db::first('provisioning_jobs', ['id' => $pj6['id']]);
T::eq('deadline failure is terminal FAILED', 'FAILED', $pj6['status']);
T::eq('deadline error code', 'PROVIDER_TIMEOUT', $pj6['error_code']);
$fake->installingTicks = 0;

T::section('Provisioning: payment re-verified at execution time');
$order7 = chs_place_order($gateway, $infra, 'vps-07.example.com');
$pj7 = Db::first('provisioning_jobs', ['id' => $order7['provisioning_job_id']]);
// Invoice exists but is NOT paid (hook never fired) — simulate a stray job.
Db::update('provisioning_jobs', ['id' => $pj7['id']], ['status' => 'QUEUED']);
$service = new \Chs\Services\ServerProvisioningService();
$queue = new \Chs\Workflow\JobQueue();
$queueJob = $queue->enqueue(\Chs\Workflow\InfraJobTypes::PROVISION, ['provisioning_job_id' => $pj7['id']], ['idempotency_key' => 'test:unpaid:' . $pj7['id']]);
$service->executeProvision($queue->find((int) $queueJob['id']));
$pj7 = Db::first('provisioning_jobs', ['id' => $order7['provisioning_job_id']]);
T::eq('unpaid job failed', 'FAILED', $pj7['status']);
T::eq('payment not confirmed', 'PAYMENT_NOT_CONFIRMED', $pj7['error_code']);
$unpaidCalls = array_filter($fake->callsTo('createServer'), function ($call) {
    return $call[0]['hostname'] === 'vps-07.example.com';
});
T::eq('no provider call for unpaid', 0, count($unpaidCalls));

T::section('Provisioning: malformed job payload');
T::throws('malformed payload rejected', function () use ($service) {
    $service->executeProvision(['payload' => []]);
}, ValidationException::class);

T::finish();
