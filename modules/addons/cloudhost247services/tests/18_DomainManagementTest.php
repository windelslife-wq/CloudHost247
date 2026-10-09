<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\DomainManagementService;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\DomainWorker;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
Secrets::setKeyOverride('mgmt-test-key');

function chs_mgmt(\Chs\Providers\Domain\ProviderRegistry $registry = null)
{
    return new DomainManagementService($registry ?: new FakeProviderRegistry(), new JobQueue());
}

T::section('Management: platform sync creates module rows linked to WHMCS');
$gateway->clientDomains[11] = [
    ['id' => 501, 'domain' => 'synced-one.com', 'expiry' => '2027-06-01', 'status' => 'Active'],
    ['id' => 502, 'domain' => 'synced-two.io', 'expiry' => '2026-12-01', 'status' => 'Active'],
];
// WHMCS' own domain table is the sync source of truth:
Db::pdo()->exec("INSERT INTO tbldomains (id, userid, domain, status, registrar, nextduedate, expirydate, autorenew, donotrenew) VALUES
    (501, 11, 'synced-one.com', 'Active', 'opensrs', '2027-06-01', '2027-06-01', 1, 0),
    (502, 11, 'synced-two.io', 'Active', '', '2026-12-01', '2026-12-01', 0, 0)");
$mgmt = chs_mgmt();
$synced = $mgmt->syncFromPlatform();
T::ok('rows synced', $synced >= 2);
$row = Db::first('domain_services', ['domain' => 'synced-one.com']);
T::ok('row exists', $row !== null);
T::eq('linked to whmcs id', 501, (int) $row['whmcs_domain_id']);
T::eq('client linked', 11, (int) $row['client_id']);
T::eq('expiry captured', '2027-06-01 00:00:00', $row['expires_at']);
T::eq('renewal price from catalogue', 1599, (int) $row['renewal_price_minor']);
// tbldomains fixture rows also sync
$all = Db::all('domain_services', [], 'domain ASC');
T::ok('multiple rows tracked', count($all) >= 2);

T::section('Management: listFor merges live platform data (no duplicates)');
$before = Db::count('domain_services');
$list = $mgmt->listFor(11);
T::ok('list returns domains', count($list) >= 2);
T::eq('no duplicate rows on re-list', $before, Db::count('domain_services'));
$byName = [];
foreach ($list as $d) {
    $byName[$d['domain']] = $d;
}
T::ok('synced-one listed', isset($byName['synced-one.com']));

T::section('Management: IDOR protection');
T::throws('other client cannot read domain', function () use ($mgmt, $row) {
    $mgmt->getFor(22, (int) $row['id']);
}, \Chs\Core\NotFoundException::class);
T::throws('unknown domain 404', function () use ($mgmt) {
    $mgmt->getFor(11, 999999);
}, \Chs\Core\NotFoundException::class);

T::section('Management: auto-renew toggle audited');
$mgmt->setAutoRenew(11, (int) $row['id'], false);
$after = Db::first('domain_services', ['id' => (int) $row['id']]);
T::eq('auto-renew off', 0, (int) $after['auto_renew']);
$events = Db::all('domain_events', ['type' => 'DOMAIN_AUTO_RENEW_CHANGED']);
T::eq('event recorded', 1, count($events));
$mgmt->setAutoRenew(11, (int) $row['id'], true);

T::section('Management: privacy toggle goes through the provider');
$registry = new FakeProviderRegistry();
$mgmt2 = chs_mgmt($registry);
$mgmt2->setPrivacy(11, (int) $row['id'], true);
T::eq('provider received update', 1, count($registry->fake->callsTo('update_domain')));
T::eq('privacy stored', 1, (int) Db::first('domain_services', ['id' => (int) $row['id']])['whois_privacy']);

T::section('Management: nameservers validated + pushed to provider');
T::throws('one nameserver rejected', function () use ($mgmt2, $row) {
    $mgmt2->updateNameservers(11, (int) $row['id'], ['ns1.only.com']);
}, ValidationException::class);
T::throws('invalid nameserver rejected', function () use ($mgmt2, $row) {
    $mgmt2->updateNameservers(11, (int) $row['id'], ['ns1.example.com', 'not a host!!']);
}, ValidationException::class);
$mgmt2->updateNameservers(11, (int) $row['id'], ['ns1.example.com', 'ns2.example.com']);
T::eq('provider received nameservers', 1, count($registry->fake->callsTo('update_nameservers')));
T::eq('nameservers stored', ['ns1.example.com', 'ns2.example.com'], json_decode(Db::first('domain_services', ['id' => (int) $row['id']])['nameservers'], true));

T::section('Management: DNS record validation (A/AAAA/CNAME/MX/TXT/NS/SRV/CAA)');
$mgmt3 = chs_mgmt(new FakeProviderRegistry());
$svcId = (int) $row['id'];
T::throws('bad record type', function () use ($mgmt3, $svcId) {
    $mgmt3->createDnsRecord(11, $svcId, ['type' => 'PTR', 'name' => '@', 'value' => 'x']);
}, ValidationException::class);
T::throws('bad A value', function () use ($mgmt3, $svcId) {
    $mgmt3->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => '@', 'value' => 'not-an-ip']);
}, ValidationException::class);
T::throws('bad AAAA value', function () use ($mgmt3, $svcId) {
    $mgmt3->createDnsRecord(11, $svcId, ['type' => 'AAAA', 'name' => '@', 'value' => '1.2.3.4']);
}, ValidationException::class);
T::throws('MX without priority', function () use ($mgmt3, $svcId) {
    $mgmt3->createDnsRecord(11, $svcId, ['type' => 'MX', 'name' => '@', 'value' => 'mail.example.com']);
}, ValidationException::class);
T::throws('bad TTL', function () use ($mgmt3, $svcId) {
    $mgmt3->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => '@', 'value' => '1.2.3.4', 'ttl' => 10]);
}, ValidationException::class);
$recA = $mgmt3->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => '@', 'value' => '203.0.113.10', 'ttl' => 3600]);
T::eq('A record synced', 'synced', $recA['sync_status']);
$recMx = $mgmt3->createDnsRecord(11, $svcId, ['type' => 'MX', 'name' => '@', 'value' => 'mail.example.com', 'priority' => 10]);
T::ok('MX record created', (int) $recMx['id'] > 0);
$recTxt = $mgmt3->createDnsRecord(11, $svcId, ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 -all']);
T::ok('TXT record created', (int) $recTxt['id'] > 0);
$recSrv = $mgmt3->createDnsRecord(11, $svcId, ['type' => 'SRV', 'name' => '_sip._tcp', 'value' => '10 5 5060 sip.example.com', 'priority' => 10]);
T::ok('SRV record created', (int) $recSrv['id'] > 0);
$recCaa = $mgmt3->createDnsRecord(11, $svcId, ['type' => 'CAA', 'name' => '@', 'value' => '0 issue "letsencrypt.org"']);
T::ok('CAA record created', (int) $recCaa['id'] > 0);

T::section('Management: DNS provider calls + IDOR + events');
$fake = $registry->fake;
$registry3 = new FakeProviderRegistry($fake);
$mgmt4 = chs_mgmt($registry3);
$before = count($fake->dnsRecords['synced-one.com'] ?? []);
$mgmt4->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => 'www', 'value' => '203.0.113.20']);
T::eq('provider stored the record', $before + 1, count($fake->dnsRecords['synced-one.com']));
T::throws('other client cannot create records', function () use ($mgmt4, $svcId) {
    $mgmt4->createDnsRecord(2, $svcId, ['type' => 'A', 'name' => 'x', 'value' => '1.2.3.4']);
}, \Chs\Core\NotFoundException::class);
$dnsEvents = Db::all('domain_events', ['type' => 'DOMAIN_DNS_UPDATED']);
T::ok('DNS events recorded', count($dnsEvents) >= 5);
$mgmt4->deleteDnsRecord(11, $svcId, (int) $recA['id']);
T::eq('provider delete called', 1, count($fake->callsTo('delete_dns_record')));
T::eq('record removed', 0, Db::count('domain_dns_records', ['id' => (int) $recA['id']]));

T::section('Management: provider hiccup → error row + async retry job → synced');
$fake2 = new FakeDomainProvider();
$registry5 = new FakeProviderRegistry($fake2);
$mgmt5 = chs_mgmt($registry5);
// provider hiccup on create → honest error state, desired state recorded
$fake2->failNextCreate = true;
$errored = $mgmt5->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => 'retry', 'value' => '203.0.113.30']);
T::eq('record saved in error state', 'error', $errored['sync_status']);
T::ok('error message captured', strpos($errored['last_error'], 'simulated provider hiccup') !== false);
T::eq('dns sync retry job enqueued', 1, Db::count('jobs', ['type' => DomainJobTypes::DNS_SYNC, 'entity_id' => (int) $errored['id']]));
// worker run retries the push (provider healthy again) → synced
\Chs\Workflow\DomainWorker::$registryOverride = $registry5;
\Chs\Workflow\DomainWorker::run(5);
T::eq('record synced after retry', 'synced', Db::first('domain_dns_records', ['id' => (int) $errored['id']])['sync_status']);
T::ok('provider holds the record', count($fake2->dnsRecords['synced-one.com'] ?? []) >= 1);
$jobRow = Db::first('jobs', ['type' => DomainJobTypes::DNS_SYNC, 'entity_id' => (int) $errored['id']]);
T::eq('retry job completed', 'completed', $jobRow['status']);
DomainWorker::$registryOverride = null;
// sync with capability off fails honestly
$fake3 = new FakeDomainProvider();
$fake3->capOverrides = ['create_dns_record' => false, 'update_dns_record' => false];
$mgmt7 = chs_mgmt(new FakeProviderRegistry($fake3));
T::throws('sync without capability fails closed', function () use ($mgmt7, $svcId) {
    $mgmt7->syncDns($svcId);
}, \Chs\Core\ServiceUnavailableException::class);

T::section('Management: capability-honest UI (WHMCS provider cannot edit DNS)');
$whmcsRegistry = new class() extends \Chs\Providers\Domain\ProviderRegistry {
    public function forTld($tld)
    {
        return new \Chs\Providers\Domain\WhmcsRegistrarProvider();
    }
};
$mgmt6 = chs_mgmt($whmcsRegistry);
T::throws('DNS create without provider capability is honest', function () use ($mgmt6, $svcId) {
    $mgmt6->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => '@', 'value' => '1.2.3.4']);
}, \Chs\Core\ServiceUnavailableException::class);
T::throws('privacy change without capability is honest', function () use ($mgmt6, $svcId) {
    $mgmt6->setPrivacy(11, $svcId, true);
}, \Chs\Core\ServiceUnavailableException::class);
T::throws('nameserver update without capability is honest', function () use ($mgmt6, $svcId) {
    $mgmt6->updateNameservers(11, $svcId, ['ns1.example.com', 'ns2.example.com']);
}, \Chs\Core\ServiceUnavailableException::class);

T::section('Management: reconciliation flags domains missing at the platform');
Db::insert('domain_services', [
    'client_id' => 1, 'domain' => 'ghost.example.com', 'tld' => 'com', 'status' => 'active',
    'created_at' => \Chs\Core\Clock::now(), 'updated_at' => \Chs\Core\Clock::now(),
]);
$mgmt->syncFromPlatform();
$ghost = Db::first('domain_services', ['domain' => 'ghost.example.com']);
T::eq('ghost flagged missing', 'missing_at_platform', $ghost['status']);
$report = $mgmt->reconciliationReport();
T::ok('report counts', (int) $report['total'] >= 3 && (int) $report['missing_at_platform'] >= 1);

T::section('Management: DNS disabled setting fails closed');
Settings::put('domain_dns_enabled', '0');
T::throws('dns disabled', function () use ($mgmt4, $svcId) {
    $mgmt4->createDnsRecord(11, $svcId, ['type' => 'A', 'name' => '@', 'value' => '1.2.3.4']);
}, \Chs\Core\ServiceUnavailableException::class);
Settings::put('domain_dns_enabled', '1');

T::finish();
