<?php
/** Suite 09 — WHMCS-bound, queued cPanel account lifecycle; no customer creation. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\ControlPanels\ControlPanelConnectionFactory;
use Ch247Apps\ControlPanels\ControlPanelService;
use Ch247Apps\ControlPanels\PanelAccountService;
use Ch247Apps\ControlPanels\PanelAccountWorker;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Events;
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
use Ch247Apps\Http\PanelAccountAdmin;
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
    public $domainListCalls = [];
    public $domainInventories = [];
    public $aliasListCalls = [];
    public $domainAliases = [];
    public $quotaUsageCalls = [];
    public $quotaUsages = [];
    public $bandwidthUsageCalls = [];
    public $bandwidthUsages = [];
    public $onVerify = null;

    public function key() { return 'cpanel_whm'; }
    public function name() { return 'Workflow fake cPanel'; }
    public function capabilities()
    {
        return [
            'account.verify' => true, 'account.get' => true, 'account.domains.list' => true,
            'account.domains.aliases.list' => true, 'account.usage.quota.read' => true,
            'account.usage.bandwidth.read' => true, 'account.create' => true, 'account.suspend' => true,
            'account.unsuspend' => true, 'account.terminate' => true,
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
    public function listDomains(array $connection, $username, $expectedMainDomain)
    {
        $username = strtolower((string) $username);
        $this->domainListCalls[] = [$username, (string) $expectedMainDomain];
        $domains = isset($this->domainInventories[$username])
            ? $this->domainInventories[$username]
            : [['domain' => (string) $expectedMainDomain, 'type' => 'main']];
        return [
            'username' => $username,
            'main_domain' => (string) $expectedMainDomain,
            'domains' => $domains,
            'count' => count($domains),
            'temporary_domains_excluded' => true,
        ];
    }
    public function listBuiltinDomainAliases(array $connection, $username, $expectedMainDomain)
    {
        $username = strtolower((string) $username);
        $this->aliasListCalls[] = [$username, (string) $expectedMainDomain];
        $aliases = isset($this->domainAliases[$username])
            ? $this->domainAliases[$username]
            : [['alias' => 'www', 'domain' => 'www.' . (string) $expectedMainDomain]];
        return [
            'username' => $username,
            'main_domain' => (string) $expectedMainDomain,
            'aliases' => $aliases,
            'count' => count($aliases),
            'completeness' => 'vendor_reported',
            'temporary_domains_excluded' => true,
        ];
    }
    public function getQuotaUsage(array $connection, $username, $expectedMainDomain)
    {
        $username = strtolower((string) $username);
        $this->quotaUsageCalls[] = [$username, (string) $expectedMainDomain];
        $usage = isset($this->quotaUsages[$username]) ? $this->quotaUsages[$username] : [
            'megabyte_limit' => '1024', 'megabytes_remain' => '1000', 'megabytes_used' => '24',
            'inode_limit' => '100000', 'inodes_remain' => '99900', 'inodes_used' => '100',
            'under_inode_limit' => true, 'under_megabyte_limit' => true, 'under_quota_overall' => true,
        ];
        return [
            'username' => $username,
            'main_domain' => (string) $expectedMainDomain,
            'usage' => $usage,
            'fields_reported' => array_keys($usage),
            'completeness' => 'vendor_reported',
        ];
    }
    public function getBandwidthUsage(array $connection, $username, $expectedMainDomain)
    {
        $username = strtolower((string) $username);
        $this->bandwidthUsageCalls[] = [$username, (string) $expectedMainDomain];
        $usage = isset($this->bandwidthUsages[$username]) ? $this->bandwidthUsages[$username] : [
            'used' => '5.25', 'limit' => '100', 'percent' => 5, 'units' => 'MB',
            'zero_is_unlimited' => true, 'is_maxed' => false, 'normalized' => false,
        ];
        return [
            'username' => $username,
            'main_domain' => (string) $expectedMainDomain,
            'usage' => $usage,
            'fields_reported' => array_keys($usage),
            'completeness' => 'vendor_reported',
        ];
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
T::ok('the existing control-panel worker dispatches the alias read job',
    PanelAccountWorker::handles(JobQueue::TYPE_PANEL_ACCOUNT_ALIASES));
T::ok('the existing control-panel worker dispatches the bandwidth snapshot job',
    PanelAccountWorker::handles(JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE));
T::is('verify actions use their scoped RBAC permission', Rbac::PANEL_ACCOUNT_VERIFY,
    PanelAccountService::permissionForAction('verify'));
T::is('termination actions use their restricted RBAC permission', Rbac::PANEL_ACCOUNT_TERMINATE,
    PanelAccountService::permissionForAction('terminate'));
T::is('bandwidth snapshot requests use the read-only staff permission', Rbac::PANEL_ACCOUNT_VIEW,
    PanelAccountService::permissionForAction(PanelAccountService::ACTION_BANDWIDTH_USAGE));
T::is('the shipped lifecycle switch defaults off', '0', Settings::get('panel_account_workflow_enabled'));
T::is('the separate read-only UAPI domains switch defaults off', '0', Settings::get('cpanel_uapi_domains_enabled'));
T::is('the separate built-in alias inventory switch defaults off', '0',
    Settings::get('cpanel_uapi_aliases_enabled'));
T::is('the separate quota-usage snapshot switch defaults off', '0',
    Settings::get('cpanel_uapi_quota_usage_enabled'));
T::is('the separate bandwidth-usage snapshot switch defaults off', '0',
    Settings::get('cpanel_uapi_bandwidth_usage_enabled'));
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
T::throws('read-only UAPI domains are independently disabled by default', StateException::class, function () use ($disabledService) {
    $disabledService->requestDomainInventory(1, 'uapi-domains-disabled-001');
});
T::throws('built-in alias inventory is independently disabled by default', StateException::class, function () use ($disabledService) {
    $disabledService->requestDomainAliases(1, 'uapi-aliases-disabled-001');
});
T::throws('quota-usage snapshots are independently disabled by default', StateException::class, function () use ($disabledService) {
    $disabledService->requestQuotaUsage(1, 'uapi-quota-usage-disabled-001');
});
T::throws('bandwidth-usage snapshots are independently disabled by default', StateException::class, function () use ($disabledService) {
    $disabledService->requestBandwidthUsage(1, 'uapi-bandwidth-usage-disabled-001');
});

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

section('Phase 5 domain inventory is staff-only, read-only and queued');
$staffApi = new InfrastructureApi(Actor::admin(6, Actor::ROLE_STAFF));
$domainPath = '/v1/panel-accounts/' . $accountId . '/domains';
$staffDisabledRequest = $staffApi->dispatch('POST', $domainPath, [], [
    'Idempotency-Key' => 'domains-disabled-http-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only staff are authorized but the UAPI switch blocks requests', 409, $staffDisabledRequest['status']);
T::is('disabled UAPI inventory has an explicit error code', 'CPANEL_UAPI_DOMAINS_DISABLED',
    $staffDisabledRequest['body']['error']['code']);
$domainBodyRejected = $staffApi->dispatch('POST', $domainPath, ['requested_domain' => 'other.example.test'], [
    'Idempotency-Key' => 'domains-body-rejected-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only domain inventory rejects all caller-supplied fields', 422, $domainBodyRejected['status']);
$customerDomainRequest = $customerApi->dispatch('POST', $domainPath, [], [
    'Idempotency-Key' => 'customer-domains-forbidden-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('customers cannot request cPanel domain inventories', 403, $customerDomainRequest['status']);
$savedDisabledUiGet = $_GET;
$savedDisabledUiMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
Identity::override(Actor::admin(6, Actor::ROLE_STAFF));
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$disabledPanelAccountPage = ob_get_clean();
T::contains('staff page explains the disabled UAPI gate', 'cPanel UAPI domain inventory is disabled', $disabledPanelAccountPage);
T::contains('staff page explains the disabled built-in alias gate', 'cPanel UAPI built-in alias inventory is disabled',
    $disabledPanelAccountPage);
T::notContains('disabled UAPI gate hides the domain queue action', 'Queue domain inventory', $disabledPanelAccountPage);
T::notContains('disabled alias gate hides the alias queue action', 'Queue built-in alias read', $disabledPanelAccountPage);
Identity::reset();
$_GET = $savedDisabledUiGet;
if ($savedDisabledUiMethod === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $savedDisabledUiMethod;
}
Settings::override('cpanel_uapi_domains_enabled', '1');
$fakePanel->domainInventories['acctuser'] = [
    ['domain' => 'hosting.example.test', 'type' => 'main'],
    ['domain' => 'addon.example.test', 'type' => 'addon'],
    ['domain' => 'mail.hosting.example.test', 'type' => 'sub'],
];
$beforeDomainCallCount = count($fakePanel->domainListCalls);
$domainRequest = $staffApi->dispatch('POST', $domainPath, [], [
    'Idempotency-Key' => 'domains-list-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only staff may queue domain inventory', 202, $domainRequest['status']);
T::is('domain inventory uses a dedicated cPanel worker job', JobQueue::TYPE_PANEL_ACCOUNT_DOMAINS,
    $domainRequest['body']['data']['job']['job_type']);
T::is('the HTTP request does not call cPanel UAPI synchronously', $beforeDomainCallCount, count($fakePanel->domainListCalls));
$domainReplay = $staffApi->dispatch('POST', $domainPath, [], [
    'Idempotency-Key' => 'domains-list-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('domain inventory replay returns the same job', (int) $domainRequest['body']['data']['job']['id'],
    (int) $domainReplay['body']['data']['job']['id']);
$domainJobId = (int) $domainRequest['body']['data']['job']['id'];
$domainJobResult = workflowJob($queue, $panelWorker, 'UAPI domain inventory');
T::is('the worker uses the account-bound username and domain', ['acctuser', 'hosting.example.test'],
    $fakePanel->domainListCalls[$beforeDomainCallCount]);
T::is('only the allowlisted domain inventory is returned to staff', $fakePanel->domainInventories['acctuser'],
    $domainJobResult['result']['domains']);
T::is('domain inventory results are readable by staff with panel-view permission', 200,
    $staffApi->dispatch('GET', '/v1/jobs/' . $domainJobId)['status']);
$readJob = $staffApi->dispatch('GET', '/v1/jobs/' . $domainJobId);
T::is('the staff job projection includes the completed inventory', $fakePanel->domainInventories['acctuser'],
    $readJob['body']['data']['result']['domains']);
T::is('the customer job route does not expose panel-account inventory', 404,
    $customerApi->dispatch('GET', '/v1/jobs/' . $domainJobId)['status']);
T::is('domain inventory records an audited history event without storing domain lists in audit metadata', 1,
    Db::count('panel_account_events', ['panel_account_id' => $accountId, 'event' => 'domains_inventory_listed']));
T::notContains('the domain list is not duplicated into lifecycle history', 'addon.example.test',
    (string) Db::first('panel_account_events', [
        'panel_account_id' => $accountId, 'event' => 'domains_inventory_listed',
    ])['metadata']);
$domainAudit = Db::first('audit_logs', ['action' => Audit::PANEL_ACCOUNT_DOMAINS_LISTED]);
T::ok('domain inventory uses a dedicated audit action', is_array($domainAudit));
$domainAuditMetadata = $domainAudit ? json_decode((string) $domainAudit['metadata'], true) : [];
T::is('domain inventory audit records the bounded count', 3,
    isset($domainAuditMetadata['domain_count']) ? (int) $domainAuditMetadata['domain_count'] : null);
T::notContains('domain inventory audit does not record the domain list', 'addon.example.test',
    (string) ($domainAudit['metadata'] ?? ''));
T::is('domain inventory emits a read-specific event', 1,
    count(Events::emitted('panel_account.domains_listed')));
$savedGet = $_GET;
$savedPost = $_POST;
$savedRequestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
Identity::override(Actor::admin(6, Actor::ROLE_STAFF));
$_GET = ['account_id' => $accountId, 'job_id' => $domainJobId];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$staffPanelAccountPage = ob_get_clean();
T::contains('staff admin page displays the completed domain inventory', 'addon.example.test', $staffPanelAccountPage);
T::contains('staff admin page labels panel capabilities read-only', 'Read-only, queued cPanel snapshots', $staffPanelAccountPage);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$uiIdempotencyKey = \Ch247Apps\Core\Str::uuid4();
$_POST = [
    'panel_account_action' => 'domains_list',
    'panel_account_id' => (string) $accountId,
    'idempotency_key' => $uiIdempotencyKey,
    'ch247_token' => \Ch247Apps\Core\Csrf::token(),
];
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$queuedFromAdminPage = ob_get_clean();
T::contains('admin page can queue only the asynchronous read operation', 'read-only domain inventory was queued', $queuedFromAdminPage);
T::ok('admin page queues the dedicated domains job',
    Db::first('jobs', ['panel_account_id' => $accountId, 'job_type' => JobQueue::TYPE_PANEL_ACCOUNT_DOMAINS,
        'status' => JobQueue::STATUS_QUEUED]) !== null);
$uiDomainJobResult = workflowJob($queue, $panelWorker, 'Admin UI domain inventory');
T::is('admin UI job completes through the existing panel worker', 'completed', $uiDomainJobResult['status']);

section('Phase 6 built-in aliases are staff-only, vendor-reported and independently gated');
$aliasPath = '/v1/panel-accounts/' . $accountId . '/domain-aliases';
$aliasDisabledRequest = $staffApi->dispatch('POST', $aliasPath, [], [
    'Idempotency-Key' => 'aliases-disabled-http-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('staff is authorized but the separate alias switch blocks requests', 409, $aliasDisabledRequest['status']);
T::is('disabled built-in alias inventory has an explicit error code', 'CPANEL_UAPI_ALIASES_DISABLED',
    $aliasDisabledRequest['body']['error']['code']);
$customerAliasRequest = $customerApi->dispatch('POST', $aliasPath, [], [
    'Idempotency-Key' => 'customer-aliases-forbidden-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('customers cannot request built-in alias inventories', 403, $customerAliasRequest['status']);
$aliasBodyRejected = $staffApi->dispatch('POST', $aliasPath, ['domain' => 'other.example.test'], [
    'Idempotency-Key' => 'aliases-body-rejected-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('built-in alias inventory rejects all caller-supplied fields', 422, $aliasBodyRejected['status']);

Settings::override('cpanel_uapi_aliases_enabled', '1');
$workerGateRequest = $staffApi->dispatch('POST', $aliasPath, [], [
    'Idempotency-Key' => 'aliases-worker-gate-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('an enabled alias feature can be queued before worker-time review', 202, $workerGateRequest['status']);
$beforeWorkerGateCalls = count($fakePanel->aliasListCalls);
Settings::override('cpanel_uapi_aliases_enabled', '0');
$workerGateJobs = $queue->lease('alias-gate-worker', JobQueue::QUEUE_CONTROL_PANEL, 1);
$workerGateResult = $panelWorker->runJob($workerGateJobs[0]);
T::is('the worker rechecks the alias feature gate before external access', 'failed', $workerGateResult['status']);
T::is('disabled-at-execution alias jobs return the dedicated error code', 'CPANEL_UAPI_ALIASES_DISABLED',
    $workerGateResult['error']['code']);
T::is('a disabled-at-execution alias job makes no UAPI request', $beforeWorkerGateCalls,
    count($fakePanel->aliasListCalls));
Settings::override('cpanel_uapi_aliases_enabled', '1');
$fakePanel->domainAliases['acctuser'] = [
    ['alias' => 'www', 'domain' => 'www.hosting.example.test'],
    ['alias' => 'mail', 'domain' => 'mail.hosting.example.test'],
];
$beforeAliasCallCount = count($fakePanel->aliasListCalls);
$aliasRequest = $staffApi->dispatch('POST', $aliasPath, [], [
    'Idempotency-Key' => 'aliases-list-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only staff may queue built-in alias inventory', 202, $aliasRequest['status']);
T::is('built-in alias inventory uses a dedicated cPanel worker job', JobQueue::TYPE_PANEL_ACCOUNT_ALIASES,
    $aliasRequest['body']['data']['job']['job_type']);
T::is('the HTTP request does not call cPanel UAPI synchronously', $beforeAliasCallCount,
    count($fakePanel->aliasListCalls));
$aliasReplay = $staffApi->dispatch('POST', $aliasPath, [], [
    'Idempotency-Key' => 'aliases-list-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('built-in alias request replay returns the same job', (int) $aliasRequest['body']['data']['job']['id'],
    (int) $aliasReplay['body']['data']['job']['id']);
$aliasJobId = (int) $aliasRequest['body']['data']['job']['id'];
$aliasJobResult = workflowJob($queue, $panelWorker, 'Built-in alias inventory');
T::is('the worker uses the account-bound username and primary domain', ['acctuser', 'hosting.example.test'],
    $fakePanel->aliasListCalls[$beforeAliasCallCount]);
T::is('the worker returns only cPanel-reported primary-domain-scoped alias names',
    $fakePanel->domainAliases['acctuser'], $aliasJobResult['result']['aliases']);
T::is('an empty or partial alias result remains explicitly vendor-reported', 'vendor_reported',
    $aliasJobResult['result']['completeness']);
T::ok('the worker audits the confirmed low-level UAPI alias operation',
    Db::first('audit_logs', ['action' => 'CPANEL_UAPI_ALIASES_CONFIRMED']) !== null);
T::is('the alias result is available to staff with panel-view permission', 200,
    $staffApi->dispatch('GET', '/v1/jobs/' . $aliasJobId)['status']);
T::is('the customer job route does not expose built-in alias inventory', 404,
    $customerApi->dispatch('GET', '/v1/jobs/' . $aliasJobId)['status']);
T::is('built-in alias inventory records an audited history event', 1,
    Db::count('panel_account_events', ['panel_account_id' => $accountId, 'event' => 'domain_aliases_listed']));
T::notContains('alias values are not duplicated into lifecycle history', 'www.hosting.example.test',
    (string) Db::first('panel_account_events', [
        'panel_account_id' => $accountId, 'event' => 'domain_aliases_listed',
    ])['metadata']);
$aliasAudit = Db::first('audit_logs', ['action' => Audit::PANEL_ACCOUNT_ALIASES_LISTED]);
T::ok('built-in alias inventory uses a dedicated audit action', is_array($aliasAudit));
T::notContains('audit metadata records no alias list', 'www.hosting.example.test',
    (string) ($aliasAudit['metadata'] ?? ''));
T::is('built-in alias inventory emits a read-specific event', 1,
    count(Events::emitted('panel_account.domain_aliases_listed')));
$_GET = ['account_id' => $accountId, 'job_id' => $aliasJobId];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$staffAliasPage = ob_get_clean();
T::contains('staff admin page displays reported alias values', 'www.hosting.example.test', $staffAliasPage);
T::contains('staff admin page disclaims complete DNS inventory', 'not a complete DNS or domain-ownership inventory', $staffAliasPage);
T::contains('staff admin page offers the gated alias read action', 'Queue built-in alias read', $staffAliasPage);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$uiAliasKey = \Ch247Apps\Core\Str::uuid4();
$_POST = [
    'panel_account_action' => 'aliases_list',
    'panel_account_id' => (string) $accountId,
    'idempotency_key' => $uiAliasKey,
    'ch247_token' => \Ch247Apps\Core\Csrf::token(),
];
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$queuedAliasesFromAdminPage = ob_get_clean();
T::contains('admin page can queue the asynchronous built-in alias read', 'built-in alias inventory was queued',
    $queuedAliasesFromAdminPage);
T::ok('admin page queues the dedicated aliases job',
    Db::first('jobs', ['panel_account_id' => $accountId, 'job_type' => JobQueue::TYPE_PANEL_ACCOUNT_ALIASES,
        'status' => JobQueue::STATUS_QUEUED]) !== null);
$uiAliasJobResult = workflowJob($queue, $panelWorker, 'Admin UI alias inventory');
T::is('admin UI alias job completes through the existing panel worker', 'completed', $uiAliasJobResult['status']);

section('Phase 7 quota snapshots are staff-only, queued, account-scoped and separately gated');
$quotaUsagePath = '/v1/panel-accounts/' . $accountId . '/quota-usage';
$quotaUsageDisabled = $staffApi->dispatch('POST', $quotaUsagePath, [], [
    'Idempotency-Key' => 'quota-usage-disabled-http-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('staff may reach the route but the independent quota switch blocks it', 409, $quotaUsageDisabled['status']);
T::is('disabled quota snapshots have a dedicated error code', 'CPANEL_UAPI_QUOTA_USAGE_DISABLED',
    $quotaUsageDisabled['body']['error']['code']);
$customerQuotaUsage = $customerApi->dispatch('POST', $quotaUsagePath, [], [
    'Idempotency-Key' => 'customer-quota-usage-forbidden-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('customers cannot request quota usage', 403, $customerQuotaUsage['status']);
$quotaBodyRejected = $staffApi->dispatch('POST', $quotaUsagePath, ['username' => 'otheruser'], [
    'Idempotency-Key' => 'quota-usage-body-rejected-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('the account-scoped quota request rejects caller-supplied fields', 422, $quotaBodyRejected['status']);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$quotaDisabledPage = ob_get_clean();
T::contains('staff page explains the quota snapshot gate', 'cPanel UAPI quota-usage snapshots are disabled', $quotaDisabledPage);
T::notContains('disabled quota feature hides its queue action', 'Queue quota-usage snapshot', $quotaDisabledPage);

Settings::override('cpanel_uapi_quota_usage_enabled', '1');
$quotaWorkerGateRequest = $staffApi->dispatch('POST', $quotaUsagePath, [], [
    'Idempotency-Key' => 'quota-usage-worker-gate-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('quota snapshot is queued before worker-time gate review', 202, $quotaWorkerGateRequest['status']);
$beforeQuotaGateCalls = count($fakePanel->quotaUsageCalls);
Settings::override('cpanel_uapi_quota_usage_enabled', '0');
$quotaWorkerGateJobs = $queue->lease('quota-usage-gate-worker', JobQueue::QUEUE_CONTROL_PANEL, 1);
$quotaWorkerGateResult = $panelWorker->runJob($quotaWorkerGateJobs[0]);
T::is('worker rechecks the quota feature gate before external access', 'failed', $quotaWorkerGateResult['status']);
T::is('disabled-at-execution quota jobs have the dedicated error code', 'CPANEL_UAPI_QUOTA_USAGE_DISABLED',
    $quotaWorkerGateResult['error']['code']);
T::is('a disabled-at-execution quota job makes no UAPI request', $beforeQuotaGateCalls,
    count($fakePanel->quotaUsageCalls));

Settings::override('cpanel_uapi_quota_usage_enabled', '1');
$fakePanel->quotaUsages['acctuser'] = [
    'megabyte_limit' => '0', 'megabytes_remain' => '0', 'megabytes_used' => '3.25',
    'inode_limit' => '0', 'inodes_remain' => '0', 'inodes_used' => '4',
    'under_inode_limit' => true, 'under_megabyte_limit' => true, 'under_quota_overall' => true,
];
$beforeQuotaCallCount = count($fakePanel->quotaUsageCalls);
$quotaUsageRequest = $staffApi->dispatch('POST', $quotaUsagePath, [], [
    'Idempotency-Key' => 'quota-usage-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only staff can queue a quota snapshot', 202, $quotaUsageRequest['status']);
T::is('quota snapshot uses its dedicated cPanel worker job', JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE,
    $quotaUsageRequest['body']['data']['job']['job_type']);
T::is('the HTTP request does not call cPanel UAPI synchronously', $beforeQuotaCallCount,
    count($fakePanel->quotaUsageCalls));
$quotaUsageReplay = $staffApi->dispatch('POST', $quotaUsagePath, [], [
    'Idempotency-Key' => 'quota-usage-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('quota request replay returns the same job', (int) $quotaUsageRequest['body']['data']['job']['id'],
    (int) $quotaUsageReplay['body']['data']['job']['id']);
$quotaJobId = (int) $quotaUsageRequest['body']['data']['job']['id'];
$quotaJobResult = workflowJob($queue, $panelWorker, 'Quota usage snapshot');
T::is('worker sends only the linked username and main-domain binding', ['acctuser', 'hosting.example.test'],
    $fakePanel->quotaUsageCalls[$beforeQuotaCallCount]);
T::is('quota metrics preserve the vendor-reported zero and decimal values', $fakePanel->quotaUsages['acctuser'],
    $quotaJobResult['result']['usage']);
T::is('quota snapshot completeness stays vendor-reported', 'vendor_reported',
    $quotaJobResult['result']['completeness']);
T::is('quota snapshot is staff-readable through the job route', 200,
    $staffApi->dispatch('GET', '/v1/jobs/' . $quotaJobId)['status']);
$quotaReadJob = $staffApi->dispatch('GET', '/v1/jobs/' . $quotaJobId);
T::is('staff job projection includes normalized quota values', $fakePanel->quotaUsages['acctuser'],
    $quotaReadJob['body']['data']['result']['usage']);
T::is('customers cannot read the staff quota snapshot job', 404,
    $customerApi->dispatch('GET', '/v1/jobs/' . $quotaJobId)['status']);
T::is('quota snapshot records a dedicated history event', 1,
    Db::count('panel_account_events', ['panel_account_id' => $accountId, 'event' => 'quota_usage_snapshot_read']));
$quotaHistory = Db::first('panel_account_events', [
    'panel_account_id' => $accountId, 'event' => 'quota_usage_snapshot_read',
]);
T::notContains('quota values are not duplicated into lifecycle history', '3.25',
    (string) ($quotaHistory['metadata'] ?? ''));
$quotaAudit = Db::first('audit_logs', ['action' => Audit::PANEL_ACCOUNT_QUOTA_USAGE_READ]);
T::ok('quota snapshots use a dedicated audit action', is_array($quotaAudit));
T::notContains('quota values are not copied into audit metadata', '3.25',
    (string) ($quotaAudit['metadata'] ?? ''));
T::ok('the worker audits the low-level fixed UAPI operation',
    Db::first('audit_logs', ['action' => 'CPANEL_UAPI_QUOTA_USAGE_CONFIRMED']) !== null);
T::is('quota snapshot emits a read-specific event', 1,
    count(Events::emitted('panel_account.quota_usage_read')));
$_GET = ['account_id' => $accountId, 'job_id' => $quotaJobId];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$staffQuotaPage = ob_get_clean();
T::contains('staff page displays vendor-reported disk usage', 'Disk used (MB)', $staffQuotaPage);
T::contains('staff page preserves the zero quota value', '<code>0</code>', $staffQuotaPage);
T::contains('staff page disclaims unlimited-or-disabled inference from zero',
    'No quota state is inferred from zero', $staffQuotaPage);
T::contains('staff page offers the separately gated quota snapshot action', 'Queue quota-usage snapshot', $staffQuotaPage);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$uiQuotaKey = \Ch247Apps\Core\Str::uuid4();
$_POST = [
    'panel_account_action' => 'quota_usage',
    'panel_account_id' => (string) $accountId,
    'idempotency_key' => $uiQuotaKey,
    'ch247_token' => \Ch247Apps\Core\Csrf::token(),
];
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$queuedQuotaFromAdminPage = ob_get_clean();
T::contains('admin page queues the asynchronous quota snapshot', 'quota-usage snapshot was queued',
    $queuedQuotaFromAdminPage);
T::ok('admin page enqueues the dedicated quota job',
    Db::first('jobs', ['panel_account_id' => $accountId, 'job_type' => JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE,
        'status' => JobQueue::STATUS_QUEUED]) !== null);
$uiQuotaJobResult = workflowJob($queue, $panelWorker, 'Admin UI quota snapshot');
T::is('admin UI quota job completes using the existing panel worker', 'completed', $uiQuotaJobResult['status']);
$_POST = $savedPost;

section('Phase 8 bandwidth snapshots are staff-only, queued, fixed-scope and separately gated');
$bandwidthUsagePath = '/v1/panel-accounts/' . $accountId . '/bandwidth-usage';
$bandwidthUsageDisabled = $staffApi->dispatch('POST', $bandwidthUsagePath, [], [
    'Idempotency-Key' => 'bandwidth-usage-disabled-http-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('staff can reach the bandwidth route but its independent switch blocks it', 409,
    $bandwidthUsageDisabled['status']);
T::is('disabled bandwidth snapshots have a dedicated error code', 'CPANEL_UAPI_BANDWIDTH_USAGE_DISABLED',
    $bandwidthUsageDisabled['body']['error']['code']);
$customerBandwidthUsage = $customerApi->dispatch('POST', $bandwidthUsagePath, [], [
    'Idempotency-Key' => 'customer-bandwidth-usage-forbidden-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('customers cannot request bandwidth snapshots', 403, $customerBandwidthUsage['status']);
$bandwidthBodyRejected = $staffApi->dispatch('POST', $bandwidthUsagePath, ['display' => 'all'], [
    'Idempotency-Key' => 'bandwidth-usage-body-rejected-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('the account-scoped bandwidth request rejects caller-supplied display choices', 422,
    $bandwidthBodyRejected['status']);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$bandwidthDisabledPage = ob_get_clean();
T::contains('staff page explains the disabled bandwidth snapshot gate',
    'cPanel bandwidth-usage snapshots are disabled', $bandwidthDisabledPage);
T::notContains('disabled bandwidth snapshots hide the queue action', 'Queue bandwidth snapshot',
    $bandwidthDisabledPage);

Settings::override('cpanel_uapi_bandwidth_usage_enabled', '1');
$bandwidthWorkerGateRequest = $staffApi->dispatch('POST', $bandwidthUsagePath, [], [
    'Idempotency-Key' => 'bandwidth-usage-worker-gate-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('bandwidth snapshot is queued before worker-time gate review', 202,
    $bandwidthWorkerGateRequest['status']);
$beforeBandwidthGateCalls = count($fakePanel->bandwidthUsageCalls);
Settings::override('cpanel_uapi_bandwidth_usage_enabled', '0');
$bandwidthWorkerGateJobs = $queue->lease('bandwidth-gate-worker', JobQueue::QUEUE_CONTROL_PANEL, 1);
$bandwidthWorkerGateResult = $panelWorker->runJob($bandwidthWorkerGateJobs[0]);
T::is('worker rechecks the bandwidth feature gate before external access', 'failed',
    $bandwidthWorkerGateResult['status']);
T::is('disabled-at-execution bandwidth jobs have a dedicated error code',
    'CPANEL_UAPI_BANDWIDTH_USAGE_DISABLED', $bandwidthWorkerGateResult['error']['code']);
T::is('a disabled-at-execution bandwidth job makes no UAPI request', $beforeBandwidthGateCalls,
    count($fakePanel->bandwidthUsageCalls));

Settings::override('cpanel_uapi_bandwidth_usage_enabled', '1');
$fakePanel->bandwidthUsages['acctuser'] = [
    'used' => '0', 'limit' => '1000', 'percent' => 0, 'units' => 'MB',
    'zero_is_unlimited' => true, 'is_maxed' => false, 'normalized' => true,
];
$beforeBandwidthCallCount = count($fakePanel->bandwidthUsageCalls);
$bandwidthUsageRequest = $staffApi->dispatch('POST', $bandwidthUsagePath, [], [
    'Idempotency-Key' => 'bandwidth-usage-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('read-only staff can queue a bandwidth snapshot', 202, $bandwidthUsageRequest['status']);
T::is('bandwidth snapshots use the dedicated cPanel worker job', JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE,
    $bandwidthUsageRequest['body']['data']['job']['job_type']);
T::is('the HTTP request does not call StatsBar synchronously', $beforeBandwidthCallCount,
    count($fakePanel->bandwidthUsageCalls));
$bandwidthUsageReplay = $staffApi->dispatch('POST', $bandwidthUsagePath, [], [
    'Idempotency-Key' => 'bandwidth-usage-account-001',
    'X-CSRF-Token' => \Ch247Apps\Core\Csrf::token(),
]);
T::is('bandwidth request replay returns the same job',
    (int) $bandwidthUsageRequest['body']['data']['job']['id'],
    (int) $bandwidthUsageReplay['body']['data']['job']['id']);
$bandwidthJobId = (int) $bandwidthUsageRequest['body']['data']['job']['id'];
$bandwidthJobResult = workflowJob($queue, $panelWorker, 'Bandwidth usage snapshot');
T::is('worker sends only the linked username and primary domain', ['acctuser', 'hosting.example.test'],
    $fakePanel->bandwidthUsageCalls[$beforeBandwidthCallCount]);
T::is('bandwidth snapshot preserves cPanel-reported field values without calculation',
    $fakePanel->bandwidthUsages['acctuser'], $bandwidthJobResult['result']['usage']);
T::is('bandwidth snapshot completeness remains vendor-reported', 'vendor_reported',
    $bandwidthJobResult['result']['completeness']);
T::is('staff can read the queued bandwidth result', 200,
    $staffApi->dispatch('GET', '/v1/jobs/' . $bandwidthJobId)['status']);
$bandwidthReadJob = $staffApi->dispatch('GET', '/v1/jobs/' . $bandwidthJobId);
T::is('staff job projection contains only normalized bandwidth fields',
    $fakePanel->bandwidthUsages['acctuser'], $bandwidthReadJob['body']['data']['result']['usage']);
T::is('customers cannot read the staff bandwidth snapshot job', 404,
    $customerApi->dispatch('GET', '/v1/jobs/' . $bandwidthJobId)['status']);
T::is('bandwidth snapshot records a dedicated history event', 1,
    Db::count('panel_account_events', ['panel_account_id' => $accountId,
        'event' => 'bandwidth_usage_snapshot_read']));
$bandwidthHistory = Db::first('panel_account_events', [
    'panel_account_id' => $accountId, 'event' => 'bandwidth_usage_snapshot_read',
]);
T::notContains('bandwidth values are not duplicated into lifecycle history', '1000',
    (string) ($bandwidthHistory['metadata'] ?? ''));
$bandwidthAudit = Db::first('audit_logs', ['action' => Audit::PANEL_ACCOUNT_BANDWIDTH_USAGE_READ]);
T::ok('bandwidth snapshots use a dedicated audit action', is_array($bandwidthAudit));
T::notContains('bandwidth values are not copied into audit metadata', '1000',
    (string) ($bandwidthAudit['metadata'] ?? ''));
T::ok('the worker audits the fixed StatsBar UAPI operation',
    Db::first('audit_logs', ['action' => 'CPANEL_UAPI_BANDWIDTH_USAGE_CONFIRMED']) !== null);
T::is('bandwidth snapshot emits a read-specific event', 1,
    count(Events::emitted('panel_account.bandwidth_usage_read')));
section('Phases 9 and 10 staff-only retained usage history and summary');
$_GET = ['account_id' => $accountId, 'job_id' => $bandwidthJobId];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$staffBandwidthPage = ob_get_clean();
T::contains('staff page includes the latest retained snapshot overview',
    'Latest retained usage snapshots', $staffBandwidthPage);
$overviewOffset = strpos($staffBandwidthPage, '<h3>Latest retained usage snapshots</h3>');
$overviewTable = $overviewOffset === false ? '' : substr($staffBandwidthPage, $overviewOffset);
$overviewTableEnd = strpos($overviewTable, '</table>');
if ($overviewTableEnd !== false) {
    $overviewTable = substr($overviewTable, 0, $overviewTableEnd + strlen('</table>'));
}
T::contains('overview shows validated quota metrics', 'Disk used (MB): <code>3.25</code>', $overviewTable);
T::contains('overview shows validated bandwidth metrics',
    'Bandwidth used (as reported): <code>0</code>', $overviewTable);
T::contains('overview links to the existing validated result view', 'job_id=' . $bandwidthJobId,
    $overviewTable);
T::contains('overview link carries the linked panel-account scope', 'account_id=' . $accountId, $overviewTable);
T::contains('overview disclaims live monitoring',
    'not a live reading or continuous monitoring', $overviewTable);
T::contains('staff page includes retained snapshot-job history', 'Recent usage snapshot jobs', $staffBandwidthPage);
T::contains('snapshot history distinguishes bandwidth snapshots', 'Bandwidth usage', $staffBandwidthPage);
T::contains('snapshot history links to the validated scoped job view', 'job_id=' . $bandwidthJobId, $staffBandwidthPage);
$historyOffset = strpos($staffBandwidthPage, '<h3>Recent usage snapshot jobs</h3>');
$historyTable = $historyOffset === false ? '' : substr($staffBandwidthPage, $historyOffset);
T::notContains('history table lists references, not raw snapshot values', '1000', $historyTable);
T::contains('history distinguishes quota snapshot references', 'Disk/inode quota', $historyTable);
T::contains('staff page displays projected bandwidth usage', 'Bandwidth used (as reported)', $staffBandwidthPage);
T::contains('staff page preserves the cPanel zero bandwidth value', '<code>0</code>', $staffBandwidthPage);
T::contains('staff page displays the allowlisted zero semantics flag verbatim',
    'Zero-is-unlimited flag (as reported)</td><td><code>true</code>', $staffBandwidthPage);
T::contains('staff page explicitly says bandwidth snapshots are not monitoring',
    'not continuous monitoring', $staffBandwidthPage);
T::contains('staff page offers the separately gated bandwidth snapshot action',
    'Queue bandwidth snapshot', $staffBandwidthPage);
$historyAccount = Db::first('panel_accounts', ['id' => $accountId]);
$historySeedIds = [];
for ($historyIndex = 0; $historyIndex < 55; $historyIndex++) {
    $historyType = $historyIndex % 2 === 0
        ? JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE : JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE;
    $historyJob = $queue->enqueue($historyType, [], [
        'queue' => JobQueue::QUEUE_CONTROL_PANEL,
        'idempotency_key' => 'usage-history-limit-' . $historyIndex,
        'panel_account_id' => $accountId,
        'client_id' => (int) $historyAccount['client_id'],
        'whmcs_service_id' => (int) $historyAccount['whmcs_service_id'],
    ]);
    Db::update('jobs', ['status' => JobQueue::STATUS_FAILED, 'error_code' => 'TEST_HISTORY_SEED'], [
        'id' => (int) $historyJob['id'],
    ]);
    $historySeedIds[] = (int) $historyJob['id'];
}
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$boundedHistoryPage = ob_get_clean();
$boundedHistoryOffset = strpos($boundedHistoryPage, '<h3>Recent usage snapshot jobs</h3>');
$boundedHistoryTable = $boundedHistoryOffset === false ? '' : substr($boundedHistoryPage, $boundedHistoryOffset);
$boundedHistoryTableEnd = strpos($boundedHistoryTable, '</table>');
if ($boundedHistoryTableEnd !== false) {
    $boundedHistoryTable = substr($boundedHistoryTable, 0, $boundedHistoryTableEnd + strlen('</table>'));
}
T::is('retained usage-snapshot history is capped at 50 rows', 50,
    preg_match_all('/<tr><td><strong>/', $boundedHistoryTable));
T::contains('bounded history includes the newest retained job', 'job_id=' . $historySeedIds[54] . '"', $boundedHistoryTable);
T::notContains('bounded history excludes older rows beyond the 50-job window',
    'job_id=' . $historySeedIds[0] . '"', $boundedHistoryTable);
T::contains('bounded history retains both quota and bandwidth job labels', 'Disk/inode quota', $boundedHistoryTable);
T::contains('bounded history retains the bandwidth job label', 'Bandwidth usage', $boundedHistoryTable);
$invalidOverviewJob = $queue->enqueue(JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE, [], [
    'queue' => JobQueue::QUEUE_CONTROL_PANEL,
    'idempotency_key' => 'usage-overview-invalid-result-001',
    'panel_account_id' => $accountId,
    'client_id' => (int) $historyAccount['client_id'],
    'whmcs_service_id' => (int) $historyAccount['whmcs_service_id'],
]);
Db::update('jobs', [
    'status' => JobQueue::STATUS_COMPLETED,
    'result' => json_encode(['untrusted_value' => 'RAW_USAGE_SECRET_842']),
], ['id' => (int) $invalidOverviewJob['id']]);
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$invalidOverviewPage = ob_get_clean();
$invalidOverviewOffset = strpos($invalidOverviewPage, '<h3>Latest retained usage snapshots</h3>');
$invalidOverviewTable = $invalidOverviewOffset === false ? '' : substr($invalidOverviewPage, $invalidOverviewOffset);
$invalidOverviewTableEnd = strpos($invalidOverviewTable, '</table>');
if ($invalidOverviewTableEnd !== false) {
    $invalidOverviewTable = substr($invalidOverviewTable, 0, $invalidOverviewTableEnd + strlen('</table>'));
}
T::contains('latest malformed completed snapshot is marked unavailable',
    'Result unavailable: it did not pass snapshot validation.', $invalidOverviewTable);
T::notContains('overview never displays an unvalidated result value', 'RAW_USAGE_SECRET_842', $invalidOverviewPage);
T::notContains('overview does not fall back to an older quota value after newer malformed data',
    'Disk used (MB)', $invalidOverviewTable);
$overviewSeedDomains = [];
for ($overviewIndex = 0; $overviewIndex < 51; $overviewIndex++) {
    $overviewDomain = sprintf('history-%02d.example.test', $overviewIndex);
    $overviewUsername = sprintf('bound%03d', $overviewIndex);
    $overviewClientId = 10000 + $overviewIndex;
    $overviewServiceId = 20000 + $overviewIndex;
    $overviewAccountId = Db::insert('panel_accounts', [
        'uuid' => \Ch247Apps\Core\Str::uuid4(),
        'client_id' => $overviewClientId,
        'whmcs_service_id' => $overviewServiceId,
        'server_id' => $serverId,
        'panel_key' => PanelAccountService::PANEL_KEY,
        'username' => $overviewUsername,
        'domain' => $overviewDomain,
        'package' => 'basic',
        'status' => PanelAccountService::STATUS_ACTIVE,
        'created_at' => \Ch247Apps\Core\Clock::now(),
        'updated_at' => \Ch247Apps\Core\Clock::now(),
    ]);
    $overviewJob = $queue->enqueue(JobQueue::TYPE_PANEL_ACCOUNT_QUOTA_USAGE, [], [
        'queue' => JobQueue::QUEUE_CONTROL_PANEL,
        'idempotency_key' => 'overview-bound-' . $overviewIndex,
        'panel_account_id' => $overviewAccountId,
        'client_id' => $overviewClientId,
        'whmcs_service_id' => $overviewServiceId,
    ]);
    Db::update('jobs', ['status' => JobQueue::STATUS_COMPLETED, 'result' => '{}'], [
        'id' => (int) $overviewJob['id'],
    ]);
    $overviewSeedDomains[] = $overviewDomain;
}
$_GET = [];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$boundedOverviewPage = ob_get_clean();
$boundedOverviewOffset = strpos($boundedOverviewPage, '<h3>Latest retained usage snapshots</h3>');
$boundedOverviewTable = $boundedOverviewOffset === false ? '' : substr($boundedOverviewPage, $boundedOverviewOffset);
$boundedOverviewTableEnd = strpos($boundedOverviewTable, '</table>');
if ($boundedOverviewTableEnd !== false) {
    $boundedOverviewTable = substr($boundedOverviewTable, 0, $boundedOverviewTableEnd + strlen('</table>'));
}
T::is('snapshot overview displays at most 50 linked accounts', 50,
    preg_match_all('/<tr><td><strong>/', $boundedOverviewTable));
T::contains('overview keeps the newest account within the bound', $overviewSeedDomains[50], $boundedOverviewTable);
T::notContains('overview excludes accounts older than its display bound',
    $overviewSeedDomains[0], $boundedOverviewTable);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$uiBandwidthKey = \Ch247Apps\Core\Str::uuid4();
$_POST = [
    'panel_account_action' => 'bandwidth_usage',
    'panel_account_id' => (string) $accountId,
    'idempotency_key' => $uiBandwidthKey,
    'ch247_token' => \Ch247Apps\Core\Csrf::token(),
];
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$queuedBandwidthFromAdminPage = ob_get_clean();
T::contains('admin page queues the asynchronous bandwidth snapshot', 'bandwidth-usage snapshot was queued',
    $queuedBandwidthFromAdminPage);
T::ok('admin page enqueues the dedicated bandwidth job',
    Db::first('jobs', ['panel_account_id' => $accountId,
        'job_type' => JobQueue::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE,
        'status' => JobQueue::STATUS_QUEUED]) !== null);
$uiBandwidthJobResult = workflowJob($queue, $panelWorker, 'Admin UI bandwidth snapshot');
T::is('admin UI bandwidth job completes through the existing panel worker', 'completed',
    $uiBandwidthJobResult['status']);
Settings::override('cpanel_uapi_bandwidth_usage_enabled', '0');
$_POST = $savedPost;
Identity::override(Actor::customer(77));
ob_start();
(new PanelAccountAdmin(['modulelink' => 'addonmodules.php?module=cloudhost247apps']))->render();
$customerPanelAccountPage = ob_get_clean();
T::notContains('customer cannot use the staff domain-inventory page', 'addon.example.test', $customerPanelAccountPage);
T::notContains('customer cannot view retained usage snapshot summaries', 'Latest retained usage snapshots',
    $customerPanelAccountPage);
T::notContains('customer cannot view retained usage-snapshot history', 'Recent usage snapshot jobs',
    $customerPanelAccountPage);
T::contains('unauthorized admin page returns a permission message', 'do not have permission', $customerPanelAccountPage);
Identity::reset();
$_GET = $savedGet;
$_POST = $savedPost;
if ($savedRequestMethod === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $savedRequestMethod;
}

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
