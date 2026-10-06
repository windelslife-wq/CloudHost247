<?php
/**
 * Domain Broker — internal escrow ledger.
 *
 * The default provider. Funds are collected through the operator's existing
 * WHMCS gateways and *held by the operator* until the transfer is verified —
 * this provider models that custody explicitly so the workflow has real
 * "secured" and "released" states instead of pretending a payment equals a
 * completed acquisition.
 *
 * It performs no external calls and invents no money movement: release and
 * refund record the operator's own action, and the actual disbursement to the
 * seller happens through the operator's banking process, which is why the
 * release step is permissioned (PAYMENT_RELEASE) and audited.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

use DomainBroker\Core\Clock;
use DomainBroker\Core\Crypto;

class InternalEscrowProvider implements EscrowProviderInterface
{
    public function name()
    {
        return 'internal';
    }

    public function label()
    {
        return 'Internal escrow ledger (funds held by the operator)';
    }

    public function confirmsSynchronously()
    {
        return true;
    }

    public function hold(array $context)
    {
        $payment = $context['payment'];
        // Only money WHMCS has actually recorded as received can be held.
        if (empty($context['paid_confirmed'])) {
            return EscrowResult::fail('Funds have not been confirmed by the billing system.');
        }

        $reference = 'INT-' . strtoupper(substr(hash('sha256', $payment['reference'] . '|' . Clock::now()), 0, 20));

        return EscrowResult::ok(
            EscrowResult::STATE_HELD,
            $reference,
            'Funds recorded as held against invoice #' . (int) $payment['whmcs_invoice_id'] . '.',
            ['invoice_id' => (int) $payment['whmcs_invoice_id']]
        );
    }

    public function release(array $context)
    {
        $payment = $context['payment'];
        if (empty($payment['escrow_reference_enc'])) {
            return EscrowResult::fail('No escrow holding exists for this payment.');
        }
        if (empty($context['transfer_verified'])) {
            return EscrowResult::fail('Funds cannot be released before the transfer is verified.');
        }
        return EscrowResult::ok(
            EscrowResult::STATE_RELEASED,
            Crypto::tryDecrypt($payment['escrow_reference_enc'], 'escrow.reference'),
            'Funds released for disbursement to the seller.'
        );
    }

    public function refund(array $context, $amountMinor)
    {
        $payment = $context['payment'];
        $amountMinor = (int) $amountMinor;
        if ($amountMinor <= 0) {
            return EscrowResult::fail('Refund amount must be positive.');
        }
        $alreadyRefunded = (int) $payment['refunded_minor'];
        $maximum = (int) $payment['total_minor'] - $alreadyRefunded;
        if ($amountMinor > $maximum) {
            return EscrowResult::fail('Refund exceeds the remaining refundable balance.');
        }

        $state = ($amountMinor === $maximum && $alreadyRefunded + $amountMinor >= (int) $payment['total_minor'])
            ? EscrowResult::STATE_REFUNDED
            : EscrowResult::STATE_PARTIALLY_REFUNDED;

        return EscrowResult::ok(
            $state,
            Crypto::tryDecrypt($payment['escrow_reference_enc'], 'escrow.reference'),
            'Refund recorded against the escrow holding.',
            ['amount_minor' => $amountMinor]
        );
    }

    public function status(array $context)
    {
        $payment = $context['payment'];
        $state = $payment['escrow_status'] ?: EscrowResult::STATE_PENDING;
        return EscrowResult::ok($state, null, 'Internal ledger state.');
    }

    public function verifyWebhookSignature($rawBody, array $headers)
    {
        // The internal provider has no external callbacks.
        return false;
    }

    public function parseWebhook(array $payload)
    {
        return ['event_id' => '', 'type' => '', 'reference' => null, 'state' => null, 'amount_minor' => null];
    }
}
