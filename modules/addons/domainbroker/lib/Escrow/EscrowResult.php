<?php
/**
 * Domain Broker — normalised escrow provider response.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

class EscrowResult
{
    const STATE_PENDING  = 'pending';
    const STATE_HELD     = 'held';
    const STATE_RELEASED = 'released';
    const STATE_REFUNDED = 'refunded';
    const STATE_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATE_FAILED   = 'failed';

    /** @var bool */
    public $success = false;

    /** @var string */
    public $state = self::STATE_PENDING;

    /** @var string|null provider-side reference (stored encrypted) */
    public $reference;

    /** @var string|null */
    public $message;

    /** @var array raw, redacted provider payload for the audit log */
    public $details = [];

    /** @var bool true when the provider will confirm later (webhook/manual) */
    public $deferred = false;

    public static function ok($state, $reference = null, $message = null, array $details = [])
    {
        $r = new self();
        $r->success = true;
        $r->state = $state;
        $r->reference = $reference;
        $r->message = $message;
        $r->details = $details;
        return $r;
    }

    public static function deferred($reference = null, $message = null, array $details = [])
    {
        $r = self::ok(self::STATE_PENDING, $reference, $message, $details);
        $r->deferred = true;
        return $r;
    }

    public static function fail($message, array $details = [])
    {
        $r = new self();
        $r->success = false;
        $r->state = self::STATE_FAILED;
        $r->message = $message;
        $r->details = $details;
        return $r;
    }

    public function toArray()
    {
        return [
            'success'   => $this->success,
            'state'     => $this->state,
            'message'   => $this->message,
            'deferred'  => $this->deferred,
            'details'   => $this->details,
            // The reference itself is intentionally omitted: it is stored
            // encrypted and must not leak into logs or audit payloads.
        ];
    }
}
