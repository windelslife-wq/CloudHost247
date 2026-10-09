<?php
/**
 * Suite 14 — Post-`server_ready` health/security readiness gates.
 *
 * A provider-ready VM becomes customer-visible `active` only after the gates
 * pass: provider-confirmed address, provider-sourced metrics when the adapter
 * declares `server.metrics` (never faked when it does not), and a deployed-spec
 * integrity check. Transient gate failures keep the server provisioning and
 * keep polling; permanent gate failures fail the server honestly.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\InfrastructureProviderInterface;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;

class ReadinessGateAdapter implements InfrastructureProviderInterface
{
    public $createCalls = 0;
    public $getCalls = 0;
    public $getMetricsCalls = 0;
    public $getStatus = 'running';
    public $metricsCapable = true;
    public $metricsFail = false;
    public $metricsEmpty = false;
    public $readyWithIp = true;

    public function key() { return 'hetzner'; }
    public function name() { return 'Readiness Gate Test Adapter'; }
    public function capabilities()
    {
        $caps = [
            'server.create' => true, 'server.get' => true, 'server.delete' => true,
            'server.reboot' => true, 'server.power_on' => true, 'server.power_off' => true,
            'server.rebuild' => true, 'server.resize' => true, 'server.metrics' => true,
        ];
        if (!$this->metricsCapable) {
            unset($caps['server.metrics']);
        }
        return $caps;
    }
    public function verifyCredentials(array $credentials, array $accountConfig)
    {
        return isset($credentials['api_token']) && $credentials['api_token'] === 'gate-test-secret';
    }
    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    {
        $this->createCalls++;
        return ['id' => 'vm-gate-' . $this->createCalls, 'status' => 'building',
            'operation_id' => 'operation-gate-create-' . $this->createCalls];
    }
    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $this->getCalls++;
        return ['id' => (string) $providerServerId, 'status' => $this->getStatus,
            'ipv4' => $this->readyWithIp ? '198.51.100.90' : null, 'ipv6' => null];
    }
    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return ['id' => (string) $providerServerId, 'status' => 'deleting', 'operation_id' => 'operation-gate-delete'];
    }
    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
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
        return ['id' => (string) $providerServerId, 'status' => 'rebuilding', 'operation_id' => 'operation-gate-rebuild'];
    }
    public function resizeServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    {
        return ['id' => (string) $providerServerId, 'status' => 'resizing', 'operation_id' => 'operation-gate-resize'];
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
        $this->getMetricsCalls++;
        if ($this->metricsFail) {
            throw new RuntimeException('Provider metrics endpoint is unavailable.');
        }
        if ($this->metricsEmpty) {
            return [];
        }
        return ['cpu_percent' => 3, 'memory_percent' => 41];
    }
}

Harness::boot();
Harness::relaxRateLimits();
Clock::freeze('2026-10-08 12:00:00');
Settings::override('customer_server_provisioning_enabled', '1');

$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$accountService = new ProviderAccountService($admin);
$adapter = new ReadinessGateAdapter();
ProviderRegistry::register($adapter);
$account = $accountService->create([
    'provider_code' => 'hetzner',
    'name' => 'Gate staging account',
    'region' => 'fsn1',
    'public_config' => ['project' => 'gate-staging'],
    'credentials' => ['api_token' => 'gate-test-secret'],
]);
$accountId = (int) $account['id'];
$accountService->requestVerification($accountId, 'gate-verify-account');
$queue = new JobQueue();
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    new ServerProvisioningWorker(Harness::systemActor(), $queue),
    new ProviderAccountVerifyWorker(Harness::systemActor(), $queue), $queue);
$verifyLease = $queue->lease('gate-account-worker', JobQueue::QUEUE_PROVISIONING, 1);
$dispatcher->runJob($verifyLease[0]);
T::is('provider account verifies through the registered adapter', 'active',
    (new ProviderAccountService($admin))->get($accountId)['status']);

$servers = new CustomerServerService($admin, Harness::$gateway, $accountService, $queue);
$workerServers = new CustomerServerService(Harness::systemActor(), Harness::$gateway, $accountService, $queue);
$clientId = Harness::client();

/** Provision one paid server and run the create job through the worker. */
$provisionServer = function ($name, $hostname) use ($servers, $accountId, $queue, $dispatcher, $clientId) {
    $order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 710, 'domain' => '']);
    $serviceId = (int) $order['service_id'];
    Harness::$gateway->payInvoice((int) $order['invoice_id']);
    $spec = [
        'name' => $name, 'hostname' => $hostname, 'region' => 'fsn1',
        'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
    ];
    $created = $servers->requestProvision($serviceId, $accountId, $spec, 'gate-' . $hostname);
    $serverId = (int) $created['server']['id'];
    $createLease = $queue->lease('gate-create-worker', JobQueue::QUEUE_PROVISIONING, 1);
    $createResult = $dispatcher->runJob($createLease[0]);
    return [$serverId, $serviceId, $createResult];
};

/** Run the next queued poll job for a server through the worker. */
$runNextPoll = function ($label) use ($queue, $dispatcher) {
    Clock::travel(15);
    $pollLease = $queue->lease($label, JobQueue::QUEUE_PROVISIONING, 1);
    T::is('a poll job is leaseable', 1, count($pollLease));
    return $dispatcher->runJob($pollLease[0]);
};

/** Provision one paid server and run create + first poll through the worker. */
$provisionAndPoll = function ($name, $hostname) use ($provisionServer, $runNextPoll) {
    list($serverId, $serviceId, $createResult) = $provisionServer($name, $hostname);
    $pollResult = $runNextPoll('gate-poll-worker');
    return [$serverId, $serviceId, $createResult, $pollResult];
};

$activationEvents = function ($serverId) {
    return Db::count('customer_server_events', [
        'customer_server_id' => $serverId, 'event' => 'server_activated',
    ]);
};

section('Metrics-capable adapters activate with provider-sourced metrics');
list($serverId, $serviceId, $createResult, $pollResult) = $provisionAndPoll('Gate VPS One', 'gate-one.example.test');
T::is('create job completes', 'completed', $createResult['status']);
T::is('poll job completes', 'completed', $pollResult['status']);
T::is('gates pass and the server activates', 'active', $pollResult['result']['customer_status']);
T::ok('gate report marks provider metrics as reported',
    $pollResult['result']['gates']['metrics']['provider_reported'] === true);
T::is('gate report counts the provider metric fields', 2, $pollResult['result']['gates']['metrics']['fields']);
T::is('provider metrics call happened exactly once in the worker', 1, $adapter->getMetricsCalls);
T::is('server is customer-active after the gates', CustomerServerService::STATUS_ACTIVE,
    $servers->get($serverId)['status']);
T::is('activation keeps the server_ready lifecycle state', CustomerServerService::STATE_SERVER_READY,
    $servers->get($serverId)['provisioning_state']);
T::is('activation lifecycle event is recorded once', 1, $activationEvents($serverId));
$activationMeta = Db::first('customer_server_events', [
    'customer_server_id' => $serverId, 'event' => 'server_activated',
]);
T::ok('activation metadata carries the provider-reported metrics',
    strpos((string) $activationMeta['metadata'], '"provider_reported":true') !== false);

section('Duplicate polls never demote an already-activated server');
$dupPoll = $queue->enqueue(JobQueue::TYPE_SERVER_POLL, [
    'customer_server_id' => $serverId, 'provider_account_id' => $accountId,
    'whmcs_service_id' => $serviceId,
], [
    'queue' => JobQueue::QUEUE_PROVISIONING, 'idempotency_key' => 'gate-dup-poll-' . $serverId,
    'customer_server_id' => $serverId, 'provider_account_id' => $accountId,
    'whmcs_service_id' => $serviceId, 'client_id' => $clientId,
]);
Clock::travel(15);
$dupLease = $queue->lease('gate-dup-poller', JobQueue::QUEUE_PROVISIONING, 1);
$dupResult = $dispatcher->runJob($dupLease[0]);
T::is('duplicate poll completes', 'completed', $dupResult['status']);
T::ok('duplicate poll is explicitly idempotent', !empty($dupResult['result']['idempotent']));
T::is('duplicate poll keeps the server active', 'active', $dupResult['result']['customer_status']);
T::is('duplicate poll does not demote the server', CustomerServerService::STATUS_ACTIVE,
    $servers->get($serverId)['status']);
T::is('duplicate poll records no second activation', 1, $activationEvents($serverId));

section('Transient metrics failure keeps the server provisioning and self-heals');
$adapter->metricsFail = true;
list($slowId, $slowServiceId, $slowCreate, $slowPoll) = $provisionAndPoll('Gate VPS Two', 'gate-two.example.test');
T::is('poll job still completes while a gate fails', 'completed', $slowPoll['status']);
T::ok('failed gate is reported, not hidden', $slowPoll['result']['gates_passed'] === false);
T::is('transient gate failure has a stable machine code', 'HEALTH_CHECK_FAILED',
    $slowPoll['result']['gate_error_code']);
T::is('failed gate keeps the server provisioning', CustomerServerService::STATUS_PROVISIONING,
    $servers->get($slowId)['status']);
T::is('failed gate keeps the server_ready state', CustomerServerService::STATE_SERVER_READY,
    $servers->get($slowId)['provisioning_state']);
T::is('failed gate records the error on the server', 'HEALTH_CHECK_FAILED',
    Db::first('customer_servers', ['id' => $slowId])['last_error_code']);
T::is('failed gate never activates the server', 0, $activationEvents($slowId));
T::is('failed gate schedules the next poll', 1, Db::count('jobs', [
    'job_type' => JobQueue::TYPE_SERVER_POLL, 'customer_server_id' => $slowId,
    'status' => JobQueue::STATUS_QUEUED,
]));
$metricsCallsBeforeHeal = $adapter->getMetricsCalls;
$adapter->metricsFail = false;
Clock::travel(15);
$healLease = $queue->lease('gate-heal-poller', JobQueue::QUEUE_PROVISIONING, 1);
$healResult = $dispatcher->runJob($healLease[0]);
T::is('healed metrics endpoint activates the server', 'active', $healResult['result']['customer_status']);
T::is('metrics endpoint was retried on the next poll', $metricsCallsBeforeHeal + 1, $adapter->getMetricsCalls);
T::is('self-healed server is customer-active', CustomerServerService::STATUS_ACTIVE,
    $servers->get($slowId)['status']);
T::is('self-healed server activated exactly once', 1, $activationEvents($slowId));

section('A ready server without an IP address fails the health gate transiently');
$adapter->metricsCapable = false;
$adapter->readyWithIp = false;
list($noIpId, $noIpServiceId, $noIpCreate, $noIpPoll) = $provisionAndPoll('Gate VPS Three', 'gate-three.example.test');
T::ok('missing-IP gate failure is reported', $noIpPoll['result']['gates_passed'] === false);
T::is('missing-IP gate failure has a stable machine code', 'HEALTH_CHECK_FAILED',
    $noIpPoll['result']['gate_error_code']);
T::is('missing-IP server stays provisioning', CustomerServerService::STATUS_PROVISIONING,
    $servers->get($noIpId)['status']);
T::is('missing-IP server never activates', 0, $activationEvents($noIpId));
$adapter->readyWithIp = true;
Clock::travel(15);
$ipLease = $queue->lease('gate-ip-poller', JobQueue::QUEUE_PROVISIONING, 1);
$ipResult = $dispatcher->runJob($ipLease[0]);
T::is('assigned IP passes the health gate', 'active', $ipResult['result']['customer_status']);
T::ok('adapter without server.metrics records metrics as unsupported, never faked',
    $ipResult['result']['gates']['metrics']['provider_reported'] === false);
T::is('reason is recorded for the missing capability', 'adapter_declares_no_server_metrics',
    $ipResult['result']['gates']['metrics']['reason']);
$adapter->metricsCapable = true;

section('Deployed-spec drift is terminal and never activates');
list($driftId, $driftServiceId, $driftCreate) = $provisionServer('Gate VPS Four', 'gate-four.example.test');
Db::update('customer_servers', ['image' => 'debian-12'], ['id' => $driftId]);
$driftResult = $runNextPoll('gate-drift-poller');
T::is('spec drift fails the poll job', 'failed', $driftResult['status']);
T::is('spec drift fails the server honestly', CustomerServerService::STATUS_FAILED,
    $servers->get($driftId)['status']);
T::is('spec drift reaches the failed lifecycle state', CustomerServerService::STATE_FAILED,
    $servers->get($driftId)['provisioning_state']);
T::is('spec-drifted server never activates', 0, $activationEvents($driftId));
T::ok('spec drift leaves a machine-readable error', in_array(
    (string) Db::first('customer_servers', ['id' => $driftId])['last_error_code'],
    ['PROVISIONING_FAILED', 'SPEC_DRIFT'], true
));

section('A missing approved spec is terminal and never activates');
list($noSpecId, $noSpecServiceId, $noSpecCreate) = $provisionServer('Gate VPS Five', 'gate-five.example.test');
Db::update('customer_servers', ['requested_spec' => null], ['id' => $noSpecId]);
$noSpecResult = $runNextPoll('gate-nospec-poller');
T::is('missing spec fails the poll job', 'failed', $noSpecResult['status']);
T::is('missing spec fails the server honestly', CustomerServerService::STATUS_FAILED,
    $servers->get($noSpecId)['status']);
T::is('spec-less server never activates', 0, $activationEvents($noSpecId));

section('workerActivate is worker-only, state-guarded, and idempotent');
T::throws('non-worker actors cannot activate servers', AuthorizationException::class, function () use ($servers, $serverId) {
    $servers->workerActivate($serverId, ['passed' => true]);
});
$creatingRowId = Db::insert('customer_servers', [
    'uuid' => Str::uuid4(), 'client_id' => $clientId, 'whmcs_service_id' => 0,
    'whmcs_order_id' => 0, 'whmcs_invoice_id' => 0, 'provider_account_id' => $accountId,
    'name' => 'Activation guard fixture', 'hostname' => null, 'region' => 'fsn1',
    'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
    'requested_spec' => json_encode([
        'name' => 'Activation guard fixture', 'hostname' => null, 'region' => 'fsn1',
        'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
    ]),
    'provider_server_id' => 'vm-gate-guard', 'provider_operation_id' => null,
    'provider_operation' => 'create', 'provider_state' => 'building', 'ipv4' => null, 'ipv6' => null,
    'status' => CustomerServerService::STATUS_PROVISIONING,
    'provisioning_state' => CustomerServerService::STATE_SERVER_CREATING,
    'create_job_id' => null, 'poll_count' => 0, 'last_error_code' => null,
    'last_error_message' => null, 'requested_by' => 'test', 'created_at' => Clock::now(),
    'updated_at' => Clock::now(),
]);
T::throws('only a provider-ready server can be activated', StateException::class, function () use ($workerServers, $creatingRowId) {
    $workerServers->workerActivate($creatingRowId, ['passed' => true]);
});
$beforeRepeat = $activationEvents($serverId);
$repeat = $workerServers->workerActivate($serverId, ['passed' => true]);
T::is('re-activating an active server is idempotent', CustomerServerService::STATUS_ACTIVE, $repeat['status']);
T::is('idempotent activation records no extra event', $beforeRepeat, $activationEvents($serverId));

section('Rebuild re-runs the gates and re-activates the server');
$rebuild = $servers->requestAction($serverId, 'rebuild',
    ['spec' => ['image' => 'debian-12']], 'gate-rebuild-request');
T::is('rebuild action is queued', JobQueue::TYPE_SERVER_REBUILD, $rebuild['job']['job_type']);
$rebuildLease = $queue->lease('gate-rebuild-worker', JobQueue::QUEUE_PROVISIONING, 1);
$rebuildResult = $dispatcher->runJob($rebuildLease[0]);
T::is('rebuild job completes its async step', 'completed', $rebuildResult['status']);
T::is('rebuild moves the server out of active while running',
    CustomerServerService::STATUS_PROVISIONING, $servers->get($serverId)['status']);
Clock::travel(15);
$rebuildPollLease = $queue->lease('gate-rebuild-poller', JobQueue::QUEUE_PROVISIONING, 1);
$rebuildPollResult = $dispatcher->runJob($rebuildPollLease[0]);
T::is('rebuild poll re-passes the gates and re-activates', 'active',
    $rebuildPollResult['result']['customer_status']);
$rebuilt = $servers->get($serverId);
T::is('rebuilt server is customer-active', CustomerServerService::STATUS_ACTIVE, $rebuilt['status']);
T::is('rebuilt server runs the new approved image', 'debian-12', $rebuilt['image']);
T::is('rebuild activation is recorded a second time', 2, $activationEvents($serverId));
$rebuildGates = $rebuildPollResult['result']['gates'];
T::is('rebuild gate report verifies the new spec', 'debian-12', $rebuildGates['security']['image']);

exit(T::summary());
