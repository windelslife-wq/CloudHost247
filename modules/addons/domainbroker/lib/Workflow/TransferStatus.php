<?php
/**
 * Domain Broker — domain transfer lifecycle.
 *
 * Tracks the movement of the domain itself, independently of the money. A
 * transfer can only be *started* once funds are secured, and an acquisition
 * can only be *completed* once the transfer is verified — both rules live in
 * the services, which consult this class.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Workflow;

class TransferStatus
{
    /** Nothing has happened yet. */
    const NOT_STARTED   = 'not_started';
    /** Waiting on the registrant to unlock / supply the authorisation code. */
    const AUTH_PENDING  = 'auth_pending';
    /** Authorisation code received and validated. */
    const AUTH_RECEIVED = 'auth_received';
    /** Transfer request submitted to the gaining registrar. */
    const INITIATED     = 'initiated';
    /** Registry has the transfer in flight. */
    const PENDING       = 'pending';
    /** Losing registrar / registrant approved. */
    const APPROVED      = 'approved';
    /** Domain confirmed in the destination account. */
    const COMPLETED     = 'completed';
    /** Technical failure; may be retried. */
    const FAILED        = 'failed';
    /** Explicitly rejected (NACK) by the losing side. */
    const REJECTED      = 'rejected';
    /** Registry transfer window lapsed. */
    const EXPIRED       = 'expired';
    /** Abandoned. */
    const CANCELLED     = 'cancelled';

    const TRANSITIONS = [
        self::NOT_STARTED   => [self::AUTH_PENDING, self::AUTH_RECEIVED, self::CANCELLED],
        self::AUTH_PENDING  => [self::AUTH_RECEIVED, self::FAILED, self::EXPIRED, self::CANCELLED],
        self::AUTH_RECEIVED => [self::INITIATED, self::FAILED, self::EXPIRED, self::CANCELLED],
        self::INITIATED     => [self::PENDING, self::APPROVED, self::FAILED, self::REJECTED, self::EXPIRED, self::CANCELLED],
        self::PENDING       => [self::APPROVED, self::COMPLETED, self::FAILED, self::REJECTED, self::EXPIRED],
        self::APPROVED      => [self::COMPLETED, self::FAILED, self::EXPIRED],
        self::COMPLETED     => [],
        self::FAILED        => [self::AUTH_PENDING, self::INITIATED, self::CANCELLED],
        self::REJECTED      => [self::AUTH_PENDING, self::INITIATED, self::CANCELLED],
        self::EXPIRED       => [self::AUTH_PENDING, self::INITIATED, self::CANCELLED],
        self::CANCELLED     => [],
    ];

    const LABELS = [
        self::NOT_STARTED   => 'Not started',
        self::AUTH_PENDING  => 'Awaiting authorisation code',
        self::AUTH_RECEIVED => 'Authorisation code received',
        self::INITIATED     => 'Transfer initiated',
        self::PENDING       => 'Transfer pending at registry',
        self::APPROVED      => 'Transfer approved',
        self::COMPLETED     => 'Transfer completed',
        self::FAILED        => 'Transfer failed',
        self::REJECTED      => 'Transfer rejected',
        self::EXPIRED       => 'Transfer expired',
        self::CANCELLED     => 'Transfer cancelled',
    ];

    const TONES = [
        self::NOT_STARTED   => 'default',
        self::AUTH_PENDING  => 'warning',
        self::AUTH_RECEIVED => 'info',
        self::INITIATED     => 'info',
        self::PENDING       => 'info',
        self::APPROVED      => 'primary',
        self::COMPLETED     => 'success',
        self::FAILED        => 'danger',
        self::REJECTED      => 'danger',
        self::EXPIRED       => 'danger',
        self::CANCELLED     => 'default',
    ];

    /** Steps rendered on the customer transfer tracker. */
    const TRACKER = [
        self::AUTH_PENDING, self::AUTH_RECEIVED, self::INITIATED,
        self::PENDING, self::APPROVED, self::COMPLETED,
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

    public static function isFinished($status)
    {
        return in_array((string) $status, [self::COMPLETED, self::CANCELLED], true);
    }

    public static function requiresCustomerAction($status)
    {
        return in_array((string) $status, [self::AUTH_PENDING], true);
    }

    public static function label($status)
    {
        return isset(self::LABELS[$status]) ? self::LABELS[$status] : ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function tone($status)
    {
        return isset(self::TONES[$status]) ? self::TONES[$status] : 'default';
    }

    public static function trackerIndex($status)
    {
        $index = array_search($status, self::TRACKER, true);
        return $index === false ? -1 : (int) $index;
    }
}
