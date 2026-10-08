<?php
/** Offline contract tests for the explicitly registered App Cloud DNS bridge. */
use Ch247Apps\Core\ConflictException as AppsConflictException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Domains\CloudflareDnsInventoryAdapter;
use Ch247Apps\Domains\DnsInventoryProviderRegistry;
use CloudHost247\Cloudflare\Core\Db as CloudflareDb;
use CloudHost247\Cloudflare\Provider\CloudflareApi;
use CloudHost247\Cloudflare\Provider\CloudflareClient;
use CloudHost247\Cloudflare\Provider\TransportInterface;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\DnsInventoryService;

require_once __DIR__ . '/bootstrap.php';
require_once '/cloudflare/autoload.php';

class Phase13CloudflareDnsBridgeTransport implements TransportInterface
{
    public $requests = [];
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function send($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = ['method' => strtoupper((string) $method), 'url' => (string) $url, 'body' => $body];
        if (!$this->responses) throw new RuntimeException('The Phase 13 Cloudflare fake transport ran out of responses.');
        return array_shift($this->responses);
    }
}

class Phase13CloudflareDnsBridgeServiceRepository extends ServiceRepository
{
    private $services;
    public $listLookups = [];
    public $serviceLookups = [];

    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function listForCustomer($customerId)
    {
        $customerId = (int) $customerId;
        $this->listLookups[] = $customerId;
        return array_values(array_filter($this->services, function ($service) use ($customerId) {
            return (int) ($service['customer_id'] ?? 0) === $customerId;
        }));
    }

    public function forCustomer($serviceId, $customerId)
    {
        $serviceId = (int) $serviceId;
        $customerId = (int) $customerId;
        $this->serviceLookups[] = [$serviceId, $customerId];
        foreach ($this->services as $service) {
            if ((int) ($service['id'] ?? 0) === $serviceId
                && (int) ($service['customer_id'] ?? 0) === $customerId) return $service;
        }
        throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare service not found.');
    }
}

class Phase13CloudflareDnsBridgeAccountRepository extends AccountRepository
{
    private $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function api($accountId, array $context = [], $allowDisabled = false)
    {
        $client = new CloudflareClient(
            'https://api.cloudflare.com/client/v4', 'phase13-offline-token', 0, $context, $this->transport
        );
        return [new CloudflareApi($client, Phase13CloudflareDnsBridgeFixture::ACCOUNT_ID), [
            'account_id' => Phase13CloudflareDnsBridgeFixture::ACCOUNT_ID,
        ]];
    }
}

class Phase13CloudflareDnsBridgeRawInventory
{
    private $recordName;

    public function __construct($recordName = 'bridge.example')
    {
        $this->recordName = $recordName;
    }

    public function listForDomain($customerId, $domain)
    {
        return [
            'zone_name' => 'bridge.example',
            'records' => [[
                'id' => '00112233445566778899aabbccddeeff', 'type' => 'A',
                'name' => $this->recordName, 'content' => '192.0.2.77', 'ttl' => 1,
                'proxied' => false, 'priority' => null, 'comment' => null,
                'provider_internal_data' => 'must-not-cross-the-bridge',
            ]],
        ];
    }
}

class Phase13CloudflareDnsBridgeFixture
{
    const CUSTOMER_ID = 713;
    const SERVICE_ID = 813;
    const ACCOUNT_ID = '1234567890abcdef1234567890abcdef';
    const ZONE_ID = 'abcdef0123456789abcdef0123456789';

    public static function service($id = self::SERVICE_ID, $domain = 'bridge.example', $customerId = self::CUSTOMER_ID)
    {
        return [
            'id' => (int) $id,
            'customer_id' => (int) $customerId,
            'account_id' => 91,
            'zone_id' => self::ZONE_ID,
            'zone_name' => $domain,
            'origin_domain' => $domain,
            'addon_parent_domain' => null,
            'features_json' => json_encode(['dns.manage' => true]),
            'status' => 'ACTIVE',
            'whmcs_service_status' => 'Active',
            'whmcs_addon_status' => null,
            'addon_parent_status' => 'Active',
        ];
    }

    public static function response($result)
    {
        return ['status' => 200, 'headers' => [], 'body' => json_encode([
            'success' => true, 'result' => $result, 'errors' => [],
        ])];
    }

    public static function transport()
    {
        return new Phase13CloudflareDnsBridgeTransport([
            self::response([
                'id' => self::ZONE_ID,
                'name' => 'bridge.example',
                'account' => ['id' => self::ACCOUNT_ID],
                'status' => 'active',
                'paused' => false,
            ]),
            self::response([[
                'id' => '00112233445566778899aabbccddeeff',
                'type' => 'A',
                'name' => 'bridge.example',
                'content' => '192.0.2.77',
                'ttl' => 1,
                'proxied' => false,
            ]]),
        ]);
    }
}

section('Phase 13 App Cloud DNS bridge is explicit, read-only and offline');
putenv('CLOUDFLARE_ENABLED=');
$phase13Pdo = new PDO('sqlite::memory:');
$phase13Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$phase13Pdo->exec('CREATE TABLE tbladdonmodules (module TEXT, setting TEXT, value TEXT)');
$phase13Pdo->exec('CREATE TABLE mod_ch247cf_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_id INTEGER,
    customer_id INTEGER, service_id INTEGER, action TEXT, entity_type TEXT,
    entity_id TEXT, success INTEGER, details_json TEXT, ip_address TEXT,
    user_agent TEXT, error_code TEXT, created_at TEXT
)');
CloudflareDb::setPdo($phase13Pdo, 'sqlite');
CloudflareDb::exec('INSERT INTO tbladdonmodules (module,setting,value) VALUES (?,?,?)',
    ['cloudhost247cloudflare', 'service_enabled', '1']);
CloudflareDb::exec('INSERT INTO tbladdonmodules (module,setting,value) VALUES (?,?,?)',
    ['cloudhost247cloudflare', 'dns_inventory_adapter_enabled', '1']);

DnsInventoryProviderRegistry::reset();
T::is('the App Cloud DNS registry starts with no providers', [], DnsInventoryProviderRegistry::registeredKeys());
T::is('Cloudflare is not auto-registered from the compute-provider registry or addon presence', false,
    DnsInventoryProviderRegistry::hasProvider('cloudflare'));
$missingProvider = T::throws('unregistered DNS provider fails closed', ProviderUnavailableException::class, function () {
    DnsInventoryProviderRegistry::forProvider('cloudflare');
});
T::is('unregistered provider has a stable DNS error code', 'DNS_PROVIDER_UNAVAILABLE',
    $missingProvider ? $missingProvider->errorCode() : null);

$transport = Phase13CloudflareDnsBridgeFixture::transport();
$repository = new Phase13CloudflareDnsBridgeServiceRepository([
    Phase13CloudflareDnsBridgeFixture::service(),
]);
$inventoryService = new DnsInventoryService(
    $repository,
    new Phase13CloudflareDnsBridgeAccountRepository($transport)
);
$adapter = new CloudflareDnsInventoryAdapter($inventoryService);
T::is('adapter declares a distinct DNS-provider key', 'cloudflare', $adapter->key());
DnsInventoryProviderRegistry::register($adapter);
T::is('provider is available only after explicit DNS-registry registration', true,
    DnsInventoryProviderRegistry::hasProvider('cloudflare'));

$inventory = DnsInventoryProviderRegistry::forProvider('cloudflare')
    ->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'BRIDGE.EXAMPLE.');
T::is('bridge returns a neutral provider/domain/records contract', [
    'provider' => 'cloudflare',
    'domain' => 'bridge.example',
    'records' => [[
        'id' => '00112233445566778899aabbccddeeff',
        'type' => 'A',
        'name' => 'bridge.example',
        'content' => '192.0.2.77',
        'ttl' => 1,
        'proxied' => false,
        'priority' => null,
        'comment' => null,
    ]],
], $inventory);
T::is('domain resolution first scopes the Cloudflare service lookup to this customer',
    [Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID], $repository->listLookups);
T::is('resolved service ownership is verified again before provider access', [[
    Phase13CloudflareDnsBridgeFixture::SERVICE_ID,
    Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID,
]], $repository->serviceLookups);
T::is('bridge issues only read-only GET calls', ['GET', 'GET'], array_column($transport->requests, 'method'));
T::is('bridge never sends request bodies', [null, null], array_column($transport->requests, 'body'));
T::is('registry does not confuse DNS inventory with compute provisioning', true,
    !\Ch247Apps\Infrastructure\ProviderRegistry::hasAdapter('cloudflare'));
$rawBridgeResult = (new CloudflareDnsInventoryAdapter(new Phase13CloudflareDnsBridgeRawInventory()))
    ->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'bridge.example');
T::is('cross-addon bridge reprojects exactly the allowlisted DNS fields', [
    'id', 'type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment',
], array_keys($rawBridgeResult['records'][0]));
T::notContains('provider-private metadata never crosses the App Cloud bridge',
    'must-not-cross-the-bridge', json_encode($rawBridgeResult));
$invalidProjection = new CloudflareDnsInventoryAdapter(
    new Phase13CloudflareDnsBridgeRawInventory('!invalid.bridge.example')
);
T::throws('bridge rejects malformed record owner names from provider projections',
    ProviderUnavailableException::class, function () use ($invalidProjection) {
        $invalidProjection->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'bridge.example');
    });

$duplicateTransport = new Phase13CloudflareDnsBridgeTransport([]);
$ambiguousRepository = new Phase13CloudflareDnsBridgeServiceRepository([
    Phase13CloudflareDnsBridgeFixture::service(814),
    Phase13CloudflareDnsBridgeFixture::service(815),
]);
$ambiguousService = new DnsInventoryService(
    $ambiguousRepository,
    new Phase13CloudflareDnsBridgeAccountRepository($duplicateTransport)
);
T::throws('ambiguous customer/domain mapping fails before provider calls',
    \CloudHost247\Cloudflare\Core\ValidationException::class, function () use ($ambiguousService) {
        $ambiguousService->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'bridge.example');
    });
T::is('ambiguous service mapping makes no provider requests', 0, count($duplicateTransport->requests));

$notFoundService = new DnsInventoryService(
    new Phase13CloudflareDnsBridgeServiceRepository([
        Phase13CloudflareDnsBridgeFixture::service(816, 'other.example'),
    ]),
    new Phase13CloudflareDnsBridgeAccountRepository($duplicateTransport)
);
T::throws('domain without a customer-owned Cloudflare service is not found',
    \CloudHost247\Cloudflare\Core\NotFoundException::class, function () use ($notFoundService) {
        $notFoundService->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'bridge.example');
    });
T::is('unmatched domain lookup makes no provider requests', 0, count($duplicateTransport->requests));

T::throws('duplicate provider registration is rejected', AppsConflictException::class, function () use ($adapter) {
    DnsInventoryProviderRegistry::register($adapter);
});
T::throws('invalid requested domain is rejected before adapter invocation',
    \Ch247Apps\Core\ValidationException::class, function () use ($adapter) {
        $adapter->listForDomain(Phase13CloudflareDnsBridgeFixture::CUSTOMER_ID, 'bridge..example');
    });

DnsInventoryProviderRegistry::reset();
CloudflareDb::reset();
exit(T::summary());
