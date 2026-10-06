<?php
/**
 * Domain Broker — string helpers (encoding, normalisation, redaction).
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Str
{
    /** Hard-truncate without splitting a multibyte character. */
    public static function clip($value, $length)
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, (int) $length, 'UTF-8');
        }
        return substr($value, 0, (int) $length);
    }

    /** HTML-escape for template output. */
    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape for use inside a JS string literal / JSON island. */
    public static function js($value)
    {
        return json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Strip control characters and normalise whitespace in free text that will
     * be stored and later rendered.
     */
    public static function cleanText($value, $maxLength = 5000)
    {
        $value = (string) $value;
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Remove C0 controls except tab and newline, plus C1 controls.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\xC2\x80-\xC2\x9F]/u', '', $value);
        if ($value === null) {
            return '';
        }
        $value = preg_replace("/\n{4,}/", "\n\n\n", $value);
        return self::clip(trim($value), $maxLength);
    }

    /** Human readable label from a snake/dot token. */
    public static function label($value)
    {
        $value = str_replace(['_', '.', '-'], ' ', (string) $value);
        return ucwords(trim($value));
    }

    public static function slug($value, $maxLength = 80)
    {
        $value = strtolower((string) $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return self::clip(trim((string) $value, '-'), $maxLength);
    }

    /**
     * Mask a value for display to a party that may not see it in full,
     * e.g. "ab***@example.com" or "XXXX-1234".
     */
    public static function mask($value, $keepStart = 2, $keepEnd = 0)
    {
        $value = (string) $value;
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, $keepStart)
            // Bounded so a masked value always fits a short display column.
            . str_repeat('*', min(8, max(3, $len - $keepStart - $keepEnd)))
            . ($keepEnd ? substr($value, -$keepEnd) : '');
    }

    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false) {
            return self::mask($email);
        }
        return self::mask(substr($email, 0, $at), 2) . substr($email, $at);
    }

    /** Short, unambiguous, human-quotable reference (no I/O/0/1). */
    public static function reference($prefix, $length = 8)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return strtoupper($prefix) . '-' . $out;
    }

    public static function uuid4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function jsonDecode($value, $default = [])
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function jsonEncode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }
}
