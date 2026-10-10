<?php
/** Offline compatibility checks for the existing Cloudflare API boundary. */
require_once __DIR__ . '/bootstrap.php';
// Sibling addon root: absolute php-wasm mount first, native-checkout relative path fallback (CI runs native PHP).
$ch247CfRoot = is_file('/cloudflare/autoload.php') ? '/cloudflare' : dirname(__DIR__) . '/../cloudhost247cloudflare';
require_once $ch247CfRoot . '/autoload.php';
if (!defined('WHMCS')) {
    define('WHMCS', true);
}
require_once $ch247CfRoot . '/cloudhost247cloudflare.php';

class Phase11CloudflareFakeTransport implements \CloudHost247\Cloudflare\Provider\TransportInterface
{
    public $requests = [];
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function send($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = [
            'method' => strtoupper((string) $method),
            'url' => (string) $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => (int) $timeoutSeconds,
        ];
        if (!$this->responses) {
            throw new RuntimeException('The Cloudflare fake transport ran out of scripted responses.');
        }
        return array_shift($this->responses);
    }
}

function phase11CloudflareResponse(array $result, $status = 200)
{
    return [
        'status' => (int) $status,
        'headers' => [],
        'body' => json_encode(['success' => true, 'result' => $result, 'errors' => []]),
    ];
}

function phase11CloudflareFailure($status, $code, $message)
{
    return [
        'status' => (int) $status,
        'headers' => [],
        'body' => json_encode(['success' => false, 'result' => null,
            'errors' => [['code' => (int) $code, 'message' => (string) $message]]]),
    ];
}

section('Phase 11 Cloudflare compatibility uses injected offline transport');
T::is('Cloudflare addon includes the Phase 13 bridge patch release', '1.0.3',
    cloudhost247cloudflare_config()['version']);
$cloudflarePdo = new PDO('sqlite::memory:');
$cloudflarePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cloudflarePdo->exec('CREATE TABLE mod_ch247cf_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id TEXT NOT NULL,
    api_base_url TEXT NOT NULL,
    encrypted_api_token TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    rate_limit_until TEXT NULL,
    circuit_open_until TEXT NULL,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    last_success_at TEXT NULL,
    connection_status TEXT NULL,
    connection_tested_at TEXT NULL,
    last_failure_at TEXT NULL,
    last_error_code TEXT NULL,
    last_error_message TEXT NULL
)');
\CloudHost247\Cloudflare\Core\Db::setPdo($cloudflarePdo, 'sqlite');
\CloudHost247\Cloudflare\Core\Crypto::setKeyForTests('phase11-cloudflare-offline-test-key');

$cloudflareAccountId = '1234567890abcdef1234567890abcdef';
$zone = ['id' => 'zone-phase11-1', 'name' => 'example.test', 'status' => 'active', 'type' => 'full'];
$record = [
    'id' => 'record-phase11-1', 'type' => 'A', 'name' => 'example.test',
    'content' => '192.0.2.25', 'ttl' => 1, 'proxied' => false,
];
$transport = new Phase11CloudflareFakeTransport([
    phase11CloudflareResponse(['status' => 'active']),
    phase11CloudflareResponse(['id' => $cloudflareAccountId, 'name' => 'Offline Test Account']),
    phase11CloudflareResponse([$zone]),
    phase11CloudflareResponse([$zone]),
    phase11CloudflareResponse([$record]),
]);
$accountId = \CloudHost247\Cloudflare\Core\Db::insert('accounts', [
    'account_id' => $cloudflareAccountId,
    'api_base_url' => 'https://api.cloudflare.com/client/v4',
    'encrypted_api_token' => \CloudHost247\Cloudflare\Core\Crypto::seal('phase11-offline-token'),
    'enabled' => 1,
    'rate_limit_until' => null,
    'circuit_open_until' => null,
    'consecutive_failures' => 0,
    'connection_status' => 'not_tested',
]);

$accounts = new \CloudHost247\Cloudflare\Repository\AccountRepository($transport);
$verification = $accounts->test($accountId);
T::is('account verification succeeds against scripted Cloudflare responses', true, $verification['connected']);
T::is('verification returns the expected account label', 'Offline Test Account', $verification['account_name']);
T::is('verification response contains no stored API token', false,
    strpos(json_encode($verification), 'phase11-offline-token') !== false);
list($api) = $accounts->api($accountId);
$foundZone = $api->findZone('example.test');
T::is('existing zone lookup returns the scoped test zone', 'zone-phase11-1', $foundZone['id']);
$records = $api->listDnsRecords('zone-phase11-1');
T::is('existing DNS inventory returns the scripted record', [$record], $records);
T::is('account repository routes all five reads through the injected fake transport', 5,
    count($transport->requests));
T::is('compatibility probe and inventory operations remain GET-only',
    ['GET', 'GET', 'GET', 'GET', 'GET'], array_column($transport->requests, 'method'));
T::is('legacy Cloudflare API paths are reused for verification and inventory', [
    '/client/v4/user/tokens/verify',
    '/client/v4/accounts/' . $cloudflareAccountId,
    '/client/v4/zones',
    '/client/v4/zones',
    '/client/v4/zones/zone-phase11-1/dns_records',
], array_map(function ($request) {
    return parse_url($request['url'], PHP_URL_PATH);
}, $transport->requests));
T::is('the fake transport receives bearer authentication without exposing it in the result', true,
    isset($transport->requests[0]['headers']['Authorization'])
        && $transport->requests[0]['headers']['Authorization'] === 'Bearer phase11-offline-token');
T::ok('Cloudflare call results were produced without CurlTransport or live network access',
    count($transport->requests) === 5);

$errorTransport = new Phase11CloudflareFakeTransport([
    phase11CloudflareFailure(401, 10000, 'provider message must not escape: phase11-raw-token'),
]);
$errorAccounts = new \CloudHost247\Cloudflare\Repository\AccountRepository($errorTransport);
list($errorApi) = $errorAccounts->api($accountId);
$cloudflareError = T::throws('Cloudflare authentication failures normalize through the existing client',
    \CloudHost247\Cloudflare\Core\CloudflareException::class, function () use ($errorApi) {
        $errorApi->listZones();
    });
T::is('normalized authentication failures have a stable code', 'CLOUDFLARE_AUTH_FAILED',
    $cloudflareError ? $cloudflareError->errorCode() : null);
T::notContains('raw provider error phrases are not returned to the caller', 'phase11-raw-token',
    $cloudflareError ? $cloudflareError->getMessage() : '');
T::is('failed compatibility probe also uses only the fake transport', 1, count($errorTransport->requests));

\CloudHost247\Cloudflare\Core\Crypto::setKeyForTests(null);
\CloudHost247\Cloudflare\Core\Db::reset();
exit(T::summary());
