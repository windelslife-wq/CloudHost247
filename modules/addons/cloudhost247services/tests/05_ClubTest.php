<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\NotFoundException;
use Chs\Core\Settings;
use Chs\Core\ValidationException;
use Chs\Services\ClubService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_seed_tld_fixtures();
chs_freeze();

T::section('Plan management (admin)');
$svc = new ClubService();
$seedPlan = $svc->plan(1);
T::ok('seeded plan exists', $seedPlan !== null);
T::eq('seed period', 12, (int) $seedPlan['period_months']);
T::ok('seed has eligible TLDs', count($seedPlan['tlds']) >= 5);
T::ok('seed discount positive', (float) $seedPlan['discount_percent'] > 0);

$newId = $svc->savePlan(0, [
    'name' => 'Volume Insiders', 'price_minor' => 4900, 'currency' => 'USD',
    'period_months' => 6, 'discount_percent' => 15,
    'applies_register' => 1, 'applies_renew' => 1, 'applies_transfer' => 0,
    'tlds' => ['com' => 15, 'net' => 10],
], 1);
T::ok('plan created', $newId > 1);
$np = $svc->plan($newId);
T::eq('slug generated', 'volume-insiders', $np['slug']);
T::eq('two tld rules', 2, count($np['tlds']));
T::ok('tld override stored', (function () use ($np) {
    foreach ($np['tlds'] as $r) {
        if ($r['tld'] === 'com') {
            return (float) $r['discount_percent'] === 15.0;
        }
    }
    return false;
})());

T::throws('empty name rejected', function () use ($svc) {
    $svc->savePlan(0, ['name' => '', 'price_minor' => 100], 1);
}, ValidationException::class);
T::throws('bad period rejected', function () use ($svc) {
    $svc->savePlan(0, ['name' => 'X', 'price_minor' => 100, 'period_months' => 7], 1);
}, ValidationException::class);
T::throws('bad discount rejected', function () use ($svc) {
    $svc->savePlan(0, ['name' => 'X', 'price_minor' => 100, 'discount_percent' => 150], 1);
}, ValidationException::class);
T::ok('update applies in place', (function () use ($svc, $newId) {
    $svc->savePlan($newId, ['name' => 'Volume Insiders', 'price_minor' => 5900, 'period_months' => 6,
        'discount_percent' => 20, 'applies_register' => 1, 'applies_renew' => 1, 'applies_transfer' => 0], 1);
    return (int) $svc->plan($newId)['price_minor'] === 5900;
})());
T::ok('slug survived the update (no duplicate row)', (function () use ($svc) {
    return Db::count('club_plans', ['slug' => 'volume-insiders']) === 1;
})());

T::section('Join flows honestly through an invoice');
$join = $svc->join(22, $newId);
T::ok('membership created pending', $join['membership_id'] > 0);
T::ok('invoice returned', $join['invoice_id'] >= 9001);
T::ok('invoice url plausible', strpos($join['url'], 'viewinvoice.php') !== false);
$inv = $gateway->callsTo('createInvoice')[0];
T::eq('invoice billed to joiner', 22, $inv['client']);
T::eq('invoice price = plan', 5900, (int) $inv['items'][0]['amount_minor']);
T::eq('invoice currency = plan currency', 'USD', $inv['invoice'] ? $gateway->invoiceMeta[$inv['invoice']]['currency'] : '-');

T::ok('repeat join reuses same unpaid invoice (no duplicates)', (function () use ($svc, $join, $newId) {
    $again = $svc->join(22, $newId);
    return $again['invoice_id'] === $join['invoice_id'] && Db::count('club_memberships', ['client_id' => 22]) === 1;
})());
T::ok('not active before payment', $svc->activeMembership(22) === null);
T::ok('no discount before payment', $svc->orderPriceOverride('register', 'com', 22, '12.99') === '');

T::section('Payment activates the membership');
$gateway->invoices[(int) $join['invoice_id']] = 'Paid';
T::ok('invoicePaid activates', $svc->invoicePaid((int) $join['invoice_id']) === true);
T::ok('replay idempotent', $svc->invoicePaid((int) $join['invoice_id']) === false);
$m = $svc->activeMembership(22);
T::ok('active membership visible', $m !== null);
T::eq('starts now', '2026-10-06 12:00:00', $m['starts_at']);
T::eq('expires 6×30 days out', gmdate('Y-m-d H:i:s', Clock::time() + 6 * 30 * 86400), $m['expires_at']);
T::ok('member notified', Db::count('notifications', ['client_id' => 22, 'type' => 'club_active']) === 1);
T::eq('plan joined on row', (int) $m['plan_id'], $newId);

T::section('Member pricing hook (WHMCS OrderDomainPricingOverride)');
\Chs\Core\Settings::override('club_allow_registrations', '1');
\Chs\Core\Settings::override('club_allow_renewals', '1');
T::eq('com register at 15% off', '11.04', $svc->orderPriceOverride('register', 'com', 22, '12.99'));
T::eq('net at plan tld override 10%', '17.99', $svc->orderPriceOverride('register', 'net', 22, '19.99'));
T::eq('renew applies for this plan at same rules', '11.04', $svc->orderPriceOverride('renew', 'com', 22, '12.99'));
T::eq('transfer not eligible on plan flags', '', $svc->orderPriceOverride('transfer', 'com', 22, '9.99'));
T::eq('unlisted tld untouched', '', $svc->orderPriceOverride('register', 'io', 22, '39.99'));
T::eq('guest never discounted', '', $svc->orderPriceOverride('register', 'com', 0, '12.99'));
T::eq('non-member not discounted', '', $svc->orderPriceOverride('register', 'com', 11, '12.99'));
T::ok('discount audit written', Db::count('audit_log', ['action' => 'club.discount_applied']) >= 3);
\Chs\Core\Settings::override('club_allow_registrations', '0');
T::eq('operator switch-off kills discounts instantly', '', $svc->orderPriceOverride('register', 'com', 22, '12.99'));
\Chs\Core\Settings::override('club_allow_registrations', null);

T::section('Cancel keeps paid-to date; expiry task finalises');
$svc->cancel(22, (int) $join['membership_id']);
$m2 = Db::first('club_memberships', ['id' => (int) $join['membership_id']]);
T::ok('cancel marks cancelled_at', $m2['cancelled_at'] !== null);
T::ok('benefits continue post-cancel-until-expiry', (function () use ($svc) {
    \Chs\Core\Settings::override('club_allow_registrations', '1');
    $r = $svc->orderPriceOverride('register', 'com', 22, '12.99');
    \Chs\Core\Settings::override('club_allow_registrations', null);
    return $r === '11.04';
})());
chs_freeze('2027-04-04 12:00:00'); // past 6×30 days
T::eq('expiry sweeps exactly one', 1, (new ClubService())->expireDue());
T::ok('membership now expired', Db::first('club_memberships', ['id' => (int) $join['membership_id']])['status'] === 'expired');
T::ok('member told about expiry', Db::count('notifications', ['client_id' => 22, 'type' => 'club_expired']) === 1);
T::ok('no membership anymore', (new ClubService())->activeMembership(22) === null);
T::ok('no discount post expiry', (new ClubService())->orderPriceOverride('register', 'com', 22, '12.99') === '');
chs_freeze();

T::section('Membership only one at a time');
$svcX = new ClubService();
$j1 = $svcX->join(11, $newId);
$gateway->invoices[(int) $j1['invoice_id']] = 'Paid';
$svcX->invoicePaid((int) $j1['invoice_id']);
T::throws('second concurrent membership refused', function () use ($svcX, $newId) {
    $svcX->join(11, 1);
}, \Chs\Core\ChsException::class);
T::throws('switch disabled blocks join', function () {
    Settings::override('club_enabled', '0');
    try {
        (new ClubService())->join(33, 1);
    } finally {
        Settings::override('club_enabled', null);
    }
}, \Chs\Core\ChsException::class);

T::finish();
