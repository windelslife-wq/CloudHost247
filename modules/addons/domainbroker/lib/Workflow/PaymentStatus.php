<?php
/**
 * Domain Broker — payment / escrow lifecycle.
 *
 * Deliberately separate from both the request status and the transfer status.
 * Money being received does not mean the domain moved, and the domain moving
 * does not release the funds — the two are reconciled by TransferService and
 * PaymentService independently.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Workflow;

class PaymentStatus
{
    /** No invoice exists yet. */
    const NONE               = 'none';
    /** A WHMCS invoice has been raised. */
    const INVOICE_GENERATED  = 'invoice_generated';
    /** Awaiting the customer's payment. */
    const PENDING            = 'pending';
    /** The gateway/WHMCS reports the invoice as paid. */
    const RECEIVED           = 'received';
    /** Funds confirmed held by the escrow provider. */
    const FUNDS_SECURED      = 'funds_secured';
    /** Funds released to the registrant/seller after transfer verification. */
    const RELEASED           = 'released';
    /** Fully refunded to the customer. */
    const REFUNDED           = 'refunded';
    /** Partially refunded (e.g. acquisition refunded, fee retained). */
    const PARTIALLY_REFUNDED = 'partially_refunded';
    /** A payment attempt failed. */
    const FAILED             = 'failed';
    /** The payment window elapsed. */
    const EXPIRED            = 'expired';
    /** Cancelled before payment. */
    const CANCELLED          = 'cancelled';

    const TRANSITIONS = [
        self::NONE => [self::INVOICE_GENERATED, self::CANCELLED],
        self::INVOICE_GENERATED => [self::PENDING, self::CANCELLED, self::EXPIRED],
        self::PENDING => [self::RECEIVED, self::FAILED, self::EXPIRED, self::CANCELLED],
        self::FAILED => [self::PENDING, self::EXPIRED, self::CANCELLED],
        self::RECEIVED => [self::FUNDS_SECURED, self::REFUNDED, self::PARTIALLY_REFUNDED, self::FAILED],
        self::FUNDS_SECURED => [self::RELEASED, self::REFUNDED, self::PARTIALLY_REFUNDED],
        self::RELEASED => [self::PARTIALLY_REFUNDED, self::REFUNDED],
        self::PARTIALLY_REFUNDED => [self::REFUNDED],
        self::REFUNDED => [],
        self::EXPIRED => [self::PENDING, self::CANCELLED],
        self::CANCELLED => [],
    ];

    const LABELS = [
        self::NONE               => 'Not invoiced',
        self::INVOICE_GENERATED  => 'Invoice generated',
        self::PENDING            => 'Payment pending',
        self::RECEIVED           => 'Payment received',
        self::FUNDS_SECURED      => 'Funds secured in escrow',
        self::RELEASED           => 'Funds released',
        self::REFUNDED           => 'Refunded',
        self::PARTIALLY_REFUNDED => 'Partially refunded',
        self::FAILED             => 'Payment failed',
        self::EXPIRED            => 'Payment window expired',
        self::CANCELLED          => 'Cancelled',
    ];

    const TONES = [
        self::NONE               => 'default',
        self::INVOICE_GENERATED  => 'info',
        self::PENDING            => 'warning',
        self::RECEIVED           => 'info',
        self::FUNDS_SECURED      => 'success',
        self::RELEASED           => 'success',
        self::REFUNDED           => 'default',
        self::PARTIALLY_REFUNDED => 'default',
        self::FAILED             => 'danger',
        self::EXPIRED            => 'danger',
        self::CANCELLED          => 'default',
    ];

    public static function all()
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid($status)
    {
        return array_key_exists((string) $status, self::TRANSITIONS);
    }

    public static function canTransition($from, $to)
    {
        if (!self::isValid($from) || !self::isValid($to) || $from === $to) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /** True once the customer's money is actually under our control. */
    public static function isSettled($status)
    {
        return in_array((string) $status, [self::RECEIVED, self::FUNDS_SECURED, self::RELEASED], true);
    }

    public static function isRefundable($status)
    {
        return in_array((string) $status, [self::RECEIVED, self::FUNDS_SECURED, self::RELEASED, self::PARTIALLY_REFUNDED], true);
    }

    public static function label($status)
    {
        return isset(self::LABELS[$status]) ? self::LABELS[$status] : ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function tone($status)
    {
        return isset(self::TONES[$status]) ? self::TONES[$status] : 'default';
    }
}
