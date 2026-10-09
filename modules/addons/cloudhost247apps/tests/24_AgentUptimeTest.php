<?php
/** Real Linux kernel uptime, independently gated; never a health verdict. */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/agent/HeartbeatSender.php';
require_once dirname(__DIR__) . '/agent/UptimeReader.php';

use Ch247Agent\HeartbeatSender;
use Ch247Agent\UptimeReader;
use Ch247Apps\Api\AgentHeartbeatIngress;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Settings;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();
$secret = str_repeat('c', 64);
$servers = new ServerService(Harness::adminActor());
$node = $servers->register([
    'name' => 'Uptime node', 'hostname' => 'uptime.example.test',
    'ip_address' => '198.51.100.31', 'server_type' => 'vps',
    'cpu_cores' => 2, 'memory_mb' => 4096, 'storage_mb' => 40960,
    'agent_endpoint' => 'https://198.51.100.31:8443', 'agent_secret' => $secret,
]);
$id = (int) $node['id'];
$agent = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$ingress = new AgentHeartbeatIngress();
$send = function ($body) use ($ingress, $agent) {
    Http::resetOverrides();
    $signed = AgentAuthenticator::sign($agent, 'POST', AgentHeartbeatIngress::PATH, $body);
    foreach ($signed['headers'] as $name => $value) {
        Http::overrideHeader($name, $value);
    }
    Http::overrideIp('203.0.113.31');
    return $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $body);
};

section('Gate independence and exact schema before any write');
T::ok('uptime gate defaults off', !Settings::bool('agent_uptime_ingress_enabled', false));
Settings::override('agent_heartbeat_ingress_enabled', '1');
T::is('uptime not silently accepted', 503, $send('{"uptime_seconds":123}')['status']);
T::is('no implicit heartbeat on gated uptime', null, Db::first('servers', ['id' => $id])['last_heartbeat_at']);
T::is('no metric stored', 0, Db::count('metrics'));
Settings::override('agent_uptime_ingress_enabled', '1');
Settings::override('agent_heartbeat_ingress_enabled', '0');
T::is('uptime cannot bypass heartbeat master gate', 503, $send('{"uptime_seconds":123}')['status']);
Settings::override('agent_heartbeat_ingress_enabled', '1');
foreach (['{"uptime_seconds":-1}', '{"uptime_seconds":0.5}', '{"uptime_seconds":"123"}',
    '{"uptime_seconds":2147483648}', '{"uptime_seconds":null}',
    '{"uptime_seconds":123,"health":"healthy"}',
    '{"uptime_seconds":123,"sampled_at":"2099-01-01 00:00:00"}',
    '{"uptime_seconds":123,"metrics":{"cpu_percent":1}}'] as $bad) {
    T::is('invalid signed sample rejected: ' . $bad, 422, $send($bad)['status']);
}
T::is('malformed reports never write metrics', 0, Db::count('metrics'));
T::is('malformed reports never update heartbeat', null, Db::first('servers', ['id' => $id])['last_heartbeat_at']);

section('One real, bounded uptime sample is recorded with unknown health');
T::is('zero is valid on fresh boot', 200, $send('{"uptime_seconds":0}')['status']);
T::is('one metric written', 1, Db::count('metrics'));
$row = Db::first('metrics', ['server_id' => $id]);
T::is('zero not mistaken for missing', 0, (int) $row['uptime_seconds']);
T::is('no fabricated health', null, $row['health']);
T::is('no fabricated CPU', null, $row['cpu_percent']);
T::is('no fabricated memory', null, $row['memory_used_mb']);
T::is('timestamp set by WHMCS clock', \Ch247Apps\Core\Clock::now(), $row['sampled_at']);
T::is('health summary remains unknown', 'unknown', $servers->health($id)['health']);
T::is('latest metric shows real uptime only', 0, $servers->present($id)['metrics']['uptime_seconds']);

section('Only assigned agents on monitoring-enabled servers may report');
Db::update('servers', ['monitoring_enabled' => 0], ['id' => $id]);
T::is('monitoring-disabled server refuses metric', 403, $send('{"uptime_seconds":321}')['status']);
T::is('existing metric count unchanged', 1, Db::count('metrics'));
T::is('plain heartbeat still works', 200, $send('{}')['status']);
Db::update('servers', ['monitoring_enabled' => 1, 'agent_id' => null], ['id' => $id]);
T::is('unassigned agent cannot add uptime', 403, $send('{"uptime_seconds":321}')['status']);
T::is('no added sample', 1, Db::count('metrics'));
Db::update('servers', ['agent_id' => (int) $agent['id']], ['id' => $id]);

section('Standalone node parser and sender interoperate through signed ingress');
foreach (['0.15 0.00\n' => 0, '12345.67 30300.43\n' => 12345,
    '2147483647.99 33.21\n' => 2147483647] as $sample => $value) {
    $sample = str_replace('\\n', "\n", $sample);
    T::is('kernel uptime parsed conservatively', $value, UptimeReader::parse($sample));
}
foreach (['', 'nan 12.33', '-1.00 0.00', '1.0', '1.0 2.0 injected',
    '2147483648.00 20.00', '1e4 20.00', str_repeat('1', 129)] as $bad) {
    T::throws('kernel uptime input refused', RuntimeException::class,
        function () use ($bad) { UptimeReader::parse($bad); });
}
$sent = [];
$sender = new HeartbeatSender('https://portal.example.test/', $agent['agent_uuid'], $secret, '1.0.0',
    function ($request) use ($ingress, &$sent) {
        $sent[] = $request;
        Http::resetOverrides();
        foreach ($request['headers'] as $key => $value) { Http::overrideHeader($key, $value); }
        $reply = $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $request['body']);
        return ['status' => $reply['status'], 'body' => json_encode($reply['body'])];
    });
T::throws('sender rejects non-integer source instead of casting', InvalidArgumentException::class,
    function () use ($sender) { $sender->send('123'); });
\Ch247Apps\Core\Clock::travel(1);
T::is('sender source sample accepted', 'online', $sender->send(UptimeReader::parse("123.45 89.00\n"))['status']);
T::is('actual value persisted', 123, (int) $servers->latestMetrics($id)['uptime_seconds']);
T::is('only one bounded metric field sent', ['agent_version', 'uptime_seconds'],
    array_keys(json_decode($sent[0]['body'], true)));
T::is('no secret in outbound request', false, strpos(json_encode($sent), $secret) !== false);
T::is('replaying same signed metric rejected', 401,
    $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $sent[0]['body'])['status']);
T::is('replay does not add a sample', 2, Db::count('metrics'));
\Ch247Apps\Core\RateLimiter::configure(['agent.uptime' => [1, 3600]]);
T::is('independent sample rate bound fails closed', 429, $send('{"uptime_seconds":456}')['status']);
T::is('rate bound does not add a sample', 2, Db::count('metrics'));
T::is('uptime rate bound does not block liveness', 200, $send('{}')['status']);

exit(T::summary());
