<?php
namespace DigitalProducts\Core;

class Clock
{
    protected static $now;

    public static function now()
    {
        return self::$now !== null ? self::$now : date('Y-m-d H:i:s');
    }

    public static function time()
    {
        return strtotime(self::now());
    }

    public static function freeze($value)
    {
        self::$now = is_int($value) ? date('Y-m-d H:i:s', $value) : (string) $value;
    }

    public static function unfreeze() { self::$now = null; }

    public static function addHours($hours)
    {
        return date('Y-m-d H:i:s', self::time() + ((int) $hours * 3600));
    }

    public static function addDays($days)
    {
        return date('Y-m-d H:i:s', self::time() + ((int) $days * 86400));
    }
}
