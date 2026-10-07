<?php
/**
 * Merge-tag substitution and validation.
 *
 * Two rules keep this safe:
 *  1. Only tags in TAGS exist. An unknown tag is a validation error at save
 *     time, never a silent empty string in a customer's inbox.
 *  2. Values are HTML-escaped when substituted into HTML, and left raw only
 *     for the plain-text part. A subscriber whose first name is `<script>`
 *     cannot inject anything.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

use Ch247Mkt\Core\Clock;

class Personalizer
{
    /** tag => human description, shown in the builder's merge-tag menu. */
    public const TAGS = [
        'first_name'      => "Subscriber's first name",
        'last_name'       => "Subscriber's last name",
        'full_name'       => 'First and last name combined',
        'email'           => 'Subscriber email address',
        'company'         => 'Company name',
        'client_id'       => 'WHMCS client ID',
        'service_name'    => 'Nearest active product/service name',
        'domain'          => 'Domain on that service',
        'invoice_number'  => 'Most recent unpaid invoice number',
        'renewal_date'    => 'Next due date of that service',
        'country'         => 'Client country code',
        'company_name'    => 'Your company name (from settings)',
        'physical_address' => 'Your postal address (from settings)',
        'current_year'    => 'Current year',
        'unsubscribe_url' => 'One-click unsubscribe link (required)',
        'preferences_url' => 'Email preference centre link',
        'webview_url'     => 'View this email in a browser',
    ];

    /** Tags the system always supplies; never flagged as missing data. */
    public const SYSTEM_TAGS = ['unsubscribe_url', 'preferences_url', 'webview_url', 'current_year', 'company_name', 'physical_address'];

    /** Fallbacks so an email never reads "Hello ,". */
    public const FALLBACKS = [
        'first_name' => 'there',
        'full_name'  => 'there',
    ];

    /** @return string[] every distinct tag used in the given text */
    public static function extract($text)
    {
        if (preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', (string) $text, $m) > 0) {
            return array_values(array_unique(array_map('strtolower', $m[1])));
        }
        return [];
    }

    /** @return string[] tags present in $text that this system cannot resolve */
    public static function unknownTags($text)
    {
        $unknown = [];
        foreach (self::extract($text) as $tag) {
            if (!array_key_exists($tag, self::TAGS)) {
                $unknown[] = $tag;
            }
        }
        return $unknown;
    }

    /**
     * Substitute tags.
     *
     * @param string $text
     * @param array  $context tag => value
     * @param bool   $escape  true for HTML parts, false for the text part
     */
    public static function apply($text, array $context, $escape = true)
    {
        $context = self::withDefaults($context);
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($context, $escape) {
            $tag = strtolower($m[1]);
            if (!array_key_exists($tag, $context)) {
                // Unknown or unresolvable: fall back, then to empty string.
                $value = array_key_exists($tag, self::FALLBACKS) ? self::FALLBACKS[$tag] : '';
            } else {
                $value = (string) $context[$tag];
                if ($value === '' && array_key_exists($tag, self::FALLBACKS)) {
                    $value = self::FALLBACKS[$tag];
                }
            }
            // URLs go into href="..." — escaping them is correct there too.
            return $escape ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, (string) $text);
    }

    /** Add tags that are always available. */
    public static function withDefaults(array $context)
    {
        if (!isset($context['current_year'])) {
            $context['current_year'] = gmdate('Y', Clock::time());
        }
        if (!isset($context['full_name'])) {
            $first = isset($context['first_name']) ? trim((string) $context['first_name']) : '';
            $last = isset($context['last_name']) ? trim((string) $context['last_name']) : '';
            $full = trim($first . ' ' . $last);
            if ($full !== '') {
                $context['full_name'] = $full;
            }
        }
        return $context;
    }

    /**
     * Pre-send audit of a campaign's tags.
     *
     * @return array{ok:bool,unknown:string[],unresolved:string[],used:string[]}
     */
    public static function audit($text, array $sampleContext = [])
    {
        $used = self::extract($text);
        $unknown = [];
        $unresolved = [];
        foreach ($used as $tag) {
            if (!array_key_exists($tag, self::TAGS)) {
                $unknown[] = $tag;
                continue;
            }
            if (in_array($tag, self::SYSTEM_TAGS, true)) {
                continue;
            }
            $value = isset($sampleContext[$tag]) ? trim((string) $sampleContext[$tag]) : '';
            if ($value === '' && !array_key_exists($tag, self::FALLBACKS)) {
                $unresolved[] = $tag;
            }
        }
        return [
            'ok'         => $unknown === [],
            'unknown'    => $unknown,
            'unresolved' => array_values(array_unique($unresolved)),
            'used'       => $used,
        ];
    }
}
