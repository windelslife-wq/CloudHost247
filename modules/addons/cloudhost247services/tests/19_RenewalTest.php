<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Services\RenewalService;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
Secrets::setKeyOverride('renewal-test-key');

function chs_renewal_domain($clientId, $domain, $expiry, $autoRenew = 1)
{
    return Db::insert('domain_services', [
        'client_id' => (int) $clientId,
        'domain'    => $domain,
        'tld'       => substr($domain, strpos($domain, '.') + 1),
        'status'    => 'active',
        'auto_renew' => $autoRenew ? 1 : 0,
        'expires_at' => $expiry,
        'renewal_price_minor' => 1599,
        'currency'  => 'USD',
        'created_at' => Clock::now(),
        'updated_at' => Clock::now(),
    ]);
}

T::section('Renewals: notice thresholds come from settings (not hard-coded)');
$svc = new RenewalService(new JobQueue());
T::eq('default thresholds', [30, 14, 7, 3, 1], $svc->noticeDays());
Settings::put('domain_renewal_notice_days', '21, 5');
T::eq('custom thresholds', [21, 5], $svc->noticeDays());
Settings::put('domain_renewal_notice_days', '30,14,7,3,1');

T::section('Renewals: expiration notices fire once per threshold');
$id = chs_renewal_domain(11, 'notice-me.com', Clock::in(10 * 86400), 0);
$result = $svc->scanExpirations();
T::ok('notice sent for 10-days-left domain', $result['notices'] >= 1);
$notices = Db::all('domain_expiration_notices', ['domain_service_id' => $id]);
$days = array_map('intval', array_column($notices, 'days_before'));
T::eq('thresholds 30,14 fired (10 days left)', [30, 14], $days);
// second scan: nothing new (dedupe)
$result2 = $svc->scanExpirations();
T::eq('no duplicate notices', 0, Db::count('domain_expiration_notices', ['domain_service_id' => $id]) - 2);
$events = Db::all('domain_events', ['type' => 'DOMAIN_EXPIRING', 'domain_service_id' => $id]);
T::eq('expiring events recorded', 2, count($events));
$customerNotices = Db::all('notifications', ['client_id' => 11, 'type' => 'domain_expiring']);
T::ok('customer notified', count($customerNotices) >= 2);

T::section('Renewals: expired domain flagged + notified once');
$id2 = chs_renewal_domain(11, 'already-gone.com', Clock::ago(2 * 86400), 0);
$svc->scanExpirations();
$row2 = Db::first('domain_services', ['id' => $id2]);
T::eq('status expired', 'expired', $row2['status']);
T::eq('expired notice recorded', 1, Db::count('domain_expiration_notices', ['domain_service_id' => $id2, 'days_before' => 0]));
$expiredEvents = Db::all('domain_events', ['type' => 'DOMAIN_EXPIRED', 'domain' => 'already-gone.com']);
T::eq('DOMAIN_EXPIRED event', 1, count($expiredEvents));

T::section('Renewals: auto-renewal creates a real invoice (server-side price)');
Settings::put('domain_auto_renew_lead_days', '14');
$id3 = chs_renewal_domain(11, 'auto-renew-me.com', Clock::in(10 * 86400), 1);
$before = count($gateway->invoices);
$svc->scanExpirations();
T::eq('renewal invoice created', $before + 1, count($gateway->invoices));
$renewal = Db::first('domain_renewals', ['domain_service_id' => $id3]);
T::ok('renewal row created', $renewal !== null);
T::eq('pending payment', RenewalService::PENDING_PAYMENT, $renewal['status']);
T::eq('server-side amount', 1599, (int) $renewal['amount_minor']);
$meta = $gateway->invoiceMeta[$renewal['invoice_id']];
T::ok('line item describes the renewal', strpos($meta['items'][0]['description'], 'auto-renew-me.com') !== false);
T::ok('invoice for the right client', (int) $meta['client_id'] === 11);
// scanning again does not duplicate
$svc->scanExpirations();
T::eq('no duplicate renewal', 1, Db::count('domain_renewals', ['domain_service_id' => $id3]));

T::section('Renewals: manual (auto-renew off) domains get no invoice');
$id4 = chs_renewal_domain(11, 'manual-only.com', Clock::in(5 * 86400), 0);
$before4 = count($gateway->invoices);
$svc->scanExpirations();
T::eq('no invoice for manual domain', $before4, count($gateway->invoices));
T::eq('no renewal row', 0, Db::count('domain_renewals', ['domain_service_id' => $id4]));

T::section('Renewals: InvoicePaid → provider job → provider renewal (real call)');
$fake = new FakeDomainProvider();
$registry = new FakeProviderRegistry($fake);
$svc2 = new RenewalService(new JobQueue());
// point the service's provider at the fake via registry injection is not
// supported here — the service builds its own registry — so drive execute()
// through the WHMCS provider path and assert honest failure, then drive the
// platform-reconciliation path to completion.
$gateway->invoices[$renewal['invoice_id']] = 'Paid';
$svc2->invoicePaid($renewal['invoice_id']);
$renewalAfter = Db::first('domain_renewals', ['id' => (int) $renewal['id']]);
T::eq('renewal processing', RenewalService::PROCESSING, $renewalAfter['status']);
$jobs = Db::all('jobs', ['type' => DomainJobTypes::RENEWAL, 'entity_id' => (int) $renewal['id']]);
T::eq('renewal job enqueued', 1, count($jobs));
$svc2->invoicePaid($renewal['invoice_id']); // replay → no-op
T::eq('replay does not re-enqueue', 1, Db::count('jobs', ['type' => DomainJobTypes::RENEWAL, 'entity_id' => (int) $renewal['id']]));

// WHMCS provider cannot renew directly → honest failure
$run = \Chs\Workflow\DomainWorker::run(5);
$failed = Db::first('domain_renewals', ['id' => (int) $renewal['id']]);
T::eq('renewal failed honestly', RenewalService::FAILED, $failed['status']);
T::ok('error mentions unsupported', strpos($failed['error'], 'PROVIDER_OPERATION_UNSUPPORTED') !== false);
$failEvents = Db::all('domain_events', ['type' => 'DOMAIN_RENEWAL_FAILED', 'domain' => 'auto-renew-me.com']);
T::eq('DOMAIN_RENEWAL_FAILED event', 1, count($failEvents));
$failNotices = Db::all('notifications', ['client_id' => 11, 'type' => 'domain_renewal_failed']);
T::ok('customer told honestly', count($failNotices) >= 1);

T::section('Renewals: platform reconciliation settles when WHMCS advanced the expiry');
$gateway->clientDomains[11][] = ['id' => 777, 'domain' => 'auto-renew-me.com', 'expiry' => '2028-01-01', 'status' => 'Active'];
// new renewal attempt
$svc3 = new RenewalService(new JobQueue());
Db::insert('domain_renewals', [
    'domain_service_id' => $id3,
    'invoice_id' => null,
    'status' => RenewalService::PROCESSING,
    'years' => 1,
    'amount_minor' => 1599,
    'currency' => 'USD',
    'attempted_at' => Clock::now(),
    'created_at' => Clock::now(),
]);
$renewalId2 = Db::first('domain_renewals', ['domain_service_id' => $id3, 'status' => RenewalService::PROCESSING, 'invoice_id' => null]);
// update our stored expiry to the past so reconciliation sees the advance
Db::update('domain_services', ['id' => $id3], ['expires_at' => '2027-01-01 00:00:00']);
$svc3->execute((int) $renewalId2['id']);
$settled = Db::first('domain_renewals', ['id' => (int) $renewalId2['id']]);
T::eq('settled completed', RenewalService::COMPLETED, $settled['status']);
$domainAfter = Db::first('domain_services', ['id' => $id3]);
T::eq('expiry advanced from platform', '2028-01-01 00:00:00', $domainAfter['expires_at']);
$renewedEvents = Db::all('domain_events', ['type' => 'DOMAIN_RENEWED', 'domain' => 'auto-renew-me.com']);
T::eq('DOMAIN_RENEWED event', 1, count($renewedEvents));

T::section('Renewals: provider-backed renewal via fake provider');
$fakeRenewal = new RenewalService(new JobQueue());
// execute() uses the registry internally; with no providers configured the
// WHMCS provider is used — covered above. Here we verify the execute path
// against a paid invoice + fake provider by calling the provider directly
// through the service's reconciliation guard: invoice must be Paid first.
$inv = $gateway->createInvoice(11, [['description' => 'Domain renewal — x.com (1 year)', 'amount_minor' => 1599, 'taxed' => true]], 'USD', 1);
$gateway->invoices[$inv] = 'Unpaid';
$svc4 = new RenewalService(new JobQueue());
$id5 = chs_renewal_domain(22, 'paid-check.com', Clock::in(3 * 86400), 1);
Db::insert('domain_renewals', [
    'domain_service_id' => $id5,
    'invoice_id' => $inv,
    'status' => RenewalService::PROCESSING,
    'years' => 1,
    'amount_minor' => 1599,
    'currency' => 'USD',
    'attempted_at' => Clock::now(),
    'created_at' => Clock::now(),
]);
$rid = (int) Db::first('domain_renewals', ['invoice_id' => $inv])['id'];
// The invoice is NOT paid: the queued job must refuse to execute.
(new JobQueue())->enqueue(DomainJobTypes::RENEWAL, ['renewal_id' => $rid], [
    'idempotency_key' => 'domain-renewal:' . $rid,
]);
\Chs\Workflow\DomainWorker::run(5);
$st = Db::first('domain_renewals', ['id' => $rid]);
T::eq('unpaid renewal refused', RenewalService::FAILED, $st['status']);
T::ok('refusal reason recorded', strpos($st['error'], 'not paid') !== false);
// paying the invoice and re-running with a capable provider completes it
$gateway->invoices[$inv] = 'Paid';
Db::update('domain_renewals', ['id' => $rid], ['status' => RenewalService::PROCESSING, 'error' => '']);
$fakeRen = new FakeDomainProvider();
$svcPaid = new RenewalService(new JobQueue(), new FakeProviderRegistry($fakeRen));
$svcPaid->execute($rid);
T::eq('paid renewal completes via provider', RenewalService::COMPLETED, Db::first('domain_renewals', ['id' => $rid])['status']);
T::eq('provider received the renewal', 1, count($fakeRen->callsTo('renew')));

T::finish();
