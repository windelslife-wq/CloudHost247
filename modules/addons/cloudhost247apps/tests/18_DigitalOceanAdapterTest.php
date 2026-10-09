<?php
/** Offline DigitalOcean v2 contract through Http::setClientFake; no live calls. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Settings;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\Providers\DigitalOceanAdapter;

class FakeDigitalOceanApi
{
    public $calls = [];
    public $droplets = [];
    public $override = null;
    public $sizePages = [];
    public $tagPages = [];
    public $autoReady = false;
    public $size = ['slug' => 's-2vcpu-2gb', 'vcpus' => 2, 'memory' => 2048,
        'disk' => 60, 'available' => true, 'regions' => ['nyc3']];
    private $nextId = 301;

    public function callsTo($method, $path)
    {
        return array_values(array_filter($this->calls, function ($call) use ($method, $path) {
            return $call[0] === $method && parse_url($call[1], PHP_URL_PATH) === $path;
        }));
    }

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
        $page = isset($query['page']) ? (int) $query['page'] : 1;
        $json = function ($data, $status = 200) {
            return ['status' => $status, 'headers' => [], 'body' => json_encode($data), 'error' => null];
        };
        if ($method === 'GET' && $path === '/v2/account') {
            return $json(['account' => ['uuid' => 'test-account', 'status' => 'active']]);
        }
        if ($method === 'GET' && $path === '/v2/account/keys/123') {
            return $json(['ssh_key' => ['id' => 123, 'name' => 'operator-key']]);
        }
        if ($method === 'GET' && $path === '/v2/sizes') {
            return $json($this->sizePages ? $this->sizePages[$page - 1]
                : ['sizes' => [$this->size], 'links' => ['pages' => []]]);
        }
        if ($method === 'GET' && $path === '/v2/droplets') {
            if ($this->tagPages) { return $json($this->tagPages[$page - 1]); }
            $filtered = array_values(array_filter($this->droplets, function ($d) use ($query) {
                return in_array($query['tag_name'], $d['tags'], true);
            }));
            return $json(['droplets' => $filtered, 'links' => ['pages' => []]]);
        }
        if ($method === 'POST' && $path === '/v2/droplets') {
            $body = $options['json'];
            $d = ['id' => $this->nextId++, 'name' => $body['name'], 'status' => 'new',
                'tags' => $body['tags'], 'size_slug' => $body['size'],
                'region' => ['slug' => $body['region']], 'image' => ['slug' => $body['image']], 'networks' => ['v4' => [], 'v6' => []]];
            $this->droplets[$d['id']] = $d;
            return $json(['droplet' => $d, 'links' => ['actions' => []]], 202);
        }
        if (preg_match('#^/v2/droplets/([0-9]+)$#', $path, $m)) {
            if (!isset($this->droplets[$m[1]])) { return $json(['id' => 'not_found'], 404); }
            if ($method === 'DELETE') {
                unset($this->droplets[$m[1]]);
                return ['status' => 204, 'body' => '', 'headers' => [], 'error' => null];
            }
            if ($method === 'GET') {
                if ($this->autoReady) {
                    $this->droplets[$m[1]]['status'] = 'active';
                    $this->droplets[$m[1]]['networks']['v4'] = [
                        ['type' => 'public', 'ip_address' => '203.0.113.66']];
                }
                return $json(['droplet' => $this->droplets[$m[1]]]);
            }
        }
        if ($method === 'POST' && preg_match('#^/v2/droplets/([0-9]+)/actions$#', $path)) {
            return $json(['action' => ['id' => 995, 'status' => 'in-progress']], 201);
        }
        return ['status' => 599, 'body' => '', 'headers' => [], 'error' => 'unexpected fake route'];
    }
}

Harness::boot();
$api = new FakeDigitalOceanApi();
Http::setClientFake([$api, 'handle']);
$adapter = new DigitalOceanAdapter();
$credentials = ['api_token' => 'digitalocean-secret-test-token'];
$config = ['size_slug' => 's-2vcpu-2gb', 'ssh_key_id' => '123'];
$spec = ['name' => 'vm-service-456', 'region' => 'nyc3', 'image' => 'ubuntu-24-04-x64',
    'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 60];
$key = 'customer-server:create:456';

section('Registry, credentials, truthful capabilities and abstention');
ProviderRegistry::reset();
ProviderBootstrap::reset();
ProviderBootstrap::boot();
T::ok('real adapter is explicitly registered', ProviderRegistry::hasAdapter('digitalocean'));
T::ok('create and reboot advertised', $adapter->capabilities()['server.create'] && $adapter->capabilities()['server.reboot']);
T::ok('rebuild, resize and snapshots not advertised', !$adapter->capabilities()['server.rebuild']
    && !$adapter->capabilities()['server.resize'] && !$adapter->capabilities()['snapshot.create']);
T::throws('unsupported resize makes no call', ProviderUnavailableException::class,
    function () use ($adapter, $credentials, $config, $spec, $key) {
        $adapter->resizeServer($credentials, $config, '301', $spec, $key);
    });
T::throws('metrics abstain', ProviderUnavailableException::class,
    function () use ($adapter, $credentials, $config) { $adapter->getMetrics($credentials, $config, '301'); });
T::is('account and key verification succeeds', true, $adapter->verifyCredentials($credentials, $config));
T::throws('missing token fails locally', ConfigurationException::class,
    function () use ($adapter, $config) { $adapter->verifyCredentials([], $config); });
T::throws('missing SSH key fails locally', ConfigurationException::class,
    function () use ($adapter, $credentials) { $adapter->verifyCredentials($credentials, ['size_slug' => 's-2vcpu-2gb']); });
$api->override = ['status' => 401, 'body' => '', 'headers' => [], 'error' => null];
T::throws('401 rejects token', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) { $adapter->verifyCredentials($credentials, $config); });
$api->override = ['status' => 500, 'body' => '', 'headers' => [], 'error' => null];
T::throws('account 5xx remains retryable', RetryableProviderException::class,
    function () use ($adapter, $credentials, $config) { $adapter->verifyCredentials($credentials, $config); });

section('202 create, exact provider size, SSH-only and deterministic replay');
$created = T::nothrow('202 accepted', function () use ($adapter, $credentials, $config, $spec, $key) {
    return $adapter->createServer($credentials, $config, $spec, $key);
});
T::is('provider ID', '301', $created['id']);
T::is('accepted is not ready', 'new', $created['status']);
T::is('one POST', 1, count($api->callsTo('POST', '/v2/droplets')));
$call = $api->callsTo('POST', '/v2/droplets')[0];
T::is('operator SSH key, no password', [123], $call[2]['json']['ssh_keys']);
T::is('exact catalog size', $config['size_slug'], $call[2]['json']['size']);
T::is('operator region', 'nyc3', $call[2]['json']['region']);
T::is('operator image', 'ubuntu-24-04-x64', $call[2]['json']['image']);
T::is('hash tag', ['ch247-' . substr(hash('sha256', $key), 0, 32)], $call[2]['json']['tags']);
T::is('backups disabled', false, $call[2]['json']['backups']);
T::is('TLS verified', true, $call[2]['verify_tls']);
T::is('fixed HTTPS host', 'api.digitalocean.com', parse_url($call[1], PHP_URL_HOST));
T::is('replay returns same ID', '301', $adapter->createServer($credentials, $config, $spec, $key)['id']);
T::is('no second POST', 1, count($api->callsTo('POST', '/v2/droplets')));

section('Public networking, 201 actions, idempotent 204/404 deletion');
$api->droplets[301]['status'] = 'active';
$api->droplets[301]['networks'] = ['v4' => [
    ['type' => 'private', 'ip_address' => '10.0.0.1'],
    ['type' => 'public', 'ip_address' => '203.0.113.17'],
], 'v6' => [['type' => 'public', 'ip_address' => '2001:db8::2']]];
$read = $adapter->getServer($credentials, $config, '301');
T::is('active status', 'active', $read['status']);
T::is('public IPv4 only', '203.0.113.17', $read['ipv4']);
T::is('public IPv6 only', '2001:db8::2', $read['ipv6']);
foreach (['rebootServer' => 'reboot', 'powerOnServer' => 'power_on', 'powerOffServer' => 'power_off'] as $method => $type) {
    $result = $adapter->$method($credentials, $config, '301', $key);
    T::is($type . ' action ID', '995', $result['operation_id']);
    $actions = $api->callsTo('POST', '/v2/droplets/301/actions');
    T::is($type . ' action body', $type, $actions[count($actions) - 1][2]['json']['type']);
}
T::is('204 delete reports deleted', 'deleted', $adapter->deleteServer($credentials, $config, '301', $key)['status']);
T::is('404 replay reports deleted', 'deleted', $adapter->deleteServer($credentials, $config, '301', $key)['status']);
T::is('404 read reports deleted', 'deleted', $adapter->getServer($credentials, $config, '301')['status']);

section('Paginated catalog/tag lookup; ambiguity and malformed data never create');
$api->sizePages = [
    ['sizes' => [], 'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/sizes?page=2']]],
    ['sizes' => [$api->size], 'links' => ['pages' => []]],
];
T::is('catalog match on second page', '302',
    $adapter->createServer($credentials, $config, $spec, 'second-key')['id']);
T::ok('catalog page two was read', count(array_filter($api->callsTo('GET', '/v2/sizes'), function ($c) {
    return strpos($c[1], 'page=2') !== false;
})) >= 1);
$api->tagPages = [
    ['droplets' => [], 'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/droplets?page=2']]],
    ['droplets' => [$api->droplets[302]], 'links' => ['pages' => []]],
];
T::is('tag replay scans second page', '302',
    $adapter->createServer($credentials, $config, $spec, 'second-key')['id']);
T::is('paged replay never posts', 2, count($api->callsTo('POST', '/v2/droplets')));
$api->tagPages = [['droplets' => [$api->droplets[302], $api->droplets[302]], 'links' => ['pages' => []]]];
T::throws('duplicate tag fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'second-key');
    });
$api->tagPages = [['droplets' => [], 'links' => ['pages' => ['next' => 'https://evil.example/droplets']]]];
T::throws('untrusted pagination hint fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'third-key');
    });
$api->tagPages = [];
$api->sizePages = [];
$bad = $spec;
$bad['storage_gb'] = 100;
T::throws('unmatched size prevents POST', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $bad) {
        $adapter->createServer($credentials, $config, $bad, 'different-key');
    });
T::is('size mismatch made no POST', 2, count($api->callsTo('POST', '/v2/droplets')));
$api->size['available'] = false;
T::throws('unavailable size prevents POST', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'unavailable-key');
    });
$api->size['available'] = true;

section('Response validation: no wrong VM, invented capacity or silently empty listings');
$api->tagPages = [['droplets' => [], 'links' => []]];
T::throws('missing pagination structure is not treated as empty', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'missing-links');
    });
$wrong = $api->droplets[302];
$wrong['image']['slug'] = 'different-image';
$api->tagPages = [['droplets' => [$wrong], 'links' => ['pages' => []]]];
T::throws('tagged wrong image cannot be attached to service', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'second-key');
    });
T::is('wrong VM made no new POST', 2, count($api->callsTo('POST', '/v2/droplets')));
$api->tagPages = [];
$api->size['gpu_info'] = ['gpus' => 1];
T::throws('GPU listing cannot be used with ordinary tag recovery', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'gpu-key');
    });
unset($api->size['gpu_info']);
T::throws('GPU slug is rejected up front', ConfigurationException::class,
    function () use ($adapter, $credentials, $spec, $config) {
        $config['size_slug'] = 'gpu-h100';
        $adapter->createServer($credentials, $config, $spec, 'gpu-slug-key');
    });
$api->droplets[302]['networks'] = ['v4' => [['type' => 'public', 'ip_address' => 'invalid']]];
T::throws('invalid provider public IP fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '302');
    });
$api->droplets[302]['networks'] = ['v4' => [], 'v6' => []];

section('Lost create reply is terminal and HTTP logs redact credentials');
Http::setClientFake(function ($method, $url, $options) use ($api) {
    if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v2/droplets') {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'timeout'];
    }
    return $api->handle($method, $url, $options);
});
$error = T::throws('lost create reply is not retryable', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'lost-response');
    });
T::ok('terminal worker error', $error && !$error->isRetryable());
T::is('requires manual reconciliation', 'PROVIDER_CREATE_UNCERTAIN', $error->errorCode());
// Malformed 202 can also follow an accepted, billable create.
Http::setClientFake(function ($method, $url, $options) use ($api) {
    if ($method === 'POST' && parse_url($url, PHP_URL_PATH) === '/v2/droplets') {
        return ['status' => 202, 'body' => '{not-json', 'headers' => [], 'error' => null];
    }
    return $api->handle($method, $url, $options);
});
$invalid = T::throws('malformed accepted create is terminal', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'malformed-202');
    });
T::is('malformed 202 requires reconciliation', 'PROVIDER_CREATE_UNCERTAIN', $invalid->errorCode());
T::ok('malformed 202 is not retryable', !$invalid->isRetryable());
T::notContains('HTTP trace never contains token', $credentials['api_token'], json_encode(Http::clientCalls()));
section('End-to-end: real registry adapter, account verification, async create, poll and readiness');
Http::setClientFake([$api, 'handle']);
Clock::freeze('2026-10-09 12:00:00');
Settings::override('customer_server_provisioning_enabled', '1');
$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$accounts = new ProviderAccountService($admin);
$account = $accounts->create([
    'provider_code' => 'digitalocean', 'name' => 'Offline contract', 'region' => 'nyc3',
    'public_config' => $config, 'credentials' => $credentials,
]);
$accountId = (int) $account['id'];
$accounts->requestVerification($accountId, 'do-verify');
$queue = new JobQueue();
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    new ServerProvisioningWorker(Harness::systemActor(), $queue),
    new ProviderAccountVerifyWorker(Harness::systemActor(), $queue), $queue);
$verifyLease = $queue->lease('do-verify-worker', JobQueue::QUEUE_PROVISIONING, 1);
T::is('verification completes', 'completed', $dispatcher->runJob($verifyLease[0])['status']);
T::is('account becomes active', 'active', $accounts->get($accountId)['status']);
$clientId = Harness::client();
$order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 808, 'domain' => '']);
Harness::$gateway->payInvoice((int) $order['invoice_id']);
$servers = new CustomerServerService($admin, Harness::$gateway, $accounts, $queue);
$e2eSpec = $spec;
$e2eSpec['hostname'] = 'vm-service-808.example.test';
$requested = $servers->requestProvision((int) $order['service_id'], $accountId,
    $e2eSpec, 'do-worker-provision');
$localId = (int) $requested['server']['id'];
$createLease = $queue->lease('do-create-worker', JobQueue::QUEUE_PROVISIONING, 1);
$createResult = $dispatcher->runJob($createLease[0]);
T::is('create worker completes on accepted 202', 'completed', $createResult['status']);
$stored = Db::first('customer_servers', ['id' => $localId]);
T::is('provider id persisted', '303', $stored['provider_server_id']);
T::is('accepted VM remains provisioning', CustomerServerService::STATUS_PROVISIONING, $stored['status']);
T::is('poll queued', 1, Db::count('jobs', ['job_type' => JobQueue::TYPE_SERVER_POLL,
    'customer_server_id' => $localId]));
T::notContains('job carries no token', $credentials['api_token'],
    (string) Db::first('jobs', ['id' => (int) $createLease[0]['id']])['payload']);
$api->autoReady = true;
Clock::travel(15);
$pollLease = $queue->lease('do-poll-worker', JobQueue::QUEUE_PROVISIONING, 1);
$pollResult = $dispatcher->runJob($pollLease[0]);
T::is('poll worker completes', 'completed', $pollResult['status']);
T::is('readiness gates activate the VM', 'active', $pollResult['result']['customer_status']);
T::is('provider IPv4 persisted', '203.0.113.66', $servers->get($localId)['ipv4']);
T::notContains('HTTP trace stays redacted', $credentials['api_token'], json_encode(Http::clientCalls()));
Http::setClientFake(null);
exit(T::summary());
