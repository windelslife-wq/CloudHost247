<?php
/**
 * Domain Broker — fee schedules, risk scoring, disputes and reporting.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\Money;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\DisputeService;
use DomainBroker\Services\FeeService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\ReportService;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\RiskService;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$fees = new FeeService();
$reports = new ReportService();
$risk = new RiskService();
$disputes = new DisputeService();
$requests = new RequestService();
$payments = new PaymentService();

$admin = Harness::admin(1, 'admin_super');
$finance = Harness::admin(5, 'admin_finance', 'Finance Officer');
$manager = Harness::admin(7, 'admin_manager', 'Operations Manager');
$viewer = Harness::admin(6, 'admin_viewer', 'Support Agent');

/* ------------------------------------------------------------ seed rule */

section('The seeded schedule');

$seeded = $fees->listRules($admin);
T::is('exactly one rule ships', 1, count($seeded));
T::is('it is a percentage rule', FeeService::CALC_PERCENTAGE, $seeded[0]['calculation']);
T::ok('it is editable, not hardcoded', (int) $seeded[0]['id'] > 0);

$quote = $fees->quote(1000000, 'USD', 'example.com');
T::is('10% of 10,000 is 1,000', 100000, $quote['fee_minor']);
T::is('the total adds the commission', 1100000, $quote['total_minor']);
T::is('the acquisition is passed through', 1000000, $quote['acquisition_minor']);
T::is('the commission is the taxable element', $quote['fee_minor'], $quote['taxable_minor']);
T::is('and the rule is identified', (int) $seeded[0]['id'], (int) $quote['rule_id']);
T::is('an invoice has two lines', 2, count($quote['lines']));

/* ------------------------------------------------------- the four modes */

section('Fee calculation modes');

$fees->updateRule($admin, $seeded[0]['id'], ['active' => 0]);
T::is('with no rule the fee is zero', 0, $fees->quote(5000000, 'USD', 'nothing.com')['fee_minor']);
T::is('and the module never invents a price', 5000000, $fees->quote(5000000, 'USD', 'nothing.com')['total_minor']);
T::is('with no rule the quote names none', null, $fees->quote(5000000, 'USD', 'nothing.com')['rule_id']);

$fixed = $fees->createRule($admin, [
    'code' => 'flat-fee', 'name' => 'Flat brokerage fee',
    'calculation' => FeeService::CALC_FIXED, 'fixed_minor' => 49900,
    'priority' => 500, 'active' => 1,
]);
T::is('a fixed fee ignores the price (small)', 49900, $fees->quote(100000, 'USD', 'a.com')['fee_minor']);
T::is('a fixed fee ignores the price (large)', 49900, $fees->quote(90000000, 'USD', 'a.com')['fee_minor']);

$pct = $fees->createRule($admin, [
    'code' => 'pct-with-floor', 'name' => 'Percentage with floor and ceiling',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '12.5',
    'min_fee_minor' => 25000, 'max_fee_minor' => 500000,
    'priority' => 100, 'active' => 1,
]);
T::is('12.5% of 20,000 is 2,500', 250000, $fees->quote(2000000, 'USD', 'a.com')['fee_minor']);
T::is('the floor lifts a tiny deal', 25000, $fees->quote(10000, 'USD', 'a.com')['fee_minor']);
T::is('the ceiling caps a huge deal', 500000, $fees->quote(100000000, 'USD', 'a.com')['fee_minor']);
T::is('the lowest priority rule wins', (int) $pct['id'], (int) $fees->quote(2000000, 'USD', 'a.com')['rule_id']);

$tiered = $fees->createRule($admin, [
    'code' => 'tiered-marginal', 'name' => 'Tiered commission',
    'calculation' => FeeService::CALC_TIERED,
    'tiers' => ['mode' => 'marginal', 'bands' => [
        ['from_minor' => 0, 'to_minor' => 1000000, 'percentage' => '20'],
        ['from_minor' => 1000000, 'to_minor' => 5000000, 'percentage' => '10'],
        ['from_minor' => 5000000, 'to_minor' => 0, 'percentage' => '5'],
    ]],
    'priority' => 50, 'active' => 1,
]);
T::is('inside the first band it is 20%', 100000, $fees->quote(500000, 'USD', 'a.com')['fee_minor']);
T::is('at the band edge it is 20% of the whole', 200000, $fees->quote(1000000, 'USD', 'a.com')['fee_minor']);
T::is('across two bands each slice is charged', 300000, $fees->quote(2000000, 'USD', 'a.com')['fee_minor']);
T::is('across three bands too', 950000, $fees->quote(12000000, 'USD', 'a.com')['fee_minor']);

$fees->updateRule($admin, $tiered['id'], ['tiers' => ['mode' => 'flat', 'bands' => [
    ['from_minor' => 0, 'to_minor' => 1000000, 'percentage' => '20'],
    ['from_minor' => 1000001, 'to_minor' => 0, 'percentage' => '8'],
]]]);
T::is('flat mode charges one rate on the whole amount', 160000, $fees->quote(2000000, 'USD', 'a.com')['fee_minor']);
$fees->updateRule($admin, $tiered['id'], ['active' => 0]);

/* --------------------------------------------------- scoping and promos */

section('Scoping, currency and promotions');

$gbp = $fees->createRule($admin, [
    'code' => 'gbp-only', 'name' => 'Sterling schedule',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '7',
    'currency' => 'GBP', 'priority' => 10, 'active' => 1,
]);
T::is('a sterling deal uses the sterling rule', (int) $gbp['id'], (int) $fees->quote(2000000, 'GBP', 'a.com')['rule_id']);
T::is('7% of 20,000', 140000, $fees->quote(2000000, 'GBP', 'a.com')['fee_minor']);
T::isnt('a dollar deal does not', (int) $gbp['id'], (int) $fees->quote(2000000, 'USD', 'a.com')['rule_id']);

$ioRule = $fees->createRule($admin, [
    'code' => 'io-premium', 'name' => 'Premium TLD schedule',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '18',
    'tld_scope' => 'io, ai', 'priority' => 20, 'active' => 1,
]);
T::is('a .io deal uses the TLD rule', (int) $ioRule['id'], (int) $fees->quote(2000000, 'USD', 'startup.io')['rule_id']);
T::is('an .ai deal too', (int) $ioRule['id'], (int) $fees->quote(2000000, 'USD', 'model.ai')['rule_id']);
T::isnt('a .com deal does not', (int) $ioRule['id'], (int) $fees->quote(2000000, 'USD', 'startup.com')['rule_id']);

$band = $fees->createRule($admin, [
    'code' => 'enterprise-band', 'name' => 'Enterprise band',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '4',
    'applies_min_minor' => 5000000, 'priority' => 30, 'active' => 1,
]);
T::is('a large deal falls into the band', (int) $band['id'], (int) $fees->quote(8000000, 'USD', 'big.com')['rule_id']);
T::isnt('a small one does not', (int) $band['id'], (int) $fees->quote(100000, 'USD', 'small.com')['rule_id']);

$promo = $fees->createRule($admin, [
    'code' => 'launch-promo', 'name' => 'Launch promotion',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '12.5',
    'promo_code' => 'LAUNCH25', 'discount_percentage' => '25',
    'priority' => 1, 'active' => 1,
]);
T::isnt('the promo is invisible without the code', (int) $promo['id'], (int) $fees->quote(2000000, 'USD', 'a.com')['rule_id']);
$promoQuote = $fees->quote(2000000, 'USD', 'a.com', 'LAUNCH25');
T::is('with the code it applies', (int) $promo['id'], (int) $promoQuote['rule_id']);
T::is('the gross fee is 12.5%', 250000, $promoQuote['gross_fee_minor']);
T::is('25% is discounted', 62500, $promoQuote['discount_minor']);
T::is('leaving 187.50', 187500, $promoQuote['fee_minor']);
T::is('codes are case-insensitive', (int) $promo['id'], (int) $fees->quote(2000000, 'USD', 'a.com', 'launch25')['rule_id']);
T::isnt('a wrong code does not unlock it', (int) $promo['id'], (int) $fees->quote(2000000, 'USD', 'a.com', 'NOPE')['rule_id']);

$fees->updateRule($admin, $promo['id'], ['min_fee_minor' => 200000]);
T::is('the floor still beats a promotion', 200000, $fees->quote(2000000, 'USD', 'a.com', 'LAUNCH25')['fee_minor']);

$expired = $fees->createRule($admin, [
    'code' => 'last-year', 'name' => 'Expired promotion',
    'calculation' => FeeService::CALC_FIXED, 'fixed_minor' => 1,
    'valid_to' => '2001-01-01 00:00:00', 'priority' => 0, 'active' => 1,
]);
T::isnt('an out-of-date rule never applies', (int) $expired['id'], (int) $fees->quote(2000000, 'USD', 'a.com')['rule_id']);

/* ------------------------------------------------------------ taxes */

section('Taxes');

$taxed = $fees->createRule($admin, [
    'code' => 'vat-rule', 'name' => 'Commission plus VAT',
    'calculation' => FeeService::CALC_PERCENTAGE, 'percentage' => '10',
    'tax_rate' => '20', 'currency' => 'EUR', 'priority' => 5, 'active' => 1,
]);
$vat = $fees->quote(1000000, 'EUR', 'a.com');
T::is('the commission is 10%', 100000, $vat['fee_minor']);
T::is('VAT is charged on the commission only', 20000, $vat['tax_minor']);
T::is('the total is price + fee + tax', 1120000, $vat['total_minor']);
T::is('the invoice gains a tax line', 3, count($vat['lines']));

$fees->updateRule($admin, $taxed['id'], ['tax_inclusive' => 1]);
$inclusive = $fees->quote(1000000, 'EUR', 'a.com');
T::is('an inclusive fee is unchanged', 100000, $inclusive['fee_minor']);
T::is('the tax is extracted from within it', 16667, $inclusive['tax_minor']);
T::is('and is not added again', 1100000, $inclusive['total_minor']);
$fees->updateRule($admin, $taxed['id'], ['active' => 0]);

/* --------------------------------------------------- budget inclusivity */

section('Budgets inclusive of fees');

$ceiling = $fees->maxAcquisitionWithinBudget(1100000, 'USD', 'a.com');
$check = $fees->quote($ceiling, 'USD', 'a.com');
T::ok('the derived ceiling fits the budget', $check['total_minor'] <= 1100000);
$over = $fees->quote($ceiling + 100, 'USD', 'a.com');
T::ok('and one step more does not', $over['total_minor'] > 1100000);
T::is('a zero budget buys nothing', 0, $fees->maxAcquisitionWithinBudget(0, 'USD', 'a.com'));

/* ------------------------------------------------------- rule management */

section('Managing the schedule');

T::throws('a manager cannot edit fees', AuthorizationException::class, function () use ($fees, $manager) {
    $fees->createRule($manager, ['code' => 'sneaky', 'name' => 'Sneaky', 'calculation' => 'fixed', 'fixed_minor' => 1]);
});
T::throws('nor a read-only admin', AuthorizationException::class, function () use ($fees, $viewer) {
    $fees->createRule($viewer, ['code' => 'sneaky2', 'name' => 'Sneaky', 'calculation' => 'fixed', 'fixed_minor' => 1]);
});
T::nothrow('finance can', function () use ($fees, $finance) {
    return $fees->createRule($finance, [
        'code' => 'finance-made', 'name' => 'Finance rule',
        'calculation' => FeeService::CALC_FIXED, 'fixed_minor' => 100, 'active' => 0,
    ]);
});
T::throws('duplicate codes are rejected', ValidationException::class, function () use ($fees, $admin) {
    $fees->createRule($admin, ['code' => 'flat-fee', 'name' => 'Clash', 'calculation' => 'fixed', 'fixed_minor' => 1]);
});
T::throws('an unknown calculation is rejected', ValidationException::class, function () use ($fees, $admin) {
    $fees->createRule($admin, ['code' => 'weird', 'name' => 'Weird', 'calculation' => 'vibes']);
});
T::throws('a percentage rule needs a percentage', ValidationException::class, function () use ($fees, $admin) {
    $fees->createRule($admin, ['code' => 'nopct', 'name' => 'No percentage', 'calculation' => 'percentage']);
});
T::throws('a tiered rule needs bands', ValidationException::class, function () use ($fees, $admin) {
    $fees->createRule($admin, ['code' => 'notiers', 'name' => 'No tiers', 'calculation' => 'tiered']);
});
T::throws('an invalid currency is rejected', ValidationException::class, function () use ($fees, $admin) {
    $fees->createRule($admin, [
        'code' => 'badccy', 'name' => 'Bad currency', 'calculation' => 'fixed',
        'fixed_minor' => 100, 'currency' => '$$',
    ]);
});

$fees->deleteRule($admin, $fixed['id'], 'Replaced by the tiered schedule.');
T::is('deleting a rule is soft', 1, Db::count('fees', ['id' => (int) $fixed['id']]));
T::ok('the row is flagged deleted', !empty(Db::first('fees', ['id' => (int) $fixed['id']])['deleted_at']));
T::is('and it no longer quotes', null, $fees->findRule($fixed['id']));
T::ok('every change is audited', Db::count('activity', ['action' => 'fee.rule.created']) >= 5);
T::is('as is the deletion, with a reason', 1, Db::count('activity', ['action' => 'fee.rule.deleted']));

// Restore a single, predictable schedule for the reporting fixtures below.
foreach ($fees->listRules($admin) as $rule) {
    if ((int) $rule['active'] === 1) {
        $fees->updateRule($admin, $rule['id'], ['active' => 0]);
    }
}
$fees->updateRule($admin, $seeded[0]['id'], ['active' => 1]);
T::is('the seeded 10% schedule is back', 100000, $fees->quote(1000000, 'USD', 'report.com')['fee_minor']);

/* -------------------------------------------------------------- risk */

section('Fraud and risk');

$whale = Harness::client(20, 'Whale Client');
Settings::overrideMany(['risk_high_value_minor' => 5000000, 'risk_review_score' => 40]);
$big = $requests->create($whale, [
    'domain' => 'enormous-budget.com', 'budget' => '250000.00', 'currency' => 'USD',
]);
$big = $requests->findRow($big['id']);
T::is('a very high value request is held for review', 1, (int) $big['manual_review']);
T::ok('a risk flag was raised', count($risk->openFlags($big['id'])) >= 1);
$flagTypes = array_map(function ($f) {
    return $f['rule_code'];
}, $risk->openFlags($big['id']));
T::ok('the high-value rule fired', in_array(RiskService::RULE_HIGH_VALUE, $flagTypes, true));
T::ok('the admins were told', Db::count('notifications', [
    'request_id' => (int) $big['id'], 'event' => 'risk.flagged',
]) >= 1);
$queueBrokerRow = Harness::broker('Queue Broker', 'broker', 31);
T::throws('a held request cannot be claimed off the queue', ConflictException::class,
    function () use ($big, $queueBrokerRow) {
        (new \DomainBroker\Services\AssignmentService())
            ->claim(Harness::brokerActor($queueBrokerRow), $big['id']);
    });
Settings::clearOverrides();

$rapid = Harness::client(21, 'Rapid Client');
for ($i = 0; $i < 4; $i++) {
    $requests->create($rapid, ['domain' => 'rapid-' . $i . '.net', 'budget' => '900.00', 'currency' => 'USD']);
}
$last = $requests->create($rapid, ['domain' => 'rapid-last.net', 'budget' => '900.00', 'currency' => 'USD']);
$rapidFlags = array_map(function ($f) {
    return $f['rule_code'];
}, $risk->allFlags($last['id']));
T::ok('a burst of submissions is flagged', in_array(RiskService::RULE_RAPID_REQUESTS, $rapidFlags, true));

$failFixture = Harness::securedRequest('risky-payments.com', 22);
$failPayment = $payments->activePayment($failFixture['request']['id']);
for ($i = 0; $i < 3; $i++) {
    Db::update('payments', ['failed_attempts' => $i + 1, 'status' => PaymentStatus::FAILED], ['id' => (int) $failPayment['id']]);
    $risk->evaluate($requests->findRow($failFixture['request']['id']), ['event' => 'payment.failed']);
}
$payFlags = array_map(function ($f) {
    return $f['rule_code'];
}, $risk->allFlags($failFixture['request']['id']));
T::ok('repeated payment failures are flagged', in_array(RiskService::RULE_FAILED_PAYMENTS, $payFlags, true));

$openFlag = $risk->openFlags($big['id'])[0];
T::throws('a broker cannot clear a risk flag', AuthorizationException::class, function () use ($risk, $failFixture, $openFlag) {
    $risk->review($failFixture['broker'], $openFlag['id'], 'cleared', 'Looks fine');
});
T::is('a manager can review it', true, $risk->review($manager, $openFlag['id'], 'cleared', 'Known client, verified by phone.'));
T::is('the flag is cleared', 'cleared', Db::first('risk', ['id' => (int) $openFlag['id']])['status']);
T::ok('the review is audited', Db::count('activity', ['action' => 'risk.flag.cleared']) >= 1);

$approved = $requests->approve($manager, $big['id'], 'Risk cleared after a call with the client.');
T::is('approval releases the hold', 0, (int) $requests->findRow($big['id'])['manual_review']);
T::is('and it is audited', 1, Db::count('activity', [
    'request_id' => (int) $big['id'], 'action' => 'risk.manual_review.released',
]));

$riskStats = $risk->statistics();
T::ok('risk statistics are available', is_array($riskStats) && count($riskStats) > 0);

/* ---------------------------------------------------------- disputes */

section('Disputes');

$disputed = Harness::securedRequest('gone-wrong.com', 23);
T::throws('a dispute needs a known reason code', ValidationException::class, function () use ($disputes, $disputed) {
    $disputes->open($disputed['customer'], $disputed['request']['id'], [
        'reason_code' => 'because', 'description' => 'The registrant has vanished entirely on me.',
    ]);
});
T::throws('and a substantial description', ValidationException::class, function () use ($disputes, $disputed) {
    $disputes->open($disputed['customer'], $disputed['request']['id'], [
        'reason_code' => 'seller_unresponsive', 'description' => 'bad',
    ]);
});

$dispute = T::nothrow('the customer opens a dispute', function () use ($disputes, $disputed) {
    return $disputes->open($disputed['customer'], $disputed['request']['id'], [
        'reason_code' => 'seller_unresponsive',
        'description' => 'The registrant has not replied for three weeks since my payment cleared.',
        'severity' => 'high',
    ]);
});
T::ok('a dispute reference was allocated', !empty($dispute['reference']));
T::is('it is open', DisputeService::STATUS_OPEN, $dispute['status']);
T::is('the amount defaults to the deal total', 990000, (int) $dispute['amount_in_dispute_minor']);
T::is('the acquisition is frozen', RequestStatus::DISPUTED, $requests->findRow($disputed['request']['id'])['status']);
T::ok('everyone was notified', Db::count('notifications', [
    'request_id' => (int) $disputed['request']['id'], 'event' => 'dispute.opened',
]) >= 2);

T::throws('a second dispute cannot be opened', ConflictException::class, function () use ($disputes, $disputed) {
    $disputes->open($disputed['customer'], $disputed['request']['id'], [
        'reason_code' => 'other', 'description' => 'I would like to complain a second time about this.',
    ]);
});
T::throws('a read-only admin cannot resolve it', AuthorizationException::class, function () use ($disputes, $viewer, $dispute) {
    $disputes->resolve($viewer, $dispute['id'], ['resolution_type' => 'no_action', 'resolution' => 'Nothing to do here.']);
});
T::throws('a broker cannot resolve it', AuthorizationException::class, function () use ($disputes, $disputed, $dispute) {
    $disputes->resolve($disputed['broker'], $dispute['id'], ['resolution_type' => 'no_action', 'resolution' => 'All good now.']);
});

$disputes->setStatus($manager, $dispute['id'], DisputeService::STATUS_INVESTIGATING, 'Contacting the registrar.');
T::is('a manager can move it along', DisputeService::STATUS_INVESTIGATING, $disputes->find($dispute['id'])['status']);

T::throws('a partial refund outcome needs an amount', ValidationException::class, function () use ($disputes, $manager, $dispute) {
    $disputes->resolve($manager, $dispute['id'], [
        'resolution_type' => 'partial_refund',
        'resolution' => 'Returning the commission as a goodwill gesture.',
    ]);
});

$resolved = T::nothrow('a manager resolves it with a refund', function () use ($disputes, $admin, $dispute) {
    return $disputes->resolve($admin, $dispute['id'], [
        'resolution_type' => 'refund',
        'resolution' => 'The registrant never responded; the full amount has been returned to the client.',
    ]);
});
T::is('the dispute is resolved', DisputeService::STATUS_RESOLVED, $resolved['status']);
T::is('and the money actually moved', PaymentStatus::REFUNDED,
    $payments->activePayment($disputed['request']['id'])['status']);
T::is('the acquisition is marked refunded', RequestStatus::REFUNDED,
    $requests->findRow($disputed['request']['id'])['status']);
T::throws('a closed dispute cannot be resolved twice', ConflictException::class, function () use ($disputes, $admin, $dispute) {
    $disputes->resolve($admin, $dispute['id'], ['resolution_type' => 'no_action', 'resolution' => 'Again please.']);
});
T::ok('the resolution is audited', Db::count('activity', ['action' => 'dispute.resolved']) >= 1);

$rejectFixture = Harness::securedRequest('frivolous.com', 24);
$frivolous = $disputes->open($rejectFixture['customer'], $rejectFixture['request']['id'], [
    'reason_code' => 'service_quality', 'description' => 'I simply did not enjoy the experience at all.',
]);
$rejected = $disputes->reject($admin, $frivolous['id'], 'The transfer completed exactly as agreed.');
T::is('a dispute can be rejected', DisputeService::STATUS_REJECTED, $rejected['status']);
T::isnt('and the request is unfrozen', RequestStatus::DISPUTED,
    $requests->findRow($rejectFixture['request']['id'])['status']);
T::is('returning to exactly where it was', RequestStatus::PAYMENT_SECURED,
    $requests->findRow($rejectFixture['request']['id'])['status']);

$disputeStats = $disputes->statistics();
T::ok('dispute statistics are available', isset($disputeStats['total']) || count($disputeStats) > 0);

/* --------------------------------------------------------- reporting */

section('Reporting');

T::throws('a customer cannot read reports', AuthorizationException::class, function () use ($reports) {
    $reports->overview(Harness::client(25, 'Nosy'), []);
});
$overview = T::nothrow('an admin can', function () use ($reports, $admin) {
    return $reports->overview($admin, []);
});
T::ok('requests are counted', $overview['requests']['total'] > 0);
T::ok('active negotiations are counted', isset($overview['negotiations']['active']));
T::ok('offers made are counted', $overview['negotiations']['offers_made'] > 0);
T::ok('a conversion rate is produced', is_numeric($overview['conversion_rate']));
T::ok('and it is a percentage', $overview['conversion_rate'] >= 0 && $overview['conversion_rate'] <= 100);
T::ok('value is grouped by currency', is_array($overview['value']));
T::ok('revenue is grouped by currency', is_array($overview['revenue']));
T::ok('refunds are reported', is_array($overview['refunds']));
T::ok('pending payments are counted', isset($overview['pending']['payments']));
T::ok('pending transfers are counted', isset($overview['pending']['transfers']));
T::ok('manual reviews are counted', isset($overview['pending']['manual_review']));
T::ok('durations are reported', isset($overview['durations']['negotiation_days']));
T::ok('the status breakdown is present', is_array($overview['status_breakdown']));
T::ok('top TLDs are reported', is_array($overview['top_tlds']));
T::ok('disputes are summarised', is_array($overview['disputes']));
T::ok('the report is stamped', !empty($overview['generated_at']));

$narrow = $reports->overview($admin, ['from' => '2000-01-01', 'to' => '2000-12-31']);
T::is('date filtering excludes everything outside the window', 0, $narrow['requests']['total']);
T::contains('and the range is labelled', '2000-01-01', $narrow['range']['label']);
$swapped = $reports->range(['from' => '2026-12-31', 'to' => '2026-01-01']);
T::ok('a reversed range is corrected', strcmp($swapped['from'], $swapped['to']) <= 0);

$performance = $reports->brokerPerformance($admin, []);
T::ok('broker performance is reported', isset($performance['brokers']));
T::ok('with at least the fixture brokers', count($performance['brokers']) >= 1);
T::ok('and per-broker counters', isset($performance['brokers'][0]['assigned']));
T::ok('a success rate', isset($performance['brokers'][0]['success_rate']));
T::ok('and revenue by currency', isset($performance['brokers'][0]['revenue']));

T::throws('a viewer cannot export', AuthorizationException::class, function () use ($reports, $viewer) {
    $reports->export($viewer, 'requests', []);
});
foreach (['requests', 'payments', 'offers', 'brokers', 'disputes'] as $dataset) {
    $csv = T::nothrow('the ' . $dataset . ' export runs', function () use ($reports, $admin, $dataset) {
        return $reports->export($admin, $dataset, []);
    });
    T::contains('it is a CSV download', '.csv', $csv['filename']);
    T::contains('with the right mime type', 'text/csv', $csv['mime']);
    T::ok('and a header row', strlen($csv['body']) > 10 && strpos($csv['body'], "\n") !== false);
}
T::ok('exports are audited', Db::count('activity', ['action' => 'report.exported']) >= 5);

$injected = $reports->toCsv(['A'], [['=1+1']]);
T::ok('CSV formulae are neutralised', strpos($injected, "\n=1+1") === false);

Harness::shutdown();
exit(T::summary());
