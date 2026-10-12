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
            $settings = array();
            try {
                $setting = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', 'Language')
                    ->first();
                $moduleSettings = \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->where('module', 'cloudhost247_i18n')
                    ->get();
                foreach ($moduleSettings as $row) {
                    $settings[$row->setting] = (string) $row->value;
                }
                if (isset($settings['rate_ttl']) && ctype_digit($settings['rate_ttl'])) {
                    $rateTtl = max(300, min(86400, (int) $settings['rate_ttl']));
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
                'detection'       => ch247_i18n_detection_config($settings + array('root' => $root)),
                'cookie'          => ch247_i18n_cookie_config($settings),
            ));

            // Apply ?language= / ?currency= first, then let detection fill in
            // whatever the customer has not already chosen. Detection never
            // overwrites an explicit choice, and it never redirects: the
            // choice is applied to this response, so there is no loop to get
            // stuck in.
            $instance->applyRequest($_GET);

            // Cookies can only be written before the first byte of output.
            $instance->flushCookies();
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

if (!function_exists('ch247_i18n_detection_config')) {
    /**
     * Automatic-detection configuration, from the addon's settings.
     *
     * Every default is the safe one:
     *
     *  - detection is on (it is the point of the feature) but it only ever
     *    fills a gap that a real preference left open;
     *  - no geolocation provider is configured, so no request leaves the
     *    server until an administrator sets one up;
     *  - no proxy is trusted, so `X-Forwarded-For` and `CF-IPCountry` are
     *    ignored until the edge's real address ranges are listed.
     *
     * @param array $settings Values from tbladdonmodules.
     *
     * @return array
     */
    function ch247_i18n_detection_config(array $settings)
    {
        $root = isset($settings['root']) ? (string) $settings['root'] : dirname(__DIR__, 2);
        $splitList = function ($value) {
            $value = trim((string) $value);

            return $value === '' ? array() : preg_split('/[\s,]+/', $value) ?: array();
        };

        $endpoint = isset($settings['geo_endpoint']) ? trim((string) $settings['geo_endpoint']) : '';

        return array(
            'enabled'                    => !isset($settings['detect_enabled'])
                || $settings['detect_enabled'] === 'on'
                || $settings['detect_enabled'] === '1',
            'trusted_proxies'            => $splitList(isset($settings['trusted_proxies']) ? $settings['trusted_proxies'] : ''),
            'country_header'             => isset($settings['country_header']) && trim((string) $settings['country_header']) !== ''
                ? trim((string) $settings['country_header'])
                : 'CF-IPCountry',
            'geo_endpoint'               => $endpoint,
            'geo_field'                  => isset($settings['geo_field']) && trim((string) $settings['geo_field']) !== ''
                ? trim((string) $settings['geo_field'])
                : 'country_code',
            'geo_allowed_hosts'          => $splitList(isset($settings['geo_allowed_hosts']) ? $settings['geo_allowed_hosts'] : ''),
            'geo_timeout'                => isset($settings['geo_timeout']) && (int) $settings['geo_timeout'] > 0
                ? min(10, (int) $settings['geo_timeout'])
                : 2,
            'geo_ttl'                    => isset($settings['geo_ttl']) && (int) $settings['geo_ttl'] >= 60
                ? min(2592000, (int) $settings['geo_ttl'])
                : 86400,
            'geo_cache_path'             => $root . '/modules/addons/cloudhost247_i18n/cache/geo.json',
            // A language tag states a language, not a billing country, so the
            // region hint stays off unless a deployer turns it on.
            'use_region_hint'            => isset($settings['use_region_hint'])
                && ($settings['use_region_hint'] === 'on' || $settings['use_region_hint'] === '1'),
            'country_currency_overrides' => ch247_i18n_country_overrides(isset($settings['country_currency_overrides']) ? $settings['country_currency_overrides'] : ''),
        );
    }
}

if (!function_exists('ch247_i18n_country_overrides')) {
    /**
     * Parse the optional "NG=NGN, DE=EUR" override box from the settings.
     *
     * @param string $raw
     *
     * @return array<string,string[]>
     */
    function ch247_i18n_country_overrides($raw)
    {
        $out = array();
        foreach (explode(',', (string) $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            list($country, $currency) = explode('=', $pair, 2);
            $country = strtoupper(trim($country));
            $currency = strtoupper(trim($currency));
            if (preg_match('/^[A-Z]{2}$/', $country) === 1 && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                $out[$country] = array($currency);
            }
        }

        return $out;
    }
}

if (!function_exists('ch247_i18n_cookie_config')) {
    /**
     * Cookie options for the store that remembers an explicit choice.
     *
     * @param array $settings
     *
     * @return array
     */
    function ch247_i18n_cookie_config(array $settings)
    {
        return array(
            'enabled' => !isset($settings['cookie_enabled']) || $settings['cookie_enabled'] !== 'off',
            'ttl'     => isset($settings['cookie_days']) && (int) $settings['cookie_days'] > 0
                ? min(730, (int) $settings['cookie_days']) * 86400
                : 15552000,
            'path'    => '/',
            'secure'  => null, // Follow the request: Secure over HTTPS only.
        );
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
        // Why this visitor sees this language and currency: 'account',
        // 'stored', 'browser', 'geolocation' or 'default'. Templates use it to
        // explain an automatic choice; tests use it to assert one.
        'ch247Detection'    => $i18n->detection(),
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
    $i18n = ch247_i18n_autoload();
    if ($i18n === null) {
        return '';
    }
    // Idempotent: anything already sent was removed from the queue. This
    // second call catches a preference written during the page render.
    $i18n->flushCookies();
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
