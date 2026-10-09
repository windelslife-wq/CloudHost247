<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\TransferService;
use Chs\Services\WhoisService;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
Secrets::setKeyOverride('transfer-test-key');

function chs_transfer_service(FakeProviderRegistry $registry = null, FakeWhoisProvider $whois = null)
{
    $whois = $whois ?: new FakeWhoisProvider();
    $svc = new TransferService(
        $registry ?: new FakeProviderRegistry(),
        new JobQueue(),
        new WhoisService($whois)
    );
    return [$svc, $whois];
}

T::section('Transfers: EPP validation');
[, $whois] = chs_transfer_service();
$svc = new TransferService(new FakeProviderRegistry(), new JobQueue(), new WhoisService($whois));
T::throws('empty EPP rejected', function () use ($svc) {
    $svc->create(11, 'example.com', '');
}, ValidationException::class);
T::throws('overlong EPP rejected', function () use ($svc) {
    $svc->create(11, 'example.com', str_repeat('a', 65));
}, ValidationException::class);
T::throws('EPP with spaces rejected', function () use ($svc) {
    $svc->create(11, 'example.com', 'has space');
}, ValidationException::class);
T::throws('invalid domain rejected', function () use ($svc) {
    $svc->create(11, 'not a domain', 'EPP123');
}, ValidationException::class);
T::throws('unsupported TLD rejected', function () use ($svc) {
    $svc->create(11, 'example.notreal', 'EPP123');
}, ValidationException::class);

T::section('Transfers: eligibility pre-check (WHOIS states)');
$whois2 = new FakeWhoisProvider();
$whois2->raw['locked.com'] = "Domain Name: LOCKED.COM\nRegistrar: Test Registrar LLC\nDomain Status: clientTransferProhibited http://x\n";
$whois2->raw['fresh.com'] = "Domain Name: FRESH.COM\nRegistrar: Test Registrar LLC\nCreation Date: " . date('Y-m-d') . "T00:00:00Z\n";
$svc2 = new TransferService(new FakeProviderRegistry(), new JobQueue(), new WhoisService($whois2));
$e1 = $svc2->checkEligibility('locked.com', 'EPP123');
T::eq('locked domain not eligible', false, $e1['eligible']);
T::ok('lock reason surfaced', strpos(implode(' ', $e1['reasons']), 'clientTransferProhibited') !== false
    || strpos(implode(' ', $e1['reasons']), 'clienttransferprohibited') !== false);
$e2 = $svc2->checkEligibility('fresh.com', 'EPP123');
T::eq('60-day rule enforced', false, $e2['eligible']);
T::ok('age reason surfaced', strpos(implode(' ', $e2['reasons']), '60 days') !== false);
$e3 = $svc2->checkEligibility('good.com', 'EPP123');
T::eq('clean domain eligible', true, $e3['eligible']);
T::ok('quote returned', $e3['quote'] !== null && (int) $e3['quote']['final_minor'] === 999);

$whoisFail = new FakeWhoisProvider();
$whoisFail->fail = true;
$svc3 = new TransferService(new FakeProviderRegistry(), new JobQueue(), new WhoisService($whoisFail));
$e4 = $svc3->checkEligibility('good.com', 'EPP123');
T::eq('whois outage → eligible unknown, not guessed', null, $e4['eligible']);

T::section('Transfers: create → invoice → tracked record (EPP sealed)');
$registry = new FakeProviderRegistry();
$svc4 = new TransferService($registry, new JobQueue(), new WhoisService(new FakeWhoisProvider()));
$outcome = $svc4->create(11, 'migrate-me.com', 'EPP-SECRET-123');
T::ok('invoice created', $outcome['invoice_id'] > 0);
T::eq('transfer pending', TransferService::PENDING, $outcome['transfer']['status']);
T::eq('price server-side', 999, (int) $outcome['transfer']['price_minor']);
$stored = Db::first('domain_transfers', ['id' => (int) $outcome['transfer']['id']]);
T::ok('EPP stored sealed', strpos($stored['epp_enc'], 'EPP-SECRET-123') === false);
T::eq('EPP decrypts', 'EPP-SECRET-123', Secrets::decrypt($stored['epp_enc']));
$events = Db::all('domain_events', ['type' => 'DOMAIN_TRANSFER_STARTED']);
T::ok('DOMAIN_TRANSFER_STARTED recorded', count($events) === 1);
T::ok('event payload has no EPP', strpos(json_encode($events[0]), 'EPP-SECRET-123') === false);
$notices = Db::all('notifications', ['client_id' => 11, 'type' => 'domain_transfer_started']);
T::ok('customer notified', count($notices) === 1);

T::section('Transfers: duplicate open transfer is idempotent');
$again = $svc4->create(11, 'migrate-me.com', 'EPP-ROTATED-999');
T::eq('same transfer reused', (int) $outcome['transfer']['id'], (int) $again['transfer']['id']);
T::eq('same invoice reused', $outcome['invoice_id'], $again['invoice_id']);
T::eq('EPP rotated + resealed', 'EPP-ROTATED-999', Secrets::decrypt(Db::first('domain_transfers', ['id' => (int) $outcome['transfer']['id']])['epp_enc']));
T::eq('still one transfer row', 1, Db::count('domain_transfers', ['domain' => 'migrate-me.com']));

T::section('Transfers: InvoicePaid queues the provider submission job');
$svc4->invoicePaid($outcome['invoice_id']);
$transfer = Db::first('domain_transfers', ['id' => (int) $outcome['transfer']['id']]);
T::eq('status initiated after payment', TransferService::INITIATED, $transfer['status']);
$jobs = Db::all('jobs', ['type' => DomainJobTypes::TRANSFER]);
T::eq('transfer job enqueued', 1, count($jobs));
T::eq('job idempotency key', 'domain-transfer:' . (int) $outcome['transfer']['id'], $jobs[0]['idempotency_key']);
// replaying the payment hook does not double-enqueue
$svc4->invoicePaid($outcome['invoice_id']);
T::eq('replay is a no-op', 1, Db::count('jobs', ['type' => DomainJobTypes::TRANSFER]));

T::section('Transfers: provider submission via worker (real provider call)');
\Chs\Workflow\DomainWorker::$registryOverride = $registry;
$run = \Chs\Workflow\DomainWorker::run(5);
T::eq('worker ran the transfer job', 'completed', Db::first('jobs', ['id' => (int) $jobs[0]['id']])['status']);
T::eq('provider received the transfer', 1, count($registry->fake->callsTo('transfer')));
T::eq('transfer moved to processing', TransferService::PROCESSING, Db::first('domain_transfers', ['id' => (int) $outcome['transfer']['id']])['status']);
$history = json_decode(Db::first('domain_transfers', ['id' => (int) $outcome['transfer']['id']])['history'], true);
T::ok('history appended', count($history) >= 3);
T::ok('history has no EPP', strpos(json_encode($history), 'EPP-ROTATED-999') === false);

T::section('Transfers: honest failure with the WHMCS provider (no fake success)');
$whmcsRegistry = new class() extends \Chs\Providers\Domain\ProviderRegistry {
    public function forTld($tld)
    {
        return new \Chs\Providers\Domain\WhmcsRegistrarProvider();
    }
};
$svc5 = new TransferService($whmcsRegistry, new JobQueue(), new WhoisService(new FakeWhoisProvider()));
$outcome5 = $svc5->create(11, 'whmcs-path.com', 'EPP123');
$gateway->invoices[$outcome5['invoice_id']] = 'Paid';
$svc5->invoicePaid($outcome5['invoice_id']);
\Chs\Workflow\DomainWorker::$registryOverride = $whmcsRegistry;
$run2 = \Chs\Workflow\DomainWorker::run(5);
$failedJob = Db::first('jobs', ['type' => DomainJobTypes::TRANSFER, 'entity_id' => (int) $outcome5['transfer']['id']]);
T::ok('job did not complete', $failedJob['status'] !== 'completed');
T::eq('error code machine-readable', 'provider_error', $failedJob['error_code']);
T::ok('error mentions unsupported', strpos($failedJob['error_message'], 'PROVIDER_OPERATION_UNSUPPORTED') !== false);
$t5 = Db::first('domain_transfers', ['id' => (int) $outcome5['transfer']['id']]);
T::eq('transfer stays initiated with error', TransferService::INITIATED, $t5['status']);
T::ok('error recorded on transfer', strpos($t5['error'], 'PROVIDER_OPERATION_UNSUPPORTED') !== false);

T::section('Transfers: platform reconciliation completes when WHMCS shows the domain');
$gateway->clientDomains[11][] = ['id' => 9001, 'domain' => 'whmcs-path.com', 'expiry' => '2027-01-01', 'status' => 'Active'];
// WHMCS' own domain table is the source of truth for the sync:
Db::pdo()->exec("INSERT INTO tbldomains (id, userid, domain, status, registrar, nextduedate, expirydate)
    VALUES (9001, 11, 'whmcs-path.com', 'Active', 'opensrs', '2027-01-01', '2027-01-01')");
$svc5->syncFromPlatform();
$t5b = Db::first('domain_transfers', ['id' => (int) $outcome5['transfer']['id']]);
T::eq('reconciled to completed', TransferService::COMPLETED, $t5b['status']);
$doneEvents = Db::all('domain_events', ['type' => 'DOMAIN_TRANSFER_COMPLETED']);
T::ok('DOMAIN_TRANSFER_COMPLETED recorded', count($doneEvents) >= 1);
// domain service row created by sync
$svcRow = Db::first('domain_services', ['domain' => 'whmcs-path.com']);
T::ok('domain service row synced', $svcRow !== null && (int) $svcRow['client_id'] === 11);

T::section('Transfers: customer cancel (unpaid invoice cancelled too)');
$outcome6 = $svc4->create(22, 'cancel-me.com', 'EPP123');
$svc4->cancel(22, (int) $outcome6['transfer']['id']);
$t6 = Db::first('domain_transfers', ['id' => (int) $outcome6['transfer']['id']]);
T::eq('cancelled status', TransferService::CANCELLED, $t6['status']);
T::eq('invoice cancelled', 'Cancelled', $gateway->invoiceStatus($outcome6['invoice_id']));
T::throws('completed transfer cannot be cancelled', function () use ($svc4, $outcome5) {
    $svc4->cancel(11, (int) $outcome5['transfer']['id']);
}, \Chs\Core\InvalidTransitionException::class);

T::section('Transfers: IDOR — client cannot see another client\'s transfer');
T::throws('other client gets 404', function () use ($svc4, $outcome) {
    $svc4->getFor(22, (int) $outcome['transfer']['id']);
}, \Chs\Core\NotFoundException::class);

T::section('Transfers: admin operations');
$adminList = $svc4->adminList('');
T::ok('admin list has rows', count($adminList) >= 3);
T::throws('retrying a completed transfer refused', function () use ($svc4, $outcome5) {
    $svc4->adminRetry((int) $outcome5['transfer']['id'], 7);
}, \Chs\Core\InvalidTransitionException::class);
$outcome7 = $svc4->create(22, 'retry-me.com', 'EPP123');
$gateway->invoices[$outcome7['invoice_id']] = 'Paid';
$svc4->invoicePaid($outcome7['invoice_id']);
\Chs\Workflow\DomainWorker::$registryOverride = $registry; // fake provider → real submission
\Chs\Workflow\DomainWorker::run(5);
$t7 = Db::first('domain_transfers', ['id' => (int) $outcome7['transfer']['id']]);
T::eq('fake provider path completed processing', TransferService::PROCESSING, $t7['status']);
$svc4->adminMarkCompleted((int) $outcome7['transfer']['id'], 7);
T::eq('admin completed', TransferService::COMPLETED, Db::first('domain_transfers', ['id' => (int) $outcome7['transfer']['id']])['status']);
$adminEvents = Db::all('domain_events', ['type' => 'DOMAIN_ADMIN_UPDATED']);
T::ok('admin action audited', count($adminEvents) >= 1);

T::section('Transfers: no EPP in any stored view model');
$view = $svc4->getFor(11, (int) $outcome['transfer']['id']);
T::ok('view model hides EPP', strpos(json_encode($view), 'EPP-ROTATED-999') === false);
T::eq('epp status indicator', 'on file (hidden)', $view['epp_status']);

T::finish();
