<?php
/** Offline interoperability: standalone CLI sender → signed WHMCS ingress. */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/agent/HeartbeatSender.php';

use Ch247Agent\HeartbeatSender;
use Ch247Apps\Api\AgentHeartbeatIngress;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Settings;
use Ch247Apps\Servers\ServerService;

Harness::boot();
Harness::relaxRateLimits();
$secret = str_repeat('a', 64);
$node = (new ServerService(Harness::adminActor()))->register([
    'name' => 'CLI node', 'hostname' => 'cli.example.test',
    'ip_address' => '198.51.100.21', 'server_type' => 'vps',
    'cpu_cores' => 2, 'memory_mb' => 2048, 'storage_mb' => 20480,
    'agent_endpoint' => 'https://198.51.100.21:8443', 'agent_secret' => $secret,
]);
$agent = Db::first('agents', ['id' => (int) $node['agent']['id']]);
$ingress = new AgentHeartbeatIngress();
$sent = [];
$transport = function ($request) use ($ingress, &$sent) {
    $sent[] = $request;
    Http::resetOverrides();
    foreach ($request['headers'] as $name => $value) {
        Http::overrideHeader($name, $value);
    }
    Http::overrideIp('203.0.113.22');
    $reply = $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $request['body']);
    return ['status' => $reply['status'], 'body' => json_encode($reply['body'])];
};
$sender = new HeartbeatSender('https://portal.example.com/whmcs/',
    $agent['agent_uuid'], $secret, '1.0.0', $transport);

section('Standalone sender signs only the documented fixed endpoint and body');
$request = $sender->buildRequest();
T::is('physical URL includes WHMCS subdirectory',
    'https://portal.example.com/whmcs/modules/addons/cloudhost247apps/api/agent.php', $request['url']);
T::is('body is only the agent version', ['agent_version' => '1.0.0'], json_decode($request['body'], true));
T::is('signed body verifies against existing platform crypto', true, Crypto::verifySignature(
    $request['headers']['X-CH247-Signature'], $secret, 'POST', AgentHeartbeatIngress::PATH,
    $request['body'], $request['headers']['X-CH247-Timestamp'], $request['headers']['X-CH247-Nonce']));
T::is('wrong signing path fails', false, Crypto::verifySignature(
    $request['headers']['X-CH247-Signature'], $secret, 'POST', '/agent/v1/deploy',
    $request['body'], $request['headers']['X-CH247-Timestamp'], $request['headers']['X-CH247-Nonce']));
T::is('second request gets fresh nonce', false,
    $request['headers']['X-CH247-Nonce'] === $sender->buildRequest()['headers']['X-CH247-Nonce']);
T::notContains('secret is not sent in request', $secret, json_encode($request));

section('Offline end-to-end WHMCS signed heartbeat; no fake server commands');
T::throws('ingress default-off fails closed without exposing server response', RuntimeException::class,
    function () use ($sender) { $sender->send(); });
T::is('no server heartbeat while disabled', null,
    Db::first('servers', ['id' => (int) $node['id']])['last_heartbeat_at']);
Settings::override('agent_heartbeat_ingress_enabled', '1');
$result = T::nothrow('signed heartbeat accepted through real ingress', function () use ($sender) {
    return $sender->send();
});
T::is('online means key checked in', 'online', $result['status']);
T::is('source IP not agent-provided', '203.0.113.22', Db::first('agents', ['id' => $agent['id']])['last_seen_ip']);
T::is('no metrics fabricated', 0, Db::count('metrics'));
T::is('two calls send fresh nonces', false,
    $sent[0]['headers']['X-CH247-Nonce'] === $sent[1]['headers']['X-CH247-Nonce']);
T::notContains('no shared secret in serialized requests', $secret, json_encode($sent));
T::is('replayed signed request rejected by ingress', 401,
    $ingress->dispatch('POST', AgentHeartbeatIngress::PATH, $sent[1]['body'])['status']);

section('Invalid configuration and unsafe network destinations fail before transport');
foreach (['http://portal.example.com', 'https://user:pass@portal.example.com',
    'https://portal.example.com?path=/evil', 'https://portal.example.com/#other',
    'https://portal.example.com/../other', "https://portal.example.com\r\nX: bad",
    'https://portal.example.com/%2e%2e', 'https://portal.example.com:999999/'] as $badUrl) {
    T::throws('reject unsafe URL ' . $badUrl, InvalidArgumentException::class,
        function () use ($badUrl, $agent, $secret) {
            new HeartbeatSender($badUrl, $agent['agent_uuid'], $secret);
        });
}
T::throws('unregistered UUID format rejected', InvalidArgumentException::class,
    function () use ($secret) { new HeartbeatSender('https://portal.example.com', 'forged', $secret); });
T::throws('weak secret refused', InvalidArgumentException::class,
    function () use ($agent) { new HeartbeatSender('https://portal.example.com', $agent['agent_uuid'], 'weak'); });
T::throws('invalid version refused', InvalidArgumentException::class,
    function () use ($agent, $secret) { new HeartbeatSender('https://portal.example.com', $agent['agent_uuid'], $secret, "invalid\nversion"); });
$badStatus = new HeartbeatSender('https://portal.example.com', $agent['agent_uuid'], $secret, '1.0.0',
    function () { return ['status' => 302, 'body' => '{"secret":"never reveal"}']; });
$e = T::throws('redirect is not followed or treated as success', RuntimeException::class,
    function () use ($badStatus) { $badStatus->send(); });
T::notContains('provider response hidden from error', 'never reveal', $e->getMessage());
$badBody = new HeartbeatSender('https://portal.example.com', $agent['agent_uuid'], $secret, '1.0.0',
    function () { return ['status' => 200, 'body' => '{"data":{"status":"online"}}']; });
T::throws('malformed success is not a successful heartbeat', RuntimeException::class,
    function () use ($badBody) { $badBody->send(); });

section('Secret is loaded only from a private file');
$path = sys_get_temp_dir() . '/ch247-agent-secret-' . bin2hex(random_bytes(5));
file_put_contents($path, $secret . "\n");
chmod($path, 0644);
putenv('CH247_AGENT_WHMCS_URL=https://portal.example.com');
putenv('CH247_AGENT_UUID=' . $agent['agent_uuid']);
putenv('CH247_AGENT_SECRET_FILE=' . $path);
T::throws('readable-by-others secret file rejected', RuntimeException::class,
    function () { HeartbeatSender::fromEnvironment(); });
chmod($path, 0600);
$loaded = T::nothrow('0600 file loads without WHMCS boot', function () { return HeartbeatSender::fromEnvironment(); });
// Build once: each call intentionally generates a fresh nonce.
$one = $loaded->buildRequest();
T::ok('private-file secret signs a verifiable request', Crypto::verifySignature(
    $one['headers']['X-CH247-Signature'], $secret, 'POST', HeartbeatSender::SIGNING_PATH,
    $one['body'], $one['headers']['X-CH247-Timestamp'], $one['headers']['X-CH247-Nonce']));
@unlink($path);
putenv('CH247_AGENT_SECRET_FILE');
putenv('CH247_AGENT_WHMCS_URL');
putenv('CH247_AGENT_UUID');

exit(T::summary());
