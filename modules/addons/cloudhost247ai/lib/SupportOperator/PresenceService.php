<?php
/**
 * Agent presence: heartbeats from the admin support page drive the
 * availability shown to visitors. Stale rows (older than the TTL setting)
 * are ignored so the widget never promises a human who left.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Settings;

class PresenceService
{
    const ONLINE = 'online';
    const BUSY = 'busy';
    const OFFLINE = 'offline';

    public static function heartbeat($adminId, $status = self::ONLINE)
    {
        $adminId = (int) $adminId;
        if ($adminId <= 0) {
            return false;
        }
        if (!in_array($status, [self::ONLINE, self::BUSY, self::OFFLINE], true)) {
            $status = self::ONLINE;
        }
        $row = Db::first('support_presence', ['admin_id' => $adminId]);
        if ($row === null) {
            Db::insert('support_presence', [
                'admin_id' => $adminId,
                'status' => $status,
                'updated_at' => Clock::now(),
            ]);
        } else {
            Db::update('support_presence', ['admin_id' => $adminId], [
                'status' => $status,
                'updated_at' => Clock::now(),
            ]);
        }
        return true;
    }

    public static function ttl()
    {
        return max(30, Settings::int('support_presence_ttl', 300));
    }

    /** Fresh heartbeats only. */
    public static function fresh()
    {
        $cutoff = date('Y-m-d H:i:s', Clock::toTime(Clock::now()) - self::ttl());
        return Db::query(
            'SELECT * FROM ' . Db::t('support_presence') . ' WHERE updated_at >= ? ORDER BY updated_at DESC',
            [$cutoff]
        );
    }

    public static function availability()
    {
        $fresh = self::fresh();
        $agents = [];
        $best = self::OFFLINE;
        foreach ($fresh as $row) {
            if ($row['status'] === self::ONLINE) {
                $best = self::ONLINE;
                $agents[] = (int) $row['admin_id'];
            } elseif ($row['status'] === self::BUSY && $best !== self::ONLINE) {
                $best = self::BUSY;
            }
        }
        return ['status' => $best, 'agents' => $agents, 'fresh_count' => count($fresh)];
    }

    /** Most recently active online admin, for escalation assignment. */
    public static function recentOnlineAdmin()
    {
        $fresh = self::fresh();
        foreach ($fresh as $row) {
            if ($row['status'] === self::ONLINE) {
                return (int) $row['admin_id'];
            }
        }
        return 0;
    }
}
