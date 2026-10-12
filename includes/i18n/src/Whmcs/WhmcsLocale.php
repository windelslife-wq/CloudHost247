<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Bridges WHMCS's own language naming to the catalog codes this subsystem
 * uses.
 *
 * WHMCS names its language files `english`, `french`, `portuguese-br`, … and
 * stores that name in `tblconfiguration.Language` and in `?language=`. Our
 * catalogs are keyed by BCP-47-ish codes (`en`, `fr`, `pt-BR`). Reusing WHMCS's
 * names — rather than inventing a second vocabulary — means an admin who
 * switches the language in WHMCS's own settings moves the whole site with it.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Whmcs;

final class WhmcsLocale
{
    /**
     * WHMCS language name => catalog locale code.
     *
     * @return array<string,string>
     */
    public static function map(): array
    {
        return array(
            'arabic'        => 'ar',
            'azerbaijani'   => 'az',
            'catalan'       => 'ca',
            'chinese'       => 'zh-CN',
            'croatian'      => 'hr',
            'czech'         => 'cs',
            'danish'        => 'da',
            'dutch'         => 'nl',
            'english'       => 'en',
            'estonian'      => 'et',
            'farsi'         => 'fa',
            'french'        => 'fr',
            'german'        => 'de',
            'hebrew'        => 'he',
            'hungarian'     => 'hu',
            'italian'       => 'it',
            'korean'        => 'ko',
            'macedonian'    => 'mk',
            'norwegian'     => 'nb',
            'portuguese-br' => 'pt-BR',
            'portuguese-pt' => 'pt',
            'romanian'      => 'ro',
            'russian'       => 'ru',
            'spanish'       => 'es',
            'swedish'       => 'sv',
            'turkish'       => 'tr',
            'ukranian'      => 'uk',
            'ukrainian'     => 'uk',
        );
    }

    /**
     * WHMCS language name or a locale code -> canonical catalog code.
     *
     * Unknown input is returned unchanged and lower-cased, so a site with a
     * custom WHMCS language still resolves if its catalog uses the same name.
     *
     * @param string $name
     *
     * @return string
     */
    public static function toCode(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $key = strtolower(str_replace(array('_', ' '), '-', $name));
        $map = self::map();

        if (isset($map[$key])) {
            return $map[$key];
        }

        return $key;
    }

    /**
     * Canonical catalog code -> the WHMCS language name, for writing back into
     * `tblclients.language` and for `?language=` links that WHMCS itself
     * understands.
     *
     * @param string $code
     *
     * @return string
     */
    public static function toWhmcsName(string $code): string
    {
        $code = \CloudHost247\I18n\Locale::normalise($code);
        $flipped = array();
        foreach (self::map() as $whmcsName => $localeCode) {
            // Prefer the first (canonical) name for a code: pt-BR, not pt-PT.
            if (!isset($flipped[$localeCode])) {
                $flipped[$localeCode] = $whmcsName;
            }
        }

        return isset($flipped[$code]) ? $flipped[$code] : strtolower($code);
    }
}
