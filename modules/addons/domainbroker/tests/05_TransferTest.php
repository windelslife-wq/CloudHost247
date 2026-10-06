<?php
/**
 * Domain Broker — transfer management, auth-code custody and the
 * verification gate that stands in front of completion.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Audit;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\TransferService;
use DomainBroker\Services\VerificationService;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$requests = new RequestService();
$transfers = new TransferService();
$verification = new VerificationService();

$fixture = Harness::securedRequest('transfer-me.com', 1);
$request = $fixture['request'];
$customer = $fixture['customer'];
$broker = $fixture['broker'];
$admin = $fixture['admin'];
$viewer = Harness::admin(6, 'admin_viewer', 'Support Agent');
$finance = Harness::admin(5, 'admin_finance', 'Finance Officer');

$otherBrokerRow = Harness::broker('Ada Lovelace', 'broker', 12);
$otherBroker = Harness::brokerActor($otherBrokerRow);

/* ------------------------------------------------------- starting out */

section('Starting a transfer');

T::is('the fixture is at payment secured', RequestStatus::PAYMENT_SECURED, $request['status']);

$unpaid = Harness::acceptedRequest('not-paid-yet.com', 2, ['brokerRow' => $fixture['brokerRow']]);
T::throws('an unpaid acquisition cannot start transferring', InvalidTransitionException::class, function () use ($transfers, $unpaid) {
    $transfers->start($unpaid['broker'], $unpaid['request']['id'], []);
});

T::throws('a customer cannot start the transfer themselves', AuthorizationException::class, function () use ($transfers, $customer, $request) {
    $transfers->start($customer, $request['id'], []);
});
T::throws('another broker cannot touch this request', AuthorizationException::class, function () use ($transfers, $otherBroker, $request) {
    $transfers->start($otherBroker, $request['id'], []);
});

$transfer = T::nothrow('the assigned broker starts the transfer', function () use ($transfers, $broker, $request) {
    return $transfers->start($broker, $request['id'], [
        'losing_registrar' => 'Tucows',
        'gaining_registrar' => 'CloudHost247 Registrar',
        'destination_account' => 'client-1@example.test',
        'notes' => 'Registrant agreed to release the lock today.',
    ]);
});
T::ok('a transfer reference was allocated', strpos($transfer['reference'], 'TR-') === 0);
T::is('it waits on the auth code', TransferStatus::AUTH_PENDING, $transfer['status']);
T::is('the losing registrar is recorded', 'Tucows', $transfer['losing_registrar']);
T::ok('a transfer window was set', !empty($transfer['expires_at']));

$afterStart = $requests->findRow($request['id']);
T::is('the request is in domain transfer', RequestStatus::DOMAIN_TRANSFER, $afterStart['status']);
T::is('the transfer status is mirrored', TransferStatus::AUTH_PENDING, $afterStart['transfer_status']);
T::isnt('which is not completion', RequestStatus::COMPLETED, $afterStart['status']);

$second = $transfers->start($broker, $request['id'], []);
T::is('starting twice returns the same transfer', (int) $transfer['id'], (int) $second['id']);
T::is('and only one transfer row exists', 1, Db::count('transfers', ['request_id' => (int) $request['id']]));

T::ok('a verification checklist was created', count($verification->forRequest($request['id'])) >= 3);
$outstanding = $verification->outstandingRequirements($requests->findRow($request['id']));
T::is('three items are outstanding', 3, count($outstanding));

/* -------------------------------------------------------- auth codes */

section('Authorisation code custody');

$transfer = $transfers->requestAuthCode($broker, $transfer['id'], 'Asked the registrant to pull the EPP code.');
T::is('the code has been requested', 'requested', $transfer['auth_code_status']);

T::throws('a short code is rejected', ValidationException::class, function () use ($transfers, $broker, $transfer) {
    $transfers->recordAuthCode($broker, $transfer['id'], 'abc');
});

$secretCode = 'EPP-Str0ng!-Auth-7731';
$transfer = T::nothrow('the broker records the real code', function () use ($transfers, $broker, $transfer, $secretCode) {
    return $transfers->recordAuthCode($broker, $transfer['id'], $secretCode);
});
T::is('the code is marked received', 'received', $transfer['auth_code_status']);
T::is('the transfer advanced', TransferStatus::AUTH_RECEIVED, $transfer['status']);
T::ok('the code is stored encrypted', !empty($transfer['auth_code_enc']));
T::ok('the ciphertext does not contain the code', strpos((string) $transfer['auth_code_enc'], $secretCode) === false);
T::ok('only a masked hint is kept in the clear', substr($transfer['auth_code_hint'], -4) === '7731');
T::ok('the hint is not the code', strpos((string) $transfer['auth_code_hint'], 'EPP-Str0ng') === false);
T::ok('a blind index allows comparison without decryption',
    $transfer['auth_code_fingerprint'] === Crypto::blindIndex($secretCode, 'transfer.auth_code'));

$row = Db::first('activity', ['request_id' => (int) $request['id'], 'action' => 'transfer.auth_code.recorded']);
T::ok('the audit entry exists', !empty($row));
T::ok('the audit log never contains the code', strpos(json_encode($row), 'Str0ng') === false);

T::throws('the broker cannot read the code back', AuthorizationException::class, function () use ($transfers, $broker, $transfer) {
    $transfers->revealAuthCode($broker, $transfer['id'], 'curiosity');
});
T::throws('nor can the customer', AuthorizationException::class, function () use ($transfers, $customer, $transfer) {
    $transfers->revealAuthCode($customer, $transfer['id'], 'curiosity');
});
T::throws('nor can a support agent', AuthorizationException::class, function () use ($transfers, $viewer, $transfer) {
    $transfers->revealAuthCode($viewer, $transfer['id'], 'curiosity');
});

$revealed = T::nothrow('a privileged admin can reveal it', function () use ($transfers, $admin, $transfer) {
    return $transfers->revealAuthCode($admin, $transfer['id'], 'Submitting the code at the gaining registrar.');
});
T::is('and gets the original value', $secretCode, $revealed);
T::is('every reveal is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'transfer.auth_code.revealed',
]));
$reveal = Db::first('activity', ['request_id' => (int) $request['id'], 'action' => 'transfer.auth_code.revealed']);
T::is('the reveal is internal-only', Audit::VIS_INTERNAL, $reveal['visibility']);
T::ok('and carries the stated reason', strpos((string) $reveal['reason'], 'gaining registrar') !== false);

$leadRow = Harness::broker('Senior Broker', 'broker_lead', 13);
$lead = Harness::brokerActor($leadRow);
T::throws('even a broker lead must own the request', AuthorizationException::class, function () use ($transfers, $lead, $transfer) {
    $transfers->revealAuthCode($lead, $transfer['id'], 'peek');
});

/* ------------------------------------------------------- state machine */

section('Transfer state machine');

T::throws('illegal jumps are refused', InvalidTransitionException::class, function () use ($transfers, $broker, $transfer) {
    $transfers->updateStatus($broker, $transfer['id'], TransferStatus::APPROVED);
});
T::throws('unknown statuses are refused', ValidationException::class, function () use ($transfers, $broker, $transfer) {
    $transfers->updateStatus($broker, $transfer['id'], 'totally_done');
});
T::throws('a customer cannot drive the transfer', AuthorizationException::class, function () use ($transfers, $customer, $transfer) {
    $transfers->updateStatus($customer, $transfer['id'], TransferStatus::INITIATED);
});

$transfer = $transfers->updateStatus($broker, $transfer['id'], TransferStatus::INITIATED, [
    'note' => 'Submitted at the gaining registrar.',
    'registrar_lock_released' => true,
    'whois_privacy_disabled' => true,
]);
T::is('the transfer is initiated', TransferStatus::INITIATED, $transfer['status']);
T::is('the attempt counter moved', 1, (int) $transfer['attempts']);
T::is('the lock release is recorded', 1, (int) $transfer['registrar_lock_released']);
T::ok('the initiation timestamp is set', !empty($transfer['initiated_at']));

$transfer = $transfers->updateStatus($broker, $transfer['id'], TransferStatus::PENDING, [
    'note' => 'Registry acknowledged; five day window running.',
]);
T::is('it is pending at the registry', TransferStatus::PENDING, $transfer['status']);
T::is('the request mirrors it', TransferStatus::PENDING, $requests->findRow($request['id'])['transfer_status']);
T::is('the customer timeline shows the progress', 1, count(array_filter(
    Audit::timeline($request['id'], Audit::VIS_CUSTOMER),
    function ($e) {
        return $e['action'] === 'transfer.status.changed'
            && strpos((string) $e['reason'], 'five day window') !== false;
    }
)));

$transfer = $transfers->updateStatus($broker, $transfer['id'], TransferStatus::APPROVED, [
    'note' => 'Registrant approved the transfer.',
]);
T::is('it is approved', TransferStatus::APPROVED, $transfer['status']);

/* ------------------------------------------------------ the hard gate */

section('Completion requires verification');

T::throws('completion needs evidence', ValidationException::class, function () use ($transfers, $broker, $transfer) {
    $transfers->markCompleted($broker, $transfer['id'], []);
});

$transfer = T::nothrow('the broker records the registry confirmation', function () use ($transfers, $broker, $transfer) {
    return $transfers->markCompleted($broker, $transfer['id'], [
        'evidence' => 'Registry confirmation 2026-10-06; domain now in our account.',
        'whmcs_domain_id' => 777,
        'registry_response' => '{"result":"ok"}',
    ]);
});
T::is('the registry operation is complete', TransferStatus::COMPLETED, $transfer['status']);
$afterTransfer = $requests->findRow($request['id']);
T::is('the request is in transfer verification', RequestStatus::TRANSFER_VERIFICATION, $afterTransfer['status']);
T::isnt('a completed transfer is still not a completed acquisition', RequestStatus::COMPLETED, $afterTransfer['status']);
T::is('the WHMCS domain is linked', 777, (int) $afterTransfer['whmcs_domain_id']);

T::throws('the broker cannot close the acquisition yet', InvalidTransitionException::class, function () use ($transfers, $broker, $request) {
    $transfers->completeAcquisition($broker, $request['id'], 'All done!');
});
T::throws('and cannot route around it via the status writer', InvalidTransitionException::class, function () use ($requests, $admin, $afterTransfer) {
    $requests->transition($admin, $afterTransfer, RequestStatus::COMPLETED, ['action' => 'request.completed']);
});

$items = [];
foreach ($verification->forRequest($request['id']) as $v) {
    $items[$v['type']] = $v;
}
T::is('registrar verification is required', 1, (int) $items[VerificationService::TYPE_REGISTRAR]['required']);
T::is('KYC is not required by default', 0, (int) $items[VerificationService::TYPE_KYC]['required']);

T::throws('a broker cannot approve their own verification', AuthorizationException::class, function () use ($verification, $broker, $items) {
    $verification->approve($broker, $items[VerificationService::TYPE_REGISTRAR]['id'], 'Trust me');
});
T::throws('an admin cannot approve an item with no evidence', \DomainBroker\Core\ConflictException::class, function () use ($verification, $admin, $items) {
    $verification->approve($admin, $items[VerificationService::TYPE_REGISTRAR]['id']);
});

$submitted = T::nothrow('the broker records evidence', function () use ($verification, $broker, $request) {
    return $verification->submitEvidence($broker, $request['id'], VerificationService::TYPE_REGISTRAR, [
        'method' => 'WHOIS record confirms the registrar of record changed.',
        'reference' => 'WHOIS-SNAP-99221',
        'provider' => 'rdap',
    ]);
});
T::is('evidence moves it to submitted, not approved', VerificationService::STATUS_SUBMITTED, $submitted['status']);
T::ok('the reference is encrypted at rest', !empty($submitted['reference_enc']));
T::ok('and is not plaintext', strpos((string) $submitted['reference_enc'], 'WHOIS-SNAP') === false);
T::is('a privileged admin can reveal it', 'WHOIS-SNAP-99221', $verification->revealReference($admin, $submitted['id']));
T::throws('a broker cannot', AuthorizationException::class, function () use ($verification, $broker, $submitted) {
    $verification->revealReference($broker, $submitted['id']);
});

$verification->approve($admin, $submitted['id'], 'RDAP snapshot checked.');
T::is('two requirements remain', 2, count($verification->outstandingRequirements($requests->findRow($request['id']))));
T::throws('still not completable', InvalidTransitionException::class, function () use ($transfers, $broker, $request) {
    $transfers->completeAcquisition($broker, $request['id'], 'Nearly there');
});

$verification->submitEvidence($broker, $request['id'], VerificationService::TYPE_OWNERSHIP, [
    'method' => 'Signed purchase agreement countersigned by the registrant.',
]);
$ownership = Db::first('verifications', ['request_id' => (int) $request['id'], 'type' => VerificationService::TYPE_OWNERSHIP]);
$rejected = $verification->reject($admin, $ownership['id'], 'The signature block is missing a date.');
T::is('an admin can reject evidence', VerificationService::STATUS_REJECTED, $rejected['status']);
T::ok('with the reason recorded', strpos((string) $rejected['rejection_reason'], 'signature block') !== false);
T::is('rejected items still count as outstanding', 2,
    count($verification->outstandingRequirements($requests->findRow($request['id']))));

$verification->submitEvidence($broker, $request['id'], VerificationService::TYPE_OWNERSHIP, [
    'method' => 'Re-executed purchase agreement, fully dated and countersigned.',
]);
$verification->approve($admin, $ownership['id'], 'Agreement is in order.');

$authItem = Db::first('verifications', ['request_id' => (int) $request['id'], 'type' => VerificationService::TYPE_TRANSFER_AUTH]);
T::throws('waiving needs a reason', ValidationException::class, function () use ($verification, $admin, $authItem) {
    $verification->waive($admin, $authItem['id'], '');
});
T::throws('a finance admin cannot waive verification', AuthorizationException::class, function () use ($verification, $finance, $authItem) {
    $verification->waive($finance, $authItem['id'], 'Shortcut');
});
$waived = $verification->waive($admin, $authItem['id'], 'Registry confirmation supersedes the auth record.');
T::is('a documented waiver is allowed', VerificationService::STATUS_WAIVED, $waived['status']);

T::is('nothing is outstanding now', [], $verification->outstandingRequirements($requests->findRow($request['id'])));
T::ok('the request is fully verified', $verification->isFullyVerified($requests->findRow($request['id'])));

$completed = T::nothrow('now the acquisition can be closed', function () use ($transfers, $broker, $request) {
    return $transfers->completeAcquisition($broker, $request['id'], 'Domain delivered to the client account.');
});
T::is('the acquisition is complete', RequestStatus::COMPLETED, $completed['status']);
T::ok('WHMCS activity was logged', count(array_filter($gateway->callsTo('logActivity'), function ($c) {
    return strpos($c['args']['description'], 'completed') !== false;
})) >= 1);
T::ok('the customer was notified of the transfer', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'transfer.completed',
]) > 0);

/* ---------------------------------------------------------- failures */

section('Failed and retried transfers');

$failFixture = Harness::securedRequest('transfer-fails.com', 3, ['brokerRow' => $fixture['brokerRow']]);
$ft = $transfers->start($failFixture['broker'], $failFixture['request']['id'], ['losing_registrar' => 'GoDaddy']);
$ft = $transfers->recordAuthCode($failFixture['broker'], $ft['id'], 'EPP-SECOND-CODE-1');
$ft = $transfers->updateStatus($failFixture['broker'], $ft['id'], TransferStatus::INITIATED);

T::throws('failing needs a reason', ValidationException::class, function () use ($transfers, $failFixture, $ft) {
    $transfers->markFailed($failFixture['broker'], $ft['id'], TransferStatus::FAILED, '  ');
});
$ft = $transfers->markFailed($failFixture['broker'], $ft['id'], TransferStatus::REJECTED,
    'The losing registrar rejected the request: the domain is inside the 60 day lock.');
T::is('the transfer is rejected', TransferStatus::REJECTED, $ft['status']);
T::ok('the reason is kept', strpos((string) $ft['failure_reason'], '60 day lock') !== false);
T::ok('the customer and admins were notified', Db::count('notifications', [
    'request_id' => (int) $failFixture['request']['id'], 'event' => 'transfer.failed',
]) >= 2);
T::throws('a failed transfer cannot be completed', InvalidTransitionException::class, function () use ($transfers, $failFixture, $ft) {
    $transfers->markCompleted($failFixture['broker'], $ft['id'], ['evidence' => 'pretend it worked']);
});

$ft = $transfers->updateStatus($failFixture['broker'], $ft['id'], TransferStatus::AUTH_PENDING, [
    'note' => 'Re-attempting after the lock expires.',
]);
T::is('a rejected transfer can be retried', TransferStatus::AUTH_PENDING, $ft['status']);
T::is('the history is preserved, not overwritten', 1, Db::count('transfers', [
    'request_id' => (int) $failFixture['request']['id'],
]));
T::ok('the failure is still in the audit trail', Db::count('activity', [
    'request_id' => (int) $failFixture['request']['id'], 'action' => 'transfer.failed',
]) >= 1);

/* ------------------------------------------------------------ expiry */

section('Transfer window expiry');

Db::update('transfers', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $ft['id']]);
$expired = $transfers->expireStale();
T::ok('the sweep expired the stale transfer', $expired >= 1);
T::is('the transfer is expired', TransferStatus::EXPIRED, $transfers->find($ft['id'])['status']);
T::is('the request reflects it', TransferStatus::EXPIRED,
    $requests->findRow($failFixture['request']['id'])['transfer_status']);
T::is('a completed transfer is never swept', TransferStatus::COMPLETED, $transfers->find($transfer['id'])['status']);

/* ----------------------------------------------------- customer view */

section('What the customer may see');

$view = $transfers->publicView($transfers->find($transfer['id']));
T::ok('the customer view has a status', !empty($view['status']));
T::is('and never the raw registry response', false, array_key_exists('registry_response', $view));
T::ok('it has a human label', !empty($view['status_label']));
T::ok('it has a tracker position', isset($view['tracker_index']));
T::is('it never carries the encrypted code', false, array_key_exists('auth_code_enc', $view));
T::is('nor the fingerprint', false, array_key_exists('auth_code_fingerprint', $view));
T::ok('the serialised view leaks nothing', strpos(json_encode($view), 'Str0ng') === false);

$stats = $transfers->statistics();
T::ok('statistics are available for dashboards', is_array($stats));
T::ok('and count the completed transfer', array_sum(array_map('intval', array_values($stats))) >= 1);

Harness::shutdown();
exit(T::summary());
