<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Db;
use Chs\Core\NotFoundException;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Services\OsCatalogService;
use Chs\Services\ServerOrderService;
use Chs\Services\ServerProvisioningService;
use Chs\Workflow\Worker;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);
chs_seed_server_products($gateway);
$infra = chs_seed_infra();
$fake = chs_fake_infra_provider($infra['provider_id']);

/** Map an active image for a version/arch on the fake provider. */
function chs_add_image($infra, $fake, $versionId, $arch, $ref, $regionId = 0)
{
    $catalog = new OsCatalogService();
    $imageId = $catalog->saveImage(0, [
        'provider_id' => $infra['provider_id'],
        'operating_system_version_id' => $versionId,
        'architecture' => $arch,
        'provider_image_id' => $ref,
        'region_id' => $regionId ?: $infra['region_id'],
    ]);
    $fake->images[$ref] = ['id' => $ref, 'name' => $ref, 'architecture' => $arch, 'region' => 'fra'];
    $catalog->testImage($imageId);
    $catalog->setImageStatus($imageId, 'active');
    return $imageId;
}

/** Place + pay + provision a server to active. Returns [order, moduleServerId]. */
function chs_provision_server($gateway, $infra, $hostname)
{
    $orders = new ServerOrderService();
    $order = $orders->createOrder(11, 101, 'monthly', $hostname, $infra['version_id'], 'x86_64', 0, $infra['region_id']);
    $gateway->invoices[$order['invoice_id']] = 'Paid';
    $orders->onInvoicePaid($order['invoice_id']);
    Worker::run();
    return [$order, $order['module_server_id']];
}

$debian = Db::first('operating_systems', ['slug' => 'debian']);
$debian13 = Db::first('operating_system_versions', ['operating_system_id' => (int) $debian['id'], 'version' => '13']);
$debianImage = chs_add_image($infra, $fake, (int) $debian13['id'], 'x86_64', 'img-debian-13');

T::section('Reinstall: happy path');
list($order, $serverId) = chs_provision_server($gateway, $infra, 'vps-r1.example.com');
$server = Db::first('module_servers', ['id' => $serverId]);
T::eq('server active before reinstall', 'active', $server['status']);
T::eq('runs ubuntu', $infra['version_id'], (int) $server['operating_system_version_id']);

$provisioning = new ServerProvisioningService();
$jobId = $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
T::ok('reinstall job created', $jobId > 0);
$pj = Db::first('provisioning_jobs', ['id' => $jobId]);
T::eq('job type REINSTALL', 'REINSTALL', $pj['type']);
T::eq('job queued', 'QUEUED', $pj['status']);
T::eq('target image resolved', $debianImage, (int) $pj['os_image_id']);
T::eq('queue job enqueued', 1, Db::count('jobs', ['type' => 'SERVER_REINSTALL']));
T::eq('audit: reinstall started', 1, Db::count('audit_log', ['action' => 'server.reinstall_started']));

Worker::run();
$pj = Db::first('provisioning_jobs', ['id' => $jobId]);
T::eq('reinstall READY', 'READY', $pj['status']);
T::eq('provider reinstall called', 1, count($fake->callsTo('reinstallServer')));
$reinstallCall = $fake->callsTo('reinstallServer')[0];
T::eq('reinstall image ref', 'img-debian-13', $reinstallCall[1]);
T::eq('reinstall target server', $server['provider_server_id'], $reinstallCall[0]);
$server = Db::first('module_servers', ['id' => $serverId]);
T::eq('server now runs debian 13', (int) $debian13['id'], (int) $server['operating_system_version_id']);
T::eq('server still active', 'active', $server['status']);
T::eq('audit: reinstall completed', 1, Db::count('audit_log', ['action' => 'server.reinstall_completed']));
$notices = Db::all('notifications', ['client_id' => 11, 'type' => 'server']);
T::eq('reinstall notification', 1, count(array_filter($notices, function ($n) {
    return strpos($n['subject'], 'reinstall') !== false;
})));

T::section('Reinstall: ownership + confirmation + validation');
T::throws('other client cannot reinstall (IDOR)', function () use ($provisioning, $serverId, $debian13) {
    $provisioning->requestReinstall(22, $serverId, (int) $debian13['id'], 'x86_64', true);
}, NotFoundException::class);
T::throws('unknown server id', function () use ($provisioning, $debian13) {
    $provisioning->requestReinstall(11, 9999, (int) $debian13['id'], 'x86_64', true);
}, NotFoundException::class);
T::throws('confirmation required', function () use ($provisioning, $serverId, $debian13) {
    $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', false);
}, ValidationException::class);
T::throws('unknown version refused', function () use ($provisioning, $serverId) {
    $provisioning->requestReinstall(11, $serverId, 9999, 'x86_64', true);
}, ValidationException::class);
$archOs = Db::first('operating_systems', ['slug' => 'arch']);
$archV = Db::first('operating_system_versions', ['operating_system_id' => (int) $archOs['id']]);
T::throws('unsupported architecture refused', function () use ($provisioning, $serverId, $archV) {
    $provisioning->requestReinstall(11, $serverId, (int) $archV['id'], 'arm64', true);
}, ValidationException::class);
$eolV = Db::first('operating_system_versions', ['status' => 'EOL']);
T::throws('EOL version refused', function () use ($provisioning, $serverId, $eolV) {
    $provisioning->requestReinstall(11, $serverId, (int) $eolV['id'], 'x86_64', true);
}, ValidationException::class);

T::section('Reinstall: OS without reinstall flag refused');
$catalog = new OsCatalogService();
$noReinstallOs = $catalog->saveOs(0, [
    'name' => 'NoReinstall OS', 'slug' => 'no-reinstall', 'status' => 'ACTIVE',
    'is_vps_supported' => 1, 'is_reinstall_supported' => 0,
]);
$nrVersion = $catalog->saveVersion($noReinstallOs, 0, [
    'version' => '1', 'display_name' => 'NoReinstall 1', 'architectures' => ['x86_64'], 'status' => 'ACTIVE',
]);
chs_add_image($infra, $fake, $nrVersion, 'x86_64', 'img-noreinstall-1');
T::throws('OS not reinstall-supported refused', function () use ($provisioning, $serverId, $nrVersion) {
    $provisioning->requestReinstall(11, $serverId, $nrVersion, 'x86_64', true);
}, ValidationException::class);

T::section('Reinstall: unavailable image fails honestly');
// Disable the debian image → resolution must fail with IMAGE_UNAVAILABLE.
Db::update('server_os_images', ['id' => $debianImage], ['status' => 'disabled']);
T::throws('unavailable image refused', function () use ($provisioning, $serverId, $debian13) {
    $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
}, ProviderFailure::class);
try {
    $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
    T::ok('IMAGE_UNAVAILABLE thrown', false);
} catch (ProviderFailure $e) {
    T::eq('machine code', 'IMAGE_UNAVAILABLE', $e->machineCode());
    T::ok('permanent', !$e->isRetryable());
}
Db::update('server_os_images', ['id' => $debianImage], ['status' => 'active']);

T::section('Reinstall: duplicate + state guards');
$jobId2 = $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
T::throws('duplicate active reinstall refused', function () use ($provisioning, $serverId, $debian13) {
    $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
}, \Chs\Core\DuplicateOperationException::class);
Worker::run(); // complete the queued reinstall so later sections can reinstall again
T::eq('first reinstall completed', 'READY', Db::first('provisioning_jobs', ['id' => $jobId2])['status']);
// A server mid-provisioning cannot be reinstalled.
list($orderB, $serverB) = chs_provision_server($gateway, $infra, 'vps-r2.example.com');
Db::update('module_servers', ['id' => $serverB], ['status' => 'provisioning']);
T::throws('provisioning server cannot reinstall', function () use ($provisioning, $serverB, $debian13) {
    $provisioning->requestReinstall(11, $serverB, (int) $debian13['id'], 'x86_64', true);
}, ValidationException::class);

T::section('Reinstall: poll continuation + failure classification');
// Slow reinstall: provider reports installing on the first poll.
$before = count($fake->callsTo('reinstallServer'));
$jobId3 = $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
$fake->installingTicks = 1;
Worker::run();
$pj3 = Db::first('provisioning_jobs', ['id' => $jobId3]);
T::eq('reinstall waiting for boot', 'HEALTH_CHECK', $pj3['status']);
chs_freeze('+90 seconds');
Worker::run();
$pj3 = Db::first('provisioning_jobs', ['id' => $jobId3]);
T::eq('reinstall completed after boot', 'READY', $pj3['status']);
$fake->installingTicks = 0;

// Provider rejects the reinstall (permanent): terminal, server keeps old OS.
$jobId4 = $provisioning->requestReinstall(11, $serverId, (int) $debian13['id'], 'x86_64', true);
$fake->failWith['reinstallServer'] = ProviderFailure::permanent('image rejected', 'IMAGE_UNAVAILABLE');
Worker::run();
$pj4 = Db::first('provisioning_jobs', ['id' => $jobId4]);
T::eq('reinstall FAILED', 'FAILED', $pj4['status']);
T::eq('reinstall error code', 'IMAGE_UNAVAILABLE', $pj4['error_code']);
T::eq('permanent', 0, (int) $pj4['retryable']);
$server = Db::first('module_servers', ['id' => $serverId]);
T::eq('server back to active after failed reinstall', 'active', $server['status']);
T::eq('audit: reinstall failed', 1, Db::count('audit_log', ['action' => 'server.reinstall_failed']));

T::section('Reinstall: malformed job');
$service2 = new ServerProvisioningService();
$provisionJobId = (int) $order['provisioning_job_id'];
T::throws('wrong job type rejected', function () use ($service2, $provisionJobId) {
    $service2->executeReinstall(['payload' => ['provisioning_job_id' => $provisionJobId]]);
}, ValidationException::class);

T::finish();
