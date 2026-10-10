<?php
/** Offline API contract tests for the default-off customer DNS inventory route. */

require_once __DIR__ . '/bootstrap.php';
// Sibling addon root: absolute php-wasm mount first, native-checkout relative path fallback (CI runs native PHP).
$ch247CfRoot = is_file('/cloudflare/autoload.php') ? '/cloudflare' : dirname(__DIR__) . '/../cloudhost247cloudflare';
require_once $ch247CfRoot . '/autoload.php';

use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Domains\DnsInventoryProviderInterface;
use Ch247Apps\Domains\DnsInventoryProviderRegistry;
use Ch247Apps\Domains\DomainService;

class Phase14CloudflareServiceRepository extends \CloudHost247\Cloudflare\Repository\ServiceRepository
{
    private $service;

    public function __construct(array $service)
    {
        $this->service = $service;
    }

    public function listForCustomer($customerId)
    {
        return (int) $customerId === (int) $this->service['customer_id'] ? [$this->service] : [];
    }

    public function forCustomer($serviceId, $customerId)
    {
        if ((int) $serviceId === (int) $this->service['id']
            && (int) $customerId === (int) $this->service['customer_id']) return $this->service;
        throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare service not found.');
    }
}

class Phase14CloudflareAccountRepository extends \CloudHost247\Cloudflare\Repository\AccountRepository
{
    public $apiCalls = 0;

    public function api($accountId, array $context = [], $allowDisabled = false)
    {
        $this->apiCalls++;
        throw new RuntimeException('The Phase 14 independent-gate test must not reach Cloudflare transport.');
    }
}

class Phase14FakeDnsInventoryProvider implements DnsInventoryProviderInterface
{
    public $calls = [];

    public function key()
    {
        return 'cloudflare';
    }

    public function listForDomain($customerId, $domain)
    {
        $this->calls[] = ['customer_id' => (int) $customerId, 'domain' => (string) $domain];
        return [
            'provider' => 'cloudflare',
            'domain' => (string) $domain,
            'records' => [[
                'id' => '00112233445566778899aabbccddeeff',
                'type' => 'A',
                'name' => (string) $domain,
                'content' => '192.0.2.44',
                'ttl' => 300,
                'proxied' => false,
                'priority' => null,
                'comment' => null,
                'provider_internal_data' => 'must-not-cross-the-api',
            ]],
        ];
    }
}

function phase14InsertToken($actorType, $actorId, $role, array $scopes)
{
    $now = Clock::now();
    return Db::insert('api_tokens', [
        'name' => 'Phase 14 read-only DNS scope',
        'token_hash' => hash('sha256', 'phase14-' . $actorType . '-' . $actorId . '-' . json_encode($scopes)),
        'token_prefix' => 'phase14',
        'actor_type' => $actorType,
        'actor_id' => (int) $actorId,
        'actor_role' => $role,
        'actor_label' => 'Phase 14 test actor',
        'scopes' => json_encode($scopes),
        'ip_allowlist' => '',
        'active' => 1,
        'request_count' => 0,
        'last_used_ip' => null,
        'last_used_at' => null,
        'expires_at' => null,
        'revoked_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ]);
}

putenv('CH247APPS_DNS_INVENTORY_API_ENABLED=');
putenv('CLOUDFLARE_ENABLED=0');
Harness::boot();
Harness::relaxRateLimits();
DnsInventoryProviderRegistry::reset();

section('Phase 14 API is authenticated, default-off, and does not register Cloudflare while disabled');
T::is('the DNS inventory API switch defaults off', false, Settings::bool('dns_inventory_api_enabled', false));
T::is('the explicit DNS registry starts empty', [], DnsInventoryProviderRegistry::registeredKeys());

$ownerId = Harness::client(['firstname' => 'DNS', 'lastname' => 'Owner', 'email' => 'dns-owner@example.test']);
$otherOwnerId = Harness::client(['firstname' => 'Other', 'lastname' => 'Owner', 'email' => 'other-owner@example.test']);
$domainService = new DomainService(Actor::system('Phase 14 fixture'));
$ownedDomain = $domainService->register($ownerId, [
    'domain' => 'api-inventory.example.test', 'provider' => 'cloudflare',
]);
Db::update('domains', [
    'verification_status' => DomainService::VERIFICATION_VERIFIED,
    'verified_at' => Clock::now(),
], ['id' => (int) $ownedDomain['id']]);
$unverifiedDomain = $domainService->register($ownerId, ['domain' => 'not-verified.example.test']);
$platformDomain = $domainService->register($ownerId, [
    'domain' => 'platform.example.test', 'domain_type' => DomainService::TYPE_PLATFORM,
]);
Db::update('domains', [
    'verification_status' => DomainService::VERIFICATION_VERIFIED,
    'verified_at' => Clock::now(),
], ['id' => (int) $platformDomain['id']]);
$foreignDomain = $domainService->register($otherOwnerId, ['domain' => 'another-customer.example.test']);
Db::update('domains', [
    'verification_status' => DomainService::VERIFICATION_VERIFIED,
    'verified_at' => Clock::now(),
], ['id' => (int) $foreignDomain['id']]);

$inventoryPath = '/v1/domains/' . (int) $ownedDomain['id'] . '/dns-inventory';
$customerApi = new InfrastructureApi(Actor::customer($ownerId, 'DNS Owner'));
$disabledResponse = $customerApi->dispatch('GET', $inventoryPath);
T::is('disabled endpoint fails closed with 503', 503, $disabledResponse['status']);
T::is('disabled endpoint has a stable error code', 'DNS_INVENTORY_API_DISABLED', $disabledResponse['body']['error']['code']);
T::is('disabled endpoint leaves the explicit registry empty', [], DnsInventoryProviderRegistry::registeredKeys());
T::is('guest cannot reach the DNS inventory route', 401,
    (new InfrastructureApi(Actor::guest()))->dispatch('GET', $inventoryPath)['status']);

section('Enabled API scopes the domain before using the offline fake provider');
Settings::override('dns_inventory_api_enabled', '1');
$phase12AccountRepository = new Phase14CloudflareAccountRepository();
$phase12Service = new \CloudHost247\Cloudflare\Service\DnsInventoryService(
    new Phase14CloudflareServiceRepository([
        'id' => 914, 'customer_id' => $ownerId, 'account_id' => 915,
        'zone_id' => 'abcdef0123456789abcdef0123456789',
        'zone_name' => 'api-inventory.example.test', 'origin_domain' => 'api-inventory.example.test',
        'addon_parent_domain' => null, 'features_json' => json_encode(['dns.manage' => true]),
        'status' => 'ACTIVE', 'whmcs_service_status' => 'Active',
        'whmcs_addon_status' => null, 'addon_parent_status' => 'Active',
    ]),
    $phase12AccountRepository
);
DnsInventoryProviderRegistry::register(new \Ch247Apps\Domains\CloudflareDnsInventoryAdapter($phase12Service));
$phase12Blocked = $customerApi->dispatch('GET', $inventoryPath);
T::is('App Cloud enablement does not bypass the independent Phase 12 Cloudflare master gate', 503,
    $phase12Blocked['status']);
T::is('Phase 12 gate failure is normalized to an unavailable inventory code', 'DNS_INVENTORY_UNAVAILABLE',
    $phase12Blocked['body']['error']['code']);
T::is('Phase 12 master-gate failure never reaches the Cloudflare transport', 0,
    $phase12AccountRepository->apiCalls);
DnsInventoryProviderRegistry::reset();

$fakeProvider = new Phase14FakeDnsInventoryProvider();
DnsInventoryProviderRegistry::register($fakeProvider);
T::is('test provider is explicitly registered after the App Cloud gate passes', ['cloudflare'],
    DnsInventoryProviderRegistry::registeredKeys());

$ownResponse = $customerApi->dispatch('GET', $inventoryPath);
T::is('customer can read a verified domain inventory', 200, $ownResponse['status']);
T::is('API returns only the neutral DNS inventory contract', [
    'provider' => 'cloudflare',
    'domain' => 'api-inventory.example.test',
    'records' => [[
        'id' => '00112233445566778899aabbccddeeff',
        'type' => 'A',
        'name' => 'api-inventory.example.test',
        'content' => '192.0.2.44',
        'ttl' => 300,
        'proxied' => false,
        'priority' => null,
        'comment' => null,
    ]],
], $ownResponse['body']['data']);
T::notContains('provider-only metadata is not exposed in the API response', 'provider_internal_data',
    json_encode($ownResponse['body']['data']));
T::is('provider receives the resolved owner and canonical stored domain', [[
    'customer_id' => $ownerId, 'domain' => 'api-inventory.example.test',
]], $fakeProvider->calls);

$foreignResponse = (new InfrastructureApi(Actor::customer($ownerId, 'DNS Owner')))
    ->dispatch('GET', '/v1/domains/' . (int) $foreignDomain['id'] . '/dns-inventory');
T::is('another customer domain is indistinguishable from a missing domain', 404, $foreignResponse['status']);
T::is('foreign-domain rejection does not call the provider', 1, count($fakeProvider->calls));
$unverifiedResponse = $customerApi->dispatch('GET', '/v1/domains/' . (int) $unverifiedDomain['id'] . '/dns-inventory');
T::is('unverified customer domain is rejected before provider access', 409, $unverifiedResponse['status']);
T::is('unverified-domain failure has a stable code', 'DOMAIN_NOT_VERIFIED', $unverifiedResponse['body']['error']['code']);
T::is('unverified-domain rejection does not call the provider', 1, count($fakeProvider->calls));
$platformResponse = $customerApi->dispatch('GET', '/v1/domains/' . (int) $platformDomain['id'] . '/dns-inventory');
T::is('platform domains are not exposed through customer DNS inventory', 404, $platformResponse['status']);
T::is('platform-domain rejection does not call the provider', 1, count($fakeProvider->calls));

$inventoryAudit = Db::first('audit_logs', ['action' => Audit::DNS_INVENTORY_READ], ['order' => 'id', 'dir' => 'desc']);
$inventoryAuditMetadata = $inventoryAudit ? json_decode((string) $inventoryAudit['metadata'], true) : null;
T::is('successful read is audited with summary-only record metrics', [
    'provider' => 'cloudflare', 'record_count' => 1, 'record_types' => ['A' => 1],
], $inventoryAuditMetadata);
T::notContains('audit metadata excludes record contents', '192.0.2.44', json_encode($inventoryAuditMetadata));
T::notContains('audit metadata excludes record identifiers', '00112233445566778899aabbccddeeff',
    json_encode($inventoryAuditMetadata));

section('Staff and bearer-token scopes follow App Cloud domain-view permissions');
$staffApi = new InfrastructureApi(Actor::admin(7, Actor::ROLE_STAFF, 'Support Staff'));
$staffResponse = $staffApi->dispatch('GET', $inventoryPath);
T::is('staff with domain.view.all can read any verified customer-domain inventory', 200, $staffResponse['status']);
T::is('staff read still uses the domain owner in provider scope', $ownerId, $fakeProvider->calls[1]['customer_id']);

$customerTokenId = phase14InsertToken(Actor::TYPE_CUSTOMER, $ownerId, Actor::ROLE_CUSTOMER, [Rbac::DOMAIN_VIEW_OWN]);
$customerTokenActor = Actor::customer($ownerId, 'Scoped DNS Owner', [
    'authMethod' => 'api_token', 'meta' => ['token_id' => $customerTokenId],
]);
T::is('customer bearer token with domain.view.own can read own inventory', 200,
    (new InfrastructureApi($customerTokenActor))->dispatch('GET', $inventoryPath)['status']);

$limitedStaffTokenId = phase14InsertToken(Actor::TYPE_ADMIN, 7, Actor::ROLE_STAFF, [Rbac::DOMAIN_VIEW_OWN]);
$limitedStaffActor = Actor::admin(7, Actor::ROLE_STAFF, 'Scoped Staff', [
    'authMethod' => 'api_token', 'meta' => ['token_id' => $limitedStaffTokenId],
]);
$limitedStaffResponse = (new InfrastructureApi($limitedStaffActor))->dispatch('GET', $inventoryPath);
T::is('staff bearer token without domain.view.all is denied even when the role has it', 403,
    $limitedStaffResponse['status']);
T::is('missing staff bearer scope does not call the provider', 3, count($fakeProvider->calls));

$allStaffTokenId = phase14InsertToken(Actor::TYPE_ADMIN, 7, Actor::ROLE_STAFF, [Rbac::DOMAIN_VIEW_ALL]);
$allStaffActor = Actor::admin(7, Actor::ROLE_STAFF, 'Scoped Staff', [
    'authMethod' => 'api_token', 'meta' => ['token_id' => $allStaffTokenId],
]);
T::is('staff bearer token with domain.view.all can read the inventory', 200,
    (new InfrastructureApi($allStaffActor))->dispatch('GET', $inventoryPath)['status']);

$callsBeforeRejectedRequests = count($fakeProvider->calls);
T::is('GET query/body fields cannot influence the inventory scope', 422,
    $customerApi->dispatch('GET', $inventoryPath, ['customer_id' => $otherOwnerId])['status']);
$csrf = \Ch247Apps\Core\Csrf::token();
T::is('the DNS inventory endpoint has no POST operation', 404,
    $customerApi->dispatch('POST', $inventoryPath, [], ['X-CSRF-Token' => $csrf])['status']);
T::is('rejected request forms do not call the provider', $callsBeforeRejectedRequests, count($fakeProvider->calls));

section('DNS inventory bridge errors are normalized before reaching the API');
class Phase14ThrowingCloudflareInventoryService
{
    public function listForDomain($customerId, $domain)
    {
        throw new \CloudHost247\Cloudflare\Core\ConfigurationException('provider secret must not appear in a customer response');
    }
}
$mappedException = T::throws('Cloudflare errors become safe App Cloud provider-unavailable errors',
    \Ch247Apps\Core\ProviderUnavailableException::class, function () {
        (new \Ch247Apps\Domains\CloudflareDnsInventoryAdapter(new Phase14ThrowingCloudflareInventoryService()))
            ->listForDomain(1, 'api-inventory.example.test');
    });
T::is('normalized Cloudflare error has a stable DNS code', 'DNS_INVENTORY_UNAVAILABLE',
    $mappedException ? $mappedException->errorCode() : null);
T::notContains('normalized Cloudflare error message hides provider details', 'provider secret',
    $mappedException ? $mappedException->getMessage() : '');

DnsInventoryProviderRegistry::reset();
T::summary();
