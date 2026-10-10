<?php
/** Offline contract tests for the gated, read-only Cloudflare DNS inventory. */
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Core\Db as CloudflareDb;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Provider\CloudflareApi;
use CloudHost247\Cloudflare\Provider\CloudflareClient;
use CloudHost247\Cloudflare\Provider\TransportInterface;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\DnsInventoryService;
use CloudHost247\Cloudflare\Service\IntegrationStatus;

require_once __DIR__ . '/bootstrap.php';
// Sibling addon root: absolute php-wasm mount first, native-checkout relative path fallback (CI runs native PHP).
$ch247CfRoot = is_file('/cloudflare/autoload.php') ? '/cloudflare' : dirname(__DIR__) . '/../cloudhost247cloudflare';
require_once $ch247CfRoot . '/autoload.php';
if (!defined('WHMCS')) {
    define('WHMCS', true);
}
require_once $ch247CfRoot . '/cloudhost247cloudflare.php';

class Phase12CloudflareInventoryTransport implements TransportInterface
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
            'body' => $body,
        ];
        if (!$this->responses) {
            throw new RuntimeException('The Cloudflare inventory fake transport ran out of responses.');
        }
        return array_shift($this->responses);
    }
}

class Phase12CloudflareInventoryServiceRepository extends ServiceRepository
{
    public $service;
    public $lookups = [];

    public function __construct(array $service)
    {
        $this->service = $service;
    }

    public function forCustomer($serviceId, $customerId)
    {
        $this->lookups[] = [(int) $serviceId, (int) $customerId];
        if ((int) ($this->service['id'] ?? 0) !== (int) $serviceId
            || (int) ($this->service['customer_id'] ?? 0) !== (int) $customerId) {
            throw new NotFoundException('Cloudflare service not found.');
        }
        return $this->service;
    }
}

class Phase12CloudflareInventoryAccountRepository extends AccountRepository
{
    private $transport;
    public $contexts = [];

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function api($accountId, array $context = [], $allowDisabled = false)
    {
        $this->contexts[] = [(int) $accountId, $context];
        $client = new CloudflareClient(
            'https://api.cloudflare.com/client/v4', 'phase12-offline-token', 0, $context, $this->transport
        );
        return [new CloudflareApi($client, Phase12CloudflareDnsFixture::ACCOUNT_ID), [
            'account_id' => Phase12CloudflareDnsFixture::ACCOUNT_ID,
        ]];
    }
}

class Phase12CloudflareDnsFixture
{
    const ACCOUNT_ID = '1234567890abcdef1234567890abcdef';
    const ZONE_ID = 'abcdef0123456789abcdef0123456789';
    const SERVICE_ID = 701;
    const CUSTOMER_ID = 4402;

    public static function service(array $overrides = [])
    {
        return array_merge([
            'id' => self::SERVICE_ID,
            'customer_id' => self::CUSTOMER_ID,
            'account_id' => 91,
            'zone_id' => self::ZONE_ID,
            'zone_name' => 'example.com',
            'origin_domain' => 'example.com',
            'addon_parent_domain' => null,
            'features_json' => json_encode(['dns.manage' => true]),
            'status' => 'ACTIVE',
            'whmcs_service_status' => 'Active',
            'whmcs_addon_status' => null,
            'addon_parent_status' => 'Active',
        ], $overrides);
    }

    public static function zone(array $overrides = [])
    {
        return array_merge([
            'id' => self::ZONE_ID,
            'name' => 'example.com',
            'account' => ['id' => self::ACCOUNT_ID],
            'status' => 'active',
            'paused' => false,
        ], $overrides);
    }

    public static function aRecord(array $overrides = [])
    {
        return array_merge([
            'id' => '00112233445566778899aabbccddeeff',
            'type' => 'A',
            'name' => 'example.com',
            'content' => '192.0.2.25',
            'ttl' => 1,
            'proxied' => false,
        ], $overrides);
    }

    public static function response($result, $status = 200)
    {
        return [
            'status' => (int) $status,
            'headers' => [],
            'body' => json_encode(['success' => true, 'result' => $result, 'errors' => []]),
        ];
    }

    public static function accountSettings($adapterEnabled)
    {
        CloudflareDb::exec('DELETE FROM tbladdonmodules WHERE module=?', ['cloudhost247cloudflare']);
        CloudflareDb::exec('INSERT INTO tbladdonmodules (module,setting,value) VALUES (?,?,?)',
            ['cloudhost247cloudflare', 'service_enabled', '1']);
        if ($adapterEnabled !== null) {
            CloudflareDb::exec('INSERT INTO tbladdonmodules (module,setting,value) VALUES (?,?,?)',
                ['cloudhost247cloudflare', 'dns_inventory_adapter_enabled', $adapterEnabled ? '1' : '0']);
        }
    }

    public static function inventory($service = null, $transport = null)
    {
        $service = $service ?: self::service();
        $transport = $transport ?: new Phase12CloudflareInventoryTransport([]);
        return new DnsInventoryService(
            new Phase12CloudflareInventoryServiceRepository($service),
            new Phase12CloudflareInventoryAccountRepository($transport)
        );
    }
}

section('Phase 12 Cloudflare DNS inventory is gated, service-scoped and read-only');
putenv('CLOUDFLARE_ENABLED=');
$phase12Pdo = new PDO('sqlite::memory:');
$phase12Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$phase12Pdo->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)');
$phase12Pdo->exec('CREATE TABLE mod_ch247cf_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER,
    customer_id INTEGER, service_id INTEGER, action TEXT, entity_type TEXT,
    entity_id TEXT, success INTEGER, details_json TEXT, ip_address TEXT,
    user_agent TEXT, error_code TEXT, created_at TEXT
)');
CloudflareDb::setPdo($phase12Pdo, 'sqlite');
Phase12CloudflareDnsFixture::accountSettings(null);
T::is('missing DNS inventory setting defaults to disabled', false, IntegrationStatus::dnsInventoryAdapterEnabled());
T::is('adapter gate setting is blank and disabled by default', '',
    cloudhost247cloudflare_config()['fields']['dns_inventory_adapter_enabled']['Default']);
T::is('Cloudflare addon advances one patch version for the App Cloud bridge', '1.0.3',
    cloudhost247cloudflare_config()['version']);

Phase12CloudflareDnsFixture::accountSettings(false);
$disabledTransport = new Phase12CloudflareInventoryTransport([]);
$disabledInventory = Phase12CloudflareDnsFixture::inventory(null, $disabledTransport);
T::throws('default-off adapter refuses inventory reads', ConfigurationException::class, function () use ($disabledInventory) {
    $disabledInventory->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
});
T::is('disabled adapter makes no Cloudflare request', 0, count($disabledTransport->requests));

Phase12CloudflareDnsFixture::accountSettings(true);
$entitlementTransport = new Phase12CloudflareInventoryTransport([]);
$noDnsEntitlement = Phase12CloudflareDnsFixture::inventory(
    Phase12CloudflareDnsFixture::service(['features_json' => json_encode(['dns.manage' => false])]),
    $entitlementTransport
);
T::throws('service without DNS entitlement is rejected before provider access',
    \CloudHost247\Cloudflare\Core\AuthorizationException::class, function () use ($noDnsEntitlement) {
        $noDnsEntitlement->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
    });
T::is('DNS entitlement rejection makes no Cloudflare request', 0, count($entitlementTransport->requests));

$scopeTransport = new Phase12CloudflareInventoryTransport([]);
$scopeInventory = Phase12CloudflareDnsFixture::inventory(null, $scopeTransport);
T::throws('another WHMCS customer cannot resolve this service', NotFoundException::class, function () use ($scopeInventory) {
    $scopeInventory->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID + 1, Phase12CloudflareDnsFixture::SERVICE_ID);
});
T::is('failed service ownership lookup makes no Cloudflare request', 0, count($scopeTransport->requests));

$linkedDomainTransport = new Phase12CloudflareInventoryTransport([]);
$wrongLinkedDomain = Phase12CloudflareDnsFixture::inventory(
    Phase12CloudflareDnsFixture::service(['origin_domain' => 'different.example']), $linkedDomainTransport
);
T::throws('service zone must match its linked WHMCS domain',
    \CloudHost247\Cloudflare\Core\AuthorizationException::class, function () use ($wrongLinkedDomain) {
        $wrongLinkedDomain->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
    });
T::is('linked-domain mismatch makes no Cloudflare request', 0, count($linkedDomainTransport->requests));

$txtSecret = 'verification=phase12-private-dns-content';
$txtRecord = [
    'id' => 'ffeeddccbbaa99887766554433221100',
    'type' => 'TXT',
    'name' => '_acme-challenge.example.com',
    'content' => $txtSecret,
    'ttl' => 300,
    'proxied' => null,
];
$successTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response(Phase12CloudflareDnsFixture::zone()),
    Phase12CloudflareDnsFixture::response([Phase12CloudflareDnsFixture::aRecord(), $txtRecord]),
]);
$services = new Phase12CloudflareInventoryServiceRepository(Phase12CloudflareDnsFixture::service());
$accounts = new Phase12CloudflareInventoryAccountRepository($successTransport);
$inventory = new DnsInventoryService($services, $accounts);
$result = $inventory->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
T::is('inventory is scoped to the authenticated customer service', [
    Phase12CloudflareDnsFixture::SERVICE_ID, Phase12CloudflareDnsFixture::CUSTOMER_ID,
], $services->lookups[0]);
T::is('zone identity and linked domain are returned after strict validation', [
    'service_id' => Phase12CloudflareDnsFixture::SERVICE_ID,
    'zone_id' => Phase12CloudflareDnsFixture::ZONE_ID,
    'zone_name' => 'example.com',
], [
    'service_id' => $result['service_id'],
    'zone_id' => $result['zone_id'],
    'zone_name' => $result['zone_name'],
]);
T::is('only the validated DNS inventory projection is returned', [
    Phase12CloudflareDnsFixture::aRecord() + ['priority' => null, 'comment' => null],
    $txtRecord + ['priority' => null, 'comment' => null],
], $result['records']);
T::is('Cloudflare receives only two read-only GETs for zone identity and DNS records',
    ['GET', 'GET'], array_column($successTransport->requests, 'method'));
T::is('zone lookup precedes paginated DNS inventory', [
    '/client/v4/zones/' . Phase12CloudflareDnsFixture::ZONE_ID,
    '/client/v4/zones/' . Phase12CloudflareDnsFixture::ZONE_ID . '/dns_records',
], array_map(function ($request) {
    return parse_url($request['url'], PHP_URL_PATH);
}, $successTransport->requests));
T::is('API request context retains the verified service/customer scope', [[
    91, ['service_id' => Phase12CloudflareDnsFixture::SERVICE_ID,
        'customer_id' => Phase12CloudflareDnsFixture::CUSTOMER_ID],
]], $accounts->contexts);

$audit = CloudflareDb::query('SELECT action, success, details_json FROM mod_ch247cf_audit_logs ORDER BY id DESC LIMIT 1')[0];
$auditDetails = json_decode((string) $audit['details_json'], true);
T::is('successful inventory read has an audit summary', 'DNS_INVENTORY_READ', $audit['action']);
T::is('audit summary includes record count and type totals only', [
    'record_count' => 2,
    'record_types' => ['A' => 1, 'TXT' => 1],
], $auditDetails);
T::notContains('audit summary never stores DNS record content', $txtSecret, $audit['details_json']);
T::notContains('audit summary never stores customer record names', '_acme-challenge.example.com', $audit['details_json']);
T::is('inventory call has no mutation methods or request bodies', [null, null],
    array_column($successTransport->requests, 'body'));

$wrongZoneTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response(Phase12CloudflareDnsFixture::zone(['name' => 'other.example'])),
]);
$wrongZone = Phase12CloudflareDnsFixture::inventory(null, $wrongZoneTransport);
T::throws('provider zone must match service zone identity and name', CloudflareException::class, function () use ($wrongZone) {
    $wrongZone->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
});
T::is('zone mismatch prevents any DNS-record request', 1, count($wrongZoneTransport->requests));

$outsideRecord = Phase12CloudflareDnsFixture::aRecord([
    'id' => '11112222333344445555666677778888',
    'name' => 'example.net',
    'content' => '198.51.100.11',
]);
$invalidRecordTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response(Phase12CloudflareDnsFixture::zone()),
    Phase12CloudflareDnsFixture::response([$outsideRecord]),
]);
$invalidRecordInventory = Phase12CloudflareDnsFixture::inventory(null, $invalidRecordTransport);
T::throws('record outside the linked zone fails the whole inventory', CloudflareException::class, function () use ($invalidRecordInventory) {
    $invalidRecordInventory->listForCustomer(Phase12CloudflareDnsFixture::CUSTOMER_ID, Phase12CloudflareDnsFixture::SERVICE_ID);
});
$failedAudit = CloudflareDb::query('SELECT success, details_json, error_code FROM mod_ch247cf_audit_logs ORDER BY id DESC LIMIT 1')[0];
T::is('invalid inventory is audited as a failure without record details', 0, (int) $failedAudit['success']);
T::is('failure audit metadata is empty and contains no DNS data', null, $failedAudit['details_json']);
T::notContains('failure audit code does not contain record content', '198.51.100.11', json_encode($failedAudit));

$apiTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response(['unexpected' => ['id' => 'not-a-list']]),
]);
$apiClient = new CloudflareClient('https://api.cloudflare.com/client/v4', 'offline', 0, [], $apiTransport);
$api = new CloudflareApi($apiClient, Phase12CloudflareDnsFixture::ACCOUNT_ID);
T::throws('malformed DNS page is rejected rather than treated as an inventory', CloudflareException::class, function () use ($api) {
    $api->listDnsRecords(Phase12CloudflareDnsFixture::ZONE_ID);
});
$emptyObjectTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response((object) []),
]);
$emptyObjectApi = new CloudflareApi(new CloudflareClient(
    'https://api.cloudflare.com/client/v4', 'offline', 0, [], $emptyObjectTransport
), Phase12CloudflareDnsFixture::ACCOUNT_ID);
T::throws('empty JSON object is not accepted as an empty DNS list', CloudflareException::class, function () use ($emptyObjectApi) {
    $emptyObjectApi->listDnsRecords(Phase12CloudflareDnsFixture::ZONE_ID);
});
$invalidEntryTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response([[]]),
]);
$invalidEntryApi = new CloudflareApi(new CloudflareClient(
    'https://api.cloudflare.com/client/v4', 'offline', 0, [], $invalidEntryTransport
), Phase12CloudflareDnsFixture::ACCOUNT_ID);
T::throws('JSON list entries must be provider objects, not nested lists', CloudflareException::class, function () use ($invalidEntryApi) {
    $invalidEntryApi->listDnsRecords(Phase12CloudflareDnsFixture::ZONE_ID);
});

$zoneListTransport = new Phase12CloudflareInventoryTransport([
    Phase12CloudflareDnsFixture::response(['not-a-list' => ['id' => Phase12CloudflareDnsFixture::ZONE_ID]]),
]);
$zoneListClient = new CloudflareClient('https://api.cloudflare.com/client/v4', 'offline', 0, [], $zoneListTransport);
$zoneListApi = new CloudflareApi($zoneListClient, Phase12CloudflareDnsFixture::ACCOUNT_ID);
T::throws('malformed zone page is rejected rather than treated as an inventory', CloudflareException::class, function () use ($zoneListApi) {
    $zoneListApi->listZones();
});

$fullZonePage = array_fill(0, 50, ['id' => Phase12CloudflareDnsFixture::ZONE_ID]);
$zoneBoundResponses = [];
for ($page = 0; $page < 20; $page++) $zoneBoundResponses[] = Phase12CloudflareDnsFixture::response($fullZonePage);
$zoneBoundTransport = new Phase12CloudflareInventoryTransport($zoneBoundResponses);
$zoneBoundApi = new CloudflareApi(new CloudflareClient(
    'https://api.cloudflare.com/client/v4', 'offline', 0, [], $zoneBoundTransport
), Phase12CloudflareDnsFixture::ACCOUNT_ID);
$zoneBoundError = T::throws('full final zone page fails instead of returning a partial list', CloudflareException::class, function () use ($zoneBoundApi) {
    $zoneBoundApi->listZones();
});
T::is('zone page bound failure has a stable API error code', 'CLOUDFLARE_API_ERROR',
    $zoneBoundError ? $zoneBoundError->errorCode() : null);
T::is('zone pagination stops exactly at the configured page bound', 20, count($zoneBoundTransport->requests));

$fullRecordPage = array_fill(0, 100, ['id' => 'record']);
$recordBoundResponses = [];
for ($page = 0; $page < 50; $page++) $recordBoundResponses[] = Phase12CloudflareDnsFixture::response($fullRecordPage);
$recordBoundTransport = new Phase12CloudflareInventoryTransport($recordBoundResponses);
$recordBoundApi = new CloudflareApi(new CloudflareClient(
    'https://api.cloudflare.com/client/v4', 'offline', 0, [], $recordBoundTransport
), Phase12CloudflareDnsFixture::ACCOUNT_ID);
$recordBoundError = T::throws('full final DNS page fails instead of returning a partial list', CloudflareException::class, function () use ($recordBoundApi) {
    $recordBoundApi->listDnsRecords(Phase12CloudflareDnsFixture::ZONE_ID);
});
T::is('DNS page bound failure has a stable API error code', 'CLOUDFLARE_API_ERROR',
    $recordBoundError ? $recordBoundError->errorCode() : null);
T::is('DNS pagination stops exactly at the configured page bound', 50, count($recordBoundTransport->requests));

CloudflareDb::reset();
exit(T::summary());
