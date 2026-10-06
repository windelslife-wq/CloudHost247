<?php
/**
 * Domain name value object.
 *
 * Split rule: the first dot separates label from TLD, so `my-shop.co.uk`
 * parses to sld=my-shop, tld=co.uk — exactly how registration works.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class DomainName
{
    /** @var string canonical, lower-cased FQDN (punycode when input was IDN) */
    private $fqdn;
    /** @var string */
    private $sld;
    /** @var string */
    private $tld;
    /** @var bool whether IDN conversion was applied */
    private $wasIdn = false;

    private function __construct($fqdn, $sld, $tld)
    {
        $this->fqdn = $fqdn;
        $this->sld = $sld;
        $this->tld = $tld;
    }

    /**
     * @throws ValidationException on any invalid input
     */
    public static function parse($input)
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            throw new ValidationException(['domain' => 'A domain is required.']);
        }
        // tolerate accidental scheme/path — registry answers only care about the host.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw);
        }
        $raw = preg_split('~[/?\#:\s]~', $raw);
        $raw = isset($raw[0]) ? trim(strtolower($raw[0]), " \t\n\r.") : '';
        // A bare host label of www is presentational, never part of the name.
        if (strpos($raw, 'www.') === 0 && substr_count($raw, '.') >= 2) {
            $raw = substr($raw, 4);
        }
        if ($raw === '' || strpos($raw, '.') === false) {
            throw new ValidationException(['domain' => 'Enter a full domain like example.com.']);
        }
        if (!preg_match('/^[a-z0-9.\-]+$/', $raw)) {
            // IDN input — convert to punycode when intl is available.
            if (!function_exists('idn_to_ascii')) {
                throw new ValidationException(['domain' => 'Internationalised domains require PHP intl on this host.']);
            }
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = @idn_to_ascii($raw, 0, $variant);
            if ($ascii === false || $ascii === '') {
                throw new ValidationException(['domain' => 'Unable to convert that internationalised domain.']);
            }
            $raw = strtolower($ascii);
        }
        if (!self::isValidAscii($raw)) {
            throw new ValidationException(['domain' => 'That does not look like a valid domain.']);
        }
        $pos = strpos($raw, '.');
        $sld = substr($raw, 0, $pos);
        $tld = substr($raw, $pos + 1);
        $name = new self($raw, $sld, $tld);
        return $name;
    }

    /** Soft-parse: null instead of throwing. */
    public static function tryParse($input)
    {
        try {
            return self::parse($input);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function isValidAscii($fqdn)
    {
        if (strlen($fqdn) > 253) {
            return false;
        }
        if (strpos($fqdn, '..') !== false) {
            return false;
        }
        $labels = explode('.', $fqdn);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return true;
    }

    public function fqdn()    { return $this->fqdn; }
    public function sld()     { return $this->sld; }
    public function tld()     { return $this->tld; }
    public function labelLength() { return strlen($this->sld); }
    public function hasHyphen()   { return strpos($this->sld, '-') !== false; }
    public function hasDigit()    { return (bool) preg_match('/\d/', $this->sld); }

    /** Rough pronounceability proxy used by the rules engine. */
    public function hasVowelFlow()
    {
        if (preg_match('/[aeiou]{2,}/', $this->sld)) {
            return true;
        }
        // consonant:value promise — at least one vowel between consonants
        return (bool) preg_match('/[a-z][aeiou][a-z]/', $this->sld);
    }

    public function __toString()
    {
        return $this->fqdn;
    }
}
