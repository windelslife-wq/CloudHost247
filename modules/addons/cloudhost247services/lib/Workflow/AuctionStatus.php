<?php
/**
 * Auction lifecycle.
 *
 *   draft → scheduled → active → sold → paid → transferred
 *                    │        └→ ended (no qualifying bids)
 *                    └──────────→ cancelled (seller/admin)
 *                    sold ──────→ cancelled_unpaid (invoice lapsed)
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

class AuctionStatus
{
    const DRAFT = 'draft';
    const SCHEDULED = 'scheduled';
    const ACTIVE = 'active';
    const ENDED = 'ended';
    const SOLD = 'sold';
    const PAID = 'paid';
    const TRANSFERRED = 'transferred';
    const CANCELLED = 'cancelled';
    const CANCELLED_UNPAID = 'cancelled_unpaid';

    public static function all()
    {
        return [
            self::DRAFT, self::SCHEDULED, self::ACTIVE, self::ENDED, self::SOLD,
            self::PAID, self::TRANSFERRED, self::CANCELLED, self::CANCELLED_UNPAID,
        ];
    }

    /** Statuses a visitor may browse. */
    public static function browseable()
    {
        return [self::ACTIVE, self::SCHEDULED];
    }

    /** Statuses after which no bids may be placed. */
    public static function terminal()
    {
        return [self::ENDED, self::TRANSFERRED, self::CANCELLED, self::CANCELLED_UNPAID];
    }
}
