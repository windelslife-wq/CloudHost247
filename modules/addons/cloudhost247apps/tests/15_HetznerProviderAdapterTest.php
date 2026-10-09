<?php
/**
 * Suite 15 — Hetzner Cloud provider adapter contract (fake HTTP transport).
 *
 * The adapter is exercised against a scripted fake of api.hetzner.cloud/v1
 * through the shared Http seam: real request routing, payloads, headers, TLS
 * options, idempotency recovery, response validation, and error mapping. No
 * live Hetzner call is made or claimed.
 */

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
use Ch247Apps\Core\Str;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\CustomerServerService;
use Ch247Apps\Infrastructure\InfrastructureProviderInterface;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderAccountService;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\Providers\HetznerCloudAdapter;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;

/** Scripted fake of the Hetzner Cloud API used through Http::setClientFake(). */
class FakeHetznerApi
{
    public $servers = [];
    public $snapshots = [];
    public $calls = [];
    public $nextStatus = null;
    public $nextError = null;
    public $nextBody = null;
    public $rejectCreateWith = null;
    public $deleteServerEmpty = false;
    public $readsBeforeReady = 1;
    private $nextId = 4242;
    private $nextActionId = 9001;
    private $nextSnapshotId = 777;

    public $serverTypes = [
        ['id' => 1, 'name' => 'cx11', 'cores' => 1, 'memory' => 2.0, 'disk' => 20],
        ['id' => 2, 'name' => 'cx22', 'cores' => 2, 'memory' => 4.0, 'disk' => 40],
        ['id' => 3, 'name' => 'cx21', 'cores' => 2, 'memory' => 2.0, 'disk' => 40],
        ['id' => 4, 'name' => 'cx31', 'cores' => 2, 'memory' => 2.0, 'disk' => 80],
    ];

    public function handle($method, $url, $options)
    {
        $this->calls[] = ['method' => $method, 'url' => (string) $url, 'options' => $options];
        if ($this->nextError !== null) {
            $error = $this->nextError;
            $this->nextError = null;
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error];
        }
        if ($this->nextBody !== null) {
            $body = $this->nextBody;
            $this->nextBody = null;
            return ['status' => 200, 'headers' => [], 'body' => $body, 'error' => null];
        }
        if ($this->nextStatus !== null) {
            $status = $this->nextStatus;
            $this->nextStatus = null;
            return ['status' => $status, 'headers' => [], 'body' => '', 'error' => null];
        }

        $parts = parse_url((string) $url);
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $json = function ($data, $status = 200) {
            return ['status' => $status, 'headers' => [], 'body' => json_encode($data), 'error' => null];
        };
        $notFound = function () use ($json) {
            return $json(['error' => ['code' => 'not_found', 'message' => 'server not found']], 404);
        };
        $action = function () {
            return ['id' => $this->nextActionId++, 'command' => 'action', 'status' => 'running', 'progress' => 0];
        };

        if ($method === 'GET' && $path === '/v1/servers') {
            $servers = array_values($this->servers);
            if (isset($query['label_selector'])
                && preg_match('/^ch247-idempotency=(.+)$/', (string) $query['label_selector'], $m)) {
                $servers = array_values(array_filter($servers, function ($s) use ($m) {
                    return isset($s['labels']['ch247-idempotency'])
                        && $s['labels']['ch247-idempotency'] === $m[1];
                }));
            }
            return $json(['servers' => $servers]);
        }
        if ($method === 'GET' && $path === '/v1/server_types') {
            return $json(['server_types' => $this->serverTypes]);
        }
        if ($method === 'GET' && preg_match('#^/v1/servers/([0-9]+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->servers[$id])) {
                return $notFound();
            }
            $server = $this->servers[$id];
            if ($server['status'] === 'initializing' && $this->readsBeforeReady > 0) {
                $this->readsBeforeReady--;
                return $json(['server' => $server]);
            }
            $server['status'] = 'running';
            $server['public_net'] = [
                'ipv4' => ['ip' => '203.0.113.10'],
                'ipv6' => ['ip' => '2a01:4f8:c17:abcd::/64'],
            ];
            $this->servers[$id] = $server;
            return $json(['server' => $server]);
        }
        if ($method === 'POST' && $path === '/v1/servers') {
            $body = isset($options['json']) && is_array($options['json']) ? $options['json'] : [];
            $id = $this->nextId++;
            $server = [
                'id' => $id,
                'name' => isset($body['name']) ? (string) $body['name'] : '',
                'status' => 'initializing',
                'labels' => isset($body['labels']) && is_array($body['labels']) ? $body['labels'] : [],
                'public_net' => ['ipv4' => ['ip' => null], 'ipv6' => ['ip' => null]],
                'server_type' => ['name' => isset($body['server_type']) ? (string) $body['server_type'] : ''],
                'image' => ['name' => isset($body['image']) ? (string) $body['image'] : ''],
                'location' => ['name' => isset($body['location']) ? (string) $body['location'] : ''],
            ];
            $this->servers[$id] = $server;
            if ($this->rejectCreateWith !== null) {
                // Simulate a lost create race: the "winner" exists, our POST is rejected.
                $status = $this->rejectCreateWith;
                $this->rejectCreateWith = null;
                return $json(['error' => ['code' => 'conflict', 'message' => 'server already exists']], $status);
            }
            return $json(['server' => $server, 'action' => $action()]);
        }
        if ($method === 'DELETE' && preg_match('#^/v1/servers/([0-9]+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->servers[$id])) {
                return $notFound();
            }
            unset($this->servers[$id]);
            if ($this->deleteServerEmpty) {
                return ['status' => 204, 'headers' => [], 'body' => '', 'error' => null];
            }
            return $json(['action' => $action()]);
        }
        if ($method === 'POST' && preg_match('#^/v1/servers/([0-9]+)/actions/([a-z_]+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->servers[$id])) {
                return $notFound();
            }
            $body = isset($options['json']) && is_array($options['json']) ? $options['json'] : [];
            if ($m[2] === 'create_snapshot') {
                $snapshotId = $this->nextSnapshotId++;
                $this->snapshots[$snapshotId] = ['id' => $snapshotId, 'status' => 'creating'];
                return $json(['action' => $action(), 'snapshot' => $this->snapshots[$snapshotId]]);
            }
            if ($m[2] === 'restore_snapshot') {
                $snapshotId = isset($body['snapshot']) ? (int) $body['snapshot'] : 0;
                if (!isset($this->snapshots[$snapshotId])) {
                    return $notFound();
                }
            }
            return $json(['action' => $action()]);
        }
        if ($method === 'DELETE' && preg_match('#^/v1/snapshots/([0-9]+)$#', $path, $m)) {
            $id = (int) $m[1];
            if (!isset($this->snapshots[$id])) {
                return $notFound();
            }
            unset($this->snapshots[$id]);
            return $json(['action' => $action()]);
        }
        return ['status' => 599, 'headers' => [], 'body' => '', 'error' => 'No fake route matched ' . $method . ' ' . $path];
    }

    public function callsTo($method, $pathRegex)
    {
        return array_values(array_filter($this->calls, function ($c) use ($method, $pathRegex) {
            return $c['method'] === $method
                && preg_match($pathRegex, (string) parse_url($c['url'], PHP_URL_PATH));
        }));
    }
}

Harness::boot();
Harness::relaxRateLimits();
Clock::freeze('2026-10-08 12:00:00');
Settings::override('customer_server_provisioning_enabled', '1');

$api = new FakeHetznerApi();
Http::setClientFake([$api, 'handle']);

$adapter = new HetznerCloudAdapter();
$token = 'hetzner-test-token-secret';
$credentials = ['api_token' => $token];
$config = ['project' => 'staging-project'];
$spec = [
    'name' => 'Contract VPS', 'hostname' => 'contract.example.test', 'region' => 'fsn1',
    'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
];
$idemKey = 'customer-server:create:9001';

section('Adapter identity, capabilities, and honest metrics abstention');
T::is('adapter key is hetzner', 'hetzner', $adapter->key());
T::is('adapter name is human-readable', 'Hetzner Cloud', $adapter->name());
$caps = $adapter->capabilities();
T::ok('server lifecycle capabilities are advertised', $caps['server.create'] && $caps['server.get']
    && $caps['server.delete'] && $caps['server.reboot'] && $caps['server.power_on']
    && $caps['server.power_off'] && $caps['server.rebuild'] && $caps['server.resize']);
T::ok('snapshot capabilities are advertised', $caps['snapshot.create'] && $caps['snapshot.delete']
    && $caps['snapshot.restore']);
T::ok('metrics capability is NOT advertised (Hetzner exposes none)', $caps['server.metrics'] === false);
T::throws('getMetrics fails closed instead of inventing data', ProviderUnavailableException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getMetrics($credentials, $config, '4242');
    });

section('providers/bootstrap.php registers the reviewed adapter');
$bootstrapFile = dirname(__DIR__) . '/providers/bootstrap.php';
T::ok('providers/bootstrap.php ships with the module', is_file($bootstrapFile));
$returned = require $bootstrapFile;
T::ok('bootstrap returns adapter instances', is_array($returned) && count($returned) === 4
    && $returned[0] instanceof InfrastructureProviderInterface
    && $returned[1] instanceof InfrastructureProviderInterface
    && $returned[2] instanceof InfrastructureProviderInterface
    && $returned[3] instanceof InfrastructureProviderInterface);
T::notContains('bootstrap file contains no secrets', $token, (string) file_get_contents($bootstrapFile));
ProviderRegistry::reset();
ProviderBootstrap::reset();
$catalog = ProviderBootstrap::boot();
$hetznerEntry = null;
foreach ($catalog as $entry) {
    if ($entry['provider_code'] === 'hetzner') {
        $hetznerEntry = $entry;
    }
}
T::ok('bootstrapped catalog reports the hetzner adapter available', $hetznerEntry && $hetznerEntry['adapter_available']);
T::ok('bootstrapped catalog exposes normalized capabilities', $hetznerEntry
    && $hetznerEntry['capabilities']['server.create'] === true
    && $hetznerEntry['capabilities']['server.metrics'] === false);
T::is('registry resolves the bootstrapped adapter', $adapter->key(), ProviderRegistry::forProvider('hetzner')->key());
ProviderBootstrap::boot();
T::is('re-booting does not double-register', 1, count(array_filter(ProviderRegistry::catalog(),
    function ($e) {
        return $e['provider_code'] === 'hetzner' && $e['adapter_available'];
    })));
ProviderRegistry::assertSupports(ProviderRegistry::forProvider('hetzner'), ['server.create', 'server.get']);
T::ok('assertSupports accepts advertised capabilities', true);
T::throws('assertSupports rejects the unadvertised metrics capability', ProviderUnavailableException::class,
    function () {
        ProviderRegistry::assertSupports(ProviderRegistry::forProvider('hetzner'), ['server.metrics']);
    });

section('verifyCredentials authenticates against the real endpoint shape');
T::ok('200 verifies the token', $adapter->verifyCredentials($credentials, $config) === true);
$verifyCall = $api->callsTo('GET', '#^/v1/servers$#')[0];
T::is('verify uses the fixed Hetzner endpoint', 'https://api.hetzner.cloud/v1/servers?per_page=1',
    $verifyCall['url']);
T::is('verify sends the token as a Bearer header', 'Bearer ' . $token,
    $verifyCall['options']['headers']['Authorization']);
T::ok('verify enforces TLS', !empty($verifyCall['options']['verify_tls']));
$api->nextStatus = 401;
T::throws('401 is a definitive authentication rejection', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->verifyCredentials($credentials, $config);
    });
$api->nextStatus = 403;
T::throws('403 is a definitive authentication rejection', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->verifyCredentials($credentials, $config);
    });
$api->nextError = 'connection refused';
T::throws('transport failure is retryable, not a rejection', RetryableProviderException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->verifyCredentials($credentials, $config);
    });
T::throws('missing token fails closed', ConfigurationException::class, function () use ($adapter, $config) {
    $adapter->verifyCredentials([], $config);
});

section('createServer maps the spec, selects the provider-catalog type, and is idempotent');
$created = $adapter->createServer($credentials, $config, $spec, $idemKey);
T::is('create returns the provider server id', '4242', $created['id']);
T::is('create returns the provider state', 'initializing', $created['status']);
$posts = $api->callsTo('POST', '#^/v1/servers$#');
T::is('exactly one create call was made', 1, count($posts));
$body = $posts[0]['options']['json'];
T::is('provider name is a valid slug of the spec name', 'contract-vps', $body['name']);
T::is('size comes from the provider catalog exact match', 'cx21', $body['server_type']);
T::is('image comes from the spec', 'ubuntu-24.04', $body['image']);
T::is('location comes from the spec region', 'fsn1', $body['location']);
T::ok('server starts after create', !empty($body['start_after_create']));
T::is('idempotency travels as a deterministic label', substr(hash('sha256', $idemKey), 0, 32),
    $body['labels']['ch247-idempotency']);
$labelLists = array_values(array_filter($api->callsTo('GET', '#^/v1/servers$#'), function ($c) {
    return strpos($c['url'], 'label_selector=ch247-idempotency%3D') !== false;
}));
T::is('create recovers by idempotency label before posting', 1, count($labelLists));
T::is('the label carries the deterministic key hash', 'label_selector=ch247-idempotency%3D'
    . substr(hash('sha256', $idemKey), 0, 32), parse_url($labelLists[0]['url'], PHP_URL_QUERY));
$typeCalls = $api->callsTo('GET', '#^/v1/server_types$#');
T::is('server type selection reads the provider catalog', 1, count($typeCalls));
$replay = $adapter->createServer($credentials, $config, $spec, $idemKey);
T::is('replay with the same key returns the same server', '4242', $replay['id']);
T::is('replay makes no second create call', 1, count($api->callsTo('POST', '#^/v1/servers$#')));
$api->rejectCreateWith = 409;
$otherKey = 'customer-server:create:9002';
$raced = $adapter->createServer($credentials, $config, $spec, $otherKey);
T::is('create race recovers the existing labelled server', '4243', $raced['id']);
T::is('recovered race returns the created state', 'initializing', $raced['status']);
$noMatch = $spec;
$noMatch['cpu_cores'] = 8;
T::throws('unmatched size fails closed without a create call', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $noMatch, $otherKey) {
        $adapter->createServer($credentials, $config, $noMatch, $otherKey . '-nomatch');
    });
T::is('failed size selection made no create call', 2, count($api->callsTo('POST', '#^/v1/servers$#')));
$badName = $spec;
$badName['name'] = '...';
T::throws('unmappable name fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $badName) {
        $adapter->createServer($credentials, $config, $badName, 'customer-server:create:badname');
    });

section('getServer normalizes provider state and represents absence explicitly');
$api->readsBeforeReady = 0;
$running = $adapter->getServer($credentials, $config, '4242');
T::is('get returns the provider status', 'running', $running['status']);
T::is('get returns the provider IPv4', '203.0.113.10', $running['ipv4']);
T::is('get strips the IPv6 prefix length', '2a01:4f8:c17:abcd::', $running['ipv6']);
$absent = $adapter->getServer($credentials, $config, '999999');
T::is('absent server is represented as deleted', 'deleted', $absent['status']);
T::throws('invalid provider id fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, 'not-an-id');
    });

section('deleteServer is idempotent and tolerates empty 204 responses');
$deleted = $adapter->deleteServer($credentials, $config, '4242', $idemKey);
T::is('delete returns the deleting state', 'deleting', $deleted['status']);
T::ok('delete carries the provider action id', !empty($deleted['operation_id']));
$again = $adapter->deleteServer($credentials, $config, '4242', $idemKey);
T::is('repeated delete is idempotent (deleted)', 'deleted', $again['status']);
$api->deleteServerEmpty = true;
$emptyDeleted = $adapter->deleteServer($credentials, $config, '4243', $idemKey);
T::is('204 empty-body delete still reports deleting', 'deleting', $emptyDeleted['status']);
T::is('204 empty-body delete has no action id', null, $emptyDeleted['operation_id']);

section('Lifecycle actions, rebuild, resize, and snapshots map to provider calls');
$api->servers[4242] = [
    'id' => 4242, 'name' => 'contract-vps', 'status' => 'running',
    'labels' => ['ch247-idempotency' => substr(hash('sha256', $idemKey), 0, 32)],
    'public_net' => ['ipv4' => ['ip' => '203.0.113.10'], 'ipv6' => ['ip' => '2a01:4f8:c17:abcd::/64']],
];
$reboot = $adapter->rebootServer($credentials, $config, '4242', $idemKey);
T::ok('reboot is confirmed', !empty($reboot['confirmed']));
T::ok('reboot carries the action id', !empty($reboot['operation_id']));
T::is('reboot hits the provider action endpoint', 1, count($api->callsTo('POST', '#/actions/reboot$#')));
$on = $adapter->powerOnServer($credentials, $config, '4242', $idemKey);
T::ok('power on is confirmed', !empty($on['confirmed']));
T::is('power on hits the provider action endpoint', 1, count($api->callsTo('POST', '#/actions/poweron$#')));
$off = $adapter->powerOffServer($credentials, $config, '4242', $idemKey);
T::ok('power off is confirmed', !empty($off['confirmed']));
T::is('power off hits the provider action endpoint', 1, count($api->callsTo('POST', '#/actions/poweroff$#')));
$rebuild = $adapter->rebuildServer($credentials, $config, '4242', $spec, $idemKey);
T::is('rebuild returns the rebuilding state', 'rebuilding', $rebuild['status']);
T::ok('rebuild carries the action id', !empty($rebuild['operation_id']));
$rebuildCalls = $api->callsTo('POST', '#/actions/rebuild$#');
T::is('rebuild sends the requested image', 'ubuntu-24.04', $rebuildCalls[0]['options']['json']['image']);
$resize = $adapter->resizeServer($credentials, $config, '4242', $spec, $idemKey);
T::is('resize returns the resizing state', 'resizing', $resize['status']);
$resizeCalls = $api->callsTo('POST', '#/actions/change_type$#');
T::is('resize sends the provider-catalog type', 'cx21', $resizeCalls[0]['options']['json']['server_type']);
T::ok('resize upgrades the disk', !empty($resizeCalls[0]['options']['json']['upgrade_disk']));
$snapshot = $adapter->createSnapshot($credentials, $config, '4242', $idemKey);
T::is('snapshot create returns the provider snapshot id', '777', $snapshot['id']);
T::is('snapshot create returns the creating state', 'creating', $snapshot['status']);
$snapCalls = $api->callsTo('POST', '#/actions/create_snapshot$#');
T::is('snapshot description is deterministic', 'ch247-' . substr(hash('sha256', $idemKey), 0, 32),
    $snapCalls[0]['options']['json']['description']);
$snapDeleted = $adapter->deleteSnapshot($credentials, $config, '4242', '777', $idemKey);
T::is('snapshot delete returns the deleting state', 'deleting', $snapDeleted['status']);
$snapAgain = $adapter->deleteSnapshot($credentials, $config, '4242', '777', $idemKey);
T::is('repeated snapshot delete is idempotent', 'deleted', $snapAgain['status']);
$adapter->createSnapshot($credentials, $config, '4242', $idemKey . '-restore');
$restored = $adapter->restoreSnapshot($credentials, $config, '4242', '778', $idemKey);
T::is('snapshot restore returns the restoring state', 'restoring', $restored['status']);
$restoreCalls = $api->callsTo('POST', '#/actions/restore_snapshot$#');
T::is('restore sends the snapshot id', 778, $restoreCalls[0]['options']['json']['snapshot']);

section('Error mapping is fail-closed and never leaks credentials');
$api->nextStatus = 500;
T::throws('5xx is retryable', RetryableProviderException::class, function () use ($adapter, $credentials, $config) {
    $adapter->getServer($credentials, $config, '4242');
});
$api->nextStatus = 429;
T::throws('429 is retryable', RetryableProviderException::class, function () use ($adapter, $credentials, $config) {
    $adapter->getServer($credentials, $config, '4242');
});
$api->nextError = 'tls handshake timeout';
T::throws('transport errors are retryable', RetryableProviderException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });
$api->nextStatus = 401;
T::throws('401 is an authentication failure', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });
$api->nextStatus = 403;
T::throws('403 is an authentication failure', ProviderAuthenticationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });
$recorded = json_encode(Http::clientCalls());
T::notContains('recorded outbound calls never contain the token', $token, $recorded);
$urls = implode(' ', array_map(function ($c) {
    return $c['url'];
}, Http::clientCalls()));
T::notContains('the token never appears in a request URL', $token, $urls);
Http::setClientFake(function ($method, $url, $options) {
    return ['status' => 422, 'headers' => [], 'body' => json_encode([
        'error' => ['code' => 'invalid_input', 'message' => 'image is invalid'],
    ]), 'error' => null];
});
$e = T::throws('422 fails closed with the provider message', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config, $spec) {
        $adapter->createServer($credentials, $config, $spec, 'customer-server:create:422');
    });
T::contains('provider error message is surfaced (clipped)', 'image is invalid', $e->getMessage());
Http::setClientFake([$api, 'handle']);
$api->nextBody = 'this is not json';
T::throws('invalid JSON fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });
$api->nextBody = json_encode(['server' => ['name' => 'no-id-here', 'status' => 'running']]);
T::throws('a server without an id fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });
$api->nextBody = json_encode(['server' => ['id' => 5, 'status' => 'running', 'public_net' => ['ipv4' => ['ip' => 'not-an-ip']]]]);
T::throws('an invalid provider IP fails closed', ProviderOperationException::class,
    function () use ($adapter, $credentials, $config) {
        $adapter->getServer($credentials, $config, '4242');
    });

section('End-to-end: real adapter drives verify, create, poll, gates, and activation');
$admin = Harness::adminActor(1, Actor::ROLE_SUPER_ADMIN);
$accountService = new ProviderAccountService($admin);
$account = $accountService->create([
    'provider_code' => 'hetzner',
    'name' => 'Contract staging account',
    'region' => 'fsn1',
    'public_config' => ['project' => 'staging-project'],
    'credentials' => ['api_token' => $token],
]);
$accountId = (int) $account['id'];
$accountService->requestVerification($accountId, 'hetzner-contract-verify');
$queue = new JobQueue();
$dispatcher = new JobDispatcher(new Orchestrator(Harness::systemActor(), null, $queue),
    new ServerProvisioningWorker(Harness::systemActor(), $queue),
    new ProviderAccountVerifyWorker(Harness::systemActor(), $queue), $queue);
$verifyLease = $queue->lease('hetzner-verify-worker', JobQueue::QUEUE_PROVISIONING, 1);
$verifyResult = $dispatcher->runJob($verifyLease[0]);
T::is('credential verification completes through the real adapter', 'completed', $verifyResult['status']);
T::is('verified account becomes active', 'active', (new ProviderAccountService($admin))->get($accountId)['status']);

$clientId = Harness::client();
$order = Harness::$gateway->createOrder(['clientid' => $clientId, 'pid' => 720, 'domain' => '']);
$serviceId = (int) $order['service_id'];
Harness::$gateway->payInvoice((int) $order['invoice_id']);
$servers = new CustomerServerService($admin, Harness::$gateway, $accountService, $queue);
$e2eSpec = [
    'name' => 'Hetzner Contract VPS', 'hostname' => 'hetzner-contract.example.test', 'region' => 'fsn1',
    'image' => 'ubuntu-24.04', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_gb' => 40,
];
$created = $servers->requestProvision($serviceId, $accountId, $e2eSpec, 'hetzner-e2e-provision');
$serverId = (int) $created['server']['id'];
$api->readsBeforeReady = 1;
$createLease = $queue->lease('hetzner-create-worker', JobQueue::QUEUE_PROVISIONING, 1);
$createResult = $dispatcher->runJob($createLease[0]);
T::is('create job completes through the real adapter', 'completed', $createResult['status']);
$stored = Db::first('customer_servers', ['id' => $serverId]);
T::is('provider server id is persisted', '4244', $stored['provider_server_id']);
T::is('async create leaves the server provisioning', CustomerServerService::STATUS_PROVISIONING, $stored['status']);
T::is('async create schedules a poll', 1, Db::count('jobs', [
    'job_type' => JobQueue::TYPE_SERVER_POLL, 'customer_server_id' => $serverId,
]));
T::notContains('job payload carries no provider credentials', $token,
    (string) Db::first('jobs', ['id' => (int) $createLease[0]['id']])['payload']);

Clock::travel(15);
$pollOne = $queue->lease('hetzner-poll-one', JobQueue::QUEUE_PROVISIONING, 1);
$pollOneResult = $dispatcher->runJob($pollOne[0]);
T::is('first poll completes while the VM initializes', 'completed', $pollOneResult['status']);
T::is('initializing VM stays provisioning', CustomerServerService::STATUS_PROVISIONING,
    $servers->get($serverId)['status']);
T::is('initializing VM schedules the next poll', 1, Db::count('jobs', [
    'job_type' => JobQueue::TYPE_SERVER_POLL, 'customer_server_id' => $serverId,
    'status' => JobQueue::STATUS_QUEUED,
]));

Clock::travel(15);
$pollTwo = $queue->lease('hetzner-poll-two', JobQueue::QUEUE_PROVISIONING, 1);
$pollTwoResult = $dispatcher->runJob($pollTwo[0]);
T::is('second poll completes', 'completed', $pollTwoResult['status']);
T::is('gates pass and the server activates', 'active', $pollTwoResult['result']['customer_status']);
T::ok('gate report records metrics as unsupported for this adapter',
    $pollTwoResult['result']['gates']['metrics']['provider_reported'] === false);
$active = $servers->get($serverId);
T::is('server is customer-active', CustomerServerService::STATUS_ACTIVE, $active['status']);
T::is('server keeps the server_ready state', CustomerServerService::STATE_SERVER_READY, $active['provisioning_state']);
T::is('IPv4 comes from the provider', '203.0.113.10', $active['ipv4']);
T::is('IPv6 comes from the provider without prefix length', '2a01:4f8:c17:abcd::', $active['ipv6']);
$activationEvent = Db::first('customer_server_events', [
    'customer_server_id' => $serverId, 'event' => 'server_activated',
]);
T::ok('activation lifecycle event exists', (bool) $activationEvent);
T::notContains('lifecycle events carry no provider credentials', $token,
    (string) $activationEvent['metadata']);
T::is('customer-visible status is active', 'active',
    (new \Ch247Apps\Api\InfrastructureApi(Actor::customer($clientId, 'Customer', ['authMethod' => 'api_token'])))
        ->dispatch('GET', '/v1/servers/' . $serverId)['body']['data']['status']);

exit(T::summary());
