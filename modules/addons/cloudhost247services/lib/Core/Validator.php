<?php
/**
 * Small boolean validators used by services and controllers.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Validator
{
    public static function isEmail($value)
    {
        return filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Strict integer string (optionally signed). */
    public static function isInt($value)
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1);
    }

    public static function inRange($value, $min, $max)
    {
        if (!self::isInt($value)) {
            return false;
        }
        $i = (int) $value;
        return $i >= $min && $i <= $max;
    }

    /** @param array<int,string> $allowed */
    public static function inEnum($value, array $allowed)
    {
        return in_array((string) $value, array_map('strval', $allowed), true);
    }

    public static function isCurrency($code)
    {
        return is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1;
    }

    /** Second-level label, unicode-tolerant (IDN accepted pre-conversion). */
    public static function isSld($label)
    {
        $label = trim((string) $label);
        if ($label === '' || mb_strlen($label, 'UTF-8') > 63) {
            return false;
        }
        return preg_match('/^[^\s.\/\\:;,_-](?:[^\s.\/\\:;,]*[^\s.\/\\:;,_-])?$/u', $label) === 1;
    }

    /** Full domain syntax check; delegates to DomainName for the canonical ruleset. */
    public static function domainName($value)
    {
        return DomainName::tryParse($value) !== null;
    }
}
