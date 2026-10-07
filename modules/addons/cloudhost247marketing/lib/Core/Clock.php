<?php
/** Central clock — every timestamp flows through here so tests can freeze time. */

namespace Ch247Mkt\Core;

class Clock
{
    /** @var int|null */
    private static $frozen;

    public static function time()
    {
        return self::$frozen !== null ? self::$frozen : time();
    }
    public static function now()
    {
        return gmdate('Y-m-d H:i:s', self::time());
    }
    public static function today()
    {
        return gmdate('Y-m-d', self::time());
    }
    public static function in($seconds)
    {
        return gmdate('Y-m-d H:i:s', self::time() + (int) $seconds);
    }
    public static function ago($seconds)
    {
        return gmdate('Y-m-d H:i:s', self::time() - (int) $seconds);
    }
    public static function datetime($timestamp)
    {
        return gmdate('Y-m-d H:i:s', (int) $timestamp);
    }
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
    public static function isPast($datetime)
    {
        return self::toTime($datetime) < self::time();
    }
    public static function freeze($timestamp)
    {
        self::$frozen = $timestamp === null ? null : (int) $timestamp;
    }
}
