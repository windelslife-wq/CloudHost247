<?php
/** Heartbeat-only ingress: offline security and state tests, no real server. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Apps\Api\AgentHeartbeatIngress;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Settings;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();
$servers = new ServerService(Harness::adminActor());
$node = $servers->register([
    'name' => 'Heartbeat node', 'hostname' => 'beat.example.test',
    'ip_address' => '198.51.100.10', 'server_type' => 'vps',
    'cpu_cores' => 2, 'memory_mb' => 4096, 'storage_mb' => 40960,
    'docker_enabled' => true, 'agent_endpoint' => 'https://198.51.100.10:8443',
    'agent_secret' => 'test-agent-secret-0123456789',
]);
$serverId = (int) $node['id'];
$agent = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$ingress = new AgentHeartbeatIngress();
$send = function ($body, $signedAgent = null) use ($ingress, $agent) {
    $signedAgent = $signedAgent ?: $agent;
    Http::resetOverrides();
    $signed = AgentAuthenticator::sign($signedAgent, 'POST', AgentHeartbeatIngress::PATH, $body);
    foreach ($signed['headers'] as $name => $value) {
        Http::overrideHeader($name, $value);
    }
    Http::overrideIp('203.0.113.8');
    return $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $body);
};

section('Independent off switch and strict fixed operation');
T::ok('default switch is off', !Settings::bool('agent_heartbeat_ingress_enabled', false));
T::is('disabled refuses without DB heartbeat', 503, $send('{}')['status']);
T::is('no heartbeat while disabled', null, Db::first('servers', ['id' => $serverId])['last_heartbeat_at']);
Settings::override('agent_heartbeat_ingress_enabled', '1');
T::is('no GET operation', 404, $ingress->dispatch('GET', AgentHeartbeatIngress::PATH, '{}')['status']);
T::is('no alternate operation', 404, $ingress->dispatch('POST', '/agent/v1/deploy', '{}')['status']);
T::is('oversized body rejected', 413, $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, str_repeat('A', 4097))['status']);

section('Signed, assigned agent may report liveness only');
$body = '{"agent_version":"1.2.3"}';
$success = $send($body);
T::is('valid heartbeat accepted', 200, $success['status']);
T::is('server now online', ServerService::STATUS_ONLINE, Db::first('servers', ['id' => $serverId])['status']);
T::is('version stored', '1.2.3', Db::first('servers', ['id' => $serverId])['agent_version']);
T::is('source IP is transport-derived', '203.0.113.8', Db::first('agents', ['id' => (int) $agent['id']])['last_seen_ip']);
T::is('no metrics invented', 0, Db::count('metrics'));
T::is('response contains no server ID or key', ['status', 'recorded_at'], array_keys($success['body']['data']));
T::is('same signed request cannot be replayed', 401,
    $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $body)['status']);
T::is('tampered body fails signature', 401,
    $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, '{}')['status']);
Http::resetOverrides();
T::is('missing HMAC headers rejected', 401,
    $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, '{}')['status']);

section('Unsupported data cannot mutate capacity, status or metrics');
$before = Db::first('servers', ['id' => $serverId]);
foreach (['{"server_id":99}', '{"metrics":{"health":"healthy"}}',
    '{"capacity":{"cpu_cores":999}}', '{"status":"degraded"}',
    '{"agent_version":123}', '{"agent_version":"\u0000invalid"}',
    '[]', '{broken'] as $bad) {
    T::is('invalid report rejected: ' . $bad, 422, $send($bad)['status']);
}
T::is('capacity unchanged', $before['cpu_cores'], Db::first('servers', ['id' => $serverId])['cpu_cores']);
T::is('last heartbeat unchanged', $before['last_heartbeat_at'], Db::first('servers', ['id' => $serverId])['last_heartbeat_at']);
T::is('no metrics inserted', 0, Db::count('metrics'));

section('No disabled or replaced agent may resurrect a server');
Db::update('servers', ['status' => ServerService::STATUS_DISABLED], ['id' => $serverId]);
T::is('disabled server rejects assigned agent', 403, $send('{}')['status']);
T::is('disabled status unchanged', ServerService::STATUS_DISABLED, Db::first('servers', ['id' => $serverId])['status']);
Db::update('servers', ['status' => ServerService::STATUS_PENDING, 'agent_id' => null], ['id' => $serverId]);
T::is('unassigned agent cannot reactivate', 403, $send('{}')['status']);
T::is('pending still pending', ServerService::STATUS_PENDING, Db::first('servers', ['id' => $serverId])['status']);
Db::update('servers', ['agent_id' => (int) $agent['id']], ['id' => $serverId]);
Db::update('agents', ['status' => 'revoked'], ['id' => (int) $agent['id']]);
T::is('revoked secret cannot report', 401, $send('{}')['status']);

exit(T::summary());
