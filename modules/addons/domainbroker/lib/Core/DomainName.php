<?php
/**
 * Domain Broker — domain name normalisation and classification.
 *
 * Used both to canonicalise what the customer typed and to derive the TLD and
 * registry for the RDAP lookup. Punycode conversion uses intl when present and
 * otherwise leaves an already-ASCII name untouched; a non-ASCII name without
 * intl is rejected rather than stored half-normalised.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class DomainName
{
    /** Multi-label public suffixes we need to treat as one unit. */
    const MULTI_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'co.za', 'org.za', 'net.za', 'web.za',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn',
        'co.in', 'net.in', 'org.in', 'gen.in', 'firm.in', 'ind.in',
        'com.mx', 'com.ar', 'com.tr', 'com.sg', 'com.hk', 'com.tw', 'com.my',
        'com.ng', 'com.gh', 'com.ua', 'com.pl', 'com.ph', 'com.vn', 'com.pk',
        'co.ke', 'co.il', 'co.kr', 'co.id', 'co.th', 'or.ke', 'ne.ke',
    ];

    /**
     * Canonicalise a user-entered domain.
     *
     * Accepts "https://Example.COM/path", "example.com.", "  example.com ".
     * Returns the lowercase ASCII (punycode) registrable name, or null when the
     * input is not a usable domain.
     *
     * @return string|null
     */
    public static function normalise($input)
    {
        $value = trim((string) $input);
        if ($value === '') {
            return null;
        }

        // Strip scheme, credentials, path, query and port.
        if (strpos($value, '//') !== false) {
            $parsed = parse_url($value);
            if (isset($parsed['host'])) {
                $value = $parsed['host'];
            }
        }
        $value = preg_replace('#[/?\#].*$#', '', $value);
        if (($at = strrpos($value, '@')) !== false) {
            $value = substr($value, $at + 1);
        }
        $value = preg_replace('/:\d+$/', '', $value);
        $value = trim($value, " \t\n\r\0\x0B.");
        $value = strtolower($value);

        // People paste "www.example.com" when they mean the registrable name.
        // Only strip it when something meaningful remains underneath.
        if (strncmp($value, 'www.', 4) === 0 && substr_count($value, '.') >= 2) {
            $value = substr($value, 4);
        }

        if ($value === '') {
            return null;
        }

        // An IP address is not a brokerable domain.
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Punycode-encode internationalised labels.
        if (preg_match('/[^\x20-\x7f]/', $value)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || $ascii === '') {
                return null;
            }
            $value = strtolower($ascii);
        }

        if (strlen($value) > 253) {
            return null;
        }

        $labels = explode('.', $value);
        if (count($labels) < 2) {
            return null;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return null;
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return null;
            }
            if (strpos($label, '--') === 2 && strncmp($label, 'xn--', 4) !== 0) {
                return null; // reserved R-LDH label that is not punycode
            }
        }

        $tld = end($labels);
        if (!preg_match('/^(xn--[a-z0-9-]+|[a-z]{2,63})$/', $tld)) {
            return null;
        }

        return $value;
    }

    public static function isValid($input)
    {
        return self::normalise($input) !== null;
    }

    /** Public suffix (best effort, multi-label aware). */
    public static function tld($domain)
    {
        $domain = self::normalise($domain);
        if ($domain === null) {
            return null;
        }
        foreach (self::MULTI_LABEL_SUFFIXES as $suffix) {
            if (substr($domain, -(strlen($suffix) + 1)) === '.' . $suffix) {
                return $suffix;
            }
        }
        $parts = explode('.', $domain);
        return end($parts);
    }

    /** The label immediately left of the public suffix. */
    public static function sld($domain)
    {
        $domain = self::normalise($domain);
        if ($domain === null) {
            return null;
        }
        $tld = self::tld($domain);
        $base = substr($domain, 0, -(strlen($tld) + 1));
        $parts = explode('.', $base);
        return end($parts);
    }

    /** The registrable name (sld + public suffix), stripping any subdomain. */
    public static function registrable($domain)
    {
        $domain = self::normalise($domain);
        if ($domain === null) {
            return null;
        }
        $tld = self::tld($domain);
        $sld = self::sld($domain);
        return $sld . '.' . $tld;
    }

    /** Unicode presentation of a punycode name, for display only. */
    public static function toUnicode($domain)
    {
        if (!function_exists('idn_to_utf8')) {
            return $domain;
        }
        $utf8 = idn_to_utf8((string) $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return $utf8 === false ? $domain : $utf8;
    }

    public static function isIdn($domain)
    {
        return strpos((string) $domain, 'xn--') !== false;
    }

    /** Rough premium signal used only to inform the customer, never pricing. */
    public static function characteristics($domain)
    {
        $sld = (string) self::sld($domain);
        return [
            'length'      => strlen($sld),
            'numeric'     => ctype_digit($sld),
            'hyphenated'  => strpos($sld, '-') !== false,
            'idn'         => self::isIdn($domain),
            'single_word' => preg_match('/^[a-z]+$/', $sld) === 1,
        ];
    }
}
