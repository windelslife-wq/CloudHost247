<?php
/** Suite 09 — WHMCS-bound, queued cPanel account lifecycle; no customer creation. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\ControlPanels\ControlPanelConnectionFactory;
use Ch247Apps\ControlPanels\ControlPanelService;
use Ch247Apps\ControlPanels\PanelAccountService;
use Ch247Apps\ControlPanels\PanelAccountWorker;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\PaymentException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;
use Ch247Apps\Integration\FakeGateway;
use Ch247Apps\ControlPanels\ControlPanelAdapterInterface;
use Ch247Apps\Servers\ServerService;

class WorkflowFakeCpanelAdapter implements ControlPanelAdapterInterface
{
    public $accounts = [];
    public $verifyCalls = 0;
    public $getCalls = [];
    public $suspendCalls = 0;
    public $unsuspendCalls = 0;
    public $terminateCalls = 0;
    public $createCalls = 0;
    public $onVerify = null;

    public function key() { return 'cpanel_whm'; }
    public function name() { return 'Workflow fake cPanel'; }
    public function capabilities()
    {
        return [
            'account.verify' => true, 'account.get' => true, 'account.create' => true,
            'account.suspend' => true, 'account.unsuspend' => true, 'account.terminate' => true,
        ];
    }
    public function verify(array $connection)
    {
        $this->verifyCalls++;
        if (is_callable($this->onVerify)) {
            call_user_func($this->onVerify);
        }
        return ['verified' => true, 'version' => 'test-whm'];
    }
    public function getAccount(array $connection, $username)
    {
        $username = strtolower((string) $username);
        $this->getCalls[] = $username;
        return isset($this->accounts[$username]) ? $this->accounts[$username] : null;
    }
    public function createAccount(array $connection, array $account, $idempotencyKey)
    {
        $this->createCalls++;
        throw new RuntimeException('This workflow must never create customer accounts.');
    }
    public function suspendAccount(array $connection, $username, $reason)
    {
        $this->suspendCalls++;
        $username = strtolower((string) $username);
        if (!isset($this->accounts[$username])) {
            throw new RuntimeException('Unknown test account.');
        }
        $this->accounts[$username]['suspended'] = true;
        $this->accounts[$username]['suspend_reason'] = (string) $reason;
        return ['account' => $this->accounts[$username], 'changed' => true, 'confirmed' => true];
    }
    public function unsuspendAccount(array $connection, $username)
    {
        $this->unsuspendCalls++;
        $username = strtolower((string) $username);
        if (!isset($this->accounts[$username])) {
            throw new RuntimeException('Unknown test account.');
        }
        $this->accounts[$username]['suspended'] = false;
        return ['account' => $this->accounts[$username], 'changed' => true, 'confirmed' => true];
    }
    public function terminateAccount(array $connection, $username, $confirmation)
    {
        $this->terminateCalls++;
        $username = strtolower((string) $username);
        if ($confirmation !== 'TERMINATE ' . $username) {
            throw new RuntimeException('Bad test confirmation.');
        }
        unset($this->accounts[$username]);
        return ['terminated' => true, 'confirmed' => true, 'already_absent' => false];
    }
}

Harness::boot();
Harness::relaxRateLimits();

$queue = new JobQueue();
$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$gateway = Harness::$gateway;
$server = (new ServerService($admin))->register([
    'name' => 'Panel workflow test host',
    'hostname' => 'workflow-whm.example.test',
    'ip_address' => '198.51.100.90',
    'server_type' => 'cpanel',
    'credentials' => [
        'whm_api_token' => ['secret' => 'workflow-test-whm-token', 'username' => 'root'],
    ],
]);
$serverId = (int) $server['id'];

function workflowPaidService(FakeGateway $gateway, $clientId, $domain)
{
    $order = $gateway->createOrder([
        'clientid' => (int) $clientId,
        'pid' => 42,
        'domain' => (string) $domain,
        'billingcycle' => 'Monthly',
    ]);
    $gateway->payInvoice((int) $order['invoice_id']);
    $gateway->setServiceStatus((int) $order['service_id'], 'Active');
    return $order;
}

function workflowJob(JobQueue $queue, $worker, $label)
{
    $jobs = $queue->lease('panel-workflow-test', JobQueue::QUEUE_CONTROL_PANEL, 10);
    if (!$jobs) {
        T::ok($label . ' job is leased from the dedicated control-panel queue', false);
        return null;
    }
    $result = $worker->runJob($jobs[0]);
    T::is($label . ' job reaches a terminal worker result', 'completed', $result['status']);
    return $result;
}

section('The cPanel workflow is disabled by default and has no customer-create action');
T::ok('customer account creation is not part of the WHMCS-bound workflow API',
    !method_exists(PanelAccountService::class, 'requestCreate'));
T::ok('no panel-account create job is registered', !in_array('panel_account_create', JobQueue::TYPES, true));
T::is('verify actions use their scoped RBAC permission', Rbac::PANEL_ACCOUNT_VERIFY,
    PanelAccountService::permissionForAction('verify'));
T::is('termination actions use their restricted RBAC permission', Rbac::PANEL_ACCOUNT_TERMINATE,
    PanelAccountService::permissionForAction('terminate'));
T::is('the shipped lifecycle switch defaults off', '0', Settings::get('panel_account_workflow_enabled'));
$firstClient = Harness::client();
$firstOrder = workflowPaidService($gateway, $firstClient, 'hosting.example.test');
$disabledService = new PanelAccountService($admin, $gateway, $queue);
T::throws('binding fails closed while the panel workflow switch is off', StateException::class, function () use (
    $disabledService, $firstOrder, $serverId
) {
    $disabledService->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    ], 'disabled-bind-001');
});
T::is('disabled workflow creates no local account row', 0, Db::count('panel_accounts'));
T::is('disabled workflow queues no panel job', 0, Db::count('jobs', ['queue' => JobQueue::QUEUE_CONTROL_PANEL]));

section('Only authorized staff may bind an existing account to a paid WHMCS service');
Settings::override('panel_account_workflow_enabled', '1');
$staffService = new PanelAccountService(Actor::admin(3, Actor::ROLE_STAFF), $gateway, $queue);
T::throws('read-only staff cannot bind a panel account', AuthorizationException::class, function () use (
    $staffService, $firstOrder, $serverId
) {
    $staffService->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    ], 'staff-bind-001');
});

$unpaidClient = Harness::client();
$unpaidOrder = $gateway->createOrder([
    'clientid' => $unpaidClient, 'pid' => 42, 'domain' => 'unpaid.example.test', 'billingcycle' => 'Monthly',
]);
$gateway->setServiceStatus($unpaidOrder['service_id'], 'Active');
T::throws('an unpaid WHMCS order cannot be linked', PaymentException::class, function () use (
    $disabledService, $unpaidOrder, $serverId
) {
    $disabledService->bindExistingAccount($unpaidOrder['service_id'], $serverId, [
        'username' => 'unpaiduser', 'domain' => 'unpaid.example.test', 'package' => 'basic',
    ], 'unpaid-bind-001');
});
$inactiveClient = Harness::client();
$inactiveOrder = workflowPaidService($gateway, $inactiveClient, 'inactive.example.test');
$gateway->setServiceStatus($inactiveOrder['service_id'], 'Suspended');
T::throws('a non-active WHMCS service cannot be linked', PaymentException::class, function () use (
    $disabledService, $inactiveOrder, $serverId
) {
    $disabledService->bindExistingAccount($inactiveOrder['service_id'], $serverId, [
        'username' => 'inactiveuser', 'domain' => 'inactive.example.test', 'package' => 'basic',
    ], 'inactive-bind-001');
});
T::throws('a cPanel account domain must match the WHMCS service domain', ConflictException::class, function () use (
    $disabledService, $firstOrder, $serverId
) {
    $disabledService->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'otheruser', 'domain' => 'wrong.example.test', 'package' => 'basic',
    ], 'domain-bind-001');
});
T::throws('password material is not accepted by the account-link operation', ValidationException::class, function () use (
    $disabledService, $firstOrder, $serverId
) {
    $disabledService->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic',
        'password' => 'not-allowed-in-this-workflow',
    ], 'secret-bind-001');
});
T::is('rejected service bindings do not create panel-account rows', 0, Db::count('panel_accounts'));

$service = new PanelAccountService($admin, $gateway, $queue);
$linked = $service->bindExistingAccount($firstOrder['service_id'], $serverId, [
    'username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic',
], 'bind-account-key-001');
$accountId = (int) $linked['account']['id'];
$verifyJobId = (int) $linked['job']['id'];
T::is('new mapping starts as unverified, not active', PanelAccountService::STATUS_UNVERIFIED, $linked['account']['status']);
T::is('binding queues asynchronous account verification', JobQueue::QUEUE_CONTROL_PANEL, $linked['job']['queue']);
T::is('verification job has a distinct panel-account correlation', $accountId, $linked['job']['panel_account_id']);
T::is('verification job is bound to the WHMCS hosting service', (int) $firstOrder['service_id'], $linked['job']['whmcs_service_id']);
T::is('verification reservation points to its queue job', $verifyJobId, (int) Db::first('panel_accounts', ['id' => $accountId])['pending_job_id']);
T::is('linking records its verification queue event', 1, Db::count('panel_account_events', [
    'panel_account_id' => $accountId, 'event' => 'verification_queued',
]));
T::notContains('stored account linkage and queue payload contain no password', 'password',
    json_encode([$linked['account'], $linked['job']['payload']]));
T::is('same binding request replays the original job', $verifyJobId,
    (int) $service->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    ], 'bind-account-key-001')['job']['id']);
T::is('binding replay does not duplicate the service mapping', 1, Db::count('panel_accounts', [
    'whmcs_service_id' => (int) $firstOrder['service_id'],
]));
T::throws('reusing the binding key with changed account data is a conflict', ConflictException::class, function () use (
    $service, $firstOrder, $serverId
) {
    $service->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'changeduser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    ], 'bind-account-key-001');
});
T::throws('a second local account cannot be bound to the same WHMCS service', ConflictException::class, function () use (
    $service, $firstOrder, $serverId
) {
    $service->bindExistingAccount($firstOrder['service_id'], $serverId, [
        'username' => 'otheracct', 'domain' => 'hosting.example.test', 'package' => 'basic',
    ], 'bind-account-key-002');
});

section('Authenticated API exposes staff-only binding and action endpoints');
$customerApi = new InfrastructureApi(Actor::customer($firstClient));
$customerList = $customerApi->dispatch('GET', '/v1/panel-accounts');
T::is('customers cannot list WHMCS-bound panel accounts', 403, $customerList['status']);
$badApi = new InfrastructureApi($admin);
$badBody = $badApi->dispatch('POST', '/v1/panel-accounts', [
    'service_id' => (int) $firstOrder['service_id'], 'server_id' => $serverId,
    'username' => 'baduser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    'password' => 'must-not-be-accepted',
], ['Idempotency-Key' => 'api-secret-bind-001', 'X-CSRF-Token' => 'valid-looking-but-wrong']);
T::is('a bad-CSRF request is rejected before binding', 401, $badBody['status']);
T::is('API rejection creates no second mapping', 1, Db::count('panel_accounts'));
$passwordBody = $badApi->dispatch('POST', '/v1/panel-accounts', [
    'service_id' => (int) $firstOrder['service_id'], 'server_id' => $serverId,
    'username' => 'baduser', 'domain' => 'hosting.example.test', 'package' => 'basic',
    'password' => 'must-not-be-accepted',
], ['Idempotency-Key' => 'api-password-field-rejected', 'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token()]);
T::is('API explicitly rejects password fields even with valid CSRF', 422, $passwordBody['status']);
T::is('unsupported API fields create no mapping', 1, Db::count('panel_accounts'));

$badIdResponse = $badApi->dispatch('POST', '/v1/panel-accounts', [
    'service_id' => ['not', 'an', 'id'], 'server_id' => $serverId,
    'username' => 'arrayiduser', 'domain' => 'api.example.test', 'package' => 'basic',
], ['Idempotency-Key' => 'api-invalid-service-id', 'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token()]);
T::is('non-scalar service IDs are rejected without array-cast warnings or binding', 422, $badIdResponse['status']);
$apiClient = Harness::client();
$apiOrder = workflowPaidService($gateway, $apiClient, 'api.example.test');
$csrfToken = \Ch247Apps\Core\Csrf::token();
$apiResponse = $badApi->dispatch('POST', '/v1/panel-accounts', [
    'service_id' => (int) $apiOrder['service_id'], 'server_id' => $serverId,
    'username' => 'apiuser', 'domain' => 'api.example.test', 'package' => 'basic',
], ['Idempotency-Key' => 'api-bind-existing-001', 'X-CSRF-Token' => $csrfToken]);
T::is('authorized staff can queue a link to an existing account', 202, $apiResponse['status']);
T::is('API binding does not perform a WHM call in the request', 0, $gateway->callCount('panelApiCall'));
T::is('admin can read sanitized linked-account records', 200,
    $badApi->dispatch('GET', '/v1/panel-accounts/' . (int) $apiResponse['body']['data']['account']['id'])['status']);
T::is('customer account-create path is not exposed', 404,
    $badApi->dispatch('POST', '/v1/panel-accounts/' . $accountId . '/create', [], [
        'Idempotency-Key' => 'no-create-route-01', 'X-CSRF-Token' => $csrfToken,
    ])['status']);

section('Worker rechecks WHMCS ownership and records only vendor-confirmed state');
$transferClient = Harness::client();
$transferOrder = workflowPaidService($gateway, $transferClient, 'transfer.example.test');
$transferLink = $service->bindExistingAccount($transferOrder['service_id'], $serverId, [
    'username' => 'transferuser', 'domain' => 'transfer.example.test', 'package' => 'basic',
], 'transfer-bind-key-1');
$transferAccountId = (int) $transferLink['account']['id'];
$gateway->setServiceOwner($transferOrder['service_id'], $apiClient);

$fakePanel = new WorkflowFakeCpanelAdapter();
foreach ([
    'acctuser' => ['username' => 'acctuser', 'domain' => 'hosting.example.test', 'package' => 'basic', 'suspended' => false],
    'apiuser' => ['username' => 'apiuser', 'domain' => 'api.example.test', 'package' => 'basic', 'suspended' => false],
    'transferuser' => ['username' => 'transferuser', 'domain' => 'transfer.example.test', 'package' => 'basic', 'suspended' => false],
] as $username => $row) {
    $fakePanel->accounts[$username] = $row;
}
$workerActor = Harness::systemActor();
$lowLevel = new ControlPanelService($workerActor, new ControlPanelConnectionFactory($workerActor), $fakePanel);
$workerAccounts = new PanelAccountService($workerActor, $gateway, $queue);
$panelWorker = new PanelAccountWorker($workerActor, $queue, $workerAccounts, $lowLevel);
$leased = $queue->lease('workflow-worker', JobQueue::QUEUE_CONTROL_PANEL, 10);
T::is('only independent panel resources are leased together', 3, count($leased));
$workerResults = [];
foreach ($leased as $job) {
    try {
        $workerResults[] = $panelWorker->runJob($job);
    } catch (\Throwable $e) {
        $workerResults[] = ['status' => 'retry', 'error' => get_class($e)];
    }
}
$firstAccount = Db::first('panel_accounts', ['id' => $accountId]);
T::is('WHM read-back activates a matching linked account', PanelAccountService::STATUS_ACTIVE, $firstAccount['status']);
T::is('successful WHM verification clears the queue reservation', null, $firstAccount['pending_job_id']);
T::is('unverified WHM token is verified before account access', 1, $fakePanel->verifyCalls);
T::ok('stale WHMCS owner is rejected before reading the external account',
    !in_array('transferuser', $fakePanel->getCalls, true));
$transferred = Db::first('panel_accounts', ['id' => $transferAccountId]);
T::is('owner mismatch fails the verification job without claiming an account state',
    PanelAccountService::STATUS_UNVERIFIED, $transferred['status']);
T::is('owner-mismatch failure clears its retry-blocking reservation', null, $transferred['pending_job_id']);
T::is('the API-created mapping is independently verified', PanelAccountService::STATUS_ACTIVE,
    Db::first('panel_accounts', ['whmcs_service_id' => (int) $apiOrder['service_id']])['status']);

section('WHMCS status and explicit confirmation gate suspend, unsuspend and termination');
$gateway->setServiceStatus($firstOrder['service_id'], 'Suspended');
$fakePanel->accounts['acctuser']['package'] = 'wrong-package';
$badSuspend = $service->requestAction($accountId, ['action' => 'suspend'], 'suspend-mismatch-001');
$badSuspendJobs = $queue->lease('workflow-worker', JobQueue::QUEUE_CONTROL_PANEL, 10);
$badSuspendResult = $panelWorker->runJob($badSuspendJobs[0]);
T::is('a changed WHM account binding fails before the lifecycle mutation', 'failed', $badSuspendResult['status']);
T::is('mismatched WHM accounts are not suspended', 0, $fakePanel->suspendCalls);
T::is('binding mismatch marks the mapping for operator reconciliation', PanelAccountService::STATUS_ERROR,
    Db::first('panel_accounts', ['id' => $accountId])['status']);
$fakePanel->accounts['acctuser']['package'] = 'basic';
$suspend = $service->requestAction($accountId, ['action' => 'suspend'], 'suspend-account-001');
$suspendJobId = (int) $suspend['job']['id'];
T::is('suspend is queued on the separate panel queue', JobQueue::QUEUE_CONTROL_PANEL, $suspend['job']['queue']);
T::throws('another action cannot overlap an in-flight account job', ConflictException::class, function () use (
    $service, $accountId
) {
    $service->requestAction($accountId, ['action' => 'verify'], 'overlap-action-001');
});
T::is('same suspend idempotency key replays its job', $suspendJobId,
    (int) $service->requestAction($accountId, ['action' => 'suspend'], 'suspend-account-001')['job']['id']);
workflowJob($queue, $panelWorker, 'suspend');
T::is('suspend updates local state only after vendor read-back', PanelAccountService::STATUS_SUSPENDED,
    Db::first('panel_accounts', ['id' => $accountId])['status']);
T::is('the WHM suspend operation ran once', 1, $fakePanel->suspendCalls);

$gateway->setServiceStatus($firstOrder['service_id'], 'Active');
$unsuspend = $service->requestAction($accountId, ['action' => 'unsuspend'], 'unsuspend-account-001');
T::is('unsuspend requires the current paid WHMCS service', JobQueue::QUEUE_CONTROL_PANEL, $unsuspend['job']['queue']);
workflowJob($queue, $panelWorker, 'unsuspend');
T::is('unsuspend records a WHM-confirmed active account', PanelAccountService::STATUS_ACTIVE,
    Db::first('panel_accounts', ['id' => $accountId])['status']);
T::is('the WHM unsuspend operation ran once', 1, $fakePanel->unsuspendCalls);

$gateway->setServiceStatus($firstOrder['service_id'], 'Cancelled');
$adminActor = Actor::admin(7, Actor::ROLE_ADMIN, 'Limited Admin');
$adminPanelService = new PanelAccountService($adminActor, $gateway, $queue);
T::throws('day-to-day admins cannot terminate cPanel accounts', AuthorizationException::class, function () use (
    $adminPanelService, $accountId
) {
    $adminPanelService->requestAction($accountId, [
        'action' => 'terminate', 'confirmation' => 'TERMINATE acctuser',
    ], 'admin-terminate-001');
});
T::throws('termination requires the exact account confirmation phrase', ValidationException::class, function () use (
    $service, $accountId
) {
    $service->requestAction($accountId, [
        'action' => 'terminate', 'confirmation' => 'TERMINATE wronguser',
    ], 'wrong-terminate-001');
});
Db::update('server_credentials', ['verified' => 0], [
    'server_id' => $serverId,
    'credential_type' => \Ch247Apps\Servers\CredentialVault::TYPE_WHM_API_TOKEN,
    'name' => 'primary',
]);
$fakePanel->onVerify = function () use ($gateway, $firstOrder) {
    $gateway->setServiceStatus($firstOrder['service_id'], 'Active');
};
$raceTerminate = $service->requestAction($accountId, [
    'action' => 'terminate', 'confirmation' => 'TERMINATE acctuser',
], 'terminate-race-check-001');
$raceJobs = $queue->lease('workflow-worker', JobQueue::QUEUE_CONTROL_PANEL, 10);
$raceResult = $panelWorker->runJob($raceJobs[0]);
T::is('worker rechecks WHMCS status after token verification and before termination', 'failed', $raceResult['status']);
T::is('a WHMCS status race blocks the external terminate operation', 0, $fakePanel->terminateCalls);
T::is('failed race check releases the panel-account reservation', null,
    Db::first('panel_accounts', ['id' => $accountId])['pending_job_id']);
$fakePanel->onVerify = null;
$gateway->setServiceStatus($firstOrder['service_id'], 'Cancelled');
$terminate = $service->requestAction($accountId, [
    'action' => 'terminate', 'confirmation' => 'TERMINATE acctuser',
], 'terminate-account-001');
T::notContains('the exact termination phrase is not persisted in queue or account history',
    'TERMINATE acctuser', json_encode([$terminate['job'], Db::fetch('panel_account_events', [
        'panel_account_id' => $accountId,
    ])]));
T::is('termination is queued only after the WHMCS service is cancelled', JobQueue::QUEUE_CONTROL_PANEL,
    $terminate['job']['queue']);
workflowJob($queue, $panelWorker, 'terminate');
T::is('termination is recorded only after confirmed remote absence', PanelAccountService::STATUS_TERMINATED,
    Db::first('panel_accounts', ['id' => $accountId])['status']);
T::is('the explicit WHM termination operation ran once', 1, $fakePanel->terminateCalls);
T::is('the service-bound workflow never creates an account or password', 0, $fakePanel->createCalls);
T::ok('the WHM API token is not included in queue or account presentation',
    strpos(json_encode([$linked, $apiResponse['body']]), 'workflow-test-whm-token') === false);

section('Dispatcher rejects panel jobs on the wrong queue');
$wrongQueueJob = $queue->enqueue(JobQueue::TYPE_PANEL_ACCOUNT_VERIFY, [
    'panel_account_id' => $accountId, 'action' => 'verify',
], [
    'queue' => JobQueue::QUEUE_PROVISIONING,
    'idempotency_key' => 'panel-wrong-queue-test',
    'panel_account_id' => $accountId,
]);
$dispatcher = new JobDispatcher(
    new Orchestrator($workerActor, null, $queue),
    new ServerProvisioningWorker($workerActor, $queue),
    new ProviderAccountVerifyWorker($workerActor, $queue),
    $queue,
    $panelWorker
);
$wrongQueueResult = $dispatcher->runJob($wrongQueueJob);
T::is('misrouted panel work fails instead of reaching the deployment orchestrator', 'PANEL_QUEUE_MISMATCH',
    $wrongQueueResult['error']['code']);
T::is('unknown panel-account create jobs are not supported', false,
    PanelAccountWorker::handles('panel_account_create'));

exit(T::summary());
