<?php
/**
 * Domain Broker — acquisition requests, authorisation and assignment.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Db;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\AssignmentService;
use DomainBroker\Services\BrokerDirectoryService;
use DomainBroker\Services\RequestService;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();
$ada = Harness::client(1, 'Ada Lovelace');
$bob = Harness::client(2, 'Bob Customer');
$admin = Harness::admin(1, 'admin_super');
$viewer = Harness::admin(7, 'admin_viewer');

$requests = new RequestService();

/* ---------------------------------------------------------------- create */

section('Request creation');

$request = T::nothrow('a valid request is accepted', function () use ($requests, $ada) {
    return $requests->create($ada, [
        'domain' => 'Wanted-Domain.com',
        'budget' => '5000.00',
        'currency' => 'USD',
        'message' => 'We would like this for our rebrand.',
        'anonymous' => '1',
    ]);
});

T::ok('a reference was allocated', isset($request['reference']) && strpos($request['reference'], 'DB-') === 0);
T::is('domain normalised', 'wanted-domain.com', $request['domain']);
T::is('budget stored in minor units', 500000, (int) $request['budget_minor']);
T::is('currency recorded', 'USD', $request['currency']);
T::is('owned by the right client', 1, (int) $request['client_id']);
T::is('triaged automatically', RequestStatus::UNDER_REVIEW, $request['status']);
T::is('previous status kept', RequestStatus::SUBMITTED, $request['previous_status']);
T::is('nothing agreed yet', 0, (int) $request['agreed_amount_minor']);
T::is('no payment yet', 'none', $request['payment_status']);
T::is('no transfer yet', 'not_started', $request['transfer_status']);
T::ok('an expiry was set', !empty($request['expires_at']));
T::ok('the submitter ip was captured', $request['ip_address'] === '198.51.100.20');

T::is('submission is on the timeline', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'request.created',
]));
T::is('the automatic triage is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'request.review.started',
]));
T::ok('the customer was notified', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'request.submitted',
]) > 0);

/* ------------------------------------------------------------ validation */

section('Request validation');

T::throws('an invalid domain is rejected', ValidationException::class, function () use ($requests, $ada) {
    $requests->create($ada, ['domain' => 'not a domain', 'budget' => '5000.00', 'currency' => 'USD']);
});
T::throws('a budget below the floor is rejected', ValidationException::class, function () use ($requests, $ada) {
    $requests->create($ada, ['domain' => 'tiny-budget.com', 'budget' => '1.00', 'currency' => 'USD']);
});
T::throws('an unsupported currency is rejected', ValidationException::class, function () use ($requests, $ada) {
    $requests->create($ada, ['domain' => 'odd-currency.com', 'budget' => '5000.00', 'currency' => 'XYZ']);
});
T::throws('a guest cannot create a request', AuthorizationException::class, function () use ($requests) {
    (new RequestService())->create(Actor::guest(), [
        'domain' => 'guest-attempt.com', 'budget' => '5000.00', 'currency' => 'USD',
    ]);
});

/* --------------------------------------------------- duplicates and idempotency */

section('Duplicate submissions');

$conflict = T::throws('a second open request for the same domain conflicts', ConflictException::class, function () use ($requests, $ada) {
    $requests->create($ada, ['domain' => 'wanted-domain.com', 'budget' => '9000.00', 'currency' => 'USD']);
});
T::ok('the conflict names the existing request', $conflict && strpos($conflict->getMessage(), $request['reference']) !== false);

T::nothrow('a different client may request the same domain', function () use ($requests, $bob) {
    return $requests->create($bob, [
        'domain' => 'wanted-domain.com', 'budget' => '7000.00', 'currency' => 'USD',
    ]);
});

$before = Db::count('requests');
$dup1 = $requests->create($ada, [
    'domain' => 'idempotent.com', 'budget' => '2000.00', 'currency' => 'USD',
    'idempotency_key' => 'form-token-abc',
]);
$dup2 = $requests->create($ada, [
    'domain' => 'idempotent.com', 'budget' => '2000.00', 'currency' => 'USD',
    'idempotency_key' => 'form-token-abc',
]);
T::is('a replayed submission returns the same request', (int) $dup1['id'], (int) $dup2['id']);
T::is('only one row was written', $before + 1, Db::count('requests'));

/* ---------------------------------------------------------- authorisation */

section('Per-resource authorisation');

T::throws('another customer cannot read the request', NotFoundException::class, function () use ($requests, $bob, $request) {
    $requests->findForActor($bob, $request['id']);
});
T::nothrow('the owner can read their request', function () use ($requests, $ada, $request) {
    return $requests->findForActor($ada, $request['id']);
});
T::nothrow('a read-only admin can read any request', function () use ($requests, $viewer, $request) {
    return $requests->findForActor($viewer, $request['id']);
});
T::throws('a read-only admin cannot approve', AuthorizationException::class, function () use ($requests, $viewer, $request) {
    $requests->approve($viewer, $request['id']);
});
T::throws('a customer cannot override a status', AuthorizationException::class, function () use ($requests, $ada, $request) {
    $requests->overrideStatus($ada, $request['id'], RequestStatus::COMPLETED, 'because I said so');
});

/* -------------------------------------------------------------- listing */

section('Listing and scoping');

$adaList = $requests->listForActor($ada, []);
T::ok('ada sees her own requests only', count($adaList) >= 2);
$clientIds = array_unique(array_map(function ($r) {
    return (int) $r['client_id'];
}, $adaList));
T::is('no other client leaks into the list', [1], array_values($clientIds));

$search = $requests->listForActor($ada, ['search' => 'idempotent']);
T::is('search filters', 1, count($search));
$byStatus = $requests->listForActor($ada, ['status' => RequestStatus::UNDER_REVIEW]);
T::ok('status filter works', count($byStatus) >= 1);
$injection = $requests->listForActor($ada, ['search' => "%' OR 1=1 --", 'order' => 'id; DROP TABLE x']);
T::is('sql injection in filters finds nothing', 0, count($injection));

/* --------------------------------------------------------- customer edits */

section('Customer edits');

$updated = T::nothrow('budget can be raised while negotiable', function () use ($requests, $ada, $request) {
    return $requests->updateByCustomer($ada, $request['id'], [
        'budget' => '6500.00', 'message' => 'Raising our budget.', 'anonymous' => '1',
    ]);
});
T::is('the new budget is stored', 650000, (int) $updated['budget_minor']);
T::is('the change is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'request.updated',
]));
T::throws('another customer cannot edit it', NotFoundException::class, function () use ($requests, $bob, $request) {
    $requests->updateByCustomer($bob, $request['id'], ['budget' => '1.00']);
});

/* ------------------------------------------------------------- assignment */

section('Broker assignment');

$brokerRow = Harness::broker('Grace Hopper', 'broker', 11);
$brokerTwo = Harness::broker('Alan Turing', 'broker', 12);
$grace = Harness::brokerActor($brokerRow);
$alan = Harness::brokerActor($brokerTwo);
$assignments = new AssignmentService();

T::throws('a broker cannot assign work to themselves', AuthorizationException::class, function () use ($assignments, $grace, $request, $brokerRow) {
    $assignments->assign($grace, $request['id'], $brokerRow['id']);
});

$assigned = T::nothrow('an admin can assign a broker', function () use ($assignments, $admin, $request, $brokerRow) {
    return $assignments->assign($admin, $request['id'], $brokerRow['id'], ['note' => 'Best fit for .com']);
});
T::is('the request records the broker', (int) $brokerRow['id'], (int) $assigned['assigned_broker_id']);
T::is('and advances to broker assigned', RequestStatus::BROKER_ASSIGNED, $assigned['status']);
T::is('an assignment row exists', 1, Db::count('assignments', [
    'request_id' => (int) $request['id'], 'status' => 'active',
]));
T::ok('the customer was told', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'broker.assigned', 'audience' => 'customer',
]) > 0);
T::ok('the broker was told', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'broker.assigned', 'audience' => 'broker',
]) > 0);

$reassigned = T::nothrow('reassignment is possible', function () use ($assignments, $admin, $request, $brokerTwo) {
    return $assignments->assign($admin, $request['id'], $brokerTwo['id'], ['reason' => 'Grace is on leave']);
});
T::is('the new broker is recorded', (int) $brokerTwo['id'], (int) $reassigned['assigned_broker_id']);
T::is('the previous assignment is retained as history', 1, Db::count('assignments', [
    'request_id' => (int) $request['id'], 'status' => 'reassigned',
]));
T::is('exactly one assignment is active', 1, Db::count('assignments', [
    'request_id' => (int) $request['id'], 'status' => 'active',
]));
T::is('assignment history is complete', 2, count($assignments->history($request['id'])));

section('Broker visibility');

T::nothrow('the assigned broker can read the request', function () use ($requests, $alan, $request) {
    return $requests->findForActor($alan, $request['id']);
});
T::throws('an unassigned broker cannot', AuthorizationException::class, function () use ($requests, $grace, $request) {
    $requests->findForActor($grace, $request['id']);
});

$queueRequest = $requests->create(Harness::client(3, 'Queue Customer'), [
    'domain' => 'queue-me.com', 'budget' => '3000.00', 'currency' => 'USD',
]);
T::nothrow('any broker may see an unassigned request in the queue', function () use ($requests, $grace, $queueRequest) {
    return $requests->findForActor($grace, $queueRequest['id']);
});
$claimed = T::nothrow('a broker can claim from the queue', function () use ($assignments, $grace, $queueRequest) {
    return $assignments->claim($grace, $queueRequest['id']);
});
T::is('claiming assigns them', (int) $brokerRow['id'], (int) $claimed['assigned_broker_id']);
T::throws('a claimed request cannot be claimed again', ConflictException::class, function () use ($assignments, $alan, $queueRequest) {
    $assignments->claim($alan, $queueRequest['id']);
});

section('Broker capacity');

$svc = new BrokerDirectoryService();
$svc->update($admin, $brokerRow['id'], ['max_active_requests' => '1']);
$overflow = $requests->create(Harness::client(4, 'Overflow Customer'), [
    'domain' => 'overflow.com', 'budget' => '3000.00', 'currency' => 'USD',
]);
T::throws('a broker at capacity cannot be assigned more', ConflictException::class, function () use ($assignments, $admin, $overflow, $brokerRow) {
    $assignments->assign($admin, $overflow['id'], $brokerRow['id']);
});
$svc->update($admin, $brokerRow['id'], ['max_active_requests' => '25']);

T::throws('a broker with live work cannot be deactivated', ConflictException::class, function () use ($svc, $admin, $brokerRow) {
    $svc->deactivate($admin, $brokerRow['id'], 'leaving');
});

/* ----------------------------------------------------- approve and cancel */

section('Admin decisions');

$held = $requests->create(Harness::client(5, 'Held Customer'), [
    'domain' => 'needs-approval.com', 'budget' => '3000.00', 'currency' => 'USD',
]);
Db::update('requests', ['manual_review' => 1], ['id' => (int) $held['id']]);
$approved = T::nothrow('an admin can release a held request', function () use ($requests, $admin, $held) {
    return $requests->approve($admin, $held['id'], 'Checked the account manually.');
});
T::is('manual review is cleared', 0, (int) $approved['manual_review']);
T::is('the release is audited', 1, Db::count('activity', [
    'request_id' => (int) $held['id'], 'action' => 'risk.manual_review.released',
]));

$rejected = T::nothrow('an admin can reject a request', function () use ($requests, $admin, $held) {
    return $requests->reject($admin, $held['id'], 'Domain is on a sanctions list.');
});
T::is('rejection is terminal', RequestStatus::REJECTED, $rejected['status']);
T::ok('rejection closes the request', !empty($rejected['closed_at']));
T::throws('a rejected request cannot be edited', \DomainBroker\Core\DomainBrokerException::class, function () use ($requests, $admin, $held) {
    $requests->cancelByAdmin($admin, $held['id'], 'too late');
});

section('Customer cancellation');

$cancellable = $requests->create(Harness::client(6, 'Cancel Customer'), [
    'domain' => 'changed-my-mind.com', 'budget' => '3000.00', 'currency' => 'USD',
]);
$customerSix = Actor::customer(6, 'Cancel Customer');
$cancelled = T::nothrow('a customer can cancel while negotiable', function () use ($requests, $customerSix, $cancellable) {
    return $requests->cancelByCustomer($customerSix, $cancellable['id'], 'No longer needed.');
});
T::is('the request is cancelled', RequestStatus::CANCELLED, $cancelled['status']);
T::throws('cancelling twice fails', \DomainBroker\Core\DomainBrokerException::class, function () use ($requests, $customerSix, $cancellable) {
    $requests->cancelByCustomer($customerSix, $cancellable['id'], 'again');
});

/* ------------------------------------------------------------ transitions */

section('Server-side status enforcement');

$t = $requests->findRow($request['id']);
T::throws('an illegal jump is refused', InvalidTransitionException::class, function () use ($requests, $admin, $t) {
    $requests->transition($admin, $t, RequestStatus::COMPLETED);
});
T::throws('an unknown status is refused', InvalidTransitionException::class, function () use ($requests, $admin, $t) {
    $requests->transition($admin, $t, 'totally_made_up');
});
T::throws('an override still needs a legal target', InvalidTransitionException::class, function () use ($requests, $admin, $request) {
    $requests->overrideStatus($admin, $request['id'], RequestStatus::COMPLETED, 'force it');
});
T::throws('an override always needs a reason', ValidationException::class, function () use ($requests, $admin, $request) {
    $requests->overrideStatus($admin, $request['id'], RequestStatus::FAILED, '');
});

/* ----------------------------------------------------------------- expiry */

section('Expiry sweep');

$stale = $requests->create(Harness::client(8, 'Stale Customer'), [
    'domain' => 'forgotten.com', 'budget' => '3000.00', 'currency' => 'USD',
]);
Db::update('requests', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $stale['id']]);
$expired = $requests->expireStale();
T::ok('the sweep expired it', $expired >= 1);
T::is('and the status reflects that', RequestStatus::EXPIRED, $requests->findRow($stale['id'])['status']);
T::ok('the customer was notified of expiry', Db::count('notifications', [
    'request_id' => (int) $stale['id'], 'event' => 'request.expired',
]) > 0);

/* --------------------------------------------------------------- summary */

section('Customer summary');

$summary = $requests->customerSummary($ada);
T::ok('summary counts the active requests', $summary['active'] >= 1);
T::ok('summary counts everything', $summary['total'] >= 2);

Harness::shutdown();
exit(T::summary());
