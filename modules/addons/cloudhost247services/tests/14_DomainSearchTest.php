<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Providers\Domain\NullDomainProvider;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Providers\Domain\WhmcsRegistrarProvider;
use Chs\Services\DomainSearchService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
Secrets::setKeyOverride('domain-search-test-key');

T::section('Search: happy path through the WHMCS provider chain');
$gateway->availability = [
    'brightideas.com' => ['status' => 'available', 'available' => true],
    'brightideas.net' => ['status' => 'taken', 'available' => false],
];
$svc = new DomainSearchService();
$r = $svc->search('brightideas.com');
T::eq('available reported', true, $r['available']);
T::eq('status available', 'available', $r['status']);
T::eq('register price minor from catalogue', 1299, $r['register_minor']);
T::eq('renew price minor', 1599, $r['renew_minor']);
T::eq('transfer price minor', 999, $r['transfer_minor']);
T::ok('currency set', $r['currency'] === 'USD');
T::ok('add-to-cart url is the WHMCS cart', strpos($r['add_to_cart_url'], 'cart.php?a=add&domain=register&query=brightideas.com') === 0);
T::ok('no discount for guests', $r['discount_percent'] === null);

$r2 = $svc->search('brightideas.net');
T::eq('taken domain reported taken', false, $r2['available']);
T::eq('status taken', 'taken', $r2['status']);

T::section('Search: unknown availability never means available');
$gateway->availability = []; // platform cannot answer
$r3 = $svc->search('unknownstate.com');
T::ok('unknown stays unknown', $r3['available'] !== true);
T::ok('unknown has a machine status', in_array($r3['status'], ['unknown', 'unavailable_lookup', 'DOMAIN_LOOKUP_UNAVAILABLE'], true));

T::section('Search: unsupported TLD fails honestly with prices withheld');
$r4 = $svc->search('something.notreal');
T::eq('unsupported tld status', 'unsupported_tld', $r4['status']);
T::eq('no availability guess', null, $r4['available']);
T::eq('no price for unsupported tld', null, $r4['register_minor']);

T::section('Search: invalid input rejected');
T::throws('empty query rejected', function () use ($svc) {
    $svc->search('!!!');
}, ValidationException::class);
T::throws('tld-only input rejected', function () use ($svc) {
    $svc->search('.com');
}, ValidationException::class);

T::section('Search: results persisted + audited');
$rows = Db::all('domain_search_results', [], 'id ASC');
T::ok('result rows persisted', count($rows) >= 4);
$searches = Db::all('domain_searches', [], 'id ASC');
T::ok('search rows persisted', count($searches) >= 4);
T::eq('all single mode', 'single', $searches[0]['mode']);
$events = Db::all('domain_events', ['type' => 'DOMAIN_SEARCHED'], 'id ASC');
T::ok('DOMAIN_SEARCHED events recorded', count($events) >= 4);
$audit = Db::all('audit_log', ['action' => 'DOMAIN_SEARCHED'], 'id ASC');
T::ok('audit rows recorded', count($audit) >= 4);

T::section('Search: unconfigured provider → DOMAIN_PROVIDER_NOT_CONFIGURED, never a guess');
$registry = new FakeProviderRegistry();
$registry->fake->configured = false;
$svc2 = new DomainSearchService($registry);
$r5 = $svc2->search('brightideas.com');
T::eq('provider not configured status', 'DOMAIN_PROVIDER_NOT_CONFIGURED', $r5['status']);
T::eq('availability null', null, $r5['available']);
T::eq('prices still from catalogue', 1299, $r5['register_minor']);
T::eq('provider flagged unconfigured', false, $r5['provider_configured']);

T::section('Search: provider failure → DOMAIN_LOOKUP_UNAVAILABLE');
$registry2 = new FakeProviderRegistry();
$registry2->fake->availability['boom.com'] = null; // marker handled below
$fake = $registry2->fake;
$fake->availability = [];
$svc3 = new class($registry2) extends DomainSearchService {
    // checkOne path with a provider that throws ProviderException
};
// Simulate via a provider that throws:
$throwing = new class() extends FakeDomainProvider {
    public function checkAvailability($fqdn)
    {
        throw new \Chs\Core\ProviderException('registry timeout');
    }
};
$registry3 = new FakeProviderRegistry($throwing);
$svc4 = new DomainSearchService($registry3);
$r6 = $svc4->search('boom.com');
T::eq('lookup unavailable status', 'DOMAIN_LOOKUP_UNAVAILABLE', $r6['status']);
T::eq('availability null on failure', null, $r6['available']);

T::section('Search: per-TLD provider mapping wins over default');
$ioProvider = new FakeDomainProvider('IO Registrar');
$ioProvider->availability['brightideas.io'] = ['available' => false, 'status' => 'taken'];
$registry4 = new FakeProviderRegistry();
$registry4->byTld['io'] = $ioProvider;
$svc5 = new DomainSearchService($registry4);
$r7 = $svc5->search('brightideas.io');
T::eq('mapped provider used', 'IO Registrar', $r7['provider']);
T::eq('mapped provider answered taken', false, $r7['available']);
$r8 = $svc5->search('brightideas.com');
T::eq('default provider used for com', 'Fake Registrar', $r8['provider']);

T::section('Search: club discount applied server-side for members');
$membership = (new \Chs\Services\ClubService())->join(11, 1);
$gateway->invoices[$membership['invoice_id']] = 'Paid';
(new \Chs\Services\ClubService())->invoicePaid($membership['invoice_id']);
Settings::put('club_allow_registrations', '1');
Settings::put('club_allow_renewals', '1');
$r9 = $svc->search('brightideas.com', 11);
T::ok('discount applied', $r9['discount_percent'] !== null && $r9['discount_percent'] > 0);
T::ok('final price below base', $r9['register_final_minor'] < $r9['register_minor']);
T::eq('final = base less percent', \Chs\Core\Money::discount(1299, $r9['discount_percent']), $r9['register_final_minor']);
// guests never see the discount
$r10 = $svc->search('brightideas.com');
T::eq('guest gets no discount', null, $r10['discount_percent']);

T::section('Search: rate limiting');
Settings::put('search_daily_limit_per_ip', '3');
Db::exec('DELETE FROM ' . Db::t('rate_limits'));
$limited = false;
try {
    for ($i = 0; $i < 10; $i++) {
        $svc->search('ratelimit-' . $i . '.com');
    }
} catch (\Chs\Core\RateLimitException $e) {
    $limited = true;
}
T::ok('guest search rate limited', $limited);
Settings::put('search_daily_limit_per_ip', '30');

T::section('Search: disabled feature fails closed');
Settings::put('domain_search_enabled', '0');
T::throws('disabled search throws', function () use ($svc) {
    $svc->search('brightideas.com');
}, \Chs\Core\ServiceUnavailableException::class);
Settings::put('domain_search_enabled', '1');

T::section('Registry: platform default + null provider honesty');
$reg = new ProviderRegistry();
$default = $reg->forTld('com');
T::ok('default resolves to WHMCS chain', $default instanceof WhmcsRegistrarProvider);
// With a configured-but-unmapped provider set and no default flag → null provider
Secrets::setKeyOverride('registry-test-key');
$id = $reg->save(0, [
    'name' => 'HTTP Test Registrar', 'type' => 'http', 'base_url' => 'https://registrar.test/api',
    'endpoints' => ['check_availability' => 'GET /domains/{domain}/availability'],
    'is_enabled' => 1, 'is_default' => 0,
], json_encode(['api_key' => 'secret-value']));
$reg2 = new ProviderRegistry();
$forUnmapped = $reg2->forTld('com'); // no mapping, no default → first enabled provider
T::ok('falls back to enabled provider', $forUnmapped->providerName() === 'HTTP Test Registrar');
$reg2->setMapping('io', $id);
$forIo = $reg2->forTld('io');
T::ok('mapping wins for io', $forIo->providerName() === 'HTTP Test Registrar');
T::eq('mapping listed', $id, $reg2->mappings()['io']);
// credentials never leak through list views
$listed = $reg2->all();
T::ok('credentials masked in list', !isset($listed[0]['credentials_enc']) && !empty($listed[0]['has_credentials']));
$stored = Db::first('domain_providers', ['id' => $id]);
T::ok('credentials stored sealed', strpos($stored['credentials_enc'], 'secret-value') === false);
T::eq('sealed credentials decrypt', 'secret-value', json_decode(Secrets::decrypt($stored['credentials_enc']), true)['api_key'] ?? null);
// health check reports configured
$health = $reg2->healthCheck($id);
T::eq('health ok when configured', 'ok', $health['status']);

T::section('Registry: null provider fails closed');
$null = new NullDomainProvider();
T::throws('null provider checkAvailability throws', function () use ($null) {
    $null->checkAvailability('x.com');
}, \Chs\Core\ProviderNotConfiguredException::class);

T::section('Secrets: refuse to seal without a key');
Secrets::setKeyOverride(null);
putenv('CHS_CREDENTIALS_KEY');
T::throws('sealing without key fails closed', function () {
    Secrets::encrypt('super-secret');
}, \Chs\Core\ConfigurationException::class);
Secrets::setKeyOverride('domain-search-test-key');

T::finish();
