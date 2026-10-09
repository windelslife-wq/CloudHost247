<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\NotFoundException;
use Chs\Core\ValidationException;
use Chs\Services\ServerOrderService;
use Chs\Services\ServerProvisioningService;
use Chs\Workflow\Worker;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);
chs_seed_server_products($gateway);
$infra = chs_seed_infra();
$fake = chs_fake_infra_provider($infra['provider_id']);

$orders = new ServerOrderService();
$order = $orders->createOrder(11, 101, 'monthly', 'vps-a1.example.com', $infra['version_id'], 'x86_64', 0, $infra['region_id']);
$gateway->invoices[$order['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order['invoice_id']);
Worker::run();
$serverId = $order['module_server_id'];
$server = Db::first('module_servers', ['id' => $serverId]);
T::eq('server active', 'active', $server['status']);
T::eq('provider ref stored', 'fake-1', $server['provider_server_id']);

$provisioning = new ServerProvisioningService();

T::section('Actions: power operations');
foreach (['stop', 'start', 'reboot', 'shutdown'] as $action) {
    $jobId = $provisioning->requestAction(11, $serverId, $action);
    T::ok($action . ' job created', $jobId > 0);
    Worker::run();
    $pj = Db::first('provisioning_jobs', ['id' => $jobId]);
    T::eq($action . ' job READY', 'READY', $pj['status']);
    T::eq($action . ' hit the provider', 1, count($fake->callsTo(
        $action === 'shutdown' ? 'shutdownServer' : $action . 'Server'
    )));
    $server = Db::first('module_servers', ['id' => $serverId]);
    if (in_array($action, ['stop', 'shutdown'], true)) {
        T::eq('server stopped after ' . $action, 'stopped', $server['status']);
    } else {
        T::eq('server active after ' . $action, 'active', $server['status']);
    }
}
T::eq('audit: reboot', 1, Db::count('audit_log', ['action' => 'server.rebooted']));
T::eq('audit: stopped', 1, Db::count('audit_log', ['action' => 'server.stopped']));
T::eq('audit: started', 1, Db::count('audit_log', ['action' => 'server.started']));

T::section('Actions: rescue mode');
$jobId = $provisioning->requestAction(11, $serverId, 'rescue');
Worker::run();
T::eq('rescue READY', 'READY', Db::first('provisioning_jobs', ['id' => $jobId])['status']);
T::eq('rescue hit the provider', 1, count($fake->callsTo('enterRescueMode')));

T::section('Actions: delete terminates the WHMCS service');
$hostingId = (int) $server['hosting_id'];
$jobId = $provisioning->requestAction(11, $serverId, 'delete');
Worker::run();
T::eq('delete READY', 'READY', Db::first('provisioning_jobs', ['id' => $jobId])['status']);
T::eq('delete hit the provider', 1, count($fake->callsTo('deleteServer')));
T::eq('module server deleted', 'deleted', Db::first('module_servers', ['id' => $serverId])['status']);
$hosting = $gateway->hostingDetail($hostingId);
T::eq('WHMCS service Terminated', 'Terminated', $hosting['status']);
T::eq('audit: deleted', 1, Db::count('audit_log', ['action' => 'server.deleted']));

T::section('Actions: ownership + capability + state guards');
T::throws('other client cannot act (IDOR)', function () use ($provisioning, $serverId) {
    $provisioning->requestAction(22, $serverId, 'reboot');
}, NotFoundException::class);
T::throws('unknown server', function () use ($provisioning) {
    $provisioning->requestAction(11, 9999, 'reboot');
}, NotFoundException::class);
T::throws('unknown action', function () use ($provisioning, $serverId) {
    $provisioning->requestAction(11, $serverId, 'explode');
}, ValidationException::class);
T::throws('deleted server cannot act', function () use ($provisioning, $serverId) {
    $provisioning->requestAction(11, $serverId, 'reboot');
}, ValidationException::class);

// Capability gating: a provider without 'delete' refuses the action.
$fake->capsOverride['delete'] = false;
$order2 = $orders->createOrder(11, 101, 'monthly', 'vps-a2.example.com', $infra['version_id'], 'x86_64', 0, $infra['region_id']);
$gateway->invoices[$order2['invoice_id']] = 'Paid';
$orders->onInvoicePaid($order2['invoice_id']);
Worker::run();
$server2Id = $order2['module_server_id'];
T::throws('unsupported capability refused', function () use ($provisioning, $server2Id) {
    $provisioning->requestAction(11, $server2Id, 'delete');
}, ValidationException::class);
$fake->capsOverride = [];

T::section('Actions: console');
$console = $provisioning->consoleFor(11, $server2Id);
T::ok('console url returned', strpos($console['url'], 'fake-2') !== false);
T::throws('console IDOR', function () use ($provisioning, $server2Id) {
    $provisioning->consoleFor(22, $server2Id);
}, NotFoundException::class);
$fake->capsOverride['console'] = false;
T::throws('console capability gated', function () use ($provisioning, $server2Id) {
    $provisioning->consoleFor(11, $server2Id);
}, ValidationException::class);
$fake->capsOverride = [];

T::section('Actions: transient failure retries');
$jobId = $provisioning->requestAction(11, $server2Id, 'reboot');
$fake->failWith['rebootServer'] = \Chs\Providers\Infrastructure\ProviderFailure::timeout('slow reboot');
Worker::run();
$pj = Db::first('provisioning_jobs', ['id' => $jobId]);
T::eq('action FAILED retryable', 'FAILED', $pj['status']);
T::eq('action error code', 'PROVIDER_TIMEOUT', $pj['error_code']);
T::eq('queue retry scheduled', 1, Db::count('jobs', ['status' => 'pending', 'entity_id' => $server2Id]));
chs_freeze('+2 minutes');
Worker::run();
$pj = Db::first('provisioning_jobs', ['id' => $jobId]);
T::eq('action retried to READY', 'READY', $pj['status']);

T::section('Admin: retry + cancel + list + stats');
// Force a permanent failure, then admin-retry it.
$jobId = $provisioning->requestAction(11, $server2Id, 'stop');
$fake->failWith['stopServer'] = \Chs\Providers\Infrastructure\ProviderFailure::permanent('nope', 'AUTHENTICATION_FAILED');
Worker::run();
$pj = Db::first('provisioning_jobs', ['id' => $jobId]);
T::eq('permanent action failure terminal', 'FAILED', $pj['status']);
T::eq('no auto retry for permanent', 0, Db::count('jobs', ['status' => 'pending', 'entity_id' => $server2Id]));
T::throws('only failed jobs can be retried (READY rejected)', function () use ($provisioning, $order2) {
    $provisioning->adminRetry($order2['provisioning_job_id'], 1);
}, DuplicateOperationException::class);
T::ok('admin retry re-queues', $provisioning->adminRetry($jobId, 1));
T::eq('job back to QUEUED', 'QUEUED', Db::first('provisioning_jobs', ['id' => $jobId])['status']);
T::eq('fresh queue job', 1, Db::count('jobs', ['status' => 'pending', 'entity_id' => $server2Id]));
Worker::run();
T::eq('admin-retried job READY', 'READY', Db::first('provisioning_jobs', ['id' => $jobId])['status']);
T::eq('audit: admin retry', 1, Db::count('audit_log', ['action' => 'server.admin_retry']));

$jobId = $provisioning->requestAction(11, $server2Id, 'reboot');
T::ok('admin cancel', $provisioning->adminCancel($jobId, 2));
T::eq('cancelled', 'CANCELLED', Db::first('provisioning_jobs', ['id' => $jobId])['status']);
T::eq('audit: admin cancel', 1, Db::count('audit_log', ['action' => 'server.admin_cancel']));

$list = $provisioning->listJobs(['search' => 'vps-a2'], 1, 50);
T::ok('list filters by search', $list['total'] >= 4);
$detail = $provisioning->jobDetail($jobId);
T::ok('detail decodes logs', is_array($detail['logs']));
T::ok('detail includes server', (int) $detail['server']['id'] === $server2Id);
$stats = $provisioning->stats();
T::ok('stats count READY', ($stats['READY'] ?? 0) >= 4);
T::eq('stats count cancelled', 1, $stats['CANCELLED'] ?? 0);

T::section('Sync: provider truth reconciles module state');
T::eq('module currently stopped', 'stopped', Db::first('module_servers', ['id' => $server2Id])['status']);
$fake->servers['fake-2']['status'] = 'active'; // provider says active, module says stopped
$synced = $provisioning->syncProviderStates();
T::ok('sync ran', $synced >= 1);
T::eq('module follows provider truth', 'active', Db::first('module_servers', ['id' => $server2Id])['status']);

T::section('Health check job type');
$queue = new \Chs\Workflow\JobQueue();
$qJob = $queue->enqueue(\Chs\Workflow\InfraJobTypes::HEALTH_CHECK, ['module_server_id' => $server2Id], ['idempotency_key' => 'hc:' . $server2Id]);
Worker::run();
T::eq('health check job ran', 'completed', Db::first('jobs', ['id' => (int) $qJob['id']])['status']);
T::ok('audit: health check started', Db::count('audit_log', ['action' => 'server.health_check_started']) >= 1);

T::finish();
