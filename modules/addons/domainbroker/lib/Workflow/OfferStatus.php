<?php
/**
 * Domain Broker — offer lifecycle.
 *
 * Offers are append-only. A counteroffer never mutates the offer it answers:
 * it is a new row whose `parent_offer_id` points back, and the parent moves to
 * COUNTERED. Historical amounts therefore remain exactly as they were sent.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Workflow;

class OfferStatus
{
    /** Sent, awaiting a response from the recipient. */
    const PENDING    = 'pending';
    /** The recipient answered with a counteroffer. */
    const COUNTERED  = 'countered';
    /** Accepted by the recipient. */
    const ACCEPTED   = 'accepted';
    /** Declined by the recipient. */
    const REJECTED   = 'rejected';
    /** Validity window elapsed without a response. */
    const EXPIRED    = 'expired';
    /** Pulled by the sender before it was answered. */
    const WITHDRAWN  = 'withdrawn';
    /** Replaced by a newer offer in the same direction. */
    const SUPERSEDED = 'superseded';

    const TRANSITIONS = [
        self::PENDING    => [self::COUNTERED, self::ACCEPTED, self::REJECTED, self::EXPIRED, self::WITHDRAWN, self::SUPERSEDED],
        self::COUNTERED  => [],
        self::ACCEPTED   => [],
        self::REJECTED   => [],
        self::EXPIRED    => [],
        self::WITHDRAWN  => [],
        self::SUPERSEDED => [],
    ];

    const LABELS = [
        self::PENDING    => 'Awaiting response',
        self::COUNTERED  => 'Countered',
        self::ACCEPTED   => 'Accepted',
        self::REJECTED   => 'Rejected',
        self::EXPIRED    => 'Expired',
        self::WITHDRAWN  => 'Withdrawn',
        self::SUPERSEDED => 'Superseded',
    ];

    /* ------------------------------------------------------------ parties */

    const PARTY_CUSTOMER = 'customer';
    const PARTY_BROKER   = 'broker';
    const PARTY_OWNER    = 'owner';

    /** Direction an offer travels. */
    const DIR_TO_OWNER    = 'to_owner';     // buyer side → registrant
    const DIR_TO_CUSTOMER = 'to_customer';  // registrant → buyer side

    public static function all()
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid($status)
    {
        return array_key_exists((string) $status, self::TRANSITIONS);
    }

    public static function isOpen($status)
    {
        return $status === self::PENDING;
    }

    public static function isClosed($status)
    {
        return self::isValid($status) && $status !== self::PENDING;
    }

    public static function canTransition($from, $to)
    {
        if (!self::isValid($from) || !self::isValid($to) || $from === $to) {
            return false;
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function label($status)
    {
        return isset(self::LABELS[$status]) ? self::LABELS[$status] : ucfirst((string) $status);
    }

    public static function parties()
    {
        return [self::PARTY_CUSTOMER, self::PARTY_BROKER, self::PARTY_OWNER];
    }

    public static function isValidParty($party)
    {
        return in_array((string) $party, self::parties(), true);
    }

    public static function directions()
    {
        return [self::DIR_TO_OWNER, self::DIR_TO_CUSTOMER];
    }
}
