<?php
/**
 * Domain Broker — negotiation rounds, immutable offers and acceptance.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\AssignmentService;
use DomainBroker\Services\NegotiationService;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\VerificationService;
use DomainBroker\Workflow\OfferStatus;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$ada = Harness::client(1, 'Ada Lovelace');
$mallory = Harness::client(2, 'Mallory');
$admin = Harness::admin(1, 'admin_super');
$brokerRow = Harness::broker('Grace Hopper', 'broker', 11);
$otherRow = Harness::broker('Alan Turing', 'broker', 12);
$grace = Harness::brokerActor($brokerRow);
$alan = Harness::brokerActor($otherRow);

$requests = new RequestService();
$assignments = new AssignmentService();
$negotiation = new NegotiationService();

$request = $requests->create($ada, [
    'domain' => 'target.com', 'budget' => '10000.00', 'currency' => 'USD',
    'budget_includes_fees' => '0',
]);
$request = $assignments->assign($admin, $request['id'], $brokerRow['id']);

/* ------------------------------------------------------- owner contact */

section('Owner contact');

T::throws('a customer cannot record owner contact', AuthorizationException::class, function () use ($negotiation, $ada, $request) {
    $negotiation->recordOwnerContact($ada, $request['id'], ['summary' => 'I emailed them myself']);
});
T::throws('an unassigned broker cannot either', \DomainBroker\Core\DomainBrokerException::class, function () use ($negotiation, $alan, $request) {
    $negotiation->recordOwnerContact($alan, $request['id'], ['summary' => 'Poaching']);
});

$round = T::nothrow('the assigned broker records contact', function () use ($negotiation, $grace, $request) {
    return $negotiation->recordOwnerContact($grace, $request['id'], [
        'channel' => 'email',
        'summary' => 'Approached the registrant via the address on the registrar record.',
    ]);
});
T::is('round one opened', 1, (int) $round['round']);
T::is('awaiting the owner', 'awaiting_owner', $round['status']);
T::is('the request advanced', RequestStatus::OWNER_CONTACTED, $requests->findRow($request['id'])['status']);
T::ok('the customer can see it on the timeline', count(array_filter(
    \DomainBroker\Core\Audit::timeline($request['id'], 'customer'),
    function ($e) {
        return $e['action'] === 'negotiation.owner.contacted';
    }
)) === 1);

T::nothrow('the broker records the response', function () use ($negotiation, $grace, $round) {
    return $negotiation->recordOwnerResponse($grace, $round['id'], [
        'response' => 'The registrant is open to offers above 12,000.',
        'outcome' => 'interested',
    ]);
});
T::is('the request is now in negotiation', RequestStatus::NEGOTIATION, $requests->findRow($request['id'])['status']);

/* --------------------------------------------------------------- offers */

section('Offers to the registrant');

T::throws('an offer above the budget is refused', ValidationException::class, function () use ($negotiation, $grace, $request) {
    $negotiation->createOffer($grace, $request['id'], [
        'amount' => '11000.00', 'direction' => OfferStatus::DIR_TO_OWNER,
    ]);
});
T::throws('an offer in the wrong currency is refused', ValidationException::class, function () use ($negotiation, $grace, $request) {
    $negotiation->createOffer($grace, $request['id'], [
        'amount' => '5000.00', 'currency' => 'EUR', 'direction' => OfferStatus::DIR_TO_OWNER,
    ]);
});
T::throws('a customer cannot fabricate an owner offer', AuthorizationException::class, function () use ($negotiation, $ada, $request) {
    $negotiation->createOffer($ada, $request['id'], [
        'amount' => '100.00', 'direction' => OfferStatus::DIR_TO_CUSTOMER,
    ]);
});

$offer1 = T::nothrow('the broker makes an opening offer', function () use ($negotiation, $grace, $request) {
    return $negotiation->createOffer($grace, $request['id'], [
        'amount' => '7500.00',
        'direction' => OfferStatus::DIR_TO_OWNER,
        'message' => 'Our client can move quickly at this level.',
        'internal_note' => 'Client indicated they will stretch to 10k if pushed.',
    ]);
});
T::ok('it has a reference', strpos($offer1['reference'], 'OF-') === 0);
T::is('amount is in minor units', 750000, (int) $offer1['amount_minor']);
T::is('it is pending', OfferStatus::PENDING, $offer1['status']);
T::is('sent by the broker', OfferStatus::PARTY_BROKER, $offer1['sender_party']);
T::is('to the owner', OfferStatus::PARTY_OWNER, $offer1['recipient_party']);
T::ok('it expires', !empty($offer1['expires_at']));

section('Internal notes stay internal');

$customerView = $negotiation->offersFor($request['id'], 'customer');
$leaked = false;
foreach ($customerView as $row) {
    if (array_key_exists('internal_note', $row)) {
        $leaked = true;
    }
}
T::ok('the customer projection has no internal_note key at all', !$leaked);
$brokerView = $negotiation->offersFor($request['id'], 'broker');
T::contains('the broker still sees the note', 'stretch to 10k', $brokerView[0]['internal_note']);

section('Owner counteroffer');

$offer2 = T::nothrow('the broker records the registrant counteroffer', function () use ($negotiation, $grace, $offer1) {
    return $negotiation->counterOffer($grace, $offer1['id'], [
        'amount' => '9500.00',
        'message' => 'The registrant will accept 9,500.',
    ]);
});
T::is('the counteroffer travels to the customer', OfferStatus::DIR_TO_CUSTOMER, $offer2['direction']);
T::is('it is marked as a counteroffer', 1, (int) $offer2['is_counteroffer']);
T::is('it points at its parent', (int) $offer1['id'], (int) $offer2['parent_offer_id']);
T::is('it is round two', 2, (int) $offer2['round']);

$parent = Db::first('offers', ['id' => (int) $offer1['id']]);
T::is('the parent is closed as countered', OfferStatus::COUNTERED, $parent['status']);
T::is('the parent amount is untouched', 750000, (int) $parent['amount_minor']);
T::is('the parent message is untouched', 'Our client can move quickly at this level.', $parent['message']);
T::is('nothing was overwritten — two rows exist', 2, Db::count('offers', ['request_id' => (int) $request['id']]));
T::is('the request reflects an offer received', RequestStatus::OFFER_RECEIVED, $requests->findRow($request['id'])['status']);
T::ok('the customer was notified', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'audience' => 'customer',
    'event' => 'counteroffer.received',
]) > 0);

section('Customer counteroffer');

$offer3 = T::nothrow('the customer counters', function () use ($negotiation, $ada, $offer2) {
    return $negotiation->counterOffer($ada, $offer2['id'], [
        'amount' => '8500.00',
        'message' => 'We can do 8,500 today.',
    ]);
});
T::is('it heads back to the owner', OfferStatus::DIR_TO_OWNER, $offer3['direction']);
T::is('the sender is the customer', OfferStatus::PARTY_CUSTOMER, $offer3['sender_party']);
T::is('three immutable rows now', 3, Db::count('offers', ['request_id' => (int) $request['id']]));
T::is('the ladder is ordered', [750000, 950000, 850000], array_map(function ($o) {
    return (int) $o['amount_minor'];
}, $negotiation->offersFor($request['id'], 'broker')));

T::throws('a customer cannot counter above their own budget', ValidationException::class, function () use ($negotiation, $ada, $request, $offer3) {
    $negotiation->createOffer($ada, $request['id'], [
        'amount' => '50000.00', 'direction' => OfferStatus::DIR_TO_OWNER,
    ]);
});
T::throws('a third party cannot touch the negotiation', \DomainBroker\Core\DomainBrokerException::class, function () use ($negotiation, $mallory, $offer2) {
    $negotiation->counterOffer($mallory, $offer2['id'], ['amount' => '1.00']);
});

/* --------------------------------------------------------- accept/reject */

section('Acceptance');

$finalOffer = $negotiation->createOffer($grace, $request['id'], [
    'amount' => '9000.00',
    'direction' => OfferStatus::DIR_TO_CUSTOMER,
    'message' => 'Registrant agrees to 9,000.',
]);
T::is('the customer has a pending offer', (int) $finalOffer['id'], (int) $negotiation->pendingCustomerOffer($request['id'])['id']);

T::throws('a stranger cannot accept it', \DomainBroker\Core\DomainBrokerException::class, function () use ($negotiation, $mallory, $finalOffer) {
    $negotiation->acceptOffer($mallory, $finalOffer['id']);
});

$accepted = T::nothrow('the customer accepts', function () use ($negotiation, $ada, $finalOffer) {
    return $negotiation->acceptOffer($ada, $finalOffer['id']);
});
T::is('the offer is accepted', OfferStatus::ACCEPTED, $accepted['status']);
T::ok('an acceptance timestamp was written', !empty($accepted['accepted_at']));
T::is('the fee was snapshotted', 90000, (int) $accepted['fee_minor']); // 10% of 9,000.00
T::is('the total was snapshotted', 990000, (int) $accepted['total_minor']);

$afterAccept = $requests->findRow($request['id']);
T::is('the request moved to offer accepted', RequestStatus::OFFER_ACCEPTED, $afterAccept['status']);
T::is('the agreed amount is on the request', 900000, (int) $afterAccept['agreed_amount_minor']);
T::is('the broker fee is on the request', 90000, (int) $afterAccept['broker_fee_minor']);
T::is('the total is on the request', 990000, (int) $afterAccept['total_minor']);
T::is('the agreed offer is recorded', (int) $finalOffer['id'], (int) $afterAccept['agreed_offer_id']);

T::is('every other offer was closed out', 0, Db::count('offers', [
    'request_id' => (int) $request['id'], 'status' => OfferStatus::PENDING,
]));
T::is('history is intact', 4, Db::count('offers', ['request_id' => (int) $request['id']]));

$verification = new VerificationService();
T::ok('a verification checklist now exists', count($verification->forRequest($request['id'])) > 0);
T::ok('and it is not satisfied yet', $verification->outstandingRequirements($afterAccept) !== []);

T::throws('an accepted offer cannot be accepted twice', ConflictException::class, function () use ($negotiation, $ada, $finalOffer) {
    $negotiation->acceptOffer($ada, $finalOffer['id']);
});
T::throws('no further offers once terms are agreed', ConflictException::class, function () use ($negotiation, $grace, $request) {
    $negotiation->createOffer($grace, $request['id'], [
        'amount' => '100.00', 'direction' => OfferStatus::DIR_TO_OWNER,
    ]);
});

section('Rejection');

$second = $requests->create(Harness::client(3, 'Second Customer'), [
    'domain' => 'reject-me.com', 'budget' => '5000.00', 'currency' => 'USD',
]);
$second = $assignments->assign($admin, $second['id'], $brokerRow['id']);
$negotiation->recordOwnerContact($grace, $second['id'], ['summary' => 'Approached the owner.']);
$rejectable = $negotiation->createOffer($grace, $second['id'], [
    'amount' => '4800.00', 'direction' => OfferStatus::DIR_TO_CUSTOMER,
]);
$customerThree = Actor::customer(3, 'Second Customer');
$rejected = T::nothrow('the customer rejects an offer', function () use ($negotiation, $customerThree, $rejectable) {
    return $negotiation->rejectOffer($customerThree, $rejectable['id'], 'Too high for us.');
});
T::is('it is marked rejected', OfferStatus::REJECTED, $rejected['status']);
T::is('the reason is kept', 'Too high for us.', $rejected['rejection_reason']);
T::is('the amount is unchanged', 480000, (int) $rejected['amount_minor']);
T::is('negotiation resumes', RequestStatus::NEGOTIATION, $requests->findRow($second['id'])['status']);
T::throws('a rejected offer cannot then be accepted', ConflictException::class, function () use ($negotiation, $customerThree, $rejectable) {
    $negotiation->acceptOffer($customerThree, $rejectable['id']);
});

section('Withdrawal');

$withdrawable = $negotiation->createOffer($grace, $second['id'], [
    'amount' => '4500.00', 'direction' => OfferStatus::DIR_TO_OWNER,
]);
T::throws('a customer cannot withdraw the broker offer', AuthorizationException::class, function () use ($negotiation, $customerThree, $withdrawable) {
    $negotiation->withdrawOffer($customerThree, $withdrawable['id'], 'nope');
});
$withdrawn = T::nothrow('the sender can withdraw it', function () use ($negotiation, $grace, $withdrawable) {
    return $negotiation->withdrawOffer($grace, $withdrawable['id'], 'Registrant went quiet.');
});
T::is('it is withdrawn', OfferStatus::WITHDRAWN, $withdrawn['status']);

section('Expiry');

$expiring = $negotiation->createOffer($grace, $second['id'], [
    'amount' => '4600.00', 'direction' => OfferStatus::DIR_TO_CUSTOMER,
    'expires_in_hours' => 1,
]);
Db::update('offers', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $expiring['id']]);
$count = $negotiation->expireOffers();
T::ok('the sweep expired it', $count >= 1);
T::is('status is expired', OfferStatus::EXPIRED, Db::first('offers', ['id' => (int) $expiring['id']])['status']);
T::ok('the customer was told', Db::count('notifications', [
    'request_id' => (int) $second['id'], 'event' => 'offer.expired',
]) > 0);

$stale = $negotiation->createOffer($grace, $second['id'], [
    'amount' => '4700.00', 'direction' => OfferStatus::DIR_TO_CUSTOMER,
]);
Db::update('offers', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $stale['id']]);
T::throws('an expired offer cannot be accepted', ConflictException::class, function () use ($negotiation, $customerThree, $stale) {
    $negotiation->acceptOffer($customerThree, $stale['id']);
});

section('Budget inclusive of fees');

$inclusive = $requests->create(Harness::client(4, 'Inclusive Customer'), [
    'domain' => 'all-in.com', 'budget' => '11000.00', 'currency' => 'USD',
    'budget_includes_fees' => '1',
]);
$inclusive = $assignments->assign($admin, $inclusive['id'], $otherRow['id']);
$negotiation->recordOwnerContact($alan, $inclusive['id'], ['summary' => 'Contacted the owner.']);

T::throws('the ceiling accounts for the commission', ValidationException::class, function () use ($negotiation, $alan, $inclusive) {
    // 10,500 + 10% commission = 11,550 > 11,000 budget.
    $negotiation->createOffer($alan, $inclusive['id'], [
        'amount' => '10500.00', 'direction' => \DomainBroker\Workflow\OfferStatus::DIR_TO_OWNER,
    ]);
});
T::nothrow('an offer that fits inclusive of fees is allowed', function () use ($negotiation, $alan, $inclusive) {
    return $negotiation->createOffer($alan, $inclusive['id'], [
        'amount' => '9500.00', 'direction' => \DomainBroker\Workflow\OfferStatus::DIR_TO_OWNER,
    ]);
});

Harness::shutdown();
exit(T::summary());
