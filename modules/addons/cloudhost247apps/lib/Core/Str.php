<?php
/**
 * CloudHost247 App Cloud — string, encoding and reference helpers.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Str
{
    /** Truncate to a column length without throwing on multibyte content. */
    public static function clip($value, $length)
    {
        $value = (string) $value;
        $length = (int) $length;
        if (function_exists('mb_substr')) {
            return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
        }
        return strlen($value) > $length ? substr($value, 0, $length) : $value;
    }

    /** HTML-escape for output. Every portal renders through this. */
    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** JS-string escape for inline scripts. */
    public static function js($value)
    {
        return str_replace(
            ['\\', "'", '"', "\n", "\r", '</'],
            ['\\\\', "\\'", '\\"', '\\n', '\\r', '<\\/'],
            (string) $value
        );
    }

    /** Strip control characters and clamp length (log/output safety). */
    public static function cleanText($value, $maxLength = 5000)
    {
        $value = preg_replace('/[^\P{C}\n\r\t]/u', '', (string) $value);
        return self::clip(trim((string) $value), $maxLength);
    }

    public static function label($value)
    {
        return ucwords(str_replace(['_', '-'], ' ', strtolower((string) $value)));
    }

    /** URL slug: lowercase, ascii, single dashes. */
    public static function slug($value, $maxLength = 80)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');
        return self::clip($value === '' ? 'item' : $value, $maxLength);
    }

    /**
     * Container/project identifier: docker compose project names must match
     * [a-z0-9][a-z0-9_-]* and are used in Traefik router names, so the same
     * normalisation applies everywhere.
     */
    public static function projectSlug($value, $maxLength = 48)
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '', strtolower((string) $value)));
        $slug = trim((string) $slug, '-_');
        if ($slug === '') {
            $slug = 'app';
        }
        return self::clip($slug, $maxLength);
    }

    /** Mask a secret for display: keeps a prefix, never the value. */
    public static function mask($value, $keepStart = 2, $keepEnd = 0)
    {
        $value = (string) $value;
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        $keepStart = min($keepStart, $len);
        $keepEnd = min($keepEnd, max(0, $len - $keepStart));
        $masked = substr($value, 0, $keepStart) . str_repeat('•', max(4, $len - $keepStart - $keepEnd));
        if ($keepEnd > 0) {
            $masked .= substr($value, -$keepEnd);
        }
        return $masked;
    }

    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || $at < 2) {
            return self::mask($email, 1);
        }
        return substr($email, 0, 2) . str_repeat('•', max(3, $at - 2)) . substr($email, $at);
    }

    /** Customer-facing reference, e.g. APP-7F3K2QD9 / DEP-4H8M2XQ1. */
    public static function reference($prefix, $length = 8)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1 confusion
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < max(4, (int) $length); $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return strtoupper((string) $prefix) . '-' . $out;
    }

    public static function uuid4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Short opaque id used for idempotency keys and job uuids. */
    public static function token($bytes = 16)
    {
        return self::base64Url(random_bytes(max(8, (int) $bytes)));
    }

    public static function base64Url($raw)
    {
        return rtrim(strtr(base64_encode((string) $raw), '+/', '-_'), '=');
    }

    public static function fromBase64Url($value)
    {
        $value = (string) $value;
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        return $raw === false ? '' : $raw;
    }

    public static function jsonDecode($value, $default = [])
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function jsonEncode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }

    /** PHP 7.4-compatible equivalent of array_is_list (also true for []). */
    public static function isList(array $value)
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** Does $haystack start with $needle? (PHP 7 compatible.) */
    public static function startsWith($haystack, $needle)
    {
        return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
    }

    public static function endsWith($haystack, $needle)
    {
        $needle = (string) $needle;
        $len = strlen($needle);
        return $len === 0 || substr((string) $haystack, -$len) === $needle;
    }

    public static function contains($haystack, $needle)
    {
        return strpos((string) $haystack, (string) $needle) !== false;
    }

    /** Byte-size parsing for manifests ("512M", "2G", 20480). */
    public static function toMegabytes($value)
    {
        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }
        $value = strtoupper(trim((string) $value));
        if ($value === '') {
            return 0;
        }
        if (!preg_match('/^([0-9]*\.?[0-9]+)\s*([KMGT]?B?)?$/', $value, $m)) {
            return (int) $value;
        }
        $number = (float) $m[1];
        switch (isset($m[2]) ? $m[2] : '') {
            case 'K':
            case 'KB':
                return (int) round($number / 1024);
            case 'M':
            case 'MB':
            case '':
                return (int) round($number);
            case 'G':
            case 'GB':
                return (int) round($number * 1024);
            case 'T':
            case 'TB':
                return (int) round($number * 1024 * 1024);
        }
        return (int) round($number);
    }

    /** CPU parsing for manifests ("500m", 2, "2.5"). Returns millicores. */
    public static function toMillicores($value)
    {
        if (is_int($value) || is_float($value)) {
            return (int) round(((float) $value) * 1000);
        }
        $value = trim((string) $value);
        if (Str::endsWith($value, 'm')) {
            return (int) round((float) substr($value, 0, -1));
        }
        return (int) round(((float) $value) * 1000);
    }

    /** Duration parsing for manifests ("30s", "5m", "1h"). Returns seconds. */
    public static function toSeconds($value, $default = 0)
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return (int) $default;
        }
        if (!preg_match('/^([0-9]*\.?[0-9]+)\s*(ms|s|m|h|d)?$/', $value, $m)) {
            return (int) $default;
        }
        $number = (float) $m[1];
        switch (isset($m[2]) ? $m[2] : 's') {
            case 'ms':
                return (int) round($number / 1000);
            case 's':
                return (int) round($number);
            case 'm':
                return (int) round($number * 60);
            case 'h':
                return (int) round($number * 3600);
            case 'd':
                return (int) round($number * 86400);
        }
        return (int) $default;
    }
}
