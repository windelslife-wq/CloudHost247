<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeDomainProvider.php';

use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\RegistrationService;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();
Secrets::setKeyOverride('registration-test-key');

T::section('Registration: server-side quotes');
$svc = new RegistrationService(new JobQueue());
$q = $svc->quote('new-name.com', 1);
T::eq('register price', 1299, $q['base_minor']);
T::eq('final equals base for guests', 1299, $q['final_minor']);
$q2 = $svc->quote('new-name.com', 3);
T::eq('multi-year multiplies honestly', 3897, $q2['base_minor']);
T::throws('zero years rejected', function () use ($svc) {
    $svc->quote('new-name.com', 0);
}, ValidationException::class);
T::throws('11 years rejected', function () use ($svc) {
    $svc->quote('new-name.com', 11);
}, ValidationException::class);
T::throws('unsupported tld rejected', function () use ($svc) {
    $svc->quote('new-name.notreal', 1);
}, ValidationException::class);

T::section('Registration: club discount applied server-side');
$club = new \Chs\Services\ClubService();
$membership = $club->join(11, 1);
$gateway->invoices[$membership['invoice_id']] = 'Paid';
$club->invoicePaid($membership['invoice_id']);
Settings::put('club_allow_registrations', '1');
$q3 = $svc->quote('new-name.com', 1, 11);
T::ok('discount applied', $q3['final_minor'] < $q3['base_minor']);
T::eq('10% of 1299', \Chs\Core\Money::discount(1299, 10), $q3['final_minor']);

T::section('Registration: order creates a real invoice + audits');
$order = $svc->createOrder(11, 'order-me.com', 1);
T::ok('invoice created', $order['invoice_id'] > 0);
T::eq('invoice amount is the discounted server price', $q3['final_minor'], (int) $gateway->invoiceMeta[$order['invoice_id']]['items'][0]['amount_minor']);
$audit = Db::all('audit_log', ['action' => 'domain.registration_ordered']);
T::ok('order audited', count($audit) >= 1);
$notices = Db::all('notifications', ['client_id' => 11, 'type' => 'domain_registration_ordered']);
T::ok('customer notified', count($notices) >= 1);

T::section('Registration: invoicePaid queues an idempotent job only with context');
$svc2 = new RegistrationService(new JobQueue());
T::eq('no context → no job', false, $svc2->invoicePaid($order['invoice_id']));
$queued = $svc2->invoicePaid($order['invoice_id'], [
    'client_id' => 11, 'domain' => 'order-me.com', 'years' => 1, 'source' => 'test',
]);
T::ok('job queued with context', $queued !== null);
$again = $svc2->invoicePaid($order['invoice_id'], [
    'client_id' => 11, 'domain' => 'order-me.com', 'years' => 1, 'source' => 'test',
]);
T::eq('replay is a no-op', false, $again);
T::eq('one registration job', 1, Db::count('jobs', ['type' => DomainJobTypes::REGISTRATION]));

T::section('Registration: execute refuses unpaid invoices (never trust the queue)');
$job = Db::first('jobs', ['type' => DomainJobTypes::REGISTRATION]);
$run = \Chs\Workflow\DomainWorker::run(5);
$after = Db::first('jobs', ['id' => (int) $job['id']]);
T::ok('job did not complete (invoice unpaid)', $after['status'] !== 'completed');
T::ok('job error says not paid', strpos($after['error_message'], 'not paid') !== false);
T::ok('no domain row created', Db::count('domain_services', ['domain' => 'order-me.com']) === 0);
$failEvents = Db::all('domain_events', ['type' => 'DOMAIN_REGISTRATION_FAILED', 'domain' => 'order-me.com']);
T::eq('failure event recorded', 1, count($failEvents));
T::eq('failure event reason', 'invoice_not_paid', json_decode($failEvents[0]['payload'], true)['error']);

T::section('Registration: execute with paid invoice + capable provider registers for real');
$fake = new FakeDomainProvider();
$registry = new FakeProviderRegistry($fake);
$svc3 = new RegistrationService(new JobQueue(), $registry);
$gateway->invoices[$order['invoice_id']] = 'Paid';
$result = $svc3->execute([
    'invoice_id' => $order['invoice_id'],
    'client_id' => 11,
    'domain' => 'order-me.com',
    'years' => 1,
]);
T::ok('provider received the registration', count($fake->callsTo('register')) === 1);
T::ok('domain service row created', (int) $result['domain_service_id'] > 0);
$row = Db::first('domain_services', ['domain' => 'order-me.com']);
T::eq('status active', 'active', $row['status']);
T::ok('expiry about a year out', \Chs\Core\Clock::toTime($row['expires_at']) > \Chs\Core\Clock::time() + 300 * 86400);
$events = Db::all('domain_events', ['type' => 'DOMAIN_REGISTERED', 'domain' => 'order-me.com']);
T::eq('DOMAIN_REGISTERED event', 1, count($events));
$notices2 = Db::all('notifications', ['client_id' => 11, 'type' => 'domain_registered']);
T::ok('customer notified', count($notices2) >= 1);

T::section('Registration: execute with WHMCS provider fails honestly');
$order2 = $svc->createOrder(11, 'whmcs-reg.com', 1);
$gateway->invoices[$order2['invoice_id']] = 'Paid';
$svc4 = new RegistrationService(new JobQueue()); // default registry → WHMCS provider
T::throws('whmcs provider cannot register directly', function () use ($svc4, $order2) {
    $svc4->execute([
        'invoice_id' => $order2['invoice_id'],
        'client_id' => 11,
        'domain' => 'whmcs-reg.com',
        'years' => 1,
    ]);
}, \Chs\Core\ProviderException::class);
T::eq('no domain row for failed registration', 0, Db::count('domain_services', ['domain' => 'whmcs-reg.com']));

T::section('Registration: execute rejects malformed payloads');
T::throws('missing invoice id', function () use ($svc3) {
    $svc3->execute(['domain' => 'x.com']);
}, ValidationException::class);
T::throws('missing domain', function () use ($svc3) {
    $svc3->execute(['invoice_id' => 1]);
}, ValidationException::class);

T::finish();
