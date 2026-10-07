<?php
/**
 * CloudHost247 App Cloud — the only clock in the platform.
 *
 * Every timestamp (deployments, leases, grace periods, certificate expiry,
 * backup retention) is written through here, which makes time travel possible in
 * tests and guarantees one format everywhere: `Y-m-d H:i:s` UTC, the format
 * WHMCS itself stores.
 *
 * Test seam: Clock::freeze('2026-01-01 00:00:00') / Clock::travel(3600) /
 * Clock::unfreeze().
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Clock
{
    const FORMAT = 'Y-m-d H:i:s';

    /** @var int|null frozen unix timestamp */
    private static $frozen;

    public static function freeze($datetime = null)
    {
        self::$frozen = $datetime === null ? time() : self::toTimestamp($datetime);
    }

    public static function unfreeze()
    {
        self::$frozen = null;
    }

    public static function isFrozen()
    {
        return self::$frozen !== null;
    }

    public static function travel($seconds)
    {
        self::$frozen = self::timestamp() + (int) $seconds;
    }

    /** Current unix timestamp. */
    public static function timestamp()
    {
        return self::$frozen !== null ? self::$frozen : time();
    }

    /** Current datetime string. */
    public static function now()
    {
        return gmdate(self::FORMAT, self::timestamp());
    }

    public static function at($secondsFromNow)
    {
        return gmdate(self::FORMAT, self::timestamp() + (int) $secondsFromNow);
    }

    public static function inMinutes($minutes)
    {
        return self::at(((int) $minutes) * 60);
    }

    public static function inHours($hours)
    {
        return self::at(((int) $hours) * 3600);
    }

    public static function inDays($days)
    {
        return self::at(((int) $days) * 86400);
    }

    /** Parse a datetime string (or timestamp) into a unix timestamp. */
    public static function toTimestamp($datetime)
    {
        if ($datetime === null || $datetime === '') {
            return 0;
        }
        if (is_int($datetime) || ctype_digit((string) $datetime)) {
            return (int) $datetime;
        }
        $ts = strtotime((string) $datetime . ' UTC');
        return $ts === false ? 0 : $ts;
    }

    public static function isPast($datetime)
    {
        if ($datetime === null || $datetime === '') {
            return false;
        }
        return self::toTimestamp($datetime) < self::timestamp();
    }

    public static function isFuture($datetime)
    {
        if ($datetime === null || $datetime === '') {
            return false;
        }
        return self::toTimestamp($datetime) > self::timestamp();
    }

    /** Whole seconds between two datetimes ($to - $from). */
    public static function diffSeconds($from, $to)
    {
        return self::toTimestamp($to) - self::toTimestamp($from);
    }

    public static function secondsUntil($datetime)
    {
        return self::toTimestamp($datetime) - self::timestamp();
    }

    /** Human duration, used by the deployment dashboard. */
    public static function humanDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}
