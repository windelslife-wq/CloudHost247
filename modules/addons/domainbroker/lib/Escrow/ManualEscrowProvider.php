<?php
/**
 * Domain Broker — manual / off-platform escrow.
 *
 * For operators who settle through a law firm, a bank escrow account or a
 * third-party escrow agent that has no API. Every step is deferred: the
 * workflow waits for an authorised administrator to confirm custody, release
 * and refund, and each confirmation is audited with the actor and a reason.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

use DomainBroker\Core\Crypto;

class ManualEscrowProvider implements EscrowProviderInterface
{
    public function name()
    {
        return 'manual';
    }

    public function label()
    {
        return 'Manual escrow (confirmed by an administrator)';
    }

    public function confirmsSynchronously()
    {
        return false;
    }

    public function hold(array $context)
    {
        return EscrowResult::deferred(
            null,
            'Awaiting administrator confirmation that funds are held by the escrow agent.'
        );
    }

    public function release(array $context)
    {
        if (empty($context['transfer_verified'])) {
            return EscrowResult::fail('Funds cannot be released before the transfer is verified.');
        }
        return EscrowResult::deferred(
            Crypto::tryDecrypt(
                isset($context['payment']['escrow_reference_enc']) ? $context['payment']['escrow_reference_enc'] : null,
                'escrow.reference'
            ),
            'Release instruction recorded; awaiting confirmation from the escrow agent.'
        );
    }

    public function refund(array $context, $amountMinor)
    {
        if ((int) $amountMinor <= 0) {
            return EscrowResult::fail('Refund amount must be positive.');
        }
        return EscrowResult::deferred(null, 'Refund instruction recorded; awaiting the escrow agent.');
    }

    public function status(array $context)
    {
        $payment = $context['payment'];
        return EscrowResult::ok(
            $payment['escrow_status'] ?: EscrowResult::STATE_PENDING,
            null,
            'Manual escrow state as recorded by an administrator.'
        );
    }

    public function verifyWebhookSignature($rawBody, array $headers)
    {
        return false;
    }

    public function parseWebhook(array $payload)
    {
        return ['event_id' => '', 'type' => '', 'reference' => null, 'state' => null, 'amount_minor' => null];
    }
}
