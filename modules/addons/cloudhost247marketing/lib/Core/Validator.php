<?php
/** Small pure validators shared by tools and portals. */

namespace Ch247Mkt\Core;

class Validator
{
    public static function isInt($value)
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1);
    }
    public static function isPositiveId($value)
    {
        return self::isInt($value) && (int) $value > 0;
    }
    public static function isDomain($value)
    {
        $value = strtolower(trim((string) $value));
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value);
    }
    public static function clampInt($value, $min, $max, $default)
    {
        if (!self::isInt($value)) {
            return (int) $default;
        }
        return max($min, min($max, (int) $value));
    }
    public static function clip($value, $length)
    {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
