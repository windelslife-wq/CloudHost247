<?php
/**
 * Central clock — every timestamp in the suite flows through here, so tests
 * can pin time (Clock::freeze) and production stays consistent.
 *
 * String timestamps are always 'Y-m-d H:i:s' UTC.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Clock
{
    /** @var int|null */
    private static $frozen;

    /** Unix timestamp of "now". */
    public static function time()
    {
        return self::$frozen !== null ? self::$frozen : time();
    }

    /** 'Y-m-d H:i:s' UTC for now. */
    public static function now()
    {
        return gmdate('Y-m-d H:i:s', self::time());
    }

    /** 'Y-m-d' UTC for now. */
    public static function today()
    {
        return gmdate('Y-m-d', self::time());
    }

    /** 'Y-m-d H:i:s' UTC $seconds from now. */
    public static function in($seconds)
    {
        return gmdate('Y-m-d H:i:s', self::time() + (int) $seconds);
    }

    /** 'Y-m-d H:i:s' UTC $seconds before now. */
    public static function ago($seconds)
    {
        return gmdate('Y-m-d H:i:s', self::time() - (int) $seconds);
    }

    /** 'Y-m-d H:i:s' UTC for a unix timestamp. */
    public static function datetime($timestamp)
    {
        return gmdate('Y-m-d H:i:s', (int) $timestamp);
    }

    /** 'Y-m-d' UTC for a unix timestamp. */
    public static function date($timestamp)
    {
        return gmdate('Y-m-d', (int) $timestamp);
    }

    /** Unix ts for a 'Y-m-d[ H:i[:s]]' string (UTC); null when unparseable. */
    public static function toTime($datetime)
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        if (is_numeric($datetime)) {
            return (int) $datetime;
        }
        $text = (string) $datetime;
        $ts = strtotime($text . (strpos($text, 'UTC') === false ? ' UTC' : ''));
        return $ts === false ? null : $ts;
    }

    /** True when $datetime is in the past. */
    public static function isPast($datetime)
    {
        return self::toTime($datetime) < self::time();
    }

    /** Test hook: pin the clock to $timestamp (or release with null). */
    public static function freeze($timestamp)
    {
        self::$frozen = $timestamp === null ? null : (int) $timestamp;
    }
}
