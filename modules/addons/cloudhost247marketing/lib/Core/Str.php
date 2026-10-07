<?php
/** Small pure string/email helpers shared across the module. */

namespace Ch247Mkt\Core;

class Str
{
    /** RFC-ish email check plus the practical limits we enforce on storage. */
    public static function isEmail($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 190) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        // Reject things WHMCS/SMTP will choke on later.
        return strpos($value, "\n") === false && strpos($value, "\r") === false;
    }

    /** Lower-cased, trimmed address used for dedupe and suppression matching. */
    public static function normalizeEmail($value)
    {
        return strtolower(trim((string) $value));
    }

    public static function emailDomain($value)
    {
        $at = strrpos((string) $value, '@');
        return $at === false ? '' : strtolower(substr((string) $value, $at + 1));
    }

    public static function slug($value, $maxLength = 60)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');
        if ($value === '') {
            $value = 'item';
        }
        return substr($value, 0, $maxLength);
    }

    /** URL-safe opaque token for tracking pixels, click links and unsubscribes. */
    public static function token($bytes = 16)
    {
        return rtrim(strtr(base64_encode(random_bytes((int) $bytes)), '+/', '-_'), '=');
    }

    public static function clip($value, $length)
    {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /** Collapse HTML to a readable plain-text alternative part. */
    public static function htmlToText($html)
    {
        $text = (string) $html;
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        // Keep link targets visible in the text part. The angle brackets are
        // the plain-text convention for a URL, but writing them here would
        // make strip_tags() below eat the whole URL as if it were a tag — so
        // they go in as control-character placeholders and are restored after.
        $text = preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[2]));
            $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $label === $href) {
                return "\x02" . $href . "\x03";
            }
            return $label . " \x02" . $href . "\x03";
        }, (string) $text);
        $text = preg_replace('#<(br|/p|/div|/tr|/h[1-6])\s*/?>#i', "\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = str_replace(["\x02", "\x03"], ['<', '>'], $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
        return trim((string) $text);
    }

    /** Stable hash used as the queue idempotency key. */
    public static function idempotencyKey($campaignId, $email)
    {
        return hash('sha256', (int) $campaignId . '|' . self::normalizeEmail($email));
    }

    /**
     * Partially hide an address for display on a public page.
     *
     * The unsubscribe page is reachable by anyone holding the token, so it
     * must confirm *which* address it acted on without printing the whole
     * thing where a shoulder-surfer or a logged referrer can harvest it.
     */
    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return str_repeat('•', max(3, strlen($email)));
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        if (strlen($local) <= 2) {
            $maskedLocal = substr($local, 0, 1) . '•';
        } else {
            $maskedLocal = substr($local, 0, 1) . str_repeat('•', min(6, strlen($local) - 2)) . substr($local, -1);
        }

        $parts = explode('.', $domain);
        $head = array_shift($parts);
        if (strlen($head) > 2) {
            $head = substr($head, 0, 1) . str_repeat('•', min(5, strlen($head) - 2)) . substr($head, -1);
        }
        return $maskedLocal . '@' . implode('.', array_merge([$head], $parts));
    }
}
