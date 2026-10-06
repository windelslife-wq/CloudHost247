<?php
/**
 * Domain Broker — invoicing, escrow custody, refunds and failure paths.
 *
 * @package DomainBroker
 */

require_once __DIR__ . '/bootstrap.php';

use DomainBroker\Core\Actor;
use DomainBroker\Core\AuthorizationException;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\Settings;
use DomainBroker\Core\ValidationException;
use DomainBroker\Escrow\EscrowManager;
use DomainBroker\Escrow\EscrowResult;
use DomainBroker\Escrow\InternalEscrowProvider;
use DomainBroker\Escrow\ManualEscrowProvider;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\RequestService;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;

$gateway = Harness::boot();
Harness::relaxRateLimits();

$requests = new RequestService();
$payments = new PaymentService();

$fixture = Harness::acceptedRequest('invoice-me.com', 1);
$request = $fixture['request'];
$customer = $fixture['customer'];
$admin = $fixture['admin'];
$broker = $fixture['broker'];
$finance = Harness::admin(5, 'admin_finance', 'Finance Officer');
$viewer = Harness::admin(6, 'admin_viewer', 'Support Agent');

/* -------------------------------------------------------------- invoice */

section('Invoice generation');

T::is('the request is at offer accepted', RequestStatus::OFFER_ACCEPTED, $request['status']);

$payment = T::nothrow('the customer can raise the invoice', function () use ($payments, $customer, $request) {
    return $payments->generateInvoice($customer, $request['id']);
});
T::ok('a payment reference was allocated', strpos($payment['reference'], 'PY-') === 0);
T::is('the payment is pending', PaymentStatus::PENDING, $payment['status']);
T::is('the acquisition amount is correct', 900000, (int) $payment['acquisition_minor']);
T::is('the fee is correct', 90000, (int) $payment['fee_minor']);
T::is('the total is correct', 990000, (int) $payment['total_minor']);
T::ok('a WHMCS invoice was created', (int) $payment['whmcs_invoice_id'] > 0);
T::is('exactly one createInvoice call', 1, $gateway->callCount('createInvoice'));

$invoiceCall = $gateway->callsTo('createInvoice')[0]['args'];
T::is('billed to the right client', 1, (int) $invoiceCall['clientid']);
T::is('two line items: acquisition and fee', 2, count($invoiceCall['items']));
T::contains('the acquisition line names the domain', 'invoice-me.com', $invoiceCall['items'][0]['description']);
T::is('the acquisition line is the agreed price', '9000.00', $invoiceCall['items'][0]['amount']);
T::is('the fee line is the commission', '900.00', $invoiceCall['items'][1]['amount']);

$afterInvoice = $requests->findRow($request['id']);
T::is('the request is awaiting payment', RequestStatus::PAYMENT_PENDING, $afterInvoice['status']);
T::is('the payment status is reflected', PaymentStatus::PENDING, $afterInvoice['payment_status']);
T::is('the invoice is linked', (int) $payment['whmcs_invoice_id'], (int) $afterInvoice['whmcs_invoice_id']);
T::ok('the customer was asked to pay', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'payment.required',
]) > 0);

$again = $payments->generateInvoice($customer, $request['id']);
T::is('asking twice reuses the same payment', (int) $payment['id'], (int) $again['id']);
T::is('and does not raise a second invoice', 1, $gateway->callCount('createInvoice'));

/* ---------------------------------------------------- client-side trust */

section('Client-side status claims are ignored');

T::throws('the module will not mark funds secured on request', InvalidTransitionException::class, function () use ($payments, $admin, $payment) {
    $payments->secureFunds($admin, $payment['id']);
});
T::is('the payment is still pending', PaymentStatus::PENDING, $payments->find($payment['id'])['status']);

$stillPending = $payments->syncWithBilling($admin, $payment['id']);
T::is('syncing an unpaid invoice changes nothing', PaymentStatus::PENDING, $stillPending['status']);

/* -------------------------------------------------------- real payment */

section('Payment received and secured');

$gateway->payInvoice((int) $payment['whmcs_invoice_id'], 'stripe');
$settled = T::nothrow('syncing after payment settles it', function () use ($payments, $admin, $payment) {
    return $payments->syncWithBilling($admin, $payment['id']);
});
T::is('the internal escrow secures funds synchronously', PaymentStatus::FUNDS_SECURED, $settled['status']);
T::ok('the paid timestamp is set', !empty($settled['paid_at']));
T::ok('the secured timestamp is set', !empty($settled['secured_at']));
T::is('the gateway was recorded', 'stripe', $settled['gateway']);
T::ok('an escrow reference is stored encrypted', !empty($settled['escrow_reference_enc']));
T::ok('the stored reference is not plaintext', strpos((string) $settled['escrow_reference_enc'], 'INT-') === false);
T::ok('it decrypts for the module', strpos(
    (string) Crypto::tryDecrypt($settled['escrow_reference_enc'], 'escrow.reference'),
    'INT-'
) === 0);

$afterPaid = $requests->findRow($request['id']);
T::is('the request reached payment secured', RequestStatus::PAYMENT_SECURED, $afterPaid['status']);
T::is('but the transfer has not started', 'not_started', $afterPaid['transfer_status']);
T::isnt('paid is not completed', RequestStatus::COMPLETED, $afterPaid['status']);
T::ok('the customer was told funds are secured', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'payment.secured',
]) > 0);

T::is('the receipt is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'payment.received',
]));
T::is('the custody is audited', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'payment.secured',
]));

/* ---------------------------------------------------------- release gate */

section('Release requires a verified transfer');

T::throws('finance cannot release before transfer', InvalidTransitionException::class, function () use ($payments, $finance, $settled) {
    $payments->releaseFunds($finance, $settled['id']);
});
T::throws('a broker cannot release at all', AuthorizationException::class, function () use ($payments, $broker, $settled) {
    $payments->releaseFunds($broker, $settled['id']);
});
T::throws('a customer certainly cannot', AuthorizationException::class, function () use ($payments, $customer, $settled) {
    $payments->releaseFunds($customer, $settled['id']);
});
T::is('funds remain secured', PaymentStatus::FUNDS_SECURED, $payments->find($settled['id'])['status']);

/* ---------------------------------------------------------------- refunds */

section('Refunds');

T::throws('a support agent cannot refund', AuthorizationException::class, function () use ($payments, $viewer, $settled) {
    $payments->refund($viewer, $settled['id'], null, 'Customer asked nicely');
});
T::throws('a broker cannot refund', AuthorizationException::class, function () use ($payments, $broker, $settled) {
    $payments->refund($broker, $settled['id'], null, 'oops');
});
T::throws('a refund always needs a reason', ValidationException::class, function () use ($payments, $finance, $settled) {
    $payments->refund($finance, $settled['id'], 10000, '');
});
T::throws('over-refunding is refused', ValidationException::class, function () use ($payments, $finance, $settled) {
    $payments->refund($finance, $settled['id'], 99900000, 'Too much');
});

$partial = T::nothrow('finance issues a partial refund', function () use ($payments, $finance, $settled) {
    return $payments->refund($finance, $settled['id'], 90000, 'Goodwill credit of the commission.');
});
T::is('the payment is partially refunded', PaymentStatus::PARTIALLY_REFUNDED, $partial['status']);
T::is('the refunded total is tracked', 90000, (int) $partial['refunded_minor']);
T::is('WHMCS was asked to refund', 1, $gateway->callCount('refundInvoice'));
T::is('the refund amount sent to WHMCS is right', '900.00', $gateway->callsTo('refundInvoice')[0]['args']['amount']);
T::is('a refund ledger row was appended', 1, Db::count('payments', [
    'request_id' => (int) $request['id'], 'type' => 'partial_refund',
]));
T::is('the original payment row is still there', 1, Db::count('payments', [
    'request_id' => (int) $request['id'], 'type' => 'acquisition',
]));
T::is('the refund is audited with a reason', 1, Db::count('activity', [
    'request_id' => (int) $request['id'], 'action' => 'payment.refunded',
]));
T::ok('the customer was told', Db::count('notifications', [
    'request_id' => (int) $request['id'], 'event' => 'payment.refunded',
]) > 0);

$refundIdempotencyKey = 'manual-refund-key-1';
$payments->refund($finance, $settled['id'], 10000, 'Second goodwill tranche.', ['idempotency_key' => $refundIdempotencyKey]);
$beforeReplay = Db::count('payments', ['request_id' => (int) $request['id']]);
$payments->refund($finance, $settled['id'], 10000, 'Second goodwill tranche.', ['idempotency_key' => $refundIdempotencyKey]);
T::is('a replayed refund does not move money twice', $beforeReplay, Db::count('payments', ['request_id' => (int) $request['id']]));

$full = $payments->refund($finance, $settled['id'], null, 'Deal collapsed — returning the balance.');
T::is('the payment is fully refunded', PaymentStatus::REFUNDED, $full['status']);
T::is('everything has been returned', 990000, (int) $full['refunded_minor']);
T::is('the request is marked refunded', RequestStatus::REFUNDED, $requests->findRow($request['id'])['status']);
T::throws('there is nothing left to refund', InvalidTransitionException::class, function () use ($payments, $finance, $settled) {
    $payments->refund($finance, $settled['id'], 100, 'again');
});

/* ------------------------------------------------------------- failures */

section('Failure paths');

$gateway->failInvoiceCreation = true;
$failFixture = Harness::acceptedRequest('billing-down.com', 2, ['brokerRow' => $fixture['brokerRow']]);
T::throws('a billing outage surfaces as a payment error', \DomainBroker\Core\PaymentException::class, function () use ($payments, $failFixture) {
    $payments->generateInvoice($failFixture['customer'], $failFixture['request']['id']);
});
$failed = Db::first('payments', ['request_id' => (int) $failFixture['request']['id']]);
T::is('the attempt is recorded as failed', PaymentStatus::FAILED, $failed['status']);
T::is('the request did not advance', RequestStatus::OFFER_ACCEPTED, (new RequestService())->findRow($failFixture['request']['id'])['status']);
$gateway->failInvoiceCreation = false;

$retryFixture = Harness::acceptedRequest('card-declined.com', 3, ['brokerRow' => $fixture['brokerRow']]);
$retryPayment = $payments->generateInvoice($retryFixture['customer'], $retryFixture['request']['id']);
$declined = $payments->recordFailure($admin, $retryPayment['id'], 'card_declined');
T::is('the payment failed', PaymentStatus::FAILED, $declined['status']);
T::is('the failure counter moved', 1, (int) $declined['failed_attempts']);
T::ok('the customer was told', Db::count('notifications', [
    'request_id' => (int) $retryFixture['request']['id'], 'event' => 'payment.failed',
]) > 0);

$retried = $payments->retry($retryFixture['customer'], $retryPayment['id']);
T::is('the customer can try again', PaymentStatus::PENDING, $retried['status']);

$gateway->failRefunds = true;
$refundFixture = Harness::acceptedRequest('refund-fails.com', 4, ['brokerRow' => $fixture['brokerRow']]);
$rp = $payments->generateInvoice($refundFixture['customer'], $refundFixture['request']['id']);
$gateway->payInvoice((int) $rp['whmcs_invoice_id']);
$payments->syncWithBilling($admin, $rp['id']);
T::throws('a gateway refund failure is not silently swallowed', \DomainBroker\Core\PaymentException::class, function () use ($payments, $finance, $rp) {
    $payments->refund($finance, $rp['id'], 1000, 'Try to refund while the gateway is down.');
});
T::is('nothing was marked refunded', 0, (int) $payments->find($rp['id'])['refunded_minor']);
$gateway->failRefunds = false;

/* -------------------------------------------------------------- expiry */

section('Payment expiry');

$expiryFixture = Harness::acceptedRequest('too-slow.com', 5, ['brokerRow' => $fixture['brokerRow']]);
$ep = $payments->generateInvoice($expiryFixture['customer'], $expiryFixture['request']['id']);
Db::update('payments', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $ep['id']]);
$expired = $payments->expirePending();
T::ok('the sweep expired it', $expired >= 1);
T::is('the payment is expired', PaymentStatus::EXPIRED, $payments->find($ep['id'])['status']);
T::is('the unpaid invoice was cancelled in WHMCS', 1, $gateway->callCount('cancelInvoice'));
T::ok('the customer was told', Db::count('notifications', [
    'request_id' => (int) $expiryFixture['request']['id'], 'event' => 'payment.expired',
]) > 0);

$lateFixture = Harness::acceptedRequest('paid-just-in-time.com', 6, ['brokerRow' => $fixture['brokerRow']]);
$lp = $payments->generateInvoice($lateFixture['customer'], $lateFixture['request']['id']);
$gateway->payInvoice((int) $lp['whmcs_invoice_id']);
Db::update('payments', ['expires_at' => '2000-01-01 00:00:00'], ['id' => (int) $lp['id']]);
$payments->expirePending();
T::is('a paid-at-the-last-moment invoice is not expired', PaymentStatus::FUNDS_SECURED, $payments->find($lp['id'])['status']);

/* ----------------------------------------------------- escrow providers */

section('Escrow abstraction');

T::is('the internal provider is the default', 'internal', EscrowManager::provider()->name());
T::ok('providers are discoverable', count(EscrowManager::available()) >= 3);

EscrowManager::force(new ManualEscrowProvider());
$manualFixture = Harness::acceptedRequest('manual-escrow.com', 7, ['brokerRow' => $fixture['brokerRow']]);
$mp = $payments->generateInvoice($manualFixture['customer'], $manualFixture['request']['id']);
$gateway->payInvoice((int) $mp['whmcs_invoice_id']);
$mp = $payments->syncWithBilling($admin, $mp['id']);
T::is('a manual escrow waits at received', PaymentStatus::RECEIVED, $mp['status']);
T::isnt('the request did not reach payment secured', RequestStatus::PAYMENT_SECURED,
    $requests->findRow($manualFixture['request']['id'])['status']);
T::throws('confirming custody needs the release permission', AuthorizationException::class, function () use ($payments, $viewer, $mp) {
    $payments->confirmManualCustody($viewer, $mp['id'], 'ESCROW-REF-1');
});
T::throws('and a provider reference', ValidationException::class, function () use ($payments, $finance, $mp) {
    $payments->confirmManualCustody($finance, $mp['id'], '   ');
});
$confirmed = T::nothrow('finance confirms the escrow agent holds the funds', function () use ($payments, $finance, $mp) {
    return $payments->confirmManualCustody($finance, $mp['id'], 'ESCROW-REF-1', 'Agent confirmed by email.');
});
T::is('now the funds are secured', PaymentStatus::FUNDS_SECURED, $confirmed['status']);
T::is('and the request advanced', RequestStatus::PAYMENT_SECURED,
    $requests->findRow($manualFixture['request']['id'])['status']);
EscrowManager::force(null);

section('Escrow webhooks');

$signed = new class extends InternalEscrowProvider {
    public function verifyWebhookSignature($rawBody, array $headers)
    {
        return isset($headers['X-Test-Signature']) && $headers['X-Test-Signature'] === 'good';
    }

    public function parseWebhook(array $payload)
    {
        return [
            'event_id' => isset($payload['id']) ? (string) $payload['id'] : '',
            'type' => 'holding.released',
            'reference' => null,
            'state' => \DomainBroker\Escrow\EscrowResult::STATE_RELEASED,
            'amount_minor' => null,
            'external_id' => isset($payload['external_id']) ? (string) $payload['external_id'] : null,
        ];
    }
};
EscrowManager::force($signed);

$body = json_encode(['id' => 'evt_1', 'external_id' => $confirmed['reference']]);
$bad = $payments->handleEscrowWebhook($body, ['X-Test-Signature' => 'forged'], '203.0.113.50');
T::is('an unsigned webhook is rejected', false, $bad['accepted']);
T::is('and the reason is recorded', 'invalid_signature', $bad['reason']);
T::is('but the delivery is still logged for forensics', 1, Db::count('webhooks', ['signature_valid' => 0]));
T::is('without trusting the unverified event id', 0, Db::count('webhooks', ['event_id' => 'evt_1']));
T::is('nothing changed', PaymentStatus::FUNDS_SECURED, $payments->find($confirmed['id'])['status']);

$ok = $payments->handleEscrowWebhook(
    json_encode(['id' => 'evt_2', 'external_id' => $confirmed['reference']]),
    ['X-Test-Signature' => 'good'],
    '203.0.113.50'
);
T::is('a signed webhook is accepted', true, $ok['accepted']);
T::is('and it is applied', PaymentStatus::RELEASED, $payments->find($confirmed['id'])['status']);

$replay = $payments->handleEscrowWebhook(
    json_encode(['id' => 'evt_2', 'external_id' => $confirmed['reference']]),
    ['X-Test-Signature' => 'good'],
    '203.0.113.50'
);
T::is('a replayed delivery is ignored', 'duplicate', $replay['reason']);
T::is('and not stored twice', 1, Db::count('webhooks', ['event_id' => 'evt_2']));
EscrowManager::force(null);

/* ------------------------------------------------------------- history */

section('Transaction history');

$history = $payments->transactionHistory(1);
T::ok('the customer can see their payments', count($history) >= 2);
T::is('history is scoped to the client', [], array_values(array_filter($history, function ($row) {
    return (int) $row['request_id'] === 0;
})));

Harness::shutdown();
exit(T::summary());
