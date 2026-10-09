<?php
/** Self-service VPS entitlement, fail-closed worker and admin mapping tests (offline only). */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Settings;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\Providers\HetznerCloudAdapter;
use Ch247Apps\Infrastructure\ServerProductMappingService;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;

Harness::boot();
Harness::relaxRateLimits();
$admin = Harness::adminActor();
$customerId = Harness::client();
$foreignId = Harness::client(['email' => 'other@example.test']);
$productId = Harness::$gateway->addProduct(['type' => 'server', 'paytype' => 'recurring']);
$otherProductId = Harness::$gateway->addProduct(['type' => 'other']);
$legacyOvhProductId = Harness::$gateway->addProduct([
    'type' => 'server', 'paytype' => 'recurring', 'servermodule' => 'soyoustart_vps',
]);
$order = Harness::$gateway->createOrder(['clientid' => $customerId, 'pid' => $productId]);
$serviceId = (int) $order['service_id'];
$otherOrder = Harness::$gateway->createOrder(['clientid' => $foreignId, 'pid' => $productId]);
$otherServiceId = (int) $otherOrder['service_id'];
$spec = ['region' => 'fsn1', 'image' => 'ubuntu-24.04', 'cpu_cores' => 2,
    'memory_mb' => 2048, 'storage_gb' => 40];
$adminApi = new InfrastructureApi($admin);
$customer = Actor::customer($customerId);
$customerApi = new InfrastructureApi($customer);
$csrf = ['X-CSRF-Token' => Csrf::token()];
$input = ['service_id' => $serviceId];

section('Additive mapping defaults and authorization');
T::ok('product mapping table exists', Db::tableExists('server_product_mappings'));
T::ok('server records correlate a mapping', (new \Ch247Apps\Core\Migrator(dirname(__DIR__) . '/install/migrations'))
    ->hasColumn('customer_servers', 'product_mapping_id'));
T::ok('self-service remains off', !Settings::bool('customer_server_self_service_enabled', false));
T::is('customer cannot edit operator mappings', 403, $customerApi->dispatch('POST', '/v1/server-product-mappings', [], $csrf)['status']);
T::is('staff cannot edit mappings', 403, (new InfrastructureApi(Actor::admin(2, Actor::ROLE_STAFF)))
    ->dispatch('POST', '/v1/server-product-mappings', [], $csrf)['status']);
T::is('customer endpoint disabled', 503, $customerApi->dispatch('POST', '/v1/servers/self-service',
    $input, $csrf + ['Idempotency-Key' => 'self-off'])['status']);
T::is('disabled request creates no server', 0, Db::count('customer_servers'));

$account = (new ProviderAccountService($admin))->create([
    'provider_code' => 'hetzner', 'name' => 'Offline test only',
    'credentials' => ['api_token' => 'not-a-real-provider-token'],
]);
$accountId = (int) $account['id'];
$manager = new ServerProductMappingService($admin);
$mappingInput = ['product_id' => $productId, 'provider_account_id' => $accountId,
    'spec' => $spec, 'enabled' => true];
T::is('unverified account cannot be enabled', 503, $adminApi->dispatch('POST',
    '/v1/server-product-mappings', $mappingInput, $csrf + ['Idempotency-Key' => 'unverified-map'])['status']);
// In tests only, simulate completion of the out-of-band provider verification.
ProviderRegistry::register(new HetznerCloudAdapter());
Db::update('provider_accounts', ['status' => 'active'], ['id' => $accountId]);
T::is('missing CSRF blocks mapping write', 401, $adminApi->dispatch('POST',
    '/v1/server-product-mappings', $mappingInput, ['Idempotency-Key' => 'no-csrf'])['status']);
$badProduct = $mappingInput;
$badProduct['product_id'] = $otherProductId;
T::is('non-server product refused', 422, $adminApi->dispatch('POST',
    '/v1/server-product-mappings', $badProduct, $csrf + ['Idempotency-Key' => 'bad-product'])['status']);
$legacyProduct = $mappingInput;
$legacyProduct['product_id'] = $legacyOvhProductId;
T::is('OVH product already managed by WHMCS cannot be double-provisioned', 422,
    $adminApi->dispatch('POST', '/v1/server-product-mappings', $legacyProduct,
        $csrf + ['Idempotency-Key' => 'legacy-ovh-product'])['status']);
$badTemplate = $mappingInput;
$badTemplate['spec']['name'] = 'customer-picked';
T::is('mapping cannot supply a name', 422, $adminApi->dispatch('POST',
    '/v1/server-product-mappings', $badTemplate, $csrf + ['Idempotency-Key' => 'bad-name'])['status']);
$saved = $adminApi->dispatch('POST', '/v1/server-product-mappings', $mappingInput,
    $csrf + ['Idempotency-Key' => 'valid-map']);
T::is('operator mapping saved', 201, $saved['status']);
T::is('one product maps to one provider', 1, Db::count('server_product_mappings'));
T::is('mapping does not expose credentials', false,
    strpos(json_encode($saved['body']), 'not-a-real-provider-token') !== false);
T::is('replay does not create duplicate', 200, $adminApi->dispatch('POST',
    '/v1/server-product-mappings', $mappingInput, $csrf + ['Idempotency-Key' => 'valid-map'])['status']);

section('Paid owner-only fixed-spec request');
Settings::override('customer_server_provisioning_enabled', '1');
Settings::override('customer_server_self_service_enabled', '1');
T::is('anonymous route cannot submit', 401,
    (new InfrastructureApi(Actor::guest()))->dispatch('POST', '/v1/servers/self-service', $input, $csrf)['status']);
T::is('customer cannot choose provider or size', 422, $customerApi->dispatch('POST', '/v1/servers/self-service',
    $input + ['provider_account_id' => $accountId], $csrf + ['Idempotency-Key' => 'tamper'])['status']);
T::is('foreign service is not visible', 404, $customerApi->dispatch('POST', '/v1/servers/self-service',
    ['service_id' => $otherServiceId], $csrf + ['Idempotency-Key' => 'foreign'])['status']);
T::is('unpaid invoice cannot queue a server', 402, $customerApi->dispatch('POST', '/v1/servers/self-service',
    $input, $csrf + ['Idempotency-Key' => 'unpaid'])['status']);
T::is('no VM created before payment', 0, Db::count('customer_servers'));

section('Client page uses WHMCS checkout and only owned services');
define('WHMCS', true);
require_once dirname(__DIR__) . '/cloudhost247apps.php';
Identity::override($customer);
$_GET['action'] = 'vps';
$page = cloudhost247apps_clientarea([]);
T::is('customer page uses the VPS template', 'templates/client/vps', $page['templatefile']);
T::is('VPS page requires WHMCS login', true, $page['requirelogin']);
T::is('public catalog reveals only operator-enabled WHMCS product', $productId,
    $page['vars']['vps_products'][0]['product_id']);
T::is('only owning customer service is shown', 1, count($page['vars']['vps_services']));
T::is('customer service id is read from WHMCS', $serviceId, $page['vars']['vps_services'][0]['id']);
T::is('API address uses WHMCS web root', '/whmcs/modules/addons/cloudhost247apps/api/index.php?path=/v1/servers/self-service',
    $page['vars']['vps_api_url']);
Identity::override(Actor::guest());
$guestPage = cloudhost247apps_clientarea([]);
T::is('guest sees no service rows', [], $guestPage['vars']['vps_services']);
unset($_GET['action']);
Identity::override($admin);

Harness::$gateway->payInvoice((int) $order['invoice_id']);
$response = $customerApi->dispatch('POST', '/v1/servers/self-service', $input,
    $csrf + ['Idempotency-Key' => 'paid-self-service']);
T::is('paid owner can queue fixed VM', 202, $response['status']);
$row = Db::first('customer_servers', ['whmcs_service_id' => $serviceId]);
T::is('mapping correlated to VM', (int) $saved['body']['data']['id'], (int) $row['product_mapping_id']);
T::is('provider account from operator mapping', $accountId, (int) $row['provider_account_id']);
T::is('VM size from mapping', 2, (int) $row['cpu_cores']);
T::is('name is derived from WHMCS service', 'vm-service-' . $serviceId, $row['name']);
T::is('same key replays without duplicate', 202, $customerApi->dispatch('POST', '/v1/servers/self-service',
    $input, $csrf + ['Idempotency-Key' => 'paid-self-service'])['status']);
T::is('exactly one VM for paid service', 1, Db::count('customer_servers'));

section('Operator revocation before worker create fails without network');
$mappingInput['enabled'] = false;
T::is('operator disables mapping', 201, $adminApi->dispatch('POST', '/v1/server-product-mappings',
    $mappingInput, $csrf + ['Idempotency-Key' => 'revoke-map'])['status']);
$providerCalls = 0;
Http::setClientFake(function () use (&$providerCalls) {
    $providerCalls++;
    throw new RuntimeException('Provider transport must not run after a mapping is revoked.');
});
$queue = new JobQueue();
$jobs = $queue->lease('test-self-service', JobQueue::QUEUE_PROVISIONING, 1);
T::is('one VM create job leased', 1, count($jobs));
if ($jobs) {
    $result = (new ServerProvisioningWorker(Actor::system('test-worker')))->runJob($jobs[0]);
    T::is('revoked mapping fails before provider create', 'failed', $result['status']);
    T::is('no provider transport attempted', 0, $providerCalls);
    T::is('no provider VM id was invented', null, Db::first('customer_servers', ['id' => $row['id']])['provider_server_id']);
}
exit(T::summary());
