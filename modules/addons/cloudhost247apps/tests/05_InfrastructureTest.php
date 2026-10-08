<?php
/**
 * Suite 05 — Phase 2 provider accounts, WHMCS-owned VMs, API and worker dispatch.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\ApiAuth;
use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\ProviderConfigurationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\InfrastructureProviderInterface;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;
use Ch247Apps\Integration\FakeGateway;

class Phase2TestProviderAdapter implements InfrastructureProviderInterface
{
    public $verifyCalls = 0;
    public $createCalls = 0;
    public $getCalls = 0;
    public $rebootCalls = 0;
    public $getStatus = 'running';
    public $lastIdempotencyKey = '';

    public function key() { return 'hetzner'; }
    public function name() { return 'Test Hetzner Adapter'; }
    public function capabilities()
    {
        return [
            'server.create' => true, 'server.get' => true, 'server.delete' => true,
            'server.reboot' => true, 'server.power_on' => true, 'server.power_off' => true,
            'server.rebuild' => true, 'server.resize' => true,
        ];
    }
    public function verifyCredentials(array $credentials, array $accountConfig)
    {
        $this->verifyCalls++;
        return isset($credentials['api_token']) && $credentials['api_token'] === 'test-provider-secret';
    }
    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    {
        $this->createCalls++;
        $this->lastIdempotencyKey = (string) $idempotencyKey;
        return ['id' => 'vm-test-1001', 'status' => 'building', 'operation_id' => 'operation-create-1'];
    }
    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $this->getCalls++;
        return ['id' => (string) $providerServerId, 'status' => $this->getStatus,
            'ipv4' => '198.51.100.44', 'ipv6' => '2001:db8::44'];
    }
    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $this->lastIdempotencyKey = (string) $idempotencyKey;
        return ['id' => (string) $providerServerId, 'status' => 'deleting', 'operation_id' => 'operation-delete-1'];
    }
    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $this->rebootCalls++;
        $this->lastIdempotencyKey = (string) $idempotencyKey;
        return ['confirmed' => true];
    }
    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return ['confirmed' => true];
    }
    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return ['confirmed' => true];
    }
    public function rebuildServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    {
        return ['id' => (string) $providerServerId, 'status' => 'rebuilding', 'operation_id' => 'operation-rebuild-1'];
    }
    public function resizeServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    {
        return ['id' => (string) $providerServerId, 'status' => 'resizing', 'operation_id' => 'operation-resize-1'];
    }
    public function createSnapshot(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        throw new RuntimeException('Not used in this suite.');
    }
    public function deleteSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    {
        throw new RuntimeException('Not used in this suite.');
    }
    public function restoreSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    {
        throw new RuntimeException('Not used in this suite.');
    }
    public function getMetrics(array $credentials, array $accountConfig, $providerServerId)
    {
        throw new RuntimeException('Not used in this suite.');
    }
}

Harness::boot();
Harness::relaxRateLimits();
Clock::freeze('2026-10-08 12:00:00');

section('Provider account and customer-server migration is additive');
T::ok('provider account table exists', Db::tableExists('provider_accounts'));
T::ok('customer VM table exists separately', Db::tableExists('customer_servers'));
T::ok('customer-server lifecycle event table exists', Db::tableExists('customer_server_events'));
$schema = new Migrator(dirname(__DIR__) . '/install/migrations');
T::ok('jobs carry separate customer-server correlation', $schema->hasColumn('jobs', 'customer_server_id'));
T::ok('events carry customer-server scope', $schema->hasColumn('events', 'customer_server_id'));
$fkBlueprint = new Blueprint('provider_fk_ddl_check');
$fkBlueprint->id();
$fkBlueprint->unsignedBigInteger('provider_account_id');
$mysqlFkDdl = implode("\n", $fkBlueprint->createSql('mysql'));
T::contains('MySQL provider FK matches unsigned module IDs', 'BIGINT UNSIGNED', $mysqlFkDdl);
T::ok('feature remains disabled by default', !Settings::bool('customer_server_provisioning_enabled', false));

section('Provider catalog is not represented as a live adapter');
$catalog = ProviderRegistry::catalog();
T::ok('catalog is informational', count($catalog) >= 1);
T::ok('known catalog entry reports no adapter', !$catalog[0]['adapter_available']);
T::throws('missing provider fails closed', ProviderUnavailableException::class, function () {
    ProviderRegistry::forProvider('hetzner');
});

section('Provider credentials are encrypted, hidden, and verification is queued');
$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$accountService = new ProviderAccountService($admin);
$account = $accountService->create([
    'provider_code' => 'hetzner',
    'name' => 'Staging account',
    'region' => 'fsn1',
    'public_config' => ['project' => 'staging'],
    'credentials' => ['api_token' => 'test-provider-secret'],
]);
$accountId = (int) $account['id'];
$row = Db::first('provider_accounts', ['id' => $accountId]);
T::is('new provider account remains unverified', 'unverified', $account['status']);
T::ok('credential ciphertext is stored', strpos((string) $row['encrypted_credentials'], 'v1:') === 0);
T::notContains('plaintext credential is absent from storage', 'test-provider-secret', $row['encrypted_credentials']);
T::notContains('plaintext credential is absent from presentation', 'test-provider-secret', json_encode($account));
T::ok('account presentation reports no installed adapter', !$account['adapter_available']);

T::throws('verification is not queued without a real adapter', ProviderUnavailableException::class, function () use ($accountService, $accountId) {
    $accountService->requestVerification($accountId, 'no-adapter-verify');
});
T::is('no provider job was queued on that failure', 0, Db::count('jobs', ['job_type' => JobQueue::TYPE_PROVIDER_ACCOUNT_VERIFY]));

$parsedRequest = InfrastructureApi::decodeJsonObject(
    '{"spec":{"cpu_cores":2,"region":"fsn1"},"credentials":{"api_token":"nested-secret"}}'
);
T::is('JSON request objects decode to nested PHP arrays', 'fsn1', $parsedRequest['spec']['region']);
T::is('nested credential JSON decodes to an array', 'nested-secret', $parsedRequest['credentials']['api_token']);
T::is('top-level JSON arrays are rejected', null, InfrastructureApi::decodeJsonObject('[{"spec":{}}]'));
T::is('malformed JSON objects are rejected', null, InfrastructureApi::decodeJsonObject('{"spec":'));

$staffApi = new InfrastructureApi(Actor::admin(2, Actor::ROLE_STAFF, 'Support', ['authMethod' => 'api_token']));
$response = $staffApi->dispatch('GET', '/v1/providers');
T::is('authenticated staff can read provider catalog', 200, $response['status']);
T::ok('API does not claim adapter availability', !$response['body']['data'][0]['adapter_available']);
$bearer = 'phase2-test-bearer-token';
Db::insert('api_tokens', [
    'name' => 'provider-read-only', 'token_hash' => hash('sha256', $bearer), 'token_prefix' => 'phase2-test',
    'actor_type' => Actor::TYPE_ADMIN, 'actor_id' => 2, 'actor_role' => Actor::ROLE_STAFF,
    'actor_label' => 'Scoped Staff', 'scopes' => json_encode([Rbac::PROVIDER_ACCOUNT_VIEW]),
    'ip_allowlist' => '', 'active' => 1, 'request_count' => 0, 'last_used_ip' => null,
    'last_used_at' => null, 'expires_at' => null, 'revoked_at' => null,
    'created_at' => Clock::now(), 'updated_at' => Clock::now(), 'deleted_at' => null,
]);
$bearerActor = Identity::fromApiToken($bearer);
T::ok('hashed bearer token resolves to an actor', $bearerActor instanceof Actor);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
$resolvedBearer = ApiAuth::resolve();
unset($_SERVER['HTTP_AUTHORIZATION']);
T::is('API authentication resolves the Authorization bearer header', Actor::ROLE_STAFF, $resolvedBearer->role);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token';
T::throws('invalid explicit bearer does not fall back to a session', \Ch247Apps\Core\AuthenticationException::class, function () {
    ApiAuth::resolve();
});
unset($_SERVER['HTTP_AUTHORIZATION']);
$scopedApi = new InfrastructureApi($resolvedBearer);
T::is('bearer token scope permits provider-account reads', 200, $scopedApi->dispatch('GET', '/v1/provider-accounts')['status']);
T::is('bearer token scope blocks unrelated server reads', 403, $scopedApi->dispatch('GET', '/v1/servers')['status']);
Db::update('api_tokens', ['active' => 0, 'revoked_at' => Clock::now(), 'updated_at' => Clock::now()],
    ['token_hash' => hash('sha256', $bearer)]);
T::is('token revocation invalidates an already-resolved bearer actor', 401,
    $scopedApi->dispatch('GET', '/v1/provider-accounts')['status']);
$response = $staffApi->dispatch('PUT', '/v1/provider-accounts/' . $accountId . '/credentials',
    ['credentials' => ['api_token' => 'forbidden']], ['Idempotency-Key' => 'staff-rotate-1']);
T::is('staff cannot rotate provider credentials', 403, $response['status']);

$adapter = new Phase2TestProviderAdapter();
ProviderRegistry::register($adapter);
$queued = $accountService->requestVerification($accountId, 'verify-staging-account');
T::is('credential verification enters the separate queue', JobQueue::QUEUE_PROVISIONING, $queued['job']['queue']);
T::is('verification job references the provider account', $accountId, $queued['job']['provider_account_id']);
T::notContains('verification payload excludes credentials', 'test-provider-secret', json_encode($queued['job']['payload']));
$queue = new JobQueue();
$provisionWorker = new ServerProvisioningWorker(Harness::systemActor(), $queue);
$accountWorker = new ProviderAccountVerifyWorker(Harness::systemActor(), $queue);
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    $provisionWorker, $accountWorker, $queue);
$leased = $queue->lease('provider-account-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('one verification job is leased', 1, count($leased));
$verificationResult = $dispatcher->runJob($leased[0]);
T::is('worker verifies through the registered adapter', 'completed', $verificationResult['status']);
T::is('successful verification activates account', 'active', (new ProviderAccountService($admin))->get($accountId)['status']);
T::is('adapter receives verification call in worker', 1, $adapter->verifyCalls);
T::notContains('verification result excludes credentials', 'test-provider-secret', json_encode($verificationResult));

section('API authentication and RBAC protect writes and customer data');
$response = $staffApi->dispatch('GET', '/v1/provider-accounts');
T::is('staff can list sanitized provider accounts', 200, $response['status']);
T::notContains('account API never returns ciphertext', $row['encrypted_credentials'], json_encode($response['body']));
$superApi = new InfrastructureApi(Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Root', ['authMethod' => 'api_token']));
$response = $superApi->dispatch('POST', '/v1/provider-accounts', ['provider_code' => 'hetzner', 'name' => 'No key']);
T::is('mutating API requires idempotency key', 422, $response['status']);
$cookieApi = new InfrastructureApi(Actor::admin(1, Actor::ROLE_SUPER_ADMIN, 'Root'));
$response = $cookieApi->dispatch('POST', '/v1/provider-accounts', ['provider_code' => 'hetzner', 'name' => 'No CSRF'],
    ['Idempotency-Key' => 'cookie-create-no-csrf']);
T::is('cookie-authenticated write requires CSRF', 401, $response['status']);

$secretCreateInput = [
    'provider_code' => 'hetzner',
    'name' => 'Idempotent secret account',
    'credentials' => ['api_token' => 'guessable-test-credential'],
];
$secretCreateKey = 'credential-create-digest-test';
$response = $superApi->dispatch('POST', '/v1/provider-accounts', $secretCreateInput,
    ['Idempotency-Key' => $secretCreateKey]);
T::is('credential-bearing account create succeeds', 201, $response['status']);
$secretAccountId = (int) $response['body']['data']['id'];
$secretIdempotencyRow = Db::first('idempotency_keys', [
    'scope' => 'provider-account.create', 'idempotency_key' => $secretCreateKey,
]);
T::ok('credential-bearing idempotency record exists', (bool) $secretIdempotencyRow);
T::ok('credential idempotency digest is not a plain request hash',
    $secretIdempotencyRow['payload_hash'] !== Idempotency::fingerprint($secretCreateInput));
$response = $superApi->dispatch('POST', '/v1/provider-accounts', $secretCreateInput,
    ['Idempotency-Key' => $secretCreateKey]);
T::is('same credential request replays successfully', 200, $response['status']);
T::ok('credential request replay is marked', !empty($response['body']['replayed']));
$changedSecretInput = $secretCreateInput;
$changedSecretInput['credentials']['api_token'] = 'different-test-credential';
$response = $superApi->dispatch('POST', '/v1/provider-accounts', $changedSecretInput,
    ['Idempotency-Key' => $secretCreateKey]);
T::is('changed credentials cannot reuse an idempotency key', 409, $response['status']);

$rotationCredentials = ['api_token' => 'rotation-test-credential'];
$rotationKey = 'credential-rotate-digest-test';
$response = $superApi->dispatch('PUT', '/v1/provider-accounts/' . $secretAccountId . '/credentials',
    ['credentials' => $rotationCredentials], ['Idempotency-Key' => $rotationKey]);
T::is('credential rotation succeeds through the API', 200, $response['status']);
$rotationIdempotencyRow = Db::first('idempotency_keys', [
    'scope' => 'provider-account.credentials.rotate', 'idempotency_key' => $rotationKey,
]);
$rotationRawPayload = [
    'provider_account_id' => $secretAccountId,
    'credentials' => $rotationCredentials,
];
T::ok('rotation idempotency digest is not an unkeyed credential hash',
    $rotationIdempotencyRow['payload_hash'] !== Idempotency::fingerprint($rotationRawPayload));
T::notContains('rotation idempotency metadata excludes plaintext credentials',
    $rotationCredentials['api_token'], $rotationIdempotencyRow['payload_hash']);

section('WHMCS service and paid invoice gate customer-VM job creation');
$clientId = Harness::client();
$order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 700, 'domain' => '']);
$serviceId = (int) $order['service_id'];
$invoiceId = (int) $order['invoice_id'];
$serverSpec = [
    'name' => 'Paid VPS', 'hostname' => 'paid-vps.example.test', 'region' => 'fsn1',
    'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
];
$servers = new CustomerServerService($admin, Harness::$gateway, $accountService, $queue);
T::throws('default-off provisioning fails before writes', ProviderConfigurationException::class, function () use ($servers, $serviceId, $accountId, $serverSpec) {
    $servers->requestProvision($serviceId, $accountId, $serverSpec, 'disabled-service-attempt');
});
Settings::override('customer_server_provisioning_enabled', '1');
T::throws('unpaid WHMCS invoice blocks provisioning', PaymentException::class, function () use ($servers, $serviceId, $accountId, $serverSpec) {
    $servers->requestProvision($serviceId, $accountId, $serverSpec, 'unpaid-service-attempt');
});
T::is('unpaid request creates no customer server', 0, Db::count('customer_servers'));

$incompleteBillingGateway = new class extends FakeGateway {
    public $ownerFieldToOmit = '';

    public function getOrder($orderId)
    {
        $order = parent::getOrder($orderId);
        if ($this->ownerFieldToOmit === 'order' && is_array($order)) {
            unset($order['userid']);
        }
        return $order;
    }

    public function getInvoice($invoiceId)
    {
        $invoice = parent::getInvoice($invoiceId);
        if ($this->ownerFieldToOmit === 'invoice' && is_array($invoice)) {
            unset($invoice['userid']);
        }
        return $invoice;
    }
};
$incompleteClientId = $incompleteBillingGateway->addClient([]);
$incompleteOrder = $incompleteBillingGateway->createOrder([
    'clientid' => $incompleteClientId, 'pid' => 700, 'domain' => '',
]);
$incompleteBillingGateway->payInvoice((int) $incompleteOrder['invoice_id']);
$incompleteServers = new CustomerServerService($admin, $incompleteBillingGateway, $accountService, $queue);
$incompleteBillingGateway->ownerFieldToOmit = 'order';
T::throws('missing WHMCS order owner fails closed', PaymentException::class, function () use (
    $incompleteServers, $incompleteOrder, $accountId, $serverSpec
) {
    $incompleteServers->requestProvision((int) $incompleteOrder['service_id'], $accountId,
        $serverSpec, 'missing-order-owner');
});
$secondIncompleteOrder = $incompleteBillingGateway->createOrder([
    'clientid' => $incompleteClientId, 'pid' => 700, 'domain' => '',
]);
$incompleteBillingGateway->payInvoice((int) $secondIncompleteOrder['invoice_id']);
$incompleteBillingGateway->ownerFieldToOmit = 'invoice';
T::throws('missing WHMCS invoice owner fails closed', PaymentException::class, function () use (
    $incompleteServers, $secondIncompleteOrder, $accountId, $serverSpec
) {
    $incompleteServers->requestProvision((int) $secondIncompleteOrder['service_id'], $accountId,
        $serverSpec, 'missing-invoice-owner');
});
T::is('incomplete WHMCS owner records create no customer VM', 0, Db::count('customer_servers'));
Harness::$gateway->payInvoice($invoiceId);
$created = $servers->requestProvision($serviceId, $accountId, $serverSpec, 'paid-service-request');
T::is('paid order is queued on the provisioning queue', JobQueue::QUEUE_PROVISIONING, $created['job']['queue']);
T::is('customer server stores the WHMCS service id', $serviceId, $created['server']['whmcs_service_id']);
T::is('customer server stores the actual linked invoice id', $invoiceId, $created['server']['whmcs_invoice_id']);
T::is('new customer server awaits worker processing', 'pending', $created['server']['status']);
T::is('exactly one server is bound to the WHMCS service', 1, Db::count('customer_servers', ['whmcs_service_id' => $serviceId]));
$replayed = $servers->requestProvision($serviceId, $accountId, $serverSpec, 'paid-service-request');
T::ok('same idempotency key replays one server', $replayed['replayed']);
T::is('replay does not create another row', 1, Db::count('customer_servers', ['whmcs_service_id' => $serviceId]));
T::throws('same key with a changed spec conflicts', ConflictException::class, function () use ($servers, $serviceId, $accountId, $serverSpec) {
    $changed = $serverSpec;
    $changed['cpu_cores'] = 4;
    $servers->requestProvision($serviceId, $accountId, $changed, 'paid-service-request');
});

section('Worker dispatch is asynchronous, serialized, and never claims customer activation');
$serverId = (int) $created['server']['id'];
$createJob = $queue->lease('customer-vm-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('worker leases the queued create job', 1, count($createJob));
$createResult = $dispatcher->runJob($createJob[0]);
T::is('provider create job is processed', 'completed', $createResult['status']);
T::ok('adapter receives stable idempotency key', strpos($adapter->lastIdempotencyKey, 'customer-server') === 0);
T::is('asynchronous provider create schedules a poll job', 1, Db::count('jobs', ['job_type' => JobQueue::TYPE_SERVER_POLL]));
$pollRow = Db::first('jobs', ['job_type' => JobQueue::TYPE_SERVER_POLL]);
T::is('poll job uses the separate provisioning queue', JobQueue::QUEUE_PROVISIONING, $pollRow['queue']);
T::notContains('provider credentials are absent from create payload', 'test-provider-secret', $createJob[0]['payload']);
$stored = Db::first('customer_servers', ['id' => $serverId]);
T::is('create begins with provisioning status', CustomerServerService::STATUS_PROVISIONING, $stored['status']);
T::is('provider id comes from the adapter response', 'vm-test-1001', $stored['provider_server_id']);

Clock::travel(15);
$pollJobs = $queue->lease('customer-vm-poller', JobQueue::QUEUE_PROVISIONING, 1);
T::is('delayed provider poll becomes leaseable', 1, count($pollJobs));
$pollResult = $dispatcher->runJob($pollJobs[0]);
T::is('provider poll completes', 'completed', $pollResult['status']);
$ready = $servers->get($serverId);
T::is('ready provider response remains customer provisioning', CustomerServerService::STATUS_PROVISIONING, $ready['status']);
T::is('provider ready is explicit, not active', CustomerServerService::STATE_SERVER_READY, $ready['provisioning_state']);
T::is('IP address comes from provider response', '198.51.100.44', $ready['ipv4']);
T::is('provider get call occurred in worker', 1, $adapter->getCalls);
T::throws('delete requires a strict boolean confirmation', ValidationException::class, function () use ($servers, $serverId) {
    $servers->requestAction($serverId, 'delete', ['confirm' => 'false'], 'delete-with-string-confirm');
});
T::throws('delete is blocked until WHMCS service cancellation', ConflictException::class, function () use ($servers, $serverId) {
    $servers->requestAction($serverId, 'delete', ['confirm' => true], 'delete-before-whmcs-cancel');
});

$action = $servers->requestAction($serverId, 'reboot', [], 'reboot-service-request');
T::is('reboot action is queued', JobQueue::TYPE_SERVER_REBOOT, $action['job']['job_type']);
T::is('reboot job remains in provisioning queue', JobQueue::QUEUE_PROVISIONING, $action['job']['queue']);
$rebootJobs = $queue->lease('customer-vm-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('worker leases queued reboot action', 1, count($rebootJobs));
$rebootResult = $dispatcher->runJob($rebootJobs[0]);
T::is('provider reboot action is dispatched', 'completed', $rebootResult['status']);
T::is('reboot remains an authenticated provider operation', $action['job']['id'], $rebootJobs[0]['id']);

$resize = $servers->requestAction($serverId, 'resize', ['spec' => ['cpu_cores' => 4, 'memory_mb' => 4096]],
    'resize-service-request');
T::is('resize stores a pending desired specification', 4, $resize['server']['pending_spec']['cpu_cores']);
T::is('resize keeps applied size until provider confirms', 2, $resize['server']['cpu_cores']);
$resizeReplay = $servers->requestAction($serverId, 'resize',
    ['spec' => ['cpu_cores' => 4, 'memory_mb' => 4096]], 'resize-service-request');
T::ok('resize retry replays while the operation is in progress', $resizeReplay['replayed']);
T::is('resize retry returns the original job', $resize['job']['id'], $resizeReplay['job']['id']);
$resizeJobs = $queue->lease('customer-vm-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('worker leases resize operation', 1, count($resizeJobs));
T::is('resize provider call completes its initial async step', 'completed',
    $dispatcher->runJob($resizeJobs[0])['status']);
Clock::travel(15);
$resizePollJobs = $queue->lease('customer-vm-poller', JobQueue::QUEUE_PROVISIONING, 1);
T::is('resize poll becomes leaseable', 1, count($resizePollJobs));
T::is('resize poll completes after provider confirmation', 'completed',
    $dispatcher->runJob($resizePollJobs[0])['status']);
$appliedResize = $servers->get($serverId);
T::is('applied size changes only after provider confirmation', 4, $appliedResize['cpu_cores']);
T::is('completed resize clears pending specification', null, $appliedResize['pending_spec']);
$completedResizeReplay = $servers->requestAction($serverId, 'resize',
    ['spec' => ['cpu_cores' => 4, 'memory_mb' => 4096]], 'resize-service-request');
T::ok('completed resize retry still replays after state transition', $completedResizeReplay['replayed']);
T::is('completed replay returns the original resize job', $resize['job']['id'], $completedResizeReplay['job']['id']);

$rebootQueuedBeforeCancel = $servers->requestAction($serverId, 'reboot', [], 'reboot-before-whmcs-cancel');
T::is('active WHMCS service can queue a reboot', JobQueue::TYPE_SERVER_REBOOT,
    $rebootQueuedBeforeCancel['job']['job_type']);
$rebootCallsBeforeCancel = $adapter->rebootCalls;
Harness::$gateway->setServiceStatus($serviceId, 'Cancelled');
$staleRebootJobs = $queue->lease('cancelled-service-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('pre-cancellation reboot is leased for worker recheck', 1, count($staleRebootJobs));
T::is('worker fails stale reboot after WHMCS cancellation', 'failed',
    $dispatcher->runJob($staleRebootJobs[0])['status']);
T::is('cancelled WHMCS service receives no provider reboot call', $rebootCallsBeforeCancel, $adapter->rebootCalls);
T::throws('WHMCS-cancelled service blocks new reboot requests', ConflictException::class, function () use ($servers, $serverId) {
    $servers->requestAction($serverId, 'reboot', [], 'reboot-after-whmcs-cancel');
});
$provisionReplayAfterCancellation = $servers->requestProvision($serviceId, $accountId, $serverSpec, 'paid-service-request');
T::ok('provision retry replays after WHMCS service cancellation', $provisionReplayAfterCancellation['replayed']);
T::is('provision replay does not create a second server after cancellation', 1,
    Db::count('customer_servers', ['whmcs_service_id' => $serviceId]));
$delete = $servers->requestAction($serverId, 'delete', ['confirm' => true], 'delete-after-whmcs-cancel');
T::is('WHMCS-cancelled service may queue delete', JobQueue::TYPE_SERVER_DELETE, $delete['job']['job_type']);
$deleteJobs = $queue->lease('customer-vm-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('worker leases delete operation', 1, count($deleteJobs));
T::is('provider delete request starts asynchronously', 'completed', $dispatcher->runJob($deleteJobs[0])['status']);
$adapter->getStatus = 'running';
Clock::travel(15);
$deletePollJobs = $queue->lease('customer-vm-poller', JobQueue::QUEUE_PROVISIONING, 1);
T::is('delete poll is leased', 1, count($deletePollJobs));
T::is('still-running VM is not falsely marked deleted', 'completed', $dispatcher->runJob($deletePollJobs[0])['status']);
T::is('server remains terminating until provider confirms deletion', CustomerServerService::STATUS_TERMINATING,
    $servers->get($serverId)['status']);
$adapter->getStatus = 'deleted';
Clock::travel(15);
$finalDeletePoll = $queue->lease('customer-vm-poller', JobQueue::QUEUE_PROVISIONING, 1);
T::is('final deletion poll is leased', 1, count($finalDeletePoll));
T::is('provider-confirmed deletion completes', 'completed', $dispatcher->runJob($finalDeletePoll[0])['status']);
T::is('only provider-confirmed absence marks VM terminated', CustomerServerService::STATUS_TERMINATED,
    $servers->get($serverId)['status']);

section('Customer visibility is limited to own sanitized VM fields');
$customerApi = new InfrastructureApi(Actor::customer($clientId, 'Customer', ['authMethod' => 'api_token']));
$customerView = $customerApi->dispatch('GET', '/v1/servers');
T::is('customer can list own VMs', 200, $customerView['status']);
T::is('own service exposes one VM', 1, count($customerView['body']['data']));
T::ok('customer presentation hides provider and billing internals', !isset($customerView['body']['data'][0]['provider_server_id'])
    && !isset($customerView['body']['data'][0]['whmcs_invoice_id']));
$ownJob = $customerApi->dispatch('GET', '/v1/jobs/' . $created['job']['id']);
T::is('customer can read own job state', 200, $ownJob['status']);
T::ok('customer job response hides queue internals and payload', !isset($ownJob['body']['data']['payload'])
    && !isset($ownJob['body']['data']['provider_account_id']) && !isset($ownJob['body']['data']['client_id']));
T::is('customer cannot create arbitrary provider-backed servers', 403,
    $customerApi->dispatch('POST', '/v1/servers', ['service_id' => $serviceId, 'provider_account_id' => $accountId,
        'spec' => $serverSpec], ['Idempotency-Key' => 'customer-must-not-select-account'])['status']);
$otherClient = Harness::client(['firstname' => 'Other', 'lastname' => 'User']);
$otherApi = new InfrastructureApi(Actor::customer($otherClient, 'Other', ['authMethod' => 'api_token']));
T::is('another customer sees no server records', [], $otherApi->dispatch('GET', '/v1/servers')['body']['data']);
T::is('another customer cannot enumerate a known server id', 404,
    $otherApi->dispatch('GET', '/v1/servers/' . $serverId)['status']);
Harness::$gateway->setServiceOwner($serviceId, $otherClient);
T::is('former WHMCS owner loses live VM list access', [], $customerApi->dispatch('GET', '/v1/servers')['body']['data']);
T::is('former WHMCS owner loses direct VM access', 404,
    $customerApi->dispatch('GET', '/v1/servers/' . $serverId)['status']);
T::is('former WHMCS owner loses job access after service transfer', 404,
    $customerApi->dispatch('GET', '/v1/jobs/' . $created['job']['id'])['status']);
T::is('new WHMCS owner cannot read a stale local customer binding', 404,
    $otherApi->dispatch('GET', '/v1/servers/' . $serverId)['status']);
Harness::$gateway->setServiceOwner($serviceId, $clientId);

section('Provisioning kill switch is rechecked before provider calls');
$disabledOrder = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 702, 'domain' => '']);
$disabledServiceId = (int) $disabledOrder['service_id'];
$disabledInvoiceId = (int) $disabledOrder['invoice_id'];
Harness::$gateway->payInvoice($disabledInvoiceId);
$disabledSpec = $serverSpec;
$disabledSpec['name'] = 'Kill switch VPS';
$disabledSpec['hostname'] = 'kill-switch-vps.example.test';
$disabledServer = $servers->requestProvision($disabledServiceId, $accountId, $disabledSpec, 'queue-before-disable');
$createCallsBeforeDisable = $adapter->createCalls;
Settings::override('customer_server_provisioning_enabled', '0');
$disabledJob = $queue->lease('disabled-provisioning-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('job queued before switch-off is still visible to worker', 1, count($disabledJob));
T::is('worker stops create after switch-off', 'failed', $dispatcher->runJob($disabledJob[0])['status']);
T::is('disabled feature makes no provider create call', $createCallsBeforeDisable, $adapter->createCalls);
T::is('switch-off failure leaves resource unprovisioned', CustomerServerService::STATUS_FAILED,
    $servers->get((int) $disabledServer['server']['id'])['status']);
Settings::override('customer_server_provisioning_enabled', '1');

section('Worker revalidates WHMCS payment before provider calls');
$secondOrder = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 701, 'domain' => '']);
$secondServiceId = (int) $secondOrder['service_id'];
$secondInvoiceId = (int) $secondOrder['invoice_id'];
Harness::$gateway->payInvoice($secondInvoiceId);
$secondSpec = $serverSpec;
$secondSpec['name'] = 'Recheck VPS';
$secondSpec['hostname'] = 'recheck-vps.example.test';
$secondServer = $servers->requestProvision($secondServiceId, $accountId, $secondSpec, 'paid-then-refunded');
$createCallsBeforeRecheck = $adapter->createCalls;
Harness::$gateway->failInvoicePayment($secondInvoiceId, 'refunded-before-worker');
$recheckJobs = $queue->lease('billing-recheck-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('queued server is leased for billing recheck', 1, count($recheckJobs));
T::is('unpaid-at-worker request fails without provider success', 'failed',
    $dispatcher->runJob($recheckJobs[0])['status']);
T::is('worker makes no provider create call after invoice becomes unpaid', $createCallsBeforeRecheck, $adapter->createCalls);
T::is('payment recheck leaves server failed and unprovisioned', CustomerServerService::STATUS_FAILED,
    $servers->get((int) $secondServer['server']['id'])['status']);

section('Terminal async resize failure clears the staged specification');
$failureSpec = $serverSpec;
$failureSpec['cpu_cores'] = 6;
$failureServerId = Db::insert('customer_servers', [
    'uuid' => Str::uuid4(), 'client_id' => $clientId, 'whmcs_service_id' => 0,
    'whmcs_order_id' => 0, 'whmcs_invoice_id' => 0, 'provider_account_id' => $accountId,
    'name' => 'Resize failure fixture', 'hostname' => null, 'region' => 'fsn1',
    'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
    'requested_spec' => json_encode($serverSpec), 'pending_spec' => json_encode($failureSpec),
    'provider_server_id' => 'vm-terminal-resize-fixture', 'provider_operation_id' => 'operation-resize-failure',
    'provider_operation' => 'resize', 'provider_state' => 'resizing', 'ipv4' => null, 'ipv6' => null,
    'status' => CustomerServerService::STATUS_PROVISIONING,
    'provisioning_state' => CustomerServerService::STATE_RESIZING,
    'create_job_id' => null, 'poll_count' => 3, 'last_error_code' => null,
    'last_error_message' => null, 'requested_by' => 'test', 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
$terminalPoll = $queue->enqueue(JobQueue::TYPE_SERVER_POLL, [
    'customer_server_id' => $failureServerId, 'provider_account_id' => $accountId,
], [
    'queue' => JobQueue::QUEUE_PROVISIONING, 'idempotency_key' => 'terminal-resize-poll',
    'customer_server_id' => $failureServerId, 'provider_account_id' => $accountId,
    'client_id' => $clientId,
]);
$adapter->getStatus = 'failed';
$terminalPollLease = $queue->lease('resize-failure-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('terminal resize poll is leased', 1, count($terminalPollLease));
T::is('provider-reported resize failure is terminal', 'failed',
    $dispatcher->runJob($terminalPollLease[0])['status']);
T::is('terminal async failure clears pending resize target', null,
    Db::first('customer_servers', ['id' => $failureServerId])['pending_spec']);
T::is('terminal poll job retains stable provider error', 'PROVISIONING_FAILED',
    $queue->find((int) $terminalPoll['id'])['error_code']);

section('Cross-resource queue dispatch fails closed');
$wrong = $queue->enqueue(JobQueue::TYPE_SERVER_CREATE, [
    'customer_server_id' => $serverId, 'provider_account_id' => $accountId,
    'whmcs_service_id' => $serviceId,
], [
    'queue' => JobQueue::QUEUE_DEPLOYMENT,
    'idempotency_key' => 'wrong-queue-server-job',
    'customer_server_id' => $serverId,
    'provider_account_id' => $accountId,
    'whmcs_service_id' => $serviceId,
    'client_id' => $clientId,
]);
$wrongLeased = $queue->lease('deployment-worker', JobQueue::QUEUE_DEPLOYMENT, 1);
T::is('wrong-queue infrastructure job is leased for validation', 1, count($wrongLeased));
$wrongResult = $dispatcher->runJob($wrongLeased[0]);
T::is('dispatcher rejects infrastructure work on deployment queue', 'failed', $wrongResult['status']);
$wrongRow = $queue->find((int) $wrong['id']);
T::is('queue mismatch has a stable error code', 'INFRASTRUCTURE_QUEUE_MISMATCH', $wrongRow['error_code']);
T::is('wrong-queue infrastructure job is terminal', JobQueue::STATUS_FAILED, $wrongRow['status']);

exit(T::summary());
