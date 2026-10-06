<?php
/**
 * Expert-service request lifecycle.
 *
 *   requested → reviewing → quoted → accepted → in_progress → delivered → completed
 *        │           │         └→ rejected (with reason)
 *        └→ cancelled (client, any time before acceptance)
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

class RequestStatus
{
    const REQUESTED = 'requested';
    const REVIEWING = 'reviewing';
    const QUOTED = 'quoted';
    const ACCEPTED = 'accepted';
    const IN_PROGRESS = 'in_progress';
    const DELIVERED = 'delivered';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
    const REJECTED = 'rejected';

    public static function all()
    {
        return [
            self::REQUESTED, self::REVIEWING, self::QUOTED, self::ACCEPTED,
            self::IN_PROGRESS, self::DELIVERED, self::COMPLETED, self::CANCELLED, self::REJECTED,
        ];
    }

    public static function open()
    {
        // Delivered work is still open until the client confirms completion.
        return [self::REQUESTED, self::REVIEWING, self::QUOTED, self::ACCEPTED,
                self::IN_PROGRESS, self::DELIVERED];
    }
}
