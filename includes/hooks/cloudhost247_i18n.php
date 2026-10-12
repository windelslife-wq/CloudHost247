<?php
/**
 * CloudHost247 — internationalisation & currency WHMCS hooks.
 *
 * This file is the only WHMCS-specific wiring in the subsystem. It:
 *
 *  1. resolves the visitor's language and currency from the request, the
 *     session and (when logged in) their client record;
 *  2. loads the matching translation catalog into `$_LANG`, so every existing
 *     `{$LANG.…}` in the theme keeps working and new pages translate by
 *     simply using `$LANG` keys;
 *  3. builds the `{$ch247Switcher}` view-model the header templates render;
 *  4. injects the switcher's stylesheet and script into <head> / the footer.
 *
 * The two preferences are handled independently throughout: `?language=` never
 * touches the stored currency and `?currency=` never touches the stored
 * language.
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\Whmcs\WhmcsLocale;
use CloudHost247\I18n\EquivalentUrl;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Rate\FileRateCache;

require_once __DIR__ . '/../i18n/autoload.php';

if (!function_exists('ch247_i18n_autoload')) {
    /**
     * Boot the subsystem once per request.
     *
     * @return I18n|null Null when the library is unavailable, in which case
     *                   every hook below becomes a no-op rather than a fatal.
     */
    function ch247_i18n_autoload()
    {
        static $booted = false;
        static $instance = null;

        if ($booted) {
            return $instance;
        }
        $booted = true;

        try {
            $root = dirname(__DIR__, 2);

            $defaultLocale = 'en';
            $rateTtl = 21600;
            try {
                $setting = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', 'Language')
                    ->first();
                $ttl = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'cloudhost247_i18n')->where('setting', 'rate_ttl')->first();
                if ($ttl && ctype_digit((string) $ttl->value)) {
                    $rateTtl = max(300, min(86400, (int) $ttl->value));
                }
                if ($setting && !empty($setting->value)) {
                    // WHMCS stores "english"; our catalogs are keyed "en".
                    $defaultLocale = WhmcsLocale::toCode($setting->value);
                }
            } catch (\Throwable $e) {
                $defaultLocale = 'en';
            }

            $instance = I18n::boot(array(
                'root'            => $root,
                'default_locale'  => $defaultLocale,
                'logger'          => function ($message) {
                    if (function_exists('logActivity')) {
                        logActivity('CloudHost247 i18n: ' . $message);
                    }
                },
                'rate_cache'      => new FileRateCache($root . '/modules/addons/cloudhost247_i18n/cache/rates.json'),
                'rate_ttl'        => $rateTtl,
                'language_value'  => array(WhmcsLocale::class, 'toWhmcsName'),
            ));

            // Apply ?language= / ?currency= and persist the outcome.
            $instance->applyRequest($_GET);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('CloudHost247 i18n boot failed: ' . $e->getMessage());
            }
            $instance = null;
        }

        return $instance;
    }
}

if (!function_exists('ch247_i18n_web_root')) {
    /**
     * @return string
     */
    function ch247_i18n_web_root()
    {
        if (isset($GLOBALS['WEB_ROOT']) && $GLOBALS['WEB_ROOT'] !== '') {
            return rtrim((string) $GLOBALS['WEB_ROOT'], '/');
        }
        if (defined('WHMCS_ROOT_URL')) {
            return rtrim(WHMCS_ROOT_URL, '/');
        }

        return '';
    }
}

if (!function_exists('ch247_i18n_route_map')) {
    /**
     * Localised URL slugs, declared per site.
     *
     * Only paths listed here get translated slugs; everything else keeps its
     * canonical path so no existing link or bookmark can break.
     *
     * @return array
     */
    function ch247_i18n_route_map()
    {
        // No translated-slug routes are installed in this overlay. Keep
        // the real path and use WHMCS's native language query parameter.
        // Deployers may add verified routes here after installing rewrites.
        return array();
    }
}

/* --------------------------------------------------------------- hooks ----- */

add_hook('ClientAreaPage', 1, function (array $vars) {
    $i18n = ch247_i18n_autoload();
    if ($i18n === null) {
        return array();
    }

    // 1. Publish the catalog into $LANG so every template, existing or new,
    //    reads translated strings the same way.
    global $_LANG;
    if (!isset($_LANG) || !is_array($_LANG)) {
        $_LANG = array();
    }
    $messages = $i18n->translator()->getCatalogMessages();
    if ($messages) {
        // WHMCS override files win for keys they define; ours fill the rest.
        $_LANG = array_merge($messages, $_LANG);
    }

    $locale = $i18n->preferences()->getLocale();
    $currency = $i18n->preferences()->getCurrency();
    $rates = $i18n->rates();

    // 2. On an invoice, quote or existing service, the document's own currency
    //    is authoritative and must not be rewritten by the switcher.
    $transactionCurrency = null;
    if (!empty($vars['invoice']['currency'])) {
        $found = $i18n->currencies()->findByCode($vars['invoice']['currency']);
        if ($found === null) {
            $found = $i18n->currencies()->find((int) $vars['invoice']['currency']);
        }
        $transactionCurrency = $found;
    } elseif (!empty($vars['clientsdetails']['currency'])) {
        $transactionCurrency = $i18n->currencies()->find((int) $vars['clientsdetails']['currency']);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    // hreflang needs the *clean* URL, otherwise every locale variant of a page
    // advertises itself with another locale's switcher parameter in it.
    $cleanUri = EquivalentUrl::canonical($uri);

    $switcher = $i18n->switcher(ch247_i18n_route_map(), $cleanUri, $transactionCurrency);
    $transactionNotice = null;
    // Billing documents/core cart stay in their own currency. Do not claim
    // the unchanged invoice has been converted merely because browsing differs.
    $file = isset($vars['filename']) ? $vars['filename'] : pathinfo(parse_url($uri, PHP_URL_PATH), PATHINFO_FILENAME);
    $lockedPage = in_array($file, array('viewinvoice', 'viewquote', 'clientarea', 'cart', 'upgrade', 'masspay'), true);
    if ($lockedPage && $transactionCurrency !== null && $transactionCurrency->getId() !== $currency->getId()) {
        $transactionNotice = $i18n->translator()->trans('currency_locked_help', array('charged' => $transactionCurrency->getCode()));
        if ($switcher['notice'] !== null) {
            $switcher['notice']['message'] = $transactionNotice;
            $switcher['notice']['locked'] = true;
        }
    }

    // 3. Locale-aware snippets templates cannot compute on their own.
    $formatter = $i18n->formatter();
    $ratesUpdated = null;
    if ($rates !== null && $rates->getAsOf()) {
        $ratesUpdated = $formatter->date($rates->getAsOf(), 'medium');
    }

    return array(
        'LANG'             => array_merge(isset($vars['LANG']) && is_array($vars['LANG']) ? $vars['LANG'] : $_LANG, $messages),
        'ch247TransactionNotice' => $transactionNotice,
        'ch247Switcher'     => $switcher,
        'ch247Locale'       => $locale->toArray(),
        'ch247Currency'     => $currency->toArray(),
        'ch247LocaleTag'    => $locale->getIntlTag(),
        'ch247Direction'    => $locale->getDirection(),
        'ch247Alternates'   => ch247_i18n_alternates($switcher),
        'ch247RatesUpdated' => $ratesUpdated,
        'ch247RatesRetrieved' => $rates !== null ? $formatter->dateTime($rates->getRetrievedAt()) : null,
        'ch247RateSource'   => $rates !== null ? $rates->getSource() : null,
    );
});

add_hook('ClientAreaHeadOutput', 1, function () {
    if (ch247_i18n_autoload() === null) {
        return '';
    }
    $root = ch247_i18n_web_root();

    $tags = '<link rel="stylesheet" href="' . htmlspecialchars($root . '/templates/hostx/css/language-currency-switcher.css', ENT_QUOTES, 'UTF-8') . '">';

    return $tags;
});

add_hook('ClientAreaFooterOutput', 1, function () {
    if (ch247_i18n_autoload() === null) {
        return '';
    }
    $root = ch247_i18n_web_root();

    return '<script src="' . htmlspecialchars($root . '/templates/hostx/js/language-currency-switcher.js', ENT_QUOTES, 'UTF-8') . '" defer></script>';
});

if (!function_exists('ch247_i18n_alternates')) {
    /**
     * hreflang data for the current page: one entry per *complete* locale,
     * pointing at the equivalent page in that language.
     *
     * @param array $switcher
     *
     * @return array
     */
    function ch247_i18n_alternates(array $switcher)
    {
        $out = array();
        foreach ($switcher['language']['options'] as $option) {
            $out[] = array(
                'hreflang' => $option['hreflang'],
                'href'     => $option['url'],
                'current'  => !empty($option['selected']),
            );
        }

        return $out;
    }
}
