<?php
/**
 * Domain Broker — brokerage request lifecycle.
 *
 * The transition table is the single source of truth for what may happen next.
 * Nothing in the module writes `status` directly: every change goes through
 * RequestService::transition(), which consults this class and appends to the
 * immutable activity log. A status posted by a client is never trusted — it is
 * only ever used to look up an *intent*, which is then validated here.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Workflow;

class RequestStatus
{
    const SUBMITTED             = 'submitted';
    const UNDER_REVIEW          = 'under_review';
    const BROKER_ASSIGNED       = 'broker_assigned';
    const OWNER_CONTACTED       = 'owner_contacted';
    const NEGOTIATION           = 'negotiation';
    const OFFER_RECEIVED        = 'offer_received';
    const COUNTEROFFER          = 'counteroffer';
    const OFFER_ACCEPTED        = 'offer_accepted';
    const PAYMENT_PENDING       = 'payment_pending';
    const PAYMENT_SECURED       = 'payment_secured';
    const DOMAIN_TRANSFER       = 'domain_transfer';
    const TRANSFER_VERIFICATION = 'transfer_verification';
    const COMPLETED             = 'completed';

    const CANCELLED = 'cancelled';
    const REJECTED  = 'rejected';
    const EXPIRED   = 'expired';
    const FAILED    = 'failed';
    const DISPUTED  = 'disputed';
    const REFUNDED  = 'refunded';

    /** The happy path, in order, for progress indicators. */
    const PIPELINE = [
        self::SUBMITTED,
        self::UNDER_REVIEW,
        self::BROKER_ASSIGNED,
        self::OWNER_CONTACTED,
        self::NEGOTIATION,
        self::OFFER_RECEIVED,
        self::COUNTEROFFER,
        self::OFFER_ACCEPTED,
        self::PAYMENT_PENDING,
        self::PAYMENT_SECURED,
        self::DOMAIN_TRANSFER,
        self::TRANSFER_VERIFICATION,
        self::COMPLETED,
    ];

    /** Non-pipeline outcomes. */
    const EXCEPTIONS = [
        self::CANCELLED, self::REJECTED, self::EXPIRED,
        self::FAILED, self::DISPUTED, self::REFUNDED,
    ];

    /** Statuses from which no further work happens without admin intervention. */
    const TERMINAL = [
        self::COMPLETED, self::CANCELLED, self::REJECTED,
        self::EXPIRED, self::FAILED, self::REFUNDED,
    ];

    /** Statuses in which the customer still has money at stake. */
    const FINANCIALLY_ACTIVE = [
        self::PAYMENT_PENDING, self::PAYMENT_SECURED,
        self::DOMAIN_TRANSFER, self::TRANSFER_VERIFICATION, self::DISPUTED,
    ];

    /**
     * Allowed transitions. A target not listed here cannot be reached, even by
     * an administrator — overrides go through the explicit override path which
     * is separately permissioned and always demands a reason.
     */
    const TRANSITIONS = [
        self::SUBMITTED => [
            self::UNDER_REVIEW, self::BROKER_ASSIGNED, self::CANCELLED,
            self::REJECTED, self::EXPIRED,
        ],
        self::UNDER_REVIEW => [
            self::BROKER_ASSIGNED, self::REJECTED, self::CANCELLED, self::EXPIRED,
        ],
        self::BROKER_ASSIGNED => [
            self::OWNER_CONTACTED, self::UNDER_REVIEW, self::CANCELLED,
            self::FAILED, self::EXPIRED,
        ],
        self::OWNER_CONTACTED => [
            self::NEGOTIATION, self::OFFER_RECEIVED, self::FAILED,
            self::CANCELLED, self::EXPIRED,
        ],
        self::NEGOTIATION => [
            self::OFFER_RECEIVED, self::COUNTEROFFER, self::OFFER_ACCEPTED,
            self::FAILED, self::CANCELLED, self::EXPIRED, self::DISPUTED,
        ],
        self::OFFER_RECEIVED => [
            self::COUNTEROFFER, self::OFFER_ACCEPTED, self::NEGOTIATION,
            self::FAILED, self::CANCELLED, self::EXPIRED, self::DISPUTED,
        ],
        self::COUNTEROFFER => [
            self::OFFER_RECEIVED, self::NEGOTIATION, self::OFFER_ACCEPTED,
            self::FAILED, self::CANCELLED, self::EXPIRED, self::DISPUTED,
        ],
        self::OFFER_ACCEPTED => [
            self::PAYMENT_PENDING, self::FAILED, self::CANCELLED,
            self::EXPIRED, self::DISPUTED,
        ],
        self::PAYMENT_PENDING => [
            self::PAYMENT_SECURED, self::FAILED, self::EXPIRED,
            self::CANCELLED, self::DISPUTED,
        ],
        self::PAYMENT_SECURED => [
            self::DOMAIN_TRANSFER, self::DISPUTED, self::REFUNDED, self::FAILED,
        ],
        self::DOMAIN_TRANSFER => [
            self::TRANSFER_VERIFICATION, self::FAILED, self::DISPUTED, self::REFUNDED,
        ],
        self::TRANSFER_VERIFICATION => [
            self::COMPLETED, self::DOMAIN_TRANSFER, self::FAILED,
            self::DISPUTED, self::REFUNDED,
        ],
        self::COMPLETED  => [self::DISPUTED],
        // A dispute freezes the acquisition wherever it was; closing the
        // dispute must be able to put it back, so every stage a dispute can
        // be raised from is also a legal destination.
        self::DISPUTED   => [
            self::NEGOTIATION, self::OFFER_ACCEPTED, self::PAYMENT_PENDING,
            self::PAYMENT_SECURED, self::DOMAIN_TRANSFER, self::TRANSFER_VERIFICATION,
            self::COMPLETED, self::REFUNDED, self::FAILED, self::CANCELLED,
        ],
        self::CANCELLED  => [],
        self::REJECTED   => [],
        self::EXPIRED    => [],
        self::FAILED     => [self::REFUNDED, self::DISPUTED],
        self::REFUNDED   => [],
    ];

    /** Customer-facing copy. */
    const LABELS = [
        self::SUBMITTED             => 'Request submitted',
        self::UNDER_REVIEW          => 'Under review',
        self::BROKER_ASSIGNED       => 'Broker assigned',
        self::OWNER_CONTACTED       => 'Owner contacted',
        self::NEGOTIATION           => 'In negotiation',
        self::OFFER_RECEIVED        => 'Offer received',
        self::COUNTEROFFER          => 'Counteroffer sent',
        self::OFFER_ACCEPTED        => 'Offer accepted',
        self::PAYMENT_PENDING       => 'Payment pending',
        self::PAYMENT_SECURED       => 'Funds secured',
        self::DOMAIN_TRANSFER       => 'Domain transfer in progress',
        self::TRANSFER_VERIFICATION => 'Transfer verification',
        self::COMPLETED             => 'Completed',
        self::CANCELLED             => 'Cancelled',
        self::REJECTED              => 'Rejected',
        self::EXPIRED               => 'Expired',
        self::FAILED                => 'Unsuccessful',
        self::DISPUTED              => 'In dispute',
        self::REFUNDED              => 'Refunded',
    ];

    const DESCRIPTIONS = [
        self::SUBMITTED             => 'We have received your acquisition request and it is queued for review.',
        self::UNDER_REVIEW          => 'Our team is reviewing the request, the target domain and your budget.',
        self::BROKER_ASSIGNED       => 'A dedicated domain broker has taken ownership of your acquisition.',
        self::OWNER_CONTACTED       => 'Your broker has made contact with the current registrant.',
        self::NEGOTIATION           => 'Negotiations with the current registrant are under way.',
        self::OFFER_RECEIVED        => 'The current registrant has responded with an offer for your review.',
        self::COUNTEROFFER          => 'A counteroffer has been put to the current registrant.',
        self::OFFER_ACCEPTED        => 'Terms have been agreed. An invoice is being prepared.',
        self::PAYMENT_PENDING       => 'Payment is required to move the acquisition into escrow.',
        self::PAYMENT_SECURED       => 'Your funds are secured. The transfer will now be arranged.',
        self::DOMAIN_TRANSFER       => 'The domain transfer has been initiated with the registrars.',
        self::TRANSFER_VERIFICATION => 'We are verifying that the transfer has completed correctly.',
        self::COMPLETED             => 'The domain is now under your control. This acquisition is complete.',
        self::CANCELLED             => 'This request was cancelled.',
        self::REJECTED              => 'This request could not be accepted.',
        self::EXPIRED               => 'This request expired before it could be completed.',
        self::FAILED                => 'This acquisition could not be completed.',
        self::DISPUTED              => 'This acquisition is under dispute review.',
        self::REFUNDED              => 'Funds for this acquisition have been refunded.',
    ];

    /** Bootstrap contextual class used by the UI. */
    const TONES = [
        self::SUBMITTED             => 'info',
        self::UNDER_REVIEW          => 'info',
        self::BROKER_ASSIGNED       => 'info',
        self::OWNER_CONTACTED       => 'info',
        self::NEGOTIATION           => 'primary',
        self::OFFER_RECEIVED        => 'warning',
        self::COUNTEROFFER          => 'warning',
        self::OFFER_ACCEPTED        => 'primary',
        self::PAYMENT_PENDING       => 'warning',
        self::PAYMENT_SECURED       => 'primary',
        self::DOMAIN_TRANSFER       => 'primary',
        self::TRANSFER_VERIFICATION => 'primary',
        self::COMPLETED             => 'success',
        self::CANCELLED             => 'default',
        self::REJECTED              => 'danger',
        self::EXPIRED               => 'default',
        self::FAILED                => 'danger',
        self::DISPUTED              => 'danger',
        self::REFUNDED              => 'default',
    ];

    public static function all()
    {
        return array_merge(self::PIPELINE, self::EXCEPTIONS);
    }

    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }

    public static function canTransition($from, $to)
    {
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }
        if ($from === $to) {
            return false;
        }
        return in_array($to, isset(self::TRANSITIONS[$from]) ? self::TRANSITIONS[$from] : [], true);
    }

    public static function nextStates($from)
    {
        return isset(self::TRANSITIONS[$from]) ? self::TRANSITIONS[$from] : [];
    }

    public static function isTerminal($status)
    {
        return in_array((string) $status, self::TERMINAL, true);
    }

    public static function isActive($status)
    {
        return self::isValid($status) && !self::isTerminal($status);
    }

    public static function isFinanciallyActive($status)
    {
        return in_array((string) $status, self::FINANCIALLY_ACTIVE, true);
    }

    public static function label($status)
    {
        return isset(self::LABELS[$status]) ? self::LABELS[$status] : ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function description($status)
    {
        return isset(self::DESCRIPTIONS[$status]) ? self::DESCRIPTIONS[$status] : '';
    }

    public static function tone($status)
    {
        return isset(self::TONES[$status]) ? self::TONES[$status] : 'default';
    }

    /** 0-100 progress through the pipeline, for the customer timeline. */
    public static function progress($status)
    {
        $index = array_search($status, self::PIPELINE, true);
        if ($index === false) {
            return $status === self::COMPLETED ? 100 : 0;
        }
        return (int) round(($index / (count(self::PIPELINE) - 1)) * 100);
    }

    public static function pipelineIndex($status)
    {
        $index = array_search($status, self::PIPELINE, true);
        return $index === false ? -1 : (int) $index;
    }
}
