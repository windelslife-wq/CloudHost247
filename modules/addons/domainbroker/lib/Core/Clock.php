<?php
/**
 * Domain Broker — clock abstraction.
 *
 * All timestamps in the module are produced here so expiry logic can be tested
 * deterministically. Stored values are always UTC 'Y-m-d H:i:s' strings, which
 * is what WHMCS uses for its own datetime columns.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Clock
{
    /** @var int|null Frozen unix timestamp (tests only). */
    protected static $frozen;

    public static function freeze($timestamp)
    {
        self::$frozen = is_numeric($timestamp) ? (int) $timestamp : strtotime($timestamp);
    }

    public static function unfreeze()
    {
        self::$frozen = null;
    }

    /** Advance a frozen clock. No effect when not frozen. */
    public static function travel($seconds)
    {
        if (self::$frozen !== null) {
            self::$frozen += (int) $seconds;
        }
    }

    public static function timestamp()
    {
        return self::$frozen !== null ? self::$frozen : time();
    }

    /** @return string 'Y-m-d H:i:s' */
    public static function now()
    {
        return gmdate('Y-m-d H:i:s', self::timestamp());
    }

    /** @return string 'Y-m-d H:i:s' offset from now. */
    public static function at($secondsFromNow)
    {
        return gmdate('Y-m-d H:i:s', self::timestamp() + (int) $secondsFromNow);
    }

    public static function inDays($days)
    {
        return self::at((int) $days * 86400);
    }

    public static function inHours($hours)
    {
        return self::at((int) $hours * 3600);
    }

    public static function toTimestamp($datetime)
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        if (is_numeric($datetime)) {
            return (int) $datetime;
        }
        $ts = strtotime($datetime . ' UTC');
        return $ts === false ? null : $ts;
    }

    /** True when $datetime is in the past relative to the (possibly frozen) clock. */
    public static function isPast($datetime)
    {
        $ts = self::toTimestamp($datetime);
        return $ts !== null && $ts <= self::timestamp();
    }

    /** Whole seconds between two stored datetimes. */
    public static function diffSeconds($from, $to)
    {
        $a = self::toTimestamp($from);
        $b = self::toTimestamp($to);
        if ($a === null || $b === null) {
            return null;
        }
        return $b - $a;
    }
}
