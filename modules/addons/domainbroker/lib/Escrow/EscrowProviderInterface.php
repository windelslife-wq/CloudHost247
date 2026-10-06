<?php
/**
 * Domain Broker — escrow provider abstraction.
 *
 * The module never talks to a specific escrow company directly. Providers
 * implement this contract and are selected by the `escrow_provider` setting,
 * so an operator can start on the internal (WHMCS-invoice backed) ledger and
 * move to a third-party escrow service later without touching the workflow.
 *
 * Implementations must be idempotent: calling hold()/release()/refund() twice
 * with the same payment reference must not move money twice.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

interface EscrowProviderInterface
{
    /** Machine name, stored on the payment row. */
    public function name();

    /** Human label for the admin UI. */
    public function label();

    /**
     * True when the provider confirms custody of funds synchronously.
     * False means custody is confirmed later, by webhook or admin action.
     */
    public function confirmsSynchronously();

    /**
     * Place received funds into escrow.
     *
     * @param array $context payment row + request row + currency
     * @return EscrowResult
     */
    public function hold(array $context);

    /**
     * Release escrowed funds to the seller after transfer verification.
     *
     * @return EscrowResult
     */
    public function release(array $context);

    /**
     * Return escrowed (or already-released) funds to the buyer.
     *
     * @param int $amountMinor amount to return; may be partial
     * @return EscrowResult
     */
    public function refund(array $context, $amountMinor);

    /**
     * Current provider-side state for a holding.
     *
     * @return EscrowResult
     */
    public function status(array $context);

    /**
     * Verify an inbound webhook.
     *
     * @param string $rawBody
     * @param array  $headers
     * @return bool
     */
    public function verifyWebhookSignature($rawBody, array $headers);

    /**
     * Translate a verified webhook payload into a normalised event.
     *
     * @return array{event_id:string, type:string, reference:string|null, state:string|null, amount_minor:int|null}
     */
    public function parseWebhook(array $payload);
}
