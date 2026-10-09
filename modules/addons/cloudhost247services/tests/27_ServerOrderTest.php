<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeInfrastructureProvider.php';

use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\NotFoundException;
use Chs\Core\ValidationException;
use Chs\Providers\Infrastructure\ProviderFailure;
use Chs\Services\ServerOrderService;

$gateway = chs_boot();
chs_freeze();
chs_seed_clients($gateway);
chs_seed_server_products($gateway);
$infra = chs_seed_infra();
$fake = chs_fake_infra_provider($infra['provider_id']);

$orders = new ServerOrderService();

T::section('Order: product listing + availability');
$products = $orders->products();
T::eq('two server products (hosting excluded)', 2, count($products));
$byId = [];
foreach ($products as $p) {
    $byId[$p['id']] = $p;
}
T::eq('product 101 listed', 'Cloud VPS', $byId[101]['name']);
T::eq('product 103 (hosting) excluded', false, isset($byId[103]));
T::ok('101 has Ubuntu in matrix', $byId[101]['os_count'] >= 1);
T::ok('101 lists x86_64', in_array('x86_64', $byId[101]['architectures'], true));
T::eq('101 has regions', 1, $byId[101]['regions']);
T::eq('101 server type default', 'vps', $byId[101]['server_type']);

T::section('Order: configuration payload');
$config = $orders->configuration(101, 0, '');
T::eq('config product', 101, $config['product']['id']);
T::ok('config regions', count($config['regions']) === 1);
T::ok('config architectures', in_array('x86_64', $config['architectures'], true));
$ubuntu = null;
foreach ($config['operatingSystems'] as $os) {
    if ($os['slug'] === 'ubuntu') {
        $ubuntu = $os;
    }
}
T::ok('ubuntu in config', (bool) $ubuntu);
T::ok('ubuntu logo url', strpos($ubuntu['logo_url'], 'assets/img/os/ubuntu.svg') !== false);
$defaultVersion = null;
foreach ($ubuntu['versions'] as $v) {
    if ($v['is_default']) {
        $defaultVersion = $v;
    }
}
T::ok('a default version is flagged', (bool) $defaultVersion);
T::ok('versions carry provider availability', isset($defaultVersion['architectures']['x86_64']));
T::throws('unknown product', function () use ($orders) {
    $orders->configuration(9999);
}, NotFoundException::class);
T::throws('hosting product refused', function () use ($orders) {
    $orders->configuration(103);
}, NotFoundException::class);

T::section('Order: createOrder happy path');
$result = $orders->createOrder(11, 101, 'monthly', 'VPS-01.Example.COM', $infra['version_id'], 'x86_64', 0, $infra['region_id']);
T::ok('invoice created', $result['invoice_id'] > 0);
T::eq('real WHMCS order via gateway', 1, count($gateway->callsTo('createOrder')));
$orderCall = $gateway->callsTo('createOrder')[0];
T::eq('order product', 101, $orderCall['product_id']);
T::eq('order cycle', 'monthly', $orderCall['billing_cycle']);
T::eq('hostname normalized to lowercase', 'vps-01.example.com', $orderCall['hostname']);
T::ok('invoice url', strpos($result['invoice_url'], 'viewinvoice') !== false);
$server = Db::first('module_servers', ['id' => $result['module_server_id']]);
T::eq('server pending payment', 'pending_payment', $server['status']);
T::eq('hostname stored lowercase', 'vps-01.example.com', $server['hostname']);
T::eq('image resolved at order time', $infra['image_id'], Db::first('provisioning_jobs', ['id' => $result['provisioning_job_id']])['os_image_id']);
T::eq('audit: order created', 1, Db::count('audit_log', ['action' => 'server.order_created']));

T::section('Order: validation (frontend never trusted)');
T::throws('unknown product', function () use ($orders, $infra) {
    $orders->createOrder(11, 9999, 'monthly', 'a.example.com', $infra['version_id'], 'x86_64');
}, ValidationException::class);
T::throws('hosting product refused', function () use ($orders, $infra) {
    $orders->createOrder(11, 103, 'monthly', 'a.example.com', $infra['version_id'], 'x86_64');
}, ValidationException::class);
T::throws('bad billing cycle', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'century', 'a.example.com', $infra['version_id'], 'x86_64');
}, ValidationException::class);
T::throws('invalid hostname', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'monthly', 'not a hostname!!', $infra['version_id'], 'x86_64');
}, ValidationException::class);
T::throws('hostname too long', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'monthly', str_repeat('a', 64) . '.example.com', $infra['version_id'], 'x86_64');
}, ValidationException::class);
T::throws('unknown OS version', function () use ($orders) {
    $orders->createOrder(11, 101, 'monthly', 'b.example.com', 9999, 'x86_64');
}, ValidationException::class);
T::throws('unsupported architecture', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'monthly', 'b.example.com', $infra['version_id'], 'ppc64le');
}, ValidationException::class);
T::throws('unavailable image combination', function () use ($orders) {
    // Arch rolling is x86_64-only in the seed and has no arm64 image.
    $arch = Db::first('operating_systems', ['slug' => 'arch']);
    $archV = Db::first('operating_system_versions', ['operating_system_id' => (int) $arch['id']]);
    $orders->createOrder(11, 101, 'monthly', 'b.example.com', (int) $archV['id'], 'arm64');
}, ValidationException::class);
T::throws('EOL version refused', function () use ($orders) {
    $eolV = Db::first('operating_system_versions', ['status' => 'EOL']);
    $orders->createOrder(11, 101, 'monthly', 'b.example.com', (int) $eolV['id'], 'x86_64');
}, ValidationException::class);
T::throws('unknown region', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'monthly', 'b.example.com', $infra['version_id'], 'x86_64', 0, 9999);
}, ValidationException::class);
T::throws('unknown client', function () use ($orders, $infra) {
    $orders->createOrder(9999, 101, 'monthly', 'b.example.com', $infra['version_id'], 'x86_64');
}, NotFoundException::class);
T::throws('duplicate pending hostname', function () use ($orders, $infra) {
    $orders->createOrder(11, 101, 'monthly', 'vps-01.example.com', $infra['version_id'], 'x86_64', 0, $infra['region_id']);
}, DuplicateOperationException::class);

T::section('Order: no active image → IMAGE_UNAVAILABLE');
Db::update('server_os_images', ['id' => $infra['image_id']], ['status' => 'disabled']);
try {
    $orders->createOrder(11, 101, 'monthly', 'c.example.com', $infra['version_id'], 'x86_64', 0, $infra['region_id']);
    T::ok('IMAGE_UNAVAILABLE thrown', false);
} catch (ProviderFailure $e) {
    T::eq('machine code', 'IMAGE_UNAVAILABLE', $e->machineCode());
    T::ok('honest permanent failure', !$e->isRetryable());
}
T::eq('no order created for unavailable image', 0, Db::count('module_servers', ['hostname' => 'c.example.com']));
Db::update('server_os_images', ['id' => $infra['image_id']], ['status' => 'active']);

T::section('Order: SSH keys');
$validKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGV4YW1wbGVrZXlmb3J0ZXN0aW5nMTIzNDU2Nzg5 test@example.com';
$keyId = $orders->addSshKey(11, 'laptop', $validKey);
T::ok('key added', $keyId > 0);
T::eq('fingerprint computed', 0, strpos($orders->sshKeys(11)[0]['fingerprint'], 'SHA256:') === 0 ? 0 : 1);
T::throws('invalid key format', function () use ($orders) {
    $orders->addSshKey(11, 'bad', 'not-a-key');
}, ValidationException::class);
T::throws('duplicate key name', function () use ($orders, $validKey) {
    $orders->addSshKey(11, 'laptop', $validKey);
}, ValidationException::class);
T::throws('empty name', function () use ($orders, $validKey) {
    $orders->addSshKey(11, '', $validKey);
}, ValidationException::class);
// Ownership: client 22 cannot use or delete client 11's key.
T::throws('other client cannot delete key (IDOR)', function () use ($orders, $keyId) {
    $orders->deleteSshKey(22, $keyId);
}, NotFoundException::class);
T::throws('key of another client not usable in order', function () use ($orders, $infra, $keyId) {
    $orders->createOrder(22, 101, 'monthly', 'd.example.com', $infra['version_id'], 'x86_64', $keyId, $infra['region_id']);
}, ForbiddenException::class);
// A valid order with the client's own key links it on the job.
$result2 = $orders->createOrder(11, 101, 'monthly', 'vps-02.example.com', $infra['version_id'], 'x86_64', $keyId, $infra['region_id']);
T::eq('ssh key linked to job', $keyId, (int) Db::first('provisioning_jobs', ['id' => $result2['provisioning_job_id']])['ssh_key_id']);
// Referenced keys cannot be deleted.
T::throws('referenced key cannot be deleted', function () use ($orders, $keyId) {
    $orders->deleteSshKey(11, $keyId);
}, DuplicateOperationException::class);
T::ok('unreferenced key deleted', $orders->deleteSshKey(11, $orders->addSshKey(11, 'temp', $validKey)));

T::section('Order: payment hook (InvoicePaid)');
T::ok('unknown invoice → false', $orders->onInvoicePaid(424242) === false);
$gateway->invoices[$result['invoice_id']] = 'Paid';
T::ok('paid invoice enqueues provisioning', $orders->onInvoicePaid($result['invoice_id']) === true);
$pj = Db::first('provisioning_jobs', ['id' => $result['provisioning_job_id']]);
T::eq('job still queued for worker', 'QUEUED', $pj['status']);
T::eq('hosting linked', 6001, (int) $pj['hosting_id']);
// Client mismatch between invoice and job → refuse.
$gateway->invoices[$result2['invoice_id']] = 'Paid';
Db::update('provisioning_jobs', ['id' => $result2['provisioning_job_id']], ['client_id' => 22]);
T::ok('client mismatch refused', $orders->onInvoicePaid($result2['invoice_id']) === false);
T::eq('no job enqueued on mismatch', 'QUEUED', Db::first('provisioning_jobs', ['id' => $result2['provisioning_job_id']])['status']);

T::section('Order: server listing decorates records');
$list = $orders->serversForClient(11);
T::eq('two servers for client 11', 2, count($list));
$first = $list[0];
T::ok('os display decorated', $first['os_display'] !== '');
T::eq('region decorated', 'Frankfurt', $first['region_name']);
T::eq('provider decorated', 'Fake Cloud', $first['provider_name']);
T::throws('other client sees nothing (IDOR)', function () use ($orders, $result) {
    $orders->serverForClient(22, $result['module_server_id']);
}, NotFoundException::class);

T::section('Order: hostname + key validators');
T::ok('valid hostname', $orders->isValidHostname('vps-01.example.com'));
T::ok('single label ok', $orders->isValidHostname('vps01'));
T::ok('rejects leading dash', !$orders->isValidHostname('-bad.example.com'));
T::ok('rejects underscore', !$orders->isValidHostname('bad_host.example.com'));
T::ok('rejects empty', !$orders->isValidHostname(''));
T::ok('valid ed25519 key', $orders->isValidPublicKey($validKey));
T::ok('valid rsa key', $orders->isValidPublicKey('ssh-rsa ' . str_repeat('A', 64) . ' test'));
T::ok('rejects garbage', !$orders->isValidPublicKey('hello world'));

T::finish();
