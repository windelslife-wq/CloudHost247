<?php
/**
 * Domain Broker — payments, escrow custody and refunds.
 *
 * Money is never invented here. The module raises a real WHMCS invoice and
 * then *follows* what WHMCS reports: a payment is only "received" when the
 * billing system says the invoice is paid, and funds are only "secured" when
 * the configured escrow provider confirms custody. Nothing in this class can
 * mark an acquisition complete — that requires a verified transfer, which is
 * TransferService's and VerificationService's job.
 *
 * Every state-changing financial operation is idempotent and audited.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Services;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Audit;
use DomainBroker\Core\Clock;
use DomainBroker\Core\ConflictException;
use DomainBroker\Core\Crypto;
use DomainBroker\Core\Db;
use DomainBroker\Core\Idempotency;
use DomainBroker\Core\InvalidTransitionException;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Money;
use DomainBroker\Core\NotFoundException;
use DomainBroker\Core\PaymentException;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Escrow\EscrowManager;
use DomainBroker\Escrow\EscrowResult;
use DomainBroker\Integration\Gateway;
use DomainBroker\Workflow\PaymentStatus;
use DomainBroker\Workflow\RequestStatus;
use DomainBroker\Workflow\TransferStatus;

class PaymentService
{
    /** @var RequestService */
    protected $requests;

    /** @var NotificationService */
    protected $notifications;

    /** @var RiskService */
    protected $risk;

    public function __construct(
        RequestService $requests = null,
        NotificationService $notifications = null,
        RiskService $risk = null
    ) {
        $this->requests = $requests ?: new RequestService();
        $this->notifications = $notifications ?: new NotificationService();
        $this->risk = $risk ?: new RiskService();
    }

    /* ----------------------------------------------------------- lookup */

    public function find($paymentId)
    {
        return Db::first('payments', ['id' => (int) $paymentId]);
    }

    public function findOrFail($paymentId)
    {
        $row = $this->find($paymentId);
        if (!$row) {
            throw new NotFoundException('Payment not found.');
        }
        return $row;
    }

    /** The live acquisition payment for a request, if any. */
    public function activePayment($requestId)
    {
        return Db::first('payments', [
            'request_id' => (int) $requestId,
            'type' => 'acquisition',
            'status' => ['notin', [PaymentStatus::CANCELLED, PaymentStatus::EXPIRED]],
        ], ['order' => 'id', 'dir' => 'desc']);
    }

    /** Every payment row for a request, oldest first — the financial history. */
    public function historyFor($requestId)
    {
        return Db::fetch('payments', ['request_id' => (int) $requestId], ['order' => 'id']);
    }

    /* -------------------------------------------------------- invoicing */

    /**
     * Raise the acquisition invoice for an accepted offer.
     *
     * @param array $options idempotency_key, payment_method, due_in_days
     */
    public function generateInvoice(Actor $actor, $requestId, array $options = [])
    {
        $request = $this->requests->findForActor($actor, $requestId);

        if (!$actor->isCustomer()) {
            Rbac::assert($actor, Rbac::PAYMENT_VIEW);
        } else {
            Rbac::assert($actor, Rbac::PAYMENT_PAY);
        }

        if ($request['status'] !== RequestStatus::OFFER_ACCEPTED
            && $request['status'] !== RequestStatus::PAYMENT_PENDING) {
            throw new InvalidTransitionException(
                'An invoice can only be raised once an offer has been accepted.',
                ['status' => $request['status']]
            );
        }
        if (empty($request['agreed_offer_id']) || (int) $request['total_minor'] <= 0) {
            throw new ConflictException('No agreed amount is recorded for this acquisition.');
        }

        $existing = $this->activePayment($request['id']);
        if ($existing && in_array($existing['status'], [
            PaymentStatus::INVOICE_GENERATED, PaymentStatus::PENDING,
        ], true)) {
            return $existing;  // already invoiced; the customer just pays it
        }
        if ($existing && PaymentStatus::isSettled($existing['status'])) {
            throw new ConflictException('This acquisition has already been paid.');
        }

        $key = !empty($options['idempotency_key'])
            ? (string) $options['idempotency_key']
            : Idempotency::deriveKey('payment.invoice', [
                $request['id'], $request['agreed_offer_id'], $request['total_minor'], $request['currency'],
            ]);

        $self = $this;
        $outcome = Idempotency::run(
            'payment.invoice',
            $key,
            [
                'request' => (int) $request['id'],
                'offer' => (int) $request['agreed_offer_id'],
                'total' => (int) $request['total_minor'],
                'currency' => $request['currency'],
            ],
            function () use ($self, $actor, $request, $options) {
                return $self->performInvoiceGeneration($actor, $request, $options);
            }
        );

        $payment = $this->findOrFail($outcome['result']['payment_id']);
        if ($outcome['replayed']) {
            return $payment;
        }

        $fresh = $this->requests->findRow($request['id']);
        if ($fresh['status'] !== RequestStatus::PAYMENT_PENDING) {
            $this->requests->transition($actor, $fresh, RequestStatus::PAYMENT_PENDING, [
                'action' => 'request.payment.pending',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        return $this->findOrFail($payment['id']);
    }

    /** @internal invoked through Idempotency::run */
    public function performInvoiceGeneration(Actor $actor, array $request, array $options)
    {
        $now = Clock::now();
        $dueDays = isset($options['due_in_days'])
            ? max(1, (int) $options['due_in_days'])
            : Settings::int('payment_due_days', 3);
        $windowHours = Settings::int('payment_window_hours', 168);

        $currency = $request['currency'];
        $reference = $this->uniquePaymentReference();

        $paymentId = Db::insert('payments', [
            'reference' => $reference,
            'request_id' => (int) $request['id'],
            'offer_id' => (int) $request['agreed_offer_id'],
            'type' => 'acquisition',
            'status' => PaymentStatus::NONE,
            'currency' => $currency,
            'acquisition_minor' => (int) $request['agreed_amount_minor'],
            'fee_minor' => (int) $request['broker_fee_minor'],
            'tax_minor' => (int) $request['tax_minor'],
            'total_minor' => (int) $request['total_minor'],
            'paid_minor' => 0,
            'refunded_minor' => 0,
            'escrow_provider' => EscrowManager::provider()->name(),
            'escrow_status' => EscrowResult::STATE_PENDING,
            'idempotency_key' => Str::clip(isset($options['idempotency_key']) ? $options['idempotency_key'] : '', 190) ?: null,
            'attempts' => 0,
            'failed_attempts' => 0,
            'due_at' => Clock::inDays($dueDays),
            'expires_at' => Clock::inHours($windowHours),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Raise the real invoice through WHMCS so it appears in the client's
        // billing area, respects tax rules and can be paid by any gateway.
        $items = [[
            'description' => 'Domain acquisition — ' . $request['domain']
                . ' (request ' . $request['reference'] . ')',
            'amount' => Money::toDecimalString($request['agreed_amount_minor'], $currency),
            'taxed' => 0,
        ]];
        if ((int) $request['broker_fee_minor'] > 0) {
            $items[] = [
                'description' => 'Domain broker service fee',
                'amount' => Money::toDecimalString($request['broker_fee_minor'], $currency),
                'taxed' => Settings::bool('fee_uses_whmcs_tax', true) ? 1 : 0,
            ];
        }
        if ((int) $request['tax_minor'] > 0 && !Settings::bool('fee_uses_whmcs_tax', true)) {
            $items[] = [
                'description' => 'Tax',
                'amount' => Money::toDecimalString($request['tax_minor'], $currency),
                'taxed' => 0,
            ];
        }

        try {
            $invoice = Gateway::get()->createInvoice([
                'clientid' => (int) $request['client_id'],
                'duedate' => substr(Clock::inDays($dueDays), 0, 10),
                'sendinvoice' => Settings::bool('send_whmcs_invoice_email', true) ? 1 : 0,
                'notes' => 'Domain Broker acquisition ' . $request['reference'] . ' — payment reference ' . $reference,
                'items' => $items,
            ]);
        } catch (\Throwable $e) {
            Db::update('payments', [
                'status' => PaymentStatus::FAILED,
                'failure_reason' => Str::clip($e->getMessage(), 1000),
                'failed_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ], ['id' => $paymentId]);

            Audit::record($actor, 'payment.invoice.failed', [
                'request_id' => (int) $request['id'],
                'entity_type' => 'payment',
                'entity_id' => $paymentId,
                'new' => ['error' => Str::clip($e->getMessage(), 500)],
                'visibility' => Audit::VIS_INTERNAL,
            ]);

            throw new PaymentException('The invoice could not be raised in the billing system.', [
                'detail' => Str::clip($e->getMessage(), 300),
            ]);
        }

        $invoiceId = (int) $invoice['invoiceid'];

        Db::update('payments', [
            'status' => PaymentStatus::PENDING,
            'whmcs_invoice_id' => $invoiceId,
            'invoiced_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ], ['id' => $paymentId]);

        Db::update('requests', [
            'whmcs_invoice_id' => $invoiceId,
            'payment_status' => PaymentStatus::PENDING,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'payment.invoice.generated', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'payment',
            'entity_id' => $paymentId,
            'previous' => ['payment_status' => $request['payment_status']],
            'new' => [
                'payment_status' => PaymentStatus::PENDING,
                'invoice_id' => $invoiceId,
                'total_minor' => (int) $request['total_minor'],
                'currency' => $currency,
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        Gateway::get()->logActivity(
            'Domain Broker: invoice #' . $invoiceId . ' raised for acquisition ' . $request['reference'],
            (int) $request['client_id']
        );

        return ['payment_id' => $paymentId, 'invoice_id' => $invoiceId];
    }

    /* ---------------------------------------------------- settlement -- */

    /**
     * Reconcile a payment against WHMCS. This is the only path that can mark
     * money as received: the module trusts the billing system, never the
     * browser.
     *
     * @return array the (possibly updated) payment row
     */
    public function syncWithBilling(Actor $actor, $paymentId)
    {
        $payment = $this->findOrFail($paymentId);
        if (!$payment['whmcs_invoice_id']) {
            return $payment;
        }
        if (in_array($payment['status'], [
            PaymentStatus::FUNDS_SECURED, PaymentStatus::RELEASED,
            PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED,
        ], true)) {
            return $payment;
        }

        $invoice = Gateway::get()->getInvoice((int) $payment['whmcs_invoice_id']);
        if (!$invoice) {
            return $payment;
        }

        $status = isset($invoice['status']) ? (string) $invoice['status'] : '';
        if (strcasecmp($status, 'Paid') === 0) {
            return $this->markReceived($actor, $payment, [
                'gateway' => isset($invoice['paymentmethod']) ? $invoice['paymentmethod'] : null,
                'paid_minor' => isset($invoice['total'])
                    ? Money::toMinor($invoice['total'], $payment['currency'])
                    : (int) $payment['total_minor'],
            ]);
        }
        if (strcasecmp($status, 'Cancelled') === 0 && $payment['status'] === PaymentStatus::PENDING) {
            $this->setStatus($actor, $payment, PaymentStatus::CANCELLED, 'payment.cancelled', [
                'reason' => 'The billing system reports the invoice as cancelled.',
            ]);
            return $this->findOrFail($payment['id']);
        }

        return $payment;
    }

    /**
     * Record confirmed receipt of funds and immediately attempt to place them
     * into escrow custody.
     */
    public function markReceived(Actor $actor, $payment, array $info = [])
    {
        $payment = is_array($payment) ? $payment : $this->findOrFail($payment);

        if (PaymentStatus::isSettled($payment['status'])) {
            return $this->findOrFail($payment['id']);
        }
        if (!PaymentStatus::canTransition($payment['status'], PaymentStatus::RECEIVED)) {
            throw new InvalidTransitionException(
                'Payment cannot move from "' . PaymentStatus::label($payment['status']) . '" to received.'
            );
        }

        $transactions = $payment['whmcs_invoice_id']
            ? Gateway::get()->getInvoiceTransactions((int) $payment['whmcs_invoice_id'])
            : [];
        $last = $transactions ? end($transactions) : null;

        $now = Clock::now();
        Db::update('payments', [
            'status' => PaymentStatus::RECEIVED,
            'paid_minor' => isset($info['paid_minor']) ? (int) $info['paid_minor'] : (int) $payment['total_minor'],
            'paid_at' => $now,
            'gateway' => Str::clip(isset($info['gateway']) ? $info['gateway'] : ($last ? $last['gateway'] : ''), 60) ?: null,
            'payment_method_label' => Str::clip(isset($info['method_label']) ? $info['method_label'] : '', 120) ?: null,
            'whmcs_transaction_id' => $last && isset($last['id']) ? (int) $last['id'] : null,
            'attempts' => (int) $payment['attempts'] + 1,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => PaymentStatus::RECEIVED,
            'updated_at' => $now,
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, 'payment.received', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => [
                'payment_status' => PaymentStatus::RECEIVED,
                'amount_minor' => (int) $payment['total_minor'],
                'currency' => $payment['currency'],
            ],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $request = $this->requests->findRow($payment['request_id']);
        $this->notifications->notify(
            NotificationService::PAYMENT_RECEIVED,
            $request,
            NotificationService::AUDIENCE_CUSTOMER
        );
        if (!empty($request['assigned_broker_id'])) {
            $this->notifications->notify(
                NotificationService::PAYMENT_RECEIVED,
                $request,
                NotificationService::AUDIENCE_BROKER,
                ['broker_id' => (int) $request['assigned_broker_id']]
            );
        }

        // Receipt is not custody. Ask the escrow provider to take the funds.
        return $this->secureFunds($actor, (int) $payment['id']);
    }

    /**
     * Place received funds into escrow. Synchronous providers confirm here;
     * deferred providers leave the payment at "received" until a webhook or an
     * administrator confirms custody.
     */
    public function secureFunds(Actor $actor, $paymentId)
    {
        $payment = $this->findOrFail($paymentId);
        if ($payment['status'] === PaymentStatus::FUNDS_SECURED) {
            return $payment;
        }
        if ($payment['status'] !== PaymentStatus::RECEIVED) {
            throw new InvalidTransitionException('Only received funds can be placed into escrow.');
        }

        $request = $this->requests->findRow($payment['request_id']);
        $provider = EscrowManager::provider($payment['escrow_provider']);

        $result = $provider->hold([
            'payment' => $payment,
            'request' => $request,
            'paid_confirmed' => true,
        ]);

        if (!$result->success) {
            Db::update('payments', [
                'escrow_status' => EscrowResult::STATE_FAILED,
                'failure_reason' => Str::clip((string) $result->message, 1000),
                'updated_at' => Clock::now(),
            ], ['id' => (int) $payment['id']]);

            Audit::record($actor, 'payment.escrow.hold_failed', [
                'request_id' => (int) $payment['request_id'],
                'entity_type' => 'payment',
                'entity_id' => (int) $payment['id'],
                'new' => $result->toArray(),
                'visibility' => Audit::VIS_INTERNAL,
            ]);
            Logger::error('Escrow hold failed', ['payment' => $payment['reference']]);
            return $this->findOrFail($payment['id']);
        }

        $updates = [
            'escrow_status' => $result->state,
            'escrow_provider' => $provider->name(),
            'updated_at' => Clock::now(),
        ];
        if ($result->reference) {
            $updates['escrow_reference_enc'] = Crypto::encrypt($result->reference, 'escrow.reference');
        }
        Db::update('payments', $updates, ['id' => (int) $payment['id']]);

        Audit::record($actor, $result->deferred ? 'payment.escrow.hold_pending' : 'payment.escrow.held', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'new' => $result->toArray(),
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        if ($result->state !== EscrowResult::STATE_HELD) {
            // Custody will be confirmed later. The workflow waits.
            return $this->findOrFail($payment['id']);
        }

        return $this->confirmCustody($actor, (int) $payment['id']);
    }

    /**
     * Mark escrow custody confirmed and advance the request to
     * "payment secured". Used by synchronous providers, by the escrow webhook
     * and by an administrator confirming a manual escrow.
     */
    public function confirmCustody(Actor $actor, $paymentId, $note = '')
    {
        $payment = $this->findOrFail($paymentId);
        if ($payment['status'] === PaymentStatus::FUNDS_SECURED) {
            return $payment;
        }
        if (!PaymentStatus::canTransition($payment['status'], PaymentStatus::FUNDS_SECURED)) {
            throw new InvalidTransitionException('Funds cannot be secured from the current payment state.');
        }

        $now = Clock::now();
        Db::update('payments', [
            'status' => PaymentStatus::FUNDS_SECURED,
            'escrow_status' => EscrowResult::STATE_HELD,
            'escrow_secured_at' => $now,
            'secured_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => PaymentStatus::FUNDS_SECURED,
            'updated_at' => $now,
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, 'payment.secured', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => ['payment_status' => PaymentStatus::FUNDS_SECURED],
            'reason' => $note,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $request = $this->requests->findRow($payment['request_id']);
        if (RequestStatus::canTransition($request['status'], RequestStatus::PAYMENT_SECURED)) {
            $this->requests->transition($actor, $request, RequestStatus::PAYMENT_SECURED, [
                'action' => 'request.payment.secured',
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        return $this->findOrFail($payment['id']);
    }

    /** An administrator confirms custody for a manual/off-platform escrow. */
    public function confirmManualCustody(Actor $actor, $paymentId, $providerReference, $note = '')
    {
        Rbac::assert($actor, Rbac::PAYMENT_RELEASE);
        $payment = $this->findOrFail($paymentId);
        if ($payment['status'] !== PaymentStatus::RECEIVED) {
            throw new InvalidTransitionException('Only received funds can be confirmed into escrow.');
        }
        $providerReference = trim((string) $providerReference);
        if ($providerReference === '') {
            throw new ValidationException('An escrow reference is required.', [
                'reference' => 'Required.',
            ]);
        }

        Db::update('payments', [
            'escrow_reference_enc' => Crypto::encrypt($providerReference, 'escrow.reference'),
            'updated_at' => Clock::now(),
        ], ['id' => (int) $payment['id']]);

        return $this->confirmCustody($actor, (int) $payment['id'], $note ?: 'Confirmed manually by an administrator.');
    }

    /**
     * Release escrowed funds to the seller. Only possible once the transfer is
     * complete and verification has passed — this is the counterpart to "paid
     * is not completed".
     */
    public function releaseFunds(Actor $actor, $paymentId, $note = '')
    {
        Rbac::assert($actor, Rbac::PAYMENT_RELEASE);
        $payment = $this->findOrFail($paymentId);

        if ($payment['status'] === PaymentStatus::RELEASED) {
            return $payment;
        }
        if ($payment['status'] !== PaymentStatus::FUNDS_SECURED) {
            throw new InvalidTransitionException('Only secured funds can be released.');
        }

        $request = $this->requests->findRow($payment['request_id']);
        $transfer = Db::first('transfers', ['request_id' => (int) $request['id']], ['order' => 'id', 'dir' => 'desc']);
        $verified = $transfer && $transfer['status'] === TransferStatus::COMPLETED;
        if (!$verified) {
            throw new InvalidTransitionException('Funds cannot be released before the domain transfer is confirmed complete.');
        }
        // Completion must carry its evidence basis (registry check or finance attestation).
        // Transfers completed before the basis was recorded are refused until re-confirmed.
        if (empty($transfer['completion_basis'])) {
            throw new InvalidTransitionException(
                'Funds cannot be released: this transfer has no recorded completion evidence. Confirm completion again.',
                ['error_code' => 'COMPLETION_BASIS_MISSING']
            );
        }
        $missing = (new VerificationService())->outstandingRequirements($request);
        if ($missing) {
            throw new InvalidTransitionException(
                'Outstanding verification prevents release: ' . implode(', ', $missing) . '.',
                ['missing' => $missing]
            );
        }

        $provider = EscrowManager::provider($payment['escrow_provider']);
        $result = $provider->release([
            'payment' => $payment,
            'request' => $request,
            'transfer' => $transfer,
            'transfer_verified' => true,
        ]);

        if (!$result->success) {
            Audit::record($actor, 'payment.escrow.release_failed', [
                'request_id' => (int) $request['id'],
                'entity_type' => 'payment',
                'entity_id' => (int) $payment['id'],
                'new' => $result->toArray(),
                'visibility' => Audit::VIS_INTERNAL,
            ]);
            throw new PaymentException('The escrow provider refused the release: ' . $result->message);
        }

        if ($result->deferred) {
            Db::update('payments', [
                'escrow_status' => EscrowResult::STATE_PENDING,
                'updated_at' => Clock::now(),
            ], ['id' => (int) $payment['id']]);

            Audit::record($actor, 'payment.escrow.release_requested', [
                'request_id' => (int) $request['id'],
                'entity_type' => 'payment',
                'entity_id' => (int) $payment['id'],
                'new' => $result->toArray(),
                'reason' => $note,
                'visibility' => Audit::VIS_INTERNAL,
            ]);
            return $this->findOrFail($payment['id']);
        }

        return $this->markReleased($actor, (int) $payment['id'], $note);
    }

    /** Record a confirmed release (synchronous provider or webhook). */
    public function markReleased(Actor $actor, $paymentId, $note = '')
    {
        $payment = $this->findOrFail($paymentId);
        if ($payment['status'] === PaymentStatus::RELEASED) {
            return $payment;
        }
        if (!PaymentStatus::canTransition($payment['status'], PaymentStatus::RELEASED)) {
            throw new InvalidTransitionException('Funds cannot be released from the current payment state.');
        }

        $now = Clock::now();
        Db::update('payments', [
            'status' => PaymentStatus::RELEASED,
            'escrow_status' => EscrowResult::STATE_RELEASED,
            'escrow_released_at' => $now,
            'released_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => PaymentStatus::RELEASED,
            'updated_at' => $now,
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, 'payment.released', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => ['payment_status' => PaymentStatus::RELEASED],
            'reason' => $note,
            'visibility' => Audit::VIS_INTERNAL,
        ]);

        return $this->findOrFail($payment['id']);
    }

    /* ----------------------------------------------------------- refunds */

    /**
     * Refund all or part of a payment through WHMCS.
     *
     * @param int|null $amountMinor null = the full remaining balance
     */
    public function refund(Actor $actor, $paymentId, $amountMinor, $reason, array $options = [])
    {
        Rbac::assert($actor, Rbac::PAYMENT_REFUND);
        $payment = $this->findOrFail($paymentId);

        $reason = Str::cleanText($reason, 1000);
        if ($reason === '') {
            throw new ValidationException('A refund reason is required.', ['reason' => 'Required.']);
        }
        if (!PaymentStatus::isRefundable($payment['status'])) {
            throw new InvalidTransitionException(
                'A payment in state "' . PaymentStatus::label($payment['status']) . '" cannot be refunded.'
            );
        }

        $remaining = (int) $payment['total_minor'] - (int) $payment['refunded_minor'];
        $amountMinor = ($amountMinor === null || $amountMinor === '') ? $remaining : (int) $amountMinor;
        if ($amountMinor <= 0) {
            throw new ValidationException('The refund amount must be positive.', ['amount' => 'Must be positive.']);
        }
        if ($amountMinor > $remaining) {
            throw new ValidationException(
                'The refund exceeds the remaining refundable balance of '
                . Money::toDecimalString($remaining, $payment['currency']) . ' ' . $payment['currency'] . '.',
                ['amount' => 'Exceeds refundable balance.']
            );
        }

        $key = !empty($options['idempotency_key'])
            ? (string) $options['idempotency_key']
            : Idempotency::deriveKey('payment.refund', [
                $payment['id'], $amountMinor, (int) $payment['refunded_minor'],
            ]);

        $self = $this;
        $outcome = Idempotency::run(
            'payment.refund',
            $key,
            // The fingerprint deliberately excludes mutable server state such as
            // the already-refunded total: a client retrying the *same* refund
            // after a timeout must replay, not collide. Distinct refunds are
            // separated by the key itself (see deriveKey above), not here.
            [
                'payment' => (int) $payment['id'],
                'amount' => $amountMinor,
            ],
            function () use ($self, $actor, $payment, $amountMinor, $reason) {
                return $self->performRefund($actor, $payment, $amountMinor, $reason);
            }
        );

        $refreshed = $this->findOrFail($payment['id']);
        if ($outcome['replayed']) {
            return $refreshed;
        }

        // A fully refunded acquisition is closed out on the request too.
        $request = $this->requests->findRow($payment['request_id']);
        if ($refreshed['status'] === PaymentStatus::REFUNDED
            && RequestStatus::canTransition($request['status'], RequestStatus::REFUNDED)) {
            $this->requests->transition($actor, $request, RequestStatus::REFUNDED, [
                'action' => 'request.refunded',
                'reason' => $reason,
                'visibility' => Audit::VIS_CUSTOMER,
            ]);
        }

        $this->notifications->notify(
            NotificationService::REFUND_ISSUED,
            $this->requests->findRow($payment['request_id']),
            NotificationService::AUDIENCE_CUSTOMER,
            ['payload' => [
                'amount' => Money::toDecimalString($amountMinor, $payment['currency']),
                'currency' => $payment['currency'],
                'reason' => $reason,
            ]]
        );

        return $refreshed;
    }

    /** @internal invoked through Idempotency::run */
    public function performRefund(Actor $actor, array $payment, $amountMinor, $reason)
    {
        $request = $this->requests->findRow($payment['request_id']);
        $provider = EscrowManager::provider($payment['escrow_provider']);

        $escrowResult = $provider->refund([
            'payment' => $payment,
            'request' => $request,
        ], $amountMinor);

        if (!$escrowResult->success) {
            throw new PaymentException('The escrow provider refused the refund: ' . $escrowResult->message);
        }

        // Move the money back through WHMCS so the client's billing records
        // and the operator's accounts stay correct.
        $transactionId = null;
        if ($payment['whmcs_invoice_id']) {
            try {
                $refund = Gateway::get()->refundInvoice(
                    (int) $payment['whmcs_invoice_id'],
                    Money::toDecimalString($amountMinor, $payment['currency']),
                    (string) $payment['gateway'],
                    'Domain Broker refund — ' . $request['reference'] . ': ' . Str::clip($reason, 200)
                );
                $transactionId = isset($refund['transid']) ? (string) $refund['transid'] : null;
            } catch (\Throwable $e) {
                Audit::record($actor, 'payment.refund.failed', [
                    'request_id' => (int) $request['id'],
                    'entity_type' => 'payment',
                    'entity_id' => (int) $payment['id'],
                    'new' => ['error' => Str::clip($e->getMessage(), 500), 'amount_minor' => (int) $amountMinor],
                    'reason' => $reason,
                    'visibility' => Audit::VIS_INTERNAL,
                ]);
                throw new PaymentException('The billing system rejected the refund.', [
                    'detail' => Str::clip($e->getMessage(), 300),
                ]);
            }
        }

        $now = Clock::now();
        $refunded = (int) $payment['refunded_minor'] + (int) $amountMinor;
        $isFull = $refunded >= (int) $payment['total_minor'];
        $newStatus = $isFull ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

        Db::update('payments', [
            'status' => $newStatus,
            'refunded_minor' => $refunded,
            'refunded_at' => $now,
            'escrow_status' => $escrowResult->state,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        // Append-only ledger: the refund itself is its own record.
        $refundRowId = Db::insert('payments', [
            'reference' => $this->uniquePaymentReference(),
            'request_id' => (int) $payment['request_id'],
            'offer_id' => $payment['offer_id'] ? (int) $payment['offer_id'] : null,
            'type' => $isFull ? 'refund' : 'partial_refund',
            'status' => $newStatus,
            'currency' => $payment['currency'],
            'acquisition_minor' => 0,
            'fee_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => (int) $amountMinor,
            'paid_minor' => 0,
            'refunded_minor' => (int) $amountMinor,
            'whmcs_invoice_id' => $payment['whmcs_invoice_id'] ? (int) $payment['whmcs_invoice_id'] : null,
            'gateway' => $payment['gateway'],
            'escrow_provider' => $payment['escrow_provider'],
            'escrow_status' => $escrowResult->state,
            'failure_reason' => null,
            'refunded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::update('requests', [
            'payment_status' => $newStatus,
            'updated_at' => $now,
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, 'payment.refunded', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => [
                'payment_status' => $payment['status'],
                'refunded_minor' => (int) $payment['refunded_minor'],
            ],
            'new' => [
                'payment_status' => $newStatus,
                'refunded_minor' => $refunded,
                'amount_minor' => (int) $amountMinor,
                'currency' => $payment['currency'],
                'transaction' => $transactionId,
            ],
            'reason' => $reason,
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        Gateway::get()->logActivity(
            'Domain Broker: refund of ' . Money::toDecimalString($amountMinor, $payment['currency']) . ' '
            . $payment['currency'] . ' issued for acquisition ' . $request['reference'],
            (int) $request['client_id']
        );

        return ['payment_id' => (int) $payment['id'], 'refund_row_id' => $refundRowId, 'status' => $newStatus];
    }

    /* ----------------------------------------------------- failure paths */

    /** Record a failed payment attempt and let the risk engine see it. */
    public function recordFailure(Actor $actor, $paymentId, $reason)
    {
        $payment = $this->findOrFail($paymentId);
        if (PaymentStatus::isSettled($payment['status'])) {
            throw new ConflictException('This payment has already settled.');
        }

        $now = Clock::now();
        Db::update('payments', [
            'status' => PaymentStatus::FAILED,
            'failed_attempts' => (int) $payment['failed_attempts'] + 1,
            'attempts' => (int) $payment['attempts'] + 1,
            'failure_reason' => Str::clip((string) $reason, 1000),
            'failed_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => PaymentStatus::FAILED,
            'updated_at' => $now,
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, 'payment.failed', [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => [
                'payment_status' => PaymentStatus::FAILED,
                'failed_attempts' => (int) $payment['failed_attempts'] + 1,
            ],
            'reason' => Str::clip((string) $reason, 500),
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        $request = $this->requests->findRow($payment['request_id']);
        $this->notifications->notify(
            NotificationService::PAYMENT_FAILED,
            $request,
            NotificationService::AUDIENCE_CUSTOMER
        );
        $this->risk->evaluate($request);

        return $this->findOrFail($payment['id']);
    }

    /** Re-open a failed payment so the customer can try again. */
    public function retry(Actor $actor, $paymentId)
    {
        $payment = $this->findOrFail($paymentId);
        if (!in_array($payment['status'], [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
            throw new InvalidTransitionException('Only a failed or expired payment can be retried.');
        }
        $request = $this->requests->findForActor($actor, $payment['request_id']);
        Rbac::assert($actor, $actor->isCustomer() ? Rbac::PAYMENT_PAY : Rbac::PAYMENT_VIEW);

        $now = Clock::now();
        Db::update('payments', [
            'status' => PaymentStatus::PENDING,
            'expires_at' => Clock::inHours(Settings::int('payment_window_hours', 168)),
            'due_at' => Clock::inDays(Settings::int('payment_due_days', 3)),
            'failure_reason' => null,
            'updated_at' => $now,
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => PaymentStatus::PENDING,
            'updated_at' => $now,
        ], ['id' => (int) $request['id']]);

        Audit::record($actor, 'payment.retry', [
            'request_id' => (int) $request['id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => ['payment_status' => PaymentStatus::PENDING],
            'visibility' => Audit::VIS_CUSTOMER,
        ]);

        return $this->findOrFail($payment['id']);
    }

    /** Expire payment windows that have elapsed (module cron). */
    public function expirePending($limit = 200)
    {
        $actor = Actor::system('Scheduled task');
        $rows = Db::fetch('payments', [
            'type' => 'acquisition',
            'status' => ['in', [PaymentStatus::PENDING, PaymentStatus::INVOICE_GENERATED, PaymentStatus::FAILED]],
            'expires_at' => ['<=', Clock::now()],
        ], ['order' => 'id', 'limit' => $limit]);

        $count = 0;
        foreach ($rows as $payment) {
            // Last chance: the invoice may have been paid since the last sync.
            $payment = $this->syncWithBilling($actor, (int) $payment['id']);
            if (PaymentStatus::isSettled($payment['status'])) {
                continue;
            }

            $now = Clock::now();
            Db::update('payments', [
                'status' => PaymentStatus::EXPIRED,
                'updated_at' => $now,
            ], ['id' => (int) $payment['id']]);

            if ($payment['whmcs_invoice_id'] && Settings::bool('cancel_expired_invoices', true)) {
                try {
                    Gateway::get()->cancelInvoice((int) $payment['whmcs_invoice_id']);
                } catch (\Throwable $e) {
                    Logger::warning('Could not cancel expired broker invoice', [
                        'invoice' => (int) $payment['whmcs_invoice_id'],
                    ]);
                }
            }

            Db::update('requests', [
                'payment_status' => PaymentStatus::EXPIRED,
                'updated_at' => $now,
            ], ['id' => (int) $payment['request_id']]);

            Audit::record($actor, 'payment.expired', [
                'request_id' => (int) $payment['request_id'],
                'entity_type' => 'payment',
                'entity_id' => (int) $payment['id'],
                'previous' => ['payment_status' => $payment['status']],
                'new' => ['payment_status' => PaymentStatus::EXPIRED],
                'visibility' => Audit::VIS_CUSTOMER,
            ]);

            $request = $this->requests->findRow($payment['request_id']);
            $this->notifications->notify(
                NotificationService::PAYMENT_EXPIRED,
                $request,
                NotificationService::AUDIENCE_CUSTOMER
            );
            $count++;
        }
        return $count;
    }

    /* ---------------------------------------------------------- webhooks */

    /**
     * Handle an inbound escrow webhook. The signature is verified before the
     * payload is trusted, every delivery is persisted, and replays are ignored
     * by (provider, event_id).
     *
     * @return array{accepted:bool, reason:string}
     */
    public function handleEscrowWebhook($rawBody, array $headers, $sourceIp = null)
    {
        $provider = EscrowManager::provider();
        $valid = $provider->verifyWebhookSignature($rawBody, $headers);
        $payload = json_decode((string) $rawBody, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $event = $valid ? $provider->parseWebhook($payload) : ['event_id' => '', 'type' => '', 'reference' => null, 'state' => null, 'amount_minor' => null];
        $eventId = $event['event_id'] !== '' ? $event['event_id'] : 'anon-' . substr(hash('sha256', (string) $rawBody), 0, 32);

        $existing = Db::first('webhooks', ['provider' => $provider->name(), 'event_id' => $eventId]);
        if ($existing) {
            return ['accepted' => true, 'reason' => 'duplicate'];
        }

        $webhookId = Db::insert('webhooks', [
            'provider' => $provider->name(),
            'event_id' => $eventId,
            'event_type' => isset($event['type']) ? Str::clip($event['type'], 80) : null,
            'signature_valid' => $valid ? 1 : 0,
            'payload' => Str::clip((string) $rawBody, 60000),
            'headers' => Str::jsonEncode($this->redactHeaders($headers)),
            'source_ip' => Str::clip((string) $sourceIp, 45) ?: null,
            'processed' => 0,
            'received_at' => Clock::now(),
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);

        if (!$valid) {
            Db::update('webhooks', [
                'error' => 'Signature verification failed.',
                'updated_at' => Clock::now(),
            ], ['id' => $webhookId]);
            Logger::warning('Rejected escrow webhook with an invalid signature.', ['ip' => (string) $sourceIp]);
            return ['accepted' => false, 'reason' => 'invalid_signature'];
        }

        try {
            $this->applyEscrowEvent($event);
            Db::update('webhooks', [
                'processed' => 1,
                'processed_at' => Clock::now(),
                'updated_at' => Clock::now(),
            ], ['id' => $webhookId]);
            return ['accepted' => true, 'reason' => 'processed'];
        } catch (\Throwable $e) {
            Db::update('webhooks', [
                'error' => Str::clip($e->getMessage(), 1000),
                'updated_at' => Clock::now(),
            ], ['id' => $webhookId]);
            Logger::exception($e, ['webhook' => $webhookId]);
            return ['accepted' => false, 'reason' => 'processing_error'];
        }
    }

    protected function applyEscrowEvent(array $event)
    {
        $payment = null;
        if (!empty($event['external_id'])) {
            $payment = Db::first('payments', ['reference' => (string) $event['external_id']]);
        }
        if (!$payment && !empty($event['reference'])) {
            // Match on the blind index of the encrypted provider reference.
            foreach (Db::fetch('payments', ['escrow_status' => ['notnull']], ['order' => 'id', 'dir' => 'desc', 'limit' => 500]) as $row) {
                if (!$row['escrow_reference_enc']) {
                    continue;
                }
                if (Crypto::tryDecrypt($row['escrow_reference_enc'], 'escrow.reference') === $event['reference']) {
                    $payment = $row;
                    break;
                }
            }
        }
        if (!$payment) {
            throw new NotFoundException('No payment matches this escrow event.');
        }

        $actor = Actor::system('Escrow webhook');
        switch ($event['state']) {
            case EscrowResult::STATE_HELD:
                if ($payment['status'] === PaymentStatus::RECEIVED) {
                    $this->confirmCustody($actor, (int) $payment['id'], 'Confirmed by escrow provider webhook.');
                }
                break;
            case EscrowResult::STATE_RELEASED:
                if ($payment['status'] === PaymentStatus::FUNDS_SECURED) {
                    $this->markReleased($actor, (int) $payment['id'], 'Confirmed by escrow provider webhook.');
                }
                break;
            case EscrowResult::STATE_REFUNDED:
            case EscrowResult::STATE_PARTIALLY_REFUNDED:
                Db::update('payments', [
                    'escrow_status' => $event['state'],
                    'updated_at' => Clock::now(),
                ], ['id' => (int) $payment['id']]);
                Audit::record($actor, 'payment.escrow.refund_confirmed', [
                    'request_id' => (int) $payment['request_id'],
                    'entity_type' => 'payment',
                    'entity_id' => (int) $payment['id'],
                    'new' => ['escrow_status' => $event['state']],
                    'visibility' => Audit::VIS_INTERNAL,
                ]);
                break;
            case EscrowResult::STATE_FAILED:
                $this->recordFailure($actor, (int) $payment['id'], 'The escrow provider reported a failure.');
                break;
            default:
                Db::update('payments', [
                    'escrow_status' => (string) $event['state'],
                    'updated_at' => Clock::now(),
                ], ['id' => (int) $payment['id']]);
        }
    }

    protected function redactHeaders(array $headers)
    {
        $safe = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (strpos($lower, 'authorization') !== false || strpos($lower, 'cookie') !== false) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = Str::clip((string) $value, 300);
        }
        return $safe;
    }

    /* ---------------------------------------------------------- reporting */

    /** Customer-facing transaction history across all of their acquisitions. */
    public function transactionHistory($clientId, array $filters = [])
    {
        $requestTable = Db::table('requests');
        $paymentTable = Db::table('payments');

        $sql = 'SELECT p.*, r.reference AS request_reference, r.domain, r.id AS req_id'
            . ' FROM ' . Db::quoteIdentifier($paymentTable) . ' p'
            . ' INNER JOIN ' . Db::quoteIdentifier($requestTable) . ' r ON r.id = p.request_id'
            . ' WHERE r.client_id = ? AND r.deleted_at IS NULL';
        $bind = [(int) $clientId];

        if (!empty($filters['from'])) {
            $sql .= ' AND p.created_at >= ?';
            $bind[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND p.created_at <= ?';
            $bind[] = (string) $filters['to'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = ?';
            $bind[] = (string) $filters['status'];
        }

        $sql .= ' ORDER BY p.id DESC LIMIT ' . max(1, min(500, isset($filters['limit']) ? (int) $filters['limit'] : 100));

        return Db::select($sql, $bind);
    }

    /** Payments awaiting the customer, for the broker/admin dashboards. */
    public function awaitingPayment($limit = 100)
    {
        return Db::fetch('payments', [
            'type' => 'acquisition',
            'status' => ['in', [PaymentStatus::PENDING, PaymentStatus::INVOICE_GENERATED, PaymentStatus::FAILED]],
        ], ['order' => 'due_at', 'limit' => $limit]);
    }

    /* ------------------------------------------------------------ helpers */

    protected function setStatus(Actor $actor, array $payment, $status, $action, array $options = [])
    {
        if (!PaymentStatus::canTransition($payment['status'], $status)) {
            throw new InvalidTransitionException(
                'Payment cannot move from "' . PaymentStatus::label($payment['status'])
                . '" to "' . PaymentStatus::label($status) . '".'
            );
        }
        Db::update('payments', [
            'status' => $status,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $payment['id']]);

        Db::update('requests', [
            'payment_status' => $status,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $payment['request_id']]);

        Audit::record($actor, $action, [
            'request_id' => (int) $payment['request_id'],
            'entity_type' => 'payment',
            'entity_id' => (int) $payment['id'],
            'previous' => ['payment_status' => $payment['status']],
            'new' => ['payment_status' => $status],
            'reason' => isset($options['reason']) ? $options['reason'] : '',
            'visibility' => isset($options['visibility']) ? $options['visibility'] : Audit::VIS_CUSTOMER,
        ]);
    }

    protected function uniquePaymentReference()
    {
        for ($i = 0; $i < 25; $i++) {
            $ref = Str::reference('PY', 10);
            if (Db::count('payments', ['reference' => $ref]) === 0) {
                return $ref;
            }
        }
        throw new ConflictException('Unable to allocate a payment reference.');
    }
}
