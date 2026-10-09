<?php
/** Suite 19: official Vultr v2 shapes exercised via a fake HTTP transport. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\Providers\VultrAdapter;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;

class FakeVultrApi
{
    const SSH = '3b8066a7-b438-455a-9688-44afc9a3597f';
    const ID1 = '4f0f12e5-1f84-404f-aa84-85f431ea5ec2';
    const ID2 = 'cb676a46-66fd-4dfb-b839-443f2e6c0b60';
    const ID3 = 'be35de98-b970-46c2-ad89-36aef5fb4b20';
    public $calls = [];
    public $instances = [];
    public $planPages = [];
    public $tagPages = [];
    public $override = null;
    public $ready = false;
    public $available = ['vc2-2c-2gb'];
    public $plan = ['id' => 'vc2-2c-2gb', 'type' => 'vc2', 'vcpu_count' => 2,
        'ram' => 2048, 'disk' => 55, 'disk_count' => 1, 'locations' => ['ewr']];
    private $ids = [self::ID1, self::ID2, self::ID3];
    private $created = 0;

    public function callsTo($method, $path)
    {
        return array_values(array_filter($this->calls, function ($c) use ($method, $path) {
            return $c[0] === $method && parse_url($c[1], PHP_URL_PATH) === $path;
        }));
    }

    private function json($data, $status = 200)
    { return ['status' => $status, 'headers' => [], 'body' => json_encode($data), 'error' => null]; }

    private function listPage($name, array $values)
    { return [$name => $values, 'meta' => ['total' => count($values), 'links' => ['next' => '', 'prev' => '']]]; }

    public function handle($method, $url, $options)
    {
        $this->calls[] = [$method, $url, $options];
        if ($this->override !== null) {
            $override = $this->override;
            $this->override = null;
            return $override;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if ($method === 'GET' && $path === '/v2/account') {
            return $this->json(['account' => ['name' => 'test', 'acls' => ['provisioning', 'subscriptions']]]);
        }
        if ($method === 'GET' && $path === '/v2/ssh-keys/' . self::SSH) {
            return $this->json(['ssh_key' => ['id' => self::SSH, 'ssh_key' => 'ssh-ed25519 test-public-key']]);
        }
        if ($method === 'GET' && $path === '/v2/plans') {
            $cursor = isset($query['cursor']) ? $query['cursor'] : '';
            if ($this->planPages) {
                return $this->json($this->planPages[$cursor === '' ? 0 : 1]);
            }
            return $this->json($this->listPage('plans', [$this->plan]));
        }
        if ($method === 'GET' && $path === '/v2/regions/ewr/availability') {
            return $this->json(['available_plans' => $this->available]);
        }
        if ($method === 'GET' && $path === '/v2/os') {
            return $this->json($this->listPage('os', [
                ['id' => 215, 'name' => 'Ubuntu 22.04', 'family' => 'ubuntu', 'arch' => 'x64'],
            ]));
        }
        if ($method === 'GET' && $path === '/v2/instances') {
            if ($this->tagPages) {
                return $this->json($this->tagPages[isset($query['cursor']) ? 1 : 0]);
            }
            $instances = array_values(array_filter($this->instances, function ($instance) use ($query) {
                return in_array($query['tag'], $instance['tags'], true);
            }));
            return $this->json($this->listPage('instances', $instances));
        }
        if ($method === 'POST' && $path === '/v2/instances') {
            $body = $options['json'];
            $id = $this->ids[$this->created++];
            $instance = [
                'id' => $id, 'plan' => $body['plan'], 'region' => $body['region'],
                'os_id' => $body['os_id'], 'hostname' => $body['hostname'],
                'label' => $body['label'], 'tags' => $body['tags'], 'status' => 'pending',
                'main_ip' => '0.0.0.0', 'v6_main_ip' => '',
                'power_status' => 'running', 'server_status' => 'none',
                'default_password' => 'SECRET-FROM-VULTR', 'internal_ip' => '10.1.1.1',
            ];
            $this->instances[$id] = $instance;
            return $this->json(['instance' => $instance], 201);
        }
        if (preg_match('#^/v2/instances/([0-9a-f-]{36})$#', $path, $m)) {
            $id = $m[1];
            if (!isset($this->instances[$id])) { return $this->json(['error' => 'Not Found'], 404); }
            if ($method === 'DELETE') {
                unset($this->instances[$id]);
                return ['status' => 204, 'body' => '', 'headers' => [], 'error' => null];
            }
            if ($method === 'GET') {
                if ($this->ready) {
                    $this->instances[$id]['status'] = 'active';
                    $this->instances[$id]['main_ip'] = '203.0.113.81';
                    $this->instances[$id]['server_status'] = 'ok';
                }
                return $this->json(['instance' => $this->instances[$id]]);
            }
        }
        if ($method === 'POST' && preg_match('#^/v2/instances/[0-9a-f-]{36}/(start|halt|reboot)$#', $path)) {
            return ['status' => 204, 'body' => '', 'headers' => [], 'error' => null];
        }
        return ['status' => 599, 'body' => '', 'headers' => [], 'error' => 'unexpected fake route'];
    }
}

Harness::boot();
$api = new FakeVultrApi();
Http::setClientFake([$api, 'handle']);
ProviderRegistry::reset();
ProviderBootstrap::reset();
ProviderBootstrap::boot();
$adapter = new VultrAdapter();
$credentials = ['api_token' => 'vultr-secret-test-token'];
$config = ['plan_id' => 'vc2-2c-2gb', 'ssh_key_id' => FakeVultrApi::SSH];
$spec = ['name' => 'vm-service-81', 'region' => 'ewr', 'image' => '215',
    'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 55];
$key = 'customer-server:create:81';

section('Registry, credentials, supported capabilities and abstention');
T::ok('reviewed Vultr adapter is registered', ProviderRegistry::hasAdapter('vultr'));
T::ok('basic lifecycle advertised', $adapter->capabilities()['server.create']
    && $adapter->capabilities()['server.delete'] && $adapter->capabilities()['server.reboot']);
T::ok('unsupported operations are not advertised', !$adapter->capabilities()['server.resize']
    && !$adapter->capabilities()['server.rebuild'] && !$adapter->capabilities()['snapshot.create']
    && !$adapter->capabilities()['server.metrics']);
T::throws('resize fails without traffic', ProviderUnavailableException::class,
    function () use ($adapter, $credentials, $config, $spec, $key) {
        $adapter->resizeServer($credentials, $config, FakeVultrApi::ID1, $spec, $key);
    });
T::is('account and SSH key verification', true, $adapter->verifyCredentials($credentials, $config));
T::throws('token is mandatory', ConfigurationException::class,
    function () use ($adapter, $config) { $adapter->verifyCredentials([], $config); });
T::throws('account SSH key UUID is mandatory', ConfigurationException::class,
    function () use ($adapter, $credentials) { $adapter->verifyCredentials($credentials, ['plan_id' => 'vc2-2c-2gb']); });
$api->override = ['status' => 401, 'body' => '', 'headers' => [], 'error' => null];
T::throws('401 is authentication failure', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) { $adapter->verifyCredentials($credentials, $config); });
$api->override = ['status' => 503, 'body' => '', 'headers' => [], 'error' => null];
T::throws('503 is retryable during verification', RetryableProviderException::class,
    function () use ($adapter, $credentials, $config) { $adapter->verifyCredentials($credentials, $config); });

section('Provider-sourced plan, OS, region, SSH key, secret-free 201 create and replay');
$created = T::nothrow('201 create accepted', function () use ($adapter, $credentials, $config, $spec, $key) {
    return $adapter->createServer($credentials, $config, $spec, $key);
});
T::is('instance UUID returned', FakeVultrApi::ID1, $created['id']);
T::is('pending not ready', 'initializing', $created['status']);
T::is('placeholder IPv4 is null', null, $created['ipv4']);
T::notContains('raw response password never reaches resource', 'SECRET-FROM-VULTR', json_encode($created));
$posts = $api->callsTo('POST', '/v2/instances');
T::is('only one billable POST', 1, count($posts));
$body = $posts[0][2]['json'];
T::is('provider plan ID', 'vc2-2c-2gb', $body['plan']);
T::is('mapped OS ID integer', 215, $body['os_id']);
T::is('registered SSH key', [FakeVultrApi::SSH], $body['sshkey_id']);
T::is('hashed tag array', ['ch247-' . substr(hash('sha256', $key), 0, 32)], $body['tags']);
T::is('disable extra-charge backups', 'disabled', $body['backups']);
T::is('disable extra-charge DDOS', false, $body['ddos_protection']);
T::is('TLS verified', true, $posts[0][2]['verify_tls']);
T::is('fixed HTTPS endpoint', 'https://api.vultr.com/v2/instances', $posts[0][1]);
T::is('tag replay gets same instance', FakeVultrApi::ID1,
    $adapter->createServer($credentials, $config, $spec, $key)['id']);
T::is('tag replay never re-POSTs', 1, count($api->callsTo('POST', '/v2/instances')));
T::notContains('HTTP traces never contain provider password', 'SECRET-FROM-VULTR', json_encode(Http::clientCalls()));
T::notContains('HTTP traces never contain bearer token', $credentials['api_token'], json_encode(Http::clientCalls()));

section('Read/status, 204 actions and idempotent deletion');
$api->instances[FakeVultrApi::ID1]['status'] = 'active';
$api->instances[FakeVultrApi::ID1]['server_status'] = 'none';
T::is('active but not healthy is not ready', 'initializing',
    $adapter->getServer($credentials, $config, FakeVultrApi::ID1)['status']);
$api->ready = true;
$read = $adapter->getServer($credentials, $config, FakeVultrApi::ID1);
T::is('healthy running is active', 'active', $read['status']);
T::is('provider IPv4', '203.0.113.81', $read['ipv4']);
foreach (['rebootServer' => 'reboot', 'powerOnServer' => 'start', 'powerOffServer' => 'halt'] as $method => $action) {
    $result = $adapter->$method($credentials, $config, FakeVultrApi::ID1, $key);
    T::is($action . ' 204 is confirmed', true, $result['confirmed']);
    T::is($action . ' has no fake operation ID', null, $result['operation_id']);
    T::is($action . ' endpoint called', 1, count($api->callsTo('POST', '/v2/instances/'
        . FakeVultrApi::ID1 . '/' . $action)));
}
T::is('204 delete', 'deleted', $adapter->deleteServer($credentials, $config, FakeVultrApi::ID1, $key)['status']);
T::is('404 deletion replay', 'deleted', $adapter->deleteServer($credentials, $config, FakeVultrApi::ID1, $key)['status']);
T::is('404 read', 'deleted', $adapter->getServer($credentials, $config, FakeVultrApi::ID1)['status']);

section('Cursor exhaustion, mismatched resource, plan/OS rejection and no unsafe POST');
$api->planPages = [
    ['plans' => [], 'meta' => ['links' => ['next' => 'CursorNext', 'prev' => '']]],
    ['plans' => [$api->plan], 'meta' => ['links' => ['next' => '', 'prev' => '']]],
];
T::is('plan on second cursor page', FakeVultrApi::ID2,
    $adapter->createServer($credentials, $config, $spec, 'key-two')['id']);
$tag = 'ch247-' . substr(hash('sha256', 'key-two'), 0, 32);
$api->tagPages = [
    ['instances' => [], 'meta' => ['links' => ['next' => 'NextCursor', 'prev' => '']]],
    ['instances' => [$api->instances[FakeVultrApi::ID2]],
        'meta' => ['links' => ['next' => '', 'prev' => '']]],
];
T::is('tag found on later cursor page', FakeVultrApi::ID2,
    $adapter->createServer($credentials, $config, $spec, 'key-two')['id']);
T::is('cursor replay made no POST', 2, count($api->callsTo('POST', '/v2/instances')));
$api->tagPages = [
    ['instances' => [$api->instances[FakeVultrApi::ID2], $api->instances[FakeVultrApi::ID2]],
        'meta' => ['links' => ['next' => '', 'prev' => '']]],
];
T::throws('duplicate tagged instance fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'key-two');
    });
$wrong = $api->instances[FakeVultrApi::ID2];
$wrong['plan'] = 'vc2-24c-96gb';
$api->tagPages = [
    ['instances' => [$wrong], 'meta' => ['links' => ['next' => '', 'prev' => '']]],
];
T::throws('tagged wrong plan cannot attach to service', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'key-two');
    });
$api->tagPages = [
    ['instances' => [], 'meta' => ['links' => ['next' => 'https://evil.example', 'prev' => '']]],
];
T::throws('untrusted cursor fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'bad-cursor');
    });
$api->tagPages = [];
$api->planPages = [];
$wrongSpec = $spec;
$wrongSpec['memory_mb'] = 4096;
T::throws('size mismatch prevents create', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $wrongSpec) {
        $adapter->createServer($credentials, $config, $wrongSpec, 'wrong-size');
    });
$api->available = [];
T::throws('region availability blocks create', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'unavailable');
    });
$api->available = ['vc2-2c-2gb'];
$wrongOs = $spec;
$wrongOs['image'] = '999';
T::throws('missing OS ID prevents create', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $wrongOs) {
        $adapter->createServer($credentials, $config, $wrongOs, 'wrong-os');
    });
T::is('none of the rejected specs POSTed', 2, count($api->callsTo('POST', '/v2/instances')));
$api->tagPages = [
    ['instances' => [], 'meta' => ['links' => ['next' => 'SameCursor', 'prev' => '']]],
    ['instances' => [], 'meta' => ['links' => ['next' => 'SameCursor', 'prev' => '']]],
];
T::throws('cursor loop never implies an empty list', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'cursor-loop');
    });
$api->tagPages = [];
T::throws('invalid UUID cannot form provider URL', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '../other-account');
    });
$api->override = ['status' => 200, 'body' => json_encode([
    'instance' => ['id' => FakeVultrApi::ID1, 'status' => 'active',
        'power_status' => 'running', 'server_status' => 'ok', 'main_ip' => '203.0.113.22']]),
    'headers' => [], 'error' => null];
T::throws('wrong ID in GET response is not trusted', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, FakeVultrApi::ID2);
    });
$api->override = ['status' => 500, 'body' => '', 'headers' => [], 'error' => null];
$actionError = T::throws('ambiguous action fails terminally', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->rebootServer($credentials, $config, FakeVultrApi::ID2, 'action-uncertain');
    });
T::is('action uncertainty has reconciliation code', 'PROVIDER_ACTION_UNCERTAIN', $actionError->errorCode());
T::ok('ambiguous action cannot be auto-retried', !$actionError->isRetryable());


section('Lost create response is terminal; no blind automatic POST retry');
Http::setClientFake(function ($method, $url, $options) use ($api) {
    if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v2/instances') {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'timeout'];
    }
    return $api->handle($method, $url, $options);
});
$error = T::throws('ambiguous POST fails terminally', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'lost-response');
    });
T::ok('not retryable by worker', $error && !$error->isRetryable());
T::is('operator reconciliation code', 'PROVIDER_CREATE_UNCERTAIN', $error->errorCode());

section('End-to-end verification, paid service, async worker and readiness');
$api->ready = false;
Http::setClientFake([$api, 'handle']);
Clock::freeze('2026-10-09 12:00:00');
Settings::override('customer_server_provisioning_enabled', '1');
$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$accounts = new ProviderAccountService($admin);
$account = $accounts->create(['provider_code' => 'vultr', 'name' => 'Offline Vultr account',
    'region' => 'ewr', 'public_config' => $config, 'credentials' => $credentials]);
$accountId = (int) $account['id'];
$accounts->requestVerification($accountId, 'vultr-verify');
$queue = new JobQueue();
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    new ServerProvisioningWorker(Harness::systemActor(), $queue),
    new ProviderAccountVerifyWorker(Harness::systemActor(), $queue), $queue);
$verifyLease = $queue->lease('vultr-verifier', JobQueue::QUEUE_PROVISIONING, 1);
T::is('account verification worker completes', 'completed', $dispatcher->runJob($verifyLease[0])['status']);
T::is('verified account is active', 'active', $accounts->get($accountId)['status']);
$clientId = Harness::client();
$order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 841, 'domain' => '']);
Harness::$gateway->payInvoice((int) $order['invoice_id']);
$servers = new CustomerServerService($admin, Harness::$gateway, $accounts, $queue);
$e2eSpec = $spec;
$e2eSpec['hostname'] = 'vm-service-841.example.test';
$requested = $servers->requestProvision((int) $order['service_id'], $accountId, $e2eSpec,
    'vultr-worker-provision');
$localId = (int) $requested['server']['id'];
$createLease = $queue->lease('vultr-creator', JobQueue::QUEUE_PROVISIONING, 1);
$createResult = $dispatcher->runJob($createLease[0]);
T::is('create worker completes', 'completed', $createResult['status']);
$stored = Db::first('customer_servers', ['id' => $localId]);
T::is('provider UUID persisted', FakeVultrApi::ID3, $stored['provider_server_id']);
T::is('pending VM stays provisioning', CustomerServerService::STATUS_PROVISIONING, $stored['status']);
T::notContains('job payload never contains token', $credentials['api_token'],
    (string) Db::first('jobs', ['id' => (int) $createLease[0]['id']])['payload']);
T::notContains('job payload never contains provider password', 'SECRET-FROM-VULTR',
    (string) Db::first('jobs', ['id' => (int) $createLease[0]['id']])['payload']);
Clock::travel(15);
$pollLease = $queue->lease('vultr-poller', JobQueue::QUEUE_PROVISIONING, 1);
T::is('poll worker completes', 'completed', $dispatcher->runJob($pollLease[0])['status']);
T::is('unhealthy VM remains provisioning', CustomerServerService::STATUS_PROVISIONING,
    $servers->get($localId)['status']);
$api->ready = true;
Clock::travel(15);
$pollLease = $queue->lease('vultr-poller-two', JobQueue::QUEUE_PROVISIONING, 1);
$poll = $dispatcher->runJob($pollLease[0]);
T::is('second poll completes', 'completed', $poll['status']);
T::is('provider health and readiness activate VM', 'active', $poll['result']['customer_status']);
T::is('provider IP visible', '203.0.113.81', $servers->get($localId)['ipv4']);
T::notContains('HTTP traces exclude provider password', 'SECRET-FROM-VULTR', json_encode(Http::clientCalls()));

section('Ambiguous create fails the actual worker terminally with reconciliation warning');
$order2 = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 842, 'domain' => '']);
Harness::$gateway->payInvoice((int) $order2['invoice_id']);
$another = $servers->requestProvision((int) $order2['service_id'], $accountId, $e2eSpec,
    'vultr-ambiguous-provision');
Http::setClientFake(function ($method, $url, $options) use ($api) {
    if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v2/instances') {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'lost reply'];
    }
    return $api->handle($method, $url, $options);
});
$ambiguousLease = $queue->lease('vultr-ambiguous-worker', JobQueue::QUEUE_PROVISIONING, 1);
$ambiguousResult = $dispatcher->runJob($ambiguousLease[0]);
T::is('worker does not retry ambiguous billable POST', 'failed', $ambiguousResult['status']);
T::is('terminal uncertainty code retained', 'PROVIDER_CREATE_UNCERTAIN', $ambiguousResult['error']['code']);
T::contains('worker tells operator to reconcile', 'Reconcile the provider resource',
    $ambiguousResult['error']['message']);
T::is('failed local resource has no invented provider ID', null,
    Db::first('customer_servers', ['id' => (int) $another['server']['id']])['provider_server_id']);
Http::setClientFake(null);
exit(T::summary());
