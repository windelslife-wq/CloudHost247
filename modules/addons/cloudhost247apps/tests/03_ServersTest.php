<?php
/**
 * Suite 03 — server registry, agent security and capacity accounting.
 *
 * Covers the infrastructure half of the control plane: registering deployment
 * targets, sealing their credentials, the signed/replay-protected agent protocol,
 * honest capacity reservation, and the rule that a server which has not reported
 * is never presented as healthy.
 */

require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AgentException;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthenticationException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ImagePullException;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\ResourceInsufficientException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\ServerUnavailableException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\AgentClient;
use Ch247Apps\Servers\CredentialVault;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();

$super = Harness::adminActor(1);
$customer = Harness::customerActor(42);
$worker = Actor::system('Worker');

$servers = new ServerService($super);
$vault = new CredentialVault($super);

// The catalog is needed by the scheduling checks (and by the installation
// fixture's foreign key), so it is imported once, up front.
Harness::catalog(true);
$n8n = Harness::application('n8n');

/* ------------------------------------------------------------ registration */

section('Registering a deployment target');

$node = $servers->register([
    'name' => 'app-node-01',
    'hostname' => 'app-node-01.cloudhost247.net',
    'ip_address' => '198.51.100.10',
    'server_type' => 'vps',
    'provider' => 'hetzner',
    'region' => 'eu-central',
    'cpu_cores' => 8,
    'memory_mb' => 16384,
    'storage_mb' => 204800,
    'docker_enabled' => true,
    'accepts_new_installs' => true,
    'agent_endpoint' => 'https://198.51.100.10:8443',
    'agent_secret' => 'harness-agent-secret-0123456789',
    'ssh_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAC3NzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    'ssh_username' => 'cloudhost',
    'tags' => ['eu', 'docker', 'prod'],
]);

T::ok('the server is registered', (int) $node['id'] > 0);
T::is('and starts in pending, never online', ServerService::STATUS_PENDING, $node['status']);
T::is('the hostname is normalised', 'app-node-01.cloudhost247.net', $node['hostname']);
T::ok('docker capability is recorded', $node['capabilities']['docker']);
T::ok('kubernetes capability is not', !$node['capabilities']['kubernetes']);
T::is('capacity is total minus zero allocated', 8000, $node['capacity']['cpu_millicores']['total']);
T::is('with everything free', 16384, $node['capacity']['memory_mb']['free']);
T::is('tags round-trip', ['eu', 'docker', 'prod'], $node['tags']);
T::ok('an agent was registered with it', $node['agent']['id'] !== null);
T::is('and no heartbeat has happened yet', null, $node['agent']['last_heartbeat_at']);
T::ok('metrics are unknown, not zero', !$node['metrics_known']);
T::is('and no metrics are presented', null, $node['metrics']);

$serverId = (int) $node['id'];

T::throws('a duplicate hostname is refused', ValidationException::class, function () use ($servers) {
    $servers->register([
        'name' => 'Duplicate', 'hostname' => 'app-node-01.cloudhost247.net',
        'ip_address' => '198.51.100.11', 'server_type' => 'vps', 'cpu_cores' => 2,
        'memory_mb' => 2048, 'storage_mb' => 20480,
    ]);
});
T::throws('an invalid IP is refused', ValidationException::class, function () use ($servers) {
    $servers->register([
        'name' => 'Bad IP', 'hostname' => 'bad-ip.example.test', 'ip_address' => 'not-an-ip',
        'server_type' => 'vps', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_mb' => 20480,
    ]);
});
T::throws('an unknown server type is refused', ValidationException::class, function () use ($servers) {
    $servers->register([
        'name' => 'Bad type', 'hostname' => 'bad-type.example.test', 'ip_address' => '198.51.100.12',
        'server_type' => 'mainframe', 'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_mb' => 20480,
    ]);
});
T::throws('a cPanel server must have the cPanel capability', ValidationException::class, function () use ($servers) {
    $servers->register([
        'name' => 'cPanel node', 'hostname' => 'cpanel.example.test', 'ip_address' => '198.51.100.13',
        'server_type' => 'cpanel', 'cpanel_enabled' => false, 'cpu_cores' => 4,
        'memory_mb' => 8192, 'storage_mb' => 102400,
    ]);
});
T::throws('a Kubernetes target is refused while the feature is off', StateException::class, function () use ($servers) {
    $servers->register([
        'name' => 'k8s cluster', 'hostname' => 'k8s.example.test', 'ip_address' => '198.51.100.14',
        'server_type' => 'kubernetes', 'kubernetes_enabled' => true, 'cpu_cores' => 16,
        'memory_mb' => 65536, 'storage_mb' => 1024000,
    ]);
});
Settings::override('kubernetes_enabled', '1');
$k8s = $servers->register([
    'name' => 'k8s cluster', 'hostname' => 'k8s.example.test', 'ip_address' => '198.51.100.14',
    'server_type' => 'kubernetes', 'kubernetes_enabled' => true, 'cpu_cores' => 16,
    'memory_mb' => 65536, 'storage_mb' => 1024000,
]);
T::is('and accepted once the platform enables it', 'kubernetes', $k8s['server_type']);
Settings::override('kubernetes_enabled', '0');

$cpanel = $servers->register([
    'name' => 'cPanel node', 'hostname' => 'cpanel.example.test', 'ip_address' => '198.51.100.13',
    'server_type' => 'cpanel', 'cpu_cores' => 4, 'memory_mb' => 8192, 'storage_mb' => 102400,
    'credentials' => ['whm_api_token' => 'WHM-API-TOKEN-SECRET-VALUE'],
]);
T::ok('a cPanel server is registered with its own capability', $cpanel['capabilities']['cpanel']);

T::throws('a customer cannot register servers', AuthorizationException::class, function () use ($customer) {
    (new ServerService($customer))->register([
        'name' => 'Sneaky', 'hostname' => 'sneaky.example.test', 'ip_address' => '203.0.113.9',
        'server_type' => 'vps', 'cpu_cores' => 1, 'memory_mb' => 1024, 'storage_mb' => 10240,
    ]);
});
T::throws('a customer cannot list servers either', AuthorizationException::class, function () use ($customer) {
    (new ServerService($customer))->listing();
});
T::throws('the worker cannot register infrastructure', AuthorizationException::class, function () use ($worker) {
    (new ServerService($worker))->register([
        'name' => 'Worker node', 'hostname' => 'worker.example.test', 'ip_address' => '203.0.113.10',
        'server_type' => 'vps', 'cpu_cores' => 1, 'memory_mb' => 1024, 'storage_mb' => 10240,
    ]);
});

/* -------------------------------------------------------------- credentials */

section('Credentials are sealed at rest and never presented');

$stored = json_encode($servers->present($serverId));
T::notContains('the presentation has no private key material', 'OPENSSH PRIVATE KEY', $stored);
T::notContains('and no agent secret', 'harness-agent-secret', $stored);
T::notContains('and no WHM token on the cPanel server', 'WHM-API-TOKEN-SECRET-VALUE',
    json_encode($servers->present((int) $cpanel['id'])));

$rawRow = Db::first('server_credentials', ['server_id' => $serverId, 'credential_type' => CredentialVault::TYPE_SSH_KEY]);
T::ok('a credential row exists', $rawRow !== null);
T::notContains('the stored ciphertext is not the plaintext', 'OPENSSH PRIVATE KEY', (string) $rawRow['encrypted_secret']);
T::is('it is tagged with a key version', Crypto::CURRENT_KEY_VERSION, (int) $rawRow['key_version']);
T::is('and carries a blind fingerprint', 64, strlen((string) $rawRow['secret_fingerprint']));
T::is('the username is not secret', 'cloudhost', $rawRow['username']);

$machineVault = new CredentialVault($worker);
T::is('the worker can decrypt it for an adapter call',
    "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAC3NzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    $machineVault->reveal((int) $rawRow['id']));
T::is('and can look it up by server and type', 'cloudhost',
    Db::first('server_credentials', ['id' => (int) $rawRow['id']])['username']);
T::is('revealFor finds it without an id',
    "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAC3NzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    $machineVault->revealFor($serverId, CredentialVault::TYPE_SSH_KEY));
T::is('a credential the server does not have is null, never invented', null,
    $machineVault->revealFor($serverId, CredentialVault::TYPE_KUBE_CONFIG));

$describe = $vault->describe($serverId);
T::ok('describe lists credentials', count($describe) >= 1);
T::notContains('describe carries no secret', 'OPENSSH', json_encode($describe));
T::ok('describe reports rotation need', array_key_exists('needs_rotation', $describe[0]));
T::ok('and an unverified credential is flagged', in_array('unverified', $vault->attention($serverId)[0]['reasons'], true));

$vault->markVerified((int) $rawRow['id'], true);
T::is('verification is recorded', true,
    (bool) Db::first('server_credentials', ['id' => (int) $rawRow['id']])['verified']);

$rotated = $vault->rotate((int) $rawRow['id'], 'ROTATED-KEY-MATERIAL', 'Quarterly rotation');
T::is('rotation replaces the secret', 'ROTATED-KEY-MATERIAL',
    $machineVault->reveal((int) $rawRow['id']));
T::is('and records who rotated it', 'admin:1', $rotated['rotated_by']);
T::ok('rotation resets verification', !$rotated['verified']);
T::throws('rotating to the same secret is refused', ValidationException::class, function () use ($vault, $rawRow) {
    $vault->rotate((int) $rawRow['id'], 'ROTATED-KEY-MATERIAL');
});
T::throws('a customer cannot rotate credentials', AuthorizationException::class, function () use ($customer, $rawRow) {
    (new CredentialVault($customer))->rotate((int) $rawRow['id'], 'ATTACKER-KEY');
});

$revoked = $vault->revoke((int) $rawRow['id'], 'Decommissioned key');
T::ok('revocation succeeds', $revoked);
$revokedRow = Db::first('server_credentials', ['id' => (int) $rawRow['id']]);
T::is('the ciphertext is destroyed', null, $revokedRow['encrypted_secret']);
T::is('and the status is revoked', CredentialVault::STATUS_REVOKED, $revokedRow['status']);
T::throws('a revoked credential cannot be revealed', NotFoundException::class, function () use ($machineVault, $rawRow) {
    $machineVault->reveal((int) $rawRow['id']);
});
T::throws('an unknown credential type is refused', ValidationException::class, function () use ($vault, $serverId) {
    $vault->store($serverId, 'root_password', 'x');
});

$auditRows = Audit::search(['action' => Audit::SERVER_CREDENTIAL_ROTATED]);
T::ok('credential rotation is audited', count($auditRows) >= 2);
$auditJson = json_encode(Audit::present($auditRows[0]));
T::notContains('and the audit entry contains no secret', 'ROTATED-KEY-MATERIAL', $auditJson);
T::notContains('nor does the log', 'ROTATED-KEY-MATERIAL',
    json_encode(Db::fetch('logs', [], ['order' => 'id', 'dir' => 'desc', 'limit' => 20])));

/* ----------------------------------------------------------------- capacity */

section('Capacity is reserved atomically and never over-committed');

T::is('nothing is allocated yet', 0, $servers->capacity($serverId)['cpu_millicores']['allocated']);
$servers->allocate($serverId, ['cpu_millicores' => 2000, 'memory_mb' => 4096, 'storage_mb' => 20480]);
$capacity = $servers->capacity($serverId);
T::is('a reservation is recorded', 2000, $capacity['cpu_millicores']['allocated']);
T::is('and reduces what is free', 12288, $capacity['memory_mb']['free']);
T::is(' utilisation is reported as a percentage', 25.0, $capacity['percent_used']['cpu']);

$servers->allocate($serverId, ['cpu_millicores' => 6000, 'memory_mb' => 12288, 'storage_mb' => 100000]);
T::is('a second reservation stacks', 8000, $servers->capacity($serverId)['cpu_millicores']['allocated']);

$refused = T::throws('a third reservation that does not fit is refused', ResourceInsufficientException::class,
    function () use ($servers, $serverId) {
        $servers->allocate($serverId, ['cpu_millicores' => 1000, 'memory_mb' => 1024, 'storage_mb' => 1024]);
    });
T::is('with a machine-readable code', 'DEPLOYMENT_RESOURCE_INSUFFICIENT', $refused->errorCode());
T::is('and the free capacity that caused it', 0, $refused->context()['free']['cpu_millicores']);
T::is('the failed reservation changed nothing', 8000, $servers->capacity($serverId)['cpu_millicores']['allocated']);

$servers->release($serverId, ['cpu_millicores' => 2000, 'memory_mb' => 4096, 'storage_mb' => 20480]);
T::is('releasing frees capacity again', 6000, $servers->capacity($serverId)['cpu_millicores']['allocated']);
$servers->release($serverId, ['cpu_millicores' => 99999, 'memory_mb' => 99999, 'storage_mb' => 99999]);
T::is('releasing more than was allocated clamps at zero', 0, $servers->capacity($serverId)['cpu_millicores']['allocated']);
T::is('cpu and memory clamp at zero', 0, $servers->capacity($serverId)['memory_mb']['allocated']);
T::is('storage keeps what was genuinely reserved', 1, $servers->capacity($serverId)['storage_mb']['allocated']);
$servers->release($serverId, ['storage_mb' => 1]);
T::is('and a matching release clears it', 0, $servers->capacity($serverId)['storage_mb']['allocated']);

$shrunk = T::throws('capacity may not shrink below what is allocated', StateException::class,
    function () use ($servers, $serverId) {
        $servers->allocate($serverId, ['cpu_millicores' => 4000, 'memory_mb' => 8192, 'storage_mb' => 40960]);
        $servers->update($serverId, ['cpu_cores' => 2]);
    });
T::is('with a clear code', 'SERVER_CAPACITY_LOCKED', $shrunk->errorCode());
$servers->update($serverId, ['cpu_cores' => 16, 'memory_mb' => 32768]);
T::is('growing capacity is allowed', 16000, $servers->capacity($serverId)['cpu_millicores']['total']);
$servers->release($serverId, ['cpu_millicores' => 4000, 'memory_mb' => 8192, 'storage_mb' => 40960]);

section('Installation limits and server state gate new work');

$limited = $servers->register([
    'name' => 'limited-node', 'hostname' => 'limited.example.test', 'ip_address' => '198.51.100.20',
    'server_type' => 'vps', 'cpu_cores' => 4, 'memory_mb' => 8192, 'storage_mb' => 102400,
    'max_installations' => 1, 'agent_endpoint' => 'https://198.51.100.20:8443',
    'agent_secret' => 'limited-agent-secret',
]);
Db::insert('installations', [
    'reference' => Str::reference('APP'), 'uuid' => Str::uuid4(), 'customer_id' => 42,
    'application_id' => (int) $n8n['id'],
    'application_version_id' => (int) Harness::version('n8n')['id'],
    'server_id' => (int) $limited['id'], 'name' => 'occupied',
    'container_project' => 'c42occupied', 'status' => 'healthy',
    'created_at' => Clock::now(), 'updated_at' => Clock::now(),
]);
$capped = T::throws('a server at its installation cap refuses new work', ResourceInsufficientException::class,
    function () use ($servers, $limited) {
        $servers->allocate((int) $limited['id'], ['cpu_millicores' => 500, 'memory_mb' => 512, 'storage_mb' => 1024]);
    });
T::is('and reports the cap', 1, $capped->context()['installations']['max']);

$servers->setStatus($serverId, ServerService::STATUS_MAINTENANCE, 'Kernel upgrade');
$maintained = $servers->present($serverId);
T::is('maintenance is recorded', ServerService::STATUS_MAINTENANCE, $maintained['status']);
T::ok('and stops new installations', !$maintained['accepts_new_installs']);
T::throws('a server in maintenance cannot be allocated', ServerUnavailableException::class,
    function () use ($servers, $serverId) {
        $servers->allocate($serverId, ['cpu_millicores' => 100, 'memory_mb' => 100, 'storage_mb' => 100]);
    });
$servers->setStatus($serverId, ServerService::STATUS_PENDING);
T::throws('a server cannot be declared online before its agent checks in', StateException::class,
    function () use ($servers, $serverId) {
        $servers->setStatus($serverId, ServerService::STATUS_ONLINE);
    });

/* --------------------------------------------------------------- heartbeat */

section('Heartbeats and metrics come from the agent, never from an assumption');

$agentRow = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$beat = $servers->heartbeat($agentRow['agent_uuid'], [
    'agent_version' => '1.4.2',
    'ip' => '198.51.100.10',
    'capabilities' => ['docker', 'compose', 'backups', 'ssl', 'metrics'],
    'capacity' => ['cpu_cores' => 8, 'memory_mb' => 16384, 'storage_mb' => 204800],
]);
T::is('the first heartbeat brings the server online', ServerService::STATUS_ONLINE, $beat['status']);
$afterBeat = $servers->present($serverId);
T::is('the agent version is recorded', '1.4.2', $afterBeat['agent']['version']);
T::ok('the heartbeat timestamp is set', $afterBeat['agent']['last_heartbeat_at'] !== null);
T::ok('and is not stale', !$afterBeat['agent']['stale']);
T::is('the agent is active', 'active', Db::first('agents', ['id' => (int) $agentRow['id']])['status']);
T::ok('metrics are still unknown until a sample arrives', !$afterBeat['metrics_known']);
T::is('health is unknown without evidence', 'unknown', $servers->health($serverId)['health']);

$servers->recordMetrics($serverId, [
    'cpu_percent' => 12.5, 'memory_used_mb' => 4096, 'memory_percent' => 25.0,
    'storage_used_mb' => 51200, 'storage_percent' => 25.0, 'load_average' => 0.42,
    'uptime_seconds' => 86400, 'container_count' => 7, 'restart_count' => 0, 'health' => 'healthy',
    'containers' => [['name' => 'n8n', 'state' => 'running']],
]);
$health = $servers->health($serverId);
T::is('a real sample produces a real verdict', 'healthy', $health['health']);
T::is('with the reason it believes that', 'The agent reported a healthy state.', $health['reason']);
T::is('and the numbers that came with it', 12.5, $health['last_sample']['cpu_percent']);
T::is('container detail is preserved', 'n8n', $health['last_sample']['containers'][0]['name']);

$servers->recordMetrics($serverId, ['cpu_percent' => 99.0, 'health' => 'unhealthy']);
T::is('an unhealthy report is honoured', 'unhealthy', $servers->health($serverId)['health']);

Clock::travel(3600);
T::ok('an hour without a heartbeat is stale', $servers->isStale($servers->row($serverId)));
$staleHealth = $servers->health($serverId);
T::is('and a stale server reports unknown, not the last known state', 'unknown', $staleHealth['health']);
T::contains('with an honest explanation', 'older than the stale window', $staleHealth['reason']);
T::is('the presentation downgrades it to offline', ServerService::STATUS_OFFLINE,
    $servers->present($serverId)['status']);
T::is('while the stored status is untouched', ServerService::STATUS_ONLINE,
    $servers->present($serverId)['stored_status']);
T::throws('and it cannot be allocated', ServerUnavailableException::class, function () use ($servers, $serverId) {
    $servers->allocate($serverId, ['cpu_millicores' => 100, 'memory_mb' => 100, 'storage_mb' => 100]);
});
$marked = $servers->markStale();
T::ok('cron marks quiet servers stale', $marked >= 1);
T::is('the stored status becomes offline', ServerService::STATUS_OFFLINE,
    Db::first('servers', ['id' => $serverId])['status']);
$staleEvents = Events::emitted(Events::SERVER_STALE);
T::ok('and an event is emitted for alerting', count($staleEvents) >= 1);

$servers->heartbeat($agentRow['agent_uuid'], ['agent_version' => '1.4.2']);
T::is('a fresh heartbeat clears the stale flag', 0, (int) Db::first('servers', ['id' => $serverId])['heartbeat_stale']);
T::is('and brings the server back online', ServerService::STATUS_ONLINE,
    Db::first('servers', ['id' => $serverId])['status']);
T::throws('an unknown agent cannot report', NotFoundException::class, function () use ($servers) {
    $servers->heartbeat('00000000-0000-4000-8000-000000000000', []);
});

/* --------------------------------------------------------- agent protocol */

section('The agent protocol is signed, timestamped and replay-protected');

$agent = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$path = '/agent/v1/heartbeat';
$body = Str::jsonEncode(['hello' => 'world']);
$signed = AgentAuthenticator::sign($agent, 'POST', $path, $body);

T::ok('signing produces a signature', strlen($signed['signature']) === 64);
T::ok('and a fresh nonce', strlen($signed['nonce']) >= 16);
T::is('each signing uses a different nonce', false,
    $signed['nonce'] === AgentAuthenticator::sign($agent, 'POST', $path, $body)['nonce']);

$applyHeaders = function (array $headers) {
    Http::resetOverrides();
    foreach ($headers as $name => $value) {
        Http::overrideHeader($name, $value);
    }
    Http::overrideIp('198.51.100.10');
};

$applyHeaders($signed['headers']);
$actor = AgentAuthenticator::authenticate('POST', $path, $body);
T::ok('a correctly signed request authenticates', $actor->isAgent());
T::is('and resolves to the agent actor', (int) $agent['id'], $actor->agentId);
T::is('bound to its server', $serverId, $actor->serverId);
T::is('with an hmac auth method', 'agent_hmac', $actor->authMethod);
T::ok('an agent may report metrics', $actor->can(Rbac::INSTALL_METRICS_VIEW));
T::ok('but may not manage servers', !$actor->can(Rbac::SERVER_MANAGE));
T::ok('and may not delete installations', !$actor->can(Rbac::INSTALL_DELETE));
T::is('the request counter advanced', 1, (int) Db::first('agents', ['id' => (int) $agent['id']])['request_count']);

// Re-send the very same signed request: the nonce is already claimed.
$applyHeaders($signed['headers']);
$replay = T::throws('the same request cannot be replayed', AuthenticationException::class,
    function () use ($path, $body) {
        return AgentAuthenticator::authenticate('POST', $path, $body);
    });
T::is('with a replay reason in the log, a generic answer to the caller', 'replayed_nonce',
    $replay->context()['reason']);
T::is('and a 401 status', 401, $replay->status());

$tampered = AgentAuthenticator::sign($agent, 'POST', $path, $body);
$tampered['headers'][AgentAuthenticator::HEADER_SIGNATURE] = str_repeat('0', 64);
$applyHeaders($tampered['headers']);
$badSignature = T::throws('a forged signature is refused', AuthenticationException::class, function () use ($path, $body) {
    return AgentAuthenticator::authenticate('POST', $path, $body);
});
T::is('as a signature failure', 'bad_signature', $badSignature->context()['reason']);

$wrongBody = AgentAuthenticator::sign($agent, 'POST', $path, '{"hello":"world"}');
$applyHeaders($wrongBody['headers']);
T::throws('a signature over a different body is refused', AuthenticationException::class,
    function () use ($path) {
        return AgentAuthenticator::authenticate('POST', $path, '{"hello":"tampered"}');
    });

$wrongPath = AgentAuthenticator::sign($agent, 'POST', '/agent/v1/deploy', $body);
$applyHeaders($wrongPath['headers']);
T::throws('a signature for another path is refused', AuthenticationException::class,
    function () use ($path, $body) {
        return AgentAuthenticator::authenticate('POST', $path, $body);
    });

$old = AgentAuthenticator::sign($agent, 'POST', $path, $body);
$old['headers'][AgentAuthenticator::HEADER_TIMESTAMP] = (string) (Clock::timestamp() - 3600);
$applyHeaders($old['headers']);
$skew = T::throws('a stale timestamp is refused', AuthenticationException::class, function () use ($path, $body) {
    return AgentAuthenticator::authenticate('POST', $path, $body);
});
T::is('as timestamp skew', 'timestamp_skew', $skew->context()['reason']);

Http::resetOverrides();
T::throws('a request with no headers is refused', AuthenticationException::class, function () use ($path, $body) {
    return AgentAuthenticator::authenticate('POST', $path, $body);
});
Http::resetOverrides();
Http::overrideHeader(AgentAuthenticator::HEADER_UUID, 'no-such-agent');
Http::overrideHeader(AgentAuthenticator::HEADER_TIMESTAMP, (string) Clock::timestamp());
Http::overrideHeader(AgentAuthenticator::HEADER_NONCE, str_repeat('a', 32));
Http::overrideHeader(AgentAuthenticator::HEADER_SIGNATURE, str_repeat('b', 64));
$unknown = T::throws('an unregistered agent is refused', AuthenticationException::class, function () use ($path, $body) {
    return AgentAuthenticator::authenticate('POST', $path, $body);
});
T::is('without revealing whether the UUID exists', 'unknown_agent', $unknown->context()['reason']);
Http::resetOverrides();

$rotatedSecret = $servers->rotateAgentSecret((int) $agent['id']);
T::ok('rotating the agent secret returns a new one', strlen($rotatedSecret['shared_secret']) >= 32);
T::isnt('and it differs from the old secret', 'harness-agent-secret-0123456789', $rotatedSecret['shared_secret']);
T::is('old nonces are cleared so nothing can be replayed across the rotation', 0,
    Db::count('agent_nonces', ['agent_id' => (int) $agent['id']]));
$newAgent = Db::first('agents', ['id' => (int) $agent['id']]);
$newSigned = AgentAuthenticator::sign($newAgent, 'POST', $path, $body);
$applyHeaders($newSigned['headers']);
T::ok('the new secret authenticates', AgentAuthenticator::authenticate('POST', $path, $body)->isAgent());

$oldSigned = AgentAuthenticator::sign(
    ['agent_uuid' => $agent['agent_uuid'], 'encrypted_shared_secret' => $agent['encrypted_shared_secret'],
        'key_version' => $agent['key_version'], 'id' => (int) $agent['id'], 'server_id' => $serverId,
        'status' => 'active'],
    'POST', $path, $body
);
$applyHeaders($oldSigned['headers']);
T::throws('a request signed with the rotated-away secret is refused', AuthenticationException::class,
    function () use ($path, $body) {
        return AgentAuthenticator::authenticate('POST', $path, $body);
    });
Http::resetOverrides();

$servers->setAgentStatus((int) $agent['id'], 'suspended');
$suspendedAgent = Db::first('agents', ['id' => (int) $agent['id']]);
$suspSigned = AgentAuthenticator::sign($suspendedAgent, 'POST', $path, $body);
$applyHeaders($suspSigned['headers']);
T::throws('a suspended agent is refused', AuthenticationException::class, function () use ($path, $body) {
    return AgentAuthenticator::authenticate('POST', $path, $body);
});
$servers->setAgentStatus((int) $agent['id'], 'active');

$rejected = Db::first('agents', ['id' => (int) $agent['id']]);
T::ok('rejections are counted on the agent record', (int) $rejected['rejected_count'] > 0);
T::ok('and audited', count(Audit::search(['action' => Audit::AGENT_REQUEST_REJECTED])) > 0);
T::is('expired nonces can be pruned', 0, AgentAuthenticator::pruneNonces());

/* ------------------------------------------------------------ agent client */

section('Only the worker may dispatch, and every failure has a code');

T::ok('dispatch is allowed in a worker/CLI/test context', AgentClient::assertWorkerContext());
$client = new AgentClient($worker);

T::throws('an unknown operation never leaves the platform', ValidationException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, 'rm_rf', []);
    });

Harness::onHttp('/agent/v1/ping', ['status' => 200, 'body' => json_encode([
    'ok' => true, 'agent_version' => '1.4.2', 'data' => ['docker' => '27.3.1', 'compose' => 'v2.29.7'],
])]);
$ping = $client->ping($serverId);
T::ok('a reachable agent answers a ping', $ping['reachable']);
T::is('with real version data', '27.3.1', $ping['data']['docker']);
$calls = Http::clientCalls('/agent/v1/ping');
T::is('one signed request was sent', 1, count($calls));
T::ok('to the agent endpoint over HTTPS', strpos($calls[0]['url'], 'https://198.51.100.10:8443/agent/v1/ping') === 0);
T::is('the signature header is present', 64,
    strlen(isset($calls[0]['options']['headers'][AgentAuthenticator::HEADER_SIGNATURE])
        ? $calls[0]['options']['headers'][AgentAuthenticator::HEADER_SIGNATURE] : ''));
T::notContains('and no secret is in the request options', 'harness-agent-secret', json_encode($calls[0]['options']));

Harness::onHttp('/agent/v1/deploy', ['status' => 200, 'body' => json_encode([
    'ok' => true, 'data' => ['project' => 'c42n8n', 'containers' => 2, 'duration_ms' => 4210],
])]);
$deploy = $client->dispatch($serverId, AgentClient::OP_DEPLOY, [
    'project' => 'c42n8n', 'compose' => 'services: {}', 'environment' => ['DB_PASSWORD' => 'super-secret'],
]);
T::ok('a deploy dispatch succeeds', $deploy['ok']);
T::is('and returns the agent data', 'c42n8n', $deploy['data']['project']);
T::is('with a request id for correlation', 'REQ', substr($deploy['request_id'], 0, 3));
$deployCall = Http::clientCalls('/agent/v1/deploy');
T::notContains('the recorded call log has no secret', 'super-secret', json_encode($deployCall));

Harness::onHttp('/agent/v1/update', ['status' => 500, 'body' => json_encode([
    'ok' => false, 'error_code' => 'IMAGE_PULL_FAILED',
    'message' => 'pull access denied for n8nio/n8n:9.9.9',
])]);
$pullFailure = T::throws('an image pull failure maps to its own exception', ImagePullException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, AgentClient::OP_UPDATE, ['project' => 'c42n8n']);
    });
T::is('with the agent error code preserved', 'APPLICATION_IMAGE_PULL_FAILED', $pullFailure->errorCode());
T::ok('and marked retryable', $pullFailure->isRetryable());
T::contains('and the agent message', 'pull access denied', $pullFailure->getMessage());

Harness::onHttp('/agent/v1/health', ['status' => 500, 'body' => json_encode([
    'ok' => false, 'error_code' => 'HEALTH_CHECK_FAILED', 'message' => 'Container did not become healthy.',
])]);
T::throws('a health failure maps to HealthCheckException', HealthCheckException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, AgentClient::OP_HEALTH, ['project' => 'c42n8n']);
    });

Harness::onHttp('/agent/v1/stop', ['status' => 0, 'error' => 'Connection timed out after 120 seconds']);
$unreachable = T::throws('an unreachable agent is a server-unavailable failure', ServerUnavailableException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, AgentClient::OP_STOP, ['project' => 'c42n8n'], ['retries' => 0]);
    });
T::is('with a transport code', 'AGENT_UNREACHABLE', $unreachable->context()['agent_error_code']);
T::ok('and is retryable', $unreachable->isRetryable());

Harness::onHttp('/agent/v1/restart', ['status' => 401, 'body' => json_encode([
    'ok' => false, 'error_code' => 'SIGNATURE_INVALID', 'message' => 'Bad signature',
])]);
T::throws('a rejected dispatch is an authentication failure', AuthenticationException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, AgentClient::OP_RESTART, ['project' => 'c42n8n'], ['retries' => 0]);
    });

Harness::onHttp('/agent/v1/remove', ['status' => 200, 'body' => json_encode([
    'ok' => false, 'error_code' => 'PROJECT_NOT_FOUND', 'message' => 'No such project',
])]);
T::throws('a 200 with ok:false is still a failure', NotFoundException::class,
    function () use ($client, $serverId) {
        $client->dispatch($serverId, AgentClient::OP_REMOVE, ['project' => 'ghost'], ['retries' => 0]);
    });

$noAgent = $servers->register([
    'name' => 'no-agent-node', 'hostname' => 'no-agent.example.test', 'ip_address' => '198.51.100.30',
    'server_type' => 'vps', 'cpu_cores' => 2, 'memory_mb' => 4096, 'storage_mb' => 51200,
]);
$missing = T::throws('a server with no agent cannot be dispatched to', ServerUnavailableException::class,
    function () use ($client, $noAgent) {
        $client->dispatch((int) $noAgent['id'], AgentClient::OP_DEPLOY, []);
    });
T::is('with a clear code', 'AGENT_NOT_REGISTERED', $missing->context()['error_code']);

$cpanelClient = T::throws('a Docker operation is refused on a cPanel server', StateException::class,
    function () use ($client, $cpanel) {
        $client->dispatch((int) $cpanel['id'], AgentClient::OP_DEPLOY, []);
    });
T::is('because the capabilities do not match', 'SERVER_CAPABILITY_MISSING', $cpanelClient->context()['error_code']);

T::is('the last dispatch is available for diagnostics', $serverId, $client->lastCall()['server_id']);

/* ------------------------------------------------------------- scheduling */

section('Scheduling only offers servers that can actually take the workload');

$requirements = ['cpu_millicores' => 1000, 'memory_mb' => 1024, 'storage_mb' => 13312];

// Maintenance switched the node out of rotation; bringing it back online does not
// silently re-open it for new work, so the operator re-enables it explicitly.
T::ok('coming back online does not auto-reopen the node',
    !$servers->present($serverId)['accepts_new_installs']);
$servers->update($serverId, ['accepts_new_installs' => true]);
T::ok('an explicit update reopens it', $servers->present($serverId)['accepts_new_installs']);

$candidates = $servers->candidatesFor((int) $n8n['id'], $requirements);
T::ok('candidates are returned for every server', count($candidates) >= 3);
$byHost = [];
foreach ($candidates as $candidate) {
    $byHost[$candidate['hostname']] = $candidate;
}
T::ok('the online Docker node is eligible', $byHost['app-node-01.cloudhost247.net']['eligible']);
T::ok('a cPanel node is not eligible for a container app',
    !$byHost['cpanel.example.test']['eligible']);
T::contains('and says why', 'HOSTING_TYPE_UNSUPPORTED',
    json_encode($byHost['cpanel.example.test']['reasons']));
T::ok('a Kubernetes target is not eligible for a compose app',
    !$byHost['k8s.example.test']['eligible']);
T::ok('the server with no agent is not eligible',
    !$byHost['no-agent.example.test']['eligible']);
T::contains('because it has never reported', 'SERVER_PENDING',
    json_encode($byHost['no-agent.example.test']['reasons']));
T::is('eligible servers sort first', true, $candidates[0]['eligible']);
T::ok('a scheduling list carries no credentials', !isset($candidates[0]['credentials']));

$selected = $servers->selectFor((int) $n8n['id'], $requirements);
T::is('selection returns the eligible node', $serverId, $selected);

$huge = ['cpu_millicores' => 999000, 'memory_mb' => 999999, 'storage_mb' => 9999999];
$noRoom = T::throws('an impossible workload has no server', ServerUnavailableException::class,
    function () use ($servers, $n8n, $huge) {
        $servers->selectFor((int) $n8n['id'], $huge);
    });
T::is('with a scheduling error code', 'NO_ELIGIBLE_SERVER', $noRoom->context()['error_code']);
T::ok('and a histogram of why', isset($noRoom->context()['reasons']['CPU_INSUFFICIENT']));

$servers->setStatus($serverId, ServerService::STATUS_MAINTENANCE, 'Test');
$maintenanceCandidates = $servers->candidatesFor((int) $n8n['id'], $requirements);
$eligibleCount = 0;
foreach ($maintenanceCandidates as $candidate) {
    if ($candidate['eligible']) {
        $eligibleCount++;
    }
}
T::is('taking the only node out of service leaves nothing eligible', 0, $eligibleCount);
$servers->setStatus($serverId, ServerService::STATUS_ONLINE);

$filtered = $servers->candidatesFor((int) $n8n['id'], $requirements, 'cpanel');
$anyEligible = false;
foreach ($filtered as $candidate) {
    $anyEligible = $anyEligible || $candidate['eligible'];
}
T::ok('filtering by an unsupported hosting type yields nothing eligible', !$anyEligible);

$stats = $servers->statistics();
T::ok('statistics count the registry', $stats['total'] >= 4);
T::ok('and how many accept installs', $stats['accepting_installs'] >= 1);
T::is('the online count matches what was reported', 1, $stats['online']);

$listing = $servers->listing(['status' => ServerService::STATUS_ONLINE]);
T::is('listing filters by status', 1, count($listing));
$searched = $servers->listing(['q' => 'hetzner']);
T::ok('listing searches provider, name, host and IP', count($searched) >= 1);

$detail = $servers->detail($serverId);
T::ok('detail includes agents', count($detail['agents']) >= 1);
T::ok('and recent metrics', count($detail['recent_metrics']) >= 1);
T::notContains('but still no secret', 'ROTATED-KEY-MATERIAL', json_encode($detail));
T::ok('agent secrets are never presented', !$detail['agents'][0]['has_secret']
    || !isset($detail['agents'][0]['shared_secret']));

T::throws('removing a server with installations is refused', StateException::class,
    function () use ($servers, $limited) {
        $servers->remove((int) $limited['id']);
    });
T::ok('removing an empty server works', $servers->remove((int) $noAgent['id'], 'Never came online'));
T::is('and revokes its agents', 0, Db::count('agents', ['server_id' => (int) $noAgent['id'], 'status' => ['notin', ['revoked']]]));
T::is('a removed server is gone from the registry', null, $servers->find((int) $noAgent['id']));
T::is('the installation cap counts only live installations', 1,
    Db::count('installations', ['server_id' => (int) $limited['id'], 'status' => ['notin', ['deleted', 'terminated']]]));

T::ok('the audit chain still verifies', Audit::verifyChain()['valid']);

Harness::shutdown();
exit(T::summary());
