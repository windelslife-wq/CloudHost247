<?php
/**
 * CloudHost247 Services Suite — string helpers.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Str
{
    /** URL/document slug; ASCII only, lower-cased, dashes collapsed. */
    public static function slug($value)
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug === '' ? 'item' : $slug;
    }

    /** Cryptographically random hex token. */
    public static function random($bytes = 16)
    {
        return bin2hex(random_bytes((int) $bytes));
    }

    /** Collapse any whitespace run into a single space. */
    public static function squash($value)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /** Truncate for listings without splitting multibyte characters. */
    public static function truncate($value, $length = 120)
    {
        $value = (string) $value;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') <= $length ? $value : mb_substr($value, 0, $length - 1, 'UTF-8') . '…';
        }
        return strlen($value) <= $length ? $value : substr($value, 0, $length - 3) . '...';
    }

    /** First N characters of a hash of the subject — for IP pseudonymisation. */
    public static function pseudonym($subject)
    {
        $salt = Settings::string('ip_hash_salt', '');
        if ($salt === '') {
            // Fall back to a per-install stable value; never log the raw input.
            $salt = defined('CHS_ROOT') ? (string) @fileinode(dirname(CHS_ROOT)) : 'chs';
        }
        return substr(hash_hmac('sha256', (string) $subject, (string) $salt), 0, 24);
    }
}
