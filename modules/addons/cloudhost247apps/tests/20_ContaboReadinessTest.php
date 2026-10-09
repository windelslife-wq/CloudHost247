<?php
/** Contabo adoption-only: no instance create/cancel endpoint can be reached. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\ContaboAdoptionService;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ServerProductMappingService;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;

class FakeContaboReadApi
{
    public $calls = [];
    public $status = 'running';
    public $instanceCustomer = '54321';
    public $image = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    public $size = 4096;
    public $next = null;
    public $failNextInstanceRead = false;

    public function handle($method, $url, $options)
    {
        $this->calls[] = [$method, $url, $options];
        if ($this->next !== null) {
            $next = $this->next;
            $this->next = null;
            return $next;
        }
        $json = function ($body) {
            return ['status' => 200, 'body' => json_encode($body), 'headers' => [], 'error' => null];
        };
        if ($method === 'POST' && $url === 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token') {
            return $json(['access_token' => 'fake-ephemeral-bearer']);
        }
        if ($method === 'GET' && strpos($url, 'https://api.contabo.com/v1/compute/instances?page=1&size=1') === 0) {
            return $json(['data' => [], '_pagination' => ['page' => 1, 'totalPages' => 0]]);
        }
        if ($method === 'GET' && $url === 'https://api.contabo.com/v1/compute/instances/12345') {
            if ($this->failNextInstanceRead) {
                $this->failNextInstanceRead = false;
                return ['status' => 503, 'body' => '', 'headers' => [], 'error' => null];
            }
            return $json(['data' => [[
                'instanceId' => 12345, 'tenantId' => 'DE', 'customerId' => $this->instanceCustomer,
                'productId' => 'V153', 'region' => 'EU', 'imageId' => $this->image,
                'cpuCores' => 2, 'ramMb' => (string) $this->size, 'diskMb' => 102400,
                'status' => $this->status, 'osType' => 'Linux',
                'ipConfig' => ['v4' => ['ip' => '203.0.113.46']],
                'sshKeys' => [123], 'defaultUser' => 'root',
            ]]]);
        }
        return ['status' => 599, 'body' => '', 'headers' => [], 'error' => 'unexpected provider route'];
    }

    public function countCalls($method, $path)
    {
        return count(array_filter($this->calls, function ($call) use ($method, $path) {
            return $call[0] === $method && parse_url($call[1], PHP_URL_PATH) === $path;
        }));
    }
}

Harness::boot();
Harness::relaxRateLimits();
ProviderRegistry::reset();
ProviderBootstrap::reset();
ProviderBootstrap::boot();
$fake = new FakeContaboReadApi();
Http::setClientFake([$fake, 'handle']);
$admin = Harness::adminActor();
$api = new InfrastructureApi($admin);
$accounts = new ProviderAccountService($admin);
$queue = new JobQueue();
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    new ServerProvisioningWorker(Harness::systemActor(), $queue),
    new ProviderAccountVerifyWorker(Harness::systemActor(), $queue), $queue);
$csrf = ['X-CSRF-Token' => Csrf::token()];
$spec = ['region' => 'EU', 'image' => $fake->image,
    'cpu_cores' => 2, 'memory_mb' => 4096, 'storage_gb' => 100];

section('Read-only adapter: capability claims cannot authorize purchases or cancellations');
T::ok('Contabo remains known', ProviderRegistry::isKnownProvider('contabo'));
T::ok('read-only adapter is installed', ProviderRegistry::hasAdapter('contabo'));
$adapter = ProviderRegistry::forProvider('contabo');
T::is('create expressly unavailable', false, $adapter->capabilities()['server.create']);
T::is('read supported', true, $adapter->capabilities()['server.get']);
foreach (['server.delete', 'server.reboot', 'server.power_on', 'server.resize', 'snapshot.create'] as $cap) {
    T::is($cap . ' unavailable', false, $adapter->capabilities()[$cap]);
}
T::throws('create method never calls the provider', ProviderUnavailableException::class,
    function () use ($adapter, $spec) { $adapter->createServer([], [], $spec, 'not-a-purchase'); });
T::throws('cancel cannot be represented as delete', ProviderUnavailableException::class,
    function () use ($adapter) { $adapter->deleteServer([], [], '12345', 'not-a-cancellation'); });
T::is('no provider call from unsupported methods', 0, count($fake->calls));
T::ok('adoption switch defaults off', !Settings::bool('contabo_adoption_enabled', false));
T::is('addon adoption setting defaults off', '',
    (function () { define('WHMCS', true); require_once dirname(__DIR__) . '/cloudhost247apps.php';
        return cloudhost247apps_config()['fields']['contabo_adoption_enabled']['Default']; })());
T::ok('self-service remains off', !Settings::bool('customer_server_self_service_enabled', false));
T::ok('migration creates independent adoption table', Db::tableExists('contabo_adoptions'));

section('Account verification makes only token and read requests');
$credentials = ['client_id' => 'test-client', 'client_secret' => 'fake-secret',
    'username' => 'api@example.invalid', 'password' => 'fake-password'];
$account = $accounts->create(['provider_code' => 'contabo', 'name' => 'Adoption test',
    'public_config' => ['tenant_id' => 'DE', 'customer_id' => '54321'], 'credentials' => $credentials]);
$accountId = (int) $account['id'];
$accounts->requestVerification($accountId, 'contabo-read-verification');
$verifyLease = $queue->lease('contabo-verify', JobQueue::QUEUE_PROVISIONING, 1);
T::is('verification uses read-only provider calls', 'completed', $dispatcher->runJob($verifyLease[0])['status']);
T::is('account is active', 'active', $accounts->get($accountId)['status']);
T::is('no instance purchase requested', 0, $fake->countCalls('POST', '/v1/compute/instances'));
T::notContains('HTTP traces redact all OAuth credentials', $credentials['password'], json_encode(Http::clientCalls()));
T::notContains('HTTP traces redact bearer tokens', 'fake-ephemeral-bearer', json_encode(Http::clientCalls()));

section('Paid WHMCS service and explicit operator approval are required');
$client = Harness::client();
$product = Harness::$gateway->addProduct(['type' => 'server', 'paytype' => 'recurring',
    'servermodule' => 'contabo']);
$order = Harness::$gateway->createOrder(['clientid' => $client, 'pid' => $product]);
$serviceId = (int) $order['service_id'];
$input = ['service_id' => $serviceId, 'provider_account_id' => $accountId,
    'provider_instance_id' => '12345', 'contabo_product_id' => 'V153',
    'spec' => $spec, 'acknowledge_existing_contract' => true];
T::is('disabled adoption returns 503', 503, $api->dispatch('POST', '/v1/contabo/adoptions',
    $input, $csrf + ['Idempotency-Key' => 'disabled'])['status']);
Settings::override('contabo_adoption_enabled', '1');
T::is('customer cannot submit a provider ID', 403,
    (new InfrastructureApi(Actor::customer($client)))->dispatch('POST', '/v1/contabo/adoptions',
        $input, $csrf + ['Idempotency-Key' => 'customer-attack'])['status']);
T::is('read-only staff cannot adopt', 403,
    (new InfrastructureApi(Actor::admin(9, Actor::ROLE_STAFF)))->dispatch('POST', '/v1/contabo/adoptions',
        $input, $csrf + ['Idempotency-Key' => 'staff-attack'])['status']);
$withoutApproval = $input;
$withoutApproval['acknowledge_existing_contract'] = false;
T::is('no explicit external-contract confirmation returns 422', 422, $api->dispatch('POST',
    '/v1/contabo/adoptions', $withoutApproval, $csrf + ['Idempotency-Key' => 'no-approval'])['status']);
T::is('unpaid service cannot be bound', 402, $api->dispatch('POST', '/v1/contabo/adoptions',
    $input, $csrf + ['Idempotency-Key' => 'unpaid'])['status']);
T::is('unpaid attempt creates no adoption', 0, Db::count('contabo_adoptions'));
Harness::$gateway->payInvoice((int) $order['invoice_id']);
T::is('paid but not yet active service cannot be adopted', 503,
    $api->dispatch('POST', '/v1/contabo/adoptions', $input,
        $csrf + ['Idempotency-Key' => 'not-yet-active'])['status']);
Harness::$gateway->setServiceStatus($serviceId, 'Active');
$created = $api->dispatch('POST', '/v1/contabo/adoptions', $input,
    $csrf + ['Idempotency-Key' => 'approved-adoption']);
T::is('approved request is queued', 202, $created['status']);
$adoptionId = (int) $created['body']['data']['adoption']['id'];
T::is('pending until provider read-back', 'pending', $created['body']['data']['adoption']['status']);
T::is('one row for one service', 1, Db::count('contabo_adoptions'));
T::is('no customer VM created or deleted', 0, Db::count('customer_servers'));
T::is('replay does not queue duplicate', 202, $api->dispatch('POST', '/v1/contabo/adoptions',
    $input, $csrf + ['Idempotency-Key' => 'approved-adoption'])['status']);
T::is('one adoption verification job', 1, Db::count('jobs', ['job_type' => JobQueue::TYPE_CONTABO_ADOPT]));
$overflow = $input;
$overflow['provider_instance_id'] = '9999999999999999999';
T::is('overflowing instance ID cannot be truncated into another binding', 422,
    $api->dispatch('POST', '/v1/contabo/adoptions', $overflow,
        $csrf + ['Idempotency-Key' => 'overflow'])['status']);
$changed = $input;
$changed['provider_instance_id'] = '12346';
T::is('one service cannot reserve a different provider instance', 409,
    $api->dispatch('POST', '/v1/contabo/adoptions', $changed,
        $csrf + ['Idempotency-Key' => 'changed-binding'])['status']);
$secondOrder = Harness::$gateway->createOrder(['clientid' => $client, 'pid' => $product]);
Harness::$gateway->payInvoice((int) $secondOrder['invoice_id']);
Harness::$gateway->setServiceStatus((int) $secondOrder['service_id'], 'Active');
$otherService = $input;
$otherService['service_id'] = (int) $secondOrder['service_id'];
T::is('one instance cannot be reserved for two services', 409,
    $api->dispatch('POST', '/v1/contabo/adoptions', $otherService,
        $csrf + ['Idempotency-Key' => 'other-service'])['status']);
T::is('customers cannot read operator-only adoption', 403,
    (new InfrastructureApi(Actor::customer($client)))->dispatch('GET', '/v1/contabo/adoptions/' . $adoptionId)['status']);
T::is('customer VM API has no adopted VM', 0, count((new CustomerServerService(Actor::customer($client)))->listing()));
T::is('App Cloud product mapping rejects WHMCS-owned Contabo product', 422,
    $api->dispatch('POST', '/v1/server-product-mappings', [
        'product_id' => $product, 'provider_account_id' => $accountId,
        'spec' => $spec, 'enabled' => true,
    ], $csrf + ['Idempotency-Key' => 'no-duplicate-product'])['status']);

section('Transient read errors keep the reservation pending for the same queued job');
$fake->failNextInstanceRead = true;
$lease = $queue->lease('contabo-adopter-transient', JobQueue::QUEUE_PROVISIONING, 1);
T::throws('transient provider outage is retryable', RetryableProviderException::class,
    function () use ($dispatcher, $lease) { $dispatcher->runJob($lease[0]); });
T::is('reservation remains pending during backoff', 'pending',
    Db::first('contabo_adoptions', ['id' => $adoptionId])['status']);
T::is('the same job is queued for retry', JobQueue::STATUS_QUEUED,
    Db::first('jobs', ['id' => $lease[0]['id']])['status']);
T::is('no extra adoption job was created', 1,
    Db::count('jobs', ['job_type' => JobQueue::TYPE_CONTABO_ADOPT]));
// Advance only this test job's due time, not the production backoff policy.
Db::update('jobs', ['available_at' => Clock::now()], ['id' => $lease[0]['id']]);

section('Worker rechecks and rejects account mismatches without binding');
$fake->instanceCustomer = 'different-customer';
$lease = $queue->lease('contabo-adopter-mismatch', JobQueue::QUEUE_PROVISIONING, 1);
$result = $dispatcher->runJob($lease[0]);
T::is('wrong Contabo customer fails terminally', 'failed', $result['status']);
T::is('adoption records failure', 'failed', Db::first('contabo_adoptions', ['id' => $adoptionId])['status']);
T::is('no provider write sent', 0, $fake->countCalls('POST', '/v1/compute/instances'));
$fake->instanceCustomer = '54321';
$retry = $api->dispatch('POST', '/v1/contabo/adoptions', $input,
    $csrf + ['Idempotency-Key' => 'approved-retry']);
T::is('operator can explicitly retry unchanged failed binding', 202, $retry['status']);
$lease = $queue->lease('contabo-adopter', JobQueue::QUEUE_PROVISIONING, 1);
$result = $dispatcher->runJob($lease[0]);
T::is('provider read-back confirms binding', 'completed', $result['status']);
$row = Db::first('contabo_adoptions', ['id' => $adoptionId]);
T::is('binding is verified', 'verified', $row['status']);
T::is('provider IPv4 projected only to staff', '203.0.113.46', $row['ipv4']);
T::is('provider spec matches operator envelope', $spec, json_decode($row['verified_spec'], true));
T::is('staff can inspect verified adoption', 200,
    $api->dispatch('GET', '/v1/contabo/adoptions/' . $adoptionId)['status']);
T::is('customer VM table still untouched', 0, Db::count('customer_servers'));
T::is('no Contabo purchase', 0, $fake->countCalls('POST', '/v1/compute/instances'));
T::is('no Contabo cancel', 0, $fake->countCalls('POST', '/v1/compute/instances/12345/cancel'));

Http::setClientFake(null);
exit(T::summary());
