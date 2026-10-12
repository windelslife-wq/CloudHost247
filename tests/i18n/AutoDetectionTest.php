<?php
/**
 * CloudHost247 — automatic language & currency detection.
 *
 * The acceptance suite for automatic localisation. It walks the ten scenarios
 * in the requirement, end to end through the real I18n entry point — real
 * LocaleRegistry over the shipped catalogs, real CurrencySelector, real
 * preference chain (session + account + cookie), real Detector.
 *
 * Nothing here asserts on a mock of the thing under test: the only fakes are
 * the data sources that cannot exist in a checkout (a WHMCS database and an
 * IP geolocation provider), and both are injected through the same
 * interfaces production uses.
 *
 * Run: php tests/i18n/AutoDetectionTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\CatalogRepository;
use CloudHost247\I18n\CurrencyRegistry;
use CloudHost247\I18n\Detection\AcceptLanguage;
use CloudHost247\I18n\Detection\ClientIp;
use CloudHost247\I18n\Detection\CountryCurrencyMap;
use CloudHost247\I18n\Detection\DetectionResult;
use CloudHost247\I18n\Detection\GeoLocatorInterface;
use CloudHost247\I18n\Detection\HttpGeoLocator;
use CloudHost247\I18n\Detection\RequestContext;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\LocaleRegistry;
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\Preference\ChainedPreferenceStore;
use CloudHost247\I18n\Preference\CookiePreferenceStore;
use CloudHost247\I18n\StaticCurrencySource;

/* ---------------------------------------------------------------- helpers -- */

/**
 * @return LocaleRegistry
 */
function ch247_locales()
{
    return new LocaleRegistry(new CatalogRepository(ch247_catalog_dir()), 'en');
}

/**
 * @return CurrencyRegistry
 */
function ch247_registry()
{
    return new CurrencyRegistry(new StaticCurrencySource(ch247_fixture_currencies(), 'test fixture'));
}

/**
 * A cookie store with the same server-side allowlists production installs.
 *
 * @param array         $cookies
 * @param callable|null $sender
 *
 * @return CookiePreferenceStore
 */
function ch247_cookie_store(array $cookies = array(), $sender = null)
{
    $locales = ch247_locales();
    $registry = ch247_registry();

    return new CookiePreferenceStore(
        $cookies,
        array('ttl' => 15552000, 'path' => '/', 'secure' => false),
        array(
            'language' => function ($value) use ($locales) {
                $locale = $locales->find((string) $value);

                return $locale !== null && $locale->isSelectable() ? $locale->getCode() : null;
            },
            'currency' => function ($value) use ($registry) {
                $currency = $registry->find($value);

                return $currency !== null ? (string) $currency->getId() : null;
            },
        ),
        $sender
    );
}

/**
 * Boot one simulated visitor.
 *
 * @param array $visitor ip, accept_language, cookie, country, trusted_proxies,
 *                       geo (locator), store, detection overrides.
 *
 * @return I18n
 */
function ch247_visit(array $visitor)
{
    $request = RequestContext::fixture($visitor);

    if (isset($visitor['geo'])) {
        $geo = $visitor['geo'] instanceof \Closure ? $visitor['geo']($request) : $visitor['geo'];
    } else {
        $geo = ch247_geo_locator(ch247_fixture_countries());
    }

    $options = array(
        'request'   => $request,
        'detection' => array_merge(
            array('geo' => $geo),
            isset($visitor['detection']) && is_array($visitor['detection']) ? $visitor['detection'] : array()
        ),
        'cookie'    => array(
            'ttl'    => 15552000,
            'path'   => '/',
            'secure' => false,
            'sender' => isset($visitor['cookie_sender']) ? $visitor['cookie_sender'] : null,
        ),
    );

    if (isset($visitor['store'])) {
        $options['store'] = $visitor['store'];
    }

    return ch247_make_i18n_for($options);
}

/**
 * A store chain that behaves like a real request: session, then account, then
 * cookie. Pass `session` and/or `account` arrays to seed them.
 *
 * @param array $session
 * @param array $account
 * @param array $cookies
 * @param callable|null $sender
 *
 * @return ChainedPreferenceStore
 */
function ch247_store_chain(array $session = array(), array $account = array(), array $cookies = array(), $sender = null)
{
    return new ChainedPreferenceStore(array(
        new ArrayPreferenceStore($session, true, 'session'),
        new ArrayPreferenceStore($account, true, 'account'),
        ch247_cookie_store($cookies, $sender),
    ));
}

/* =========================================================== scenario 1 ==== */

section('1. first-time visitor, English browser, no saved preferences');

$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'accept_language' => 'en-US,en;q=0.9',
    'geo'             => ch247_geo_locator(array()), // no geolocation configured
));

T::is('the language is English', 'en', $i18n->preferences()->getLanguage());
T::is('the language came from the browser header', DetectionResult::SOURCE_BROWSER, $i18n->preferences()->getLanguageSource());
T::is('the currency is the shop default', 'NGN', $i18n->preferences()->getCurrency()->getCode());
T::is('the currency source is the default', DetectionResult::SOURCE_DEFAULT, $i18n->preferences()->getCurrencySource());
T::is('no country was resolved', null, $i18n->detector()->country());
T::ok('the page still knows it is a fresh visitor', $i18n->detection()['language']['detected'] === true);

// Same visitor, but with no Accept-Language at all.
$i18n = ch247_visit(array('ip' => '203.0.113.7', 'geo' => ch247_geo_locator(array())));
T::is('with no header the default language is used', 'en', $i18n->preferences()->getLanguage());
T::is('and the reason says why', 'no_accept_language_header', $i18n->preferences()->resolveLanguage()->getReason());

// Detection disabled entirely: everything falls back to the defaults.
$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'accept_language' => 'fr-FR,fr;q=0.9',
    'detection'       => array('enabled' => false),
));
T::is('detection can be switched off', null, $i18n->detector());
T::is('and then the default language wins', 'en', $i18n->preferences()->getLanguage());
T::is('with the default currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());

/* =========================================================== scenario 2 ==== */

section('2. first-time visitor whose browser prefers another supported language');

foreach (array(
    'fr-FR,fr;q=0.9,en;q=0.4' => 'fr',
    'de-DE,de;q=0.9'          => 'de',
    'es-419,es;q=0.8'         => 'es',
    'pt-BR,pt;q=0.9,en;q=0.2' => 'pt-BR',
    'DE_de'                   => 'de',
) as $header => $expected) {
    $i18n = ch247_visit(array('ip' => '203.0.113.7', 'accept_language' => (string) $header, 'geo' => ch247_geo_locator(array())));
    T::is('[' . $header . '] resolves to ' . $expected, $expected, $i18n->preferences()->getLanguage());
    T::is('[' . $header . '] is credited to the browser', DetectionResult::SOURCE_BROWSER, $i18n->preferences()->getLanguageSource());
}

// A regional variant with no catalog of its own is not guessed at. Brazilian
// Portuguese is not European Portuguese, and serving one to a reader of the
// other is worse than serving English, which they can see is not theirs and
// change in one click.
$i18n = ch247_visit(array('ip' => '203.0.113.7', 'accept_language' => 'pt-PT,pt;q=0.9', 'geo' => ch247_geo_locator(array())));
T::is('pt-PT is not silently mapped to pt-BR', 'en', $i18n->preferences()->getLanguage());
T::is('and the fallback is reported as such', 'no_supported_language', $i18n->preferences()->resolveLanguage()->getReason());

// Quality ordering, not document order, decides.
$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'accept_language' => 'en;q=0.3,de;q=0.9,fr;q=0.6',
    'geo'             => ch247_geo_locator(array()),
));
T::is('the highest quality supported language wins', 'de', $i18n->preferences()->getLanguage());

// Only unsupported languages requested: English, never a half-built locale.
$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'accept_language' => 'ja-JP,ja;q=0.9,ko;q=0.5',
    'geo'             => ch247_geo_locator(array()),
));
T::is('an unsupported language falls back to English', 'en', $i18n->preferences()->getLanguage());
T::is('and says so', 'no_supported_language', $i18n->preferences()->resolveLanguage()->getReason());

// A language that exists as a catalog but is not finished is not "supported".
$partial = new \CloudHost247\I18n\Locale('it', 'Italian', 'Italiano', 'it', 'ltr', \CloudHost247\I18n\Locale::STATUS_PARTIAL);
$locales = new LocaleRegistry(new CatalogRepository(ch247_catalog_dir()), 'en', array($partial));
$detector = new \CloudHost247\I18n\Detection\Detector(
    $locales,
    ch247_registry(),
    RequestContext::fixture(array('accept_language' => 'it-IT,it;q=0.9'))
);
T::is('a partial catalog is never auto-selected', 'en', $detector->detectLanguage()->getValue()->getCode());

/* =========================================================== scenario 3 ==== */

section('3. visitor from a country with a non-default supported currency');

foreach (array(
    'GB' => 'GBP',
    'US' => 'USD',
    'DE' => 'EUR',
    'FR' => 'EUR',
    'NG' => 'NGN',
    'JP' => 'JPY',
) as $country => $expected) {
    $ip = array_search($country, ch247_fixture_countries(), true);
    $i18n = ch247_visit(array('ip' => $ip, 'accept_language' => 'en-US,en;q=0.9'));
    T::is('[' . $country . '] is offered ' . $expected, $expected, $i18n->preferences()->getCurrency()->getCode());
    T::is('[' . $country . '] came from geolocation', DetectionResult::SOURCE_GEOLOCATION, $i18n->preferences()->getCurrencySource());
    T::is('[' . $country . '] resolved the country too', $country, $i18n->detector()->country()->getCountry());
}

// Many countries share a currency; that is a feature, not a bug.
$map = new CountryCurrencyMap();
T::is('Germany and France share the euro', array('EUR'), $map->candidates('DE'));
T::is('and so do twenty countries', array('EUR'), $map->candidates('NL'));
T::is('the CFA franc spans a region', array('XOF'), $map->candidates('SN'));
T::is('Panama is offered the US dollar first', array('USD', 'PAB'), $map->candidates('PA'));
T::ok('the table covers the world', $map->size() > 200);

/* =========================================================== scenario 4 ==== */

section('4. unknown country, or one whose currency is not enabled');

// (a) The IP is not in the provider's data at all.
$i18n = ch247_visit(array('ip' => '203.0.113.250', 'accept_language' => 'en'));
T::is('an unknown country yields no location', null, $i18n->detector()->country());
T::is('and the default currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());
T::is('for the documented reason', 'no_geolocation', $i18n->preferences()->resolveCurrency()->getReason());

// (b) The country is known but the shop does not sell in its currency (INR, CAD).
foreach (array('IN' => 'INR', 'CA' => 'CAD') as $country => $code) {
    $ip = array_search($country, ch247_fixture_countries(), true);
    $i18n = ch247_visit(array('ip' => $ip, 'accept_language' => 'en'));
    T::is('[' . $country . '] resolves the country', $country, $i18n->detector()->country()->getCountry());
    T::is('[' . $country . '] falls back to the default currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());
    T::is('[' . $country . '] says why', 'no_enabled_currency_for_country', $i18n->preferences()->resolveCurrency()->getReason());
    T::is('[' . $country . '] recorded what it looked for', $code, implode('/', $i18n->detection()['currency']['evidence']['candidates']));
}

// (c) Antarctica has no universal currency in ISO 4217.
$ip = array_search('AQ', ch247_fixture_countries(), true);
$i18n = ch247_visit(array('ip' => $ip, 'accept_language' => 'en'));
T::is('an unrecognised country still resolves', 'AQ', $i18n->detector()->country()->getCountry());
T::is('and uses the default currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());
T::is('because ISO 4217 gives it no currency', 'no_currency_for_country', $i18n->preferences()->resolveCurrency()->getReason());

// (d) The provider's own "unknown" value must never be treated as a country.
$ip = array_search('XX', ch247_fixture_countries(), true);
$i18n = ch247_visit(array('ip' => $ip, 'accept_language' => 'en'));
T::is('Cloudflare\'s XX is rejected', null, $i18n->detector()->country());
T::is('so the default currency is used', 'NGN', $i18n->preferences()->getCurrency()->getCode());

// (e) An overridden mapping is honoured, but only for enabled currencies.
$i18n = ch247_visit(array(
    'ip'        => '198.51.100.13', // IN
    'detection' => array('country_currency_overrides' => array('IN' => 'EUR')),
));
T::is('a configured override applies', 'EUR', $i18n->preferences()->getCurrency()->getCode());
$i18n = ch247_visit(array(
    'ip'        => '198.51.100.13',
    'detection' => array('country_currency_overrides' => array('IN' => 'XYZ')),
));
T::is('an override naming a disabled currency is ignored', 'NGN', $i18n->preferences()->getCurrency()->getCode());

/* =========================================================== scenario 5 ==== */

section('5. returning visitor with a saved language and currency');

$i18n = ch247_visit(array(
    'ip'              => '192.0.2.44',      // US -> USD if detected
    'accept_language' => 'fr-FR,fr;q=0.9',  // fr if detected
    'cookie'          => array('ch247_language' => 'de', 'ch247_currency' => '4'), // GBP
));

T::is('the saved language wins over the browser', 'de', $i18n->preferences()->getLanguage());
T::is('and is reported as a saved preference', DetectionResult::SOURCE_STORED, $i18n->preferences()->getLanguageSource());
T::is('the saved currency wins over geolocation', 'GBP', $i18n->preferences()->getCurrency()->getCode());
T::is('and is reported as a saved preference', DetectionResult::SOURCE_STORED, $i18n->preferences()->getCurrencySource());
T::ok('neither is described as detected', $i18n->detection()['language']['detected'] === false
    && $i18n->detection()['currency']['detected'] === false);

// A detected language from earlier in the same session is reused...
$store = ch247_store_chain(array('detected_language' => 'es', 'detected_currency' => 3));
$i18n = ch247_visit(array(
    'ip'              => '192.0.2.44',
    'accept_language' => 'ja',
    'store'           => $store,
));
T::is('a previous detection is reused for the language', 'es', $i18n->preferences()->getLanguage());
T::is('and for the currency', 'EUR', $i18n->preferences()->getCurrency()->getCode());

// ...but an explicit preference still beats it.
$store = ch247_store_chain(array('detected_language' => 'es', 'detected_currency' => 3, 'currency' => 4));
$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'ja', 'store' => $store));
T::is('an explicit currency beats a stored detection', 'GBP', $i18n->preferences()->getCurrency()->getCode());

/* =========================================================== scenario 6 ==== */

section('6. logged-in customer whose account preferences differ from browser and IP');

$store = ch247_store_chain(
    array(),                                                 // session: nothing
    array('language' => 'es', 'currency' => 3),              // account: Spanish + EUR
    array('ch247_language' => 'fr', 'ch247_currency' => '2') // cookie: French + USD
);

$i18n = ch247_visit(array(
    'ip'              => '192.0.2.44',     // US  -> USD
    'accept_language' => 'de-DE,de;q=0.9', // German
    'store'           => $store,
));

T::is('the account language wins', 'es', $i18n->preferences()->getLanguage());
T::is('and is credited to the account', DetectionResult::SOURCE_ACCOUNT, $i18n->preferences()->getLanguageSource());
T::is('the account currency wins', 'EUR', $i18n->preferences()->getCurrency()->getCode());
T::is('and is credited to the account', DetectionResult::SOURCE_ACCOUNT, $i18n->preferences()->getCurrencySource());

// A detected value must never outrank the account, either.
$store = ch247_store_chain(array('detected_language' => 'fr', 'detected_currency' => 2), array('currency' => 3));
$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'de', 'store' => $store));
T::is('a detected currency never outranks the account currency', 'EUR', $i18n->preferences()->getCurrency()->getCode());
T::is('and is still credited to the account', DetectionResult::SOURCE_ACCOUNT, $i18n->preferences()->getCurrencySource());

// A choice made moments ago in this session is the customer speaking now, so
// it does outrank the stored account value.
$store = ch247_store_chain(array('language' => 'fr', 'currency' => 2), array('currency' => 3));
$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'de', 'store' => $store));
T::is('a choice made this visit outranks the saved account currency', 'USD', $i18n->preferences()->getCurrency()->getCode());

// A guest choice does survive login when the account has no preference.
$store = ch247_store_chain(array(), array(), array('ch247_language' => 'pt-BR', 'ch247_currency' => '2'));
$i18n = ch247_visit(array('ip' => '203.0.113.7', 'accept_language' => 'de', 'store' => $store));
T::is('an anonymous choice survives into a logged-in session', 'pt-BR', $i18n->preferences()->getLanguage());
T::is('including the currency', 'USD', $i18n->preferences()->getCurrency()->getCode());

/* =========================================================== scenario 7 ==== */

section('7. customer changes language and currency, then navigates elsewhere');

$recorder = ch247_cookie_recorder();
$sender = $recorder->sender();

$i18n = ch247_visit(array(
    'ip'              => '198.51.100.4',   // GB
    'accept_language' => 'fr-FR,fr;q=0.9',
    'store'           => ch247_store_chain(array(), array(), array(), $sender),
    'cookie_sender'   => $sender,
));
T::is('before the switch, detection applies', 'fr', $i18n->preferences()->getLanguage());
T::is('and the detected currency is offered', 'GBP', $i18n->preferences()->getCurrency()->getCode());

// The customer clicks German and NGN in the switcher.
$applied = $i18n->applyRequest(array('language' => 'de', 'currency' => 1));
T::ok('the language switch succeeded', $applied['language']->isOk());
T::ok('the currency switch succeeded', $applied['currency']->isOk());
T::is('the language changed at once', 'de', $i18n->preferences()->getLanguage());
T::is('the currency changed at once', 'NGN', $i18n->preferences()->getCurrency()->getCode());

$i18n->flushCookies();
T::is('the language choice was written to a cookie', 'de', $recorder->sent['ch247_language']['value']);
T::is('the currency choice was written to a cookie', '1', $recorder->sent['ch247_currency']['value']);
T::ok('the cookie is HttpOnly', $recorder->sent['ch247_language']['options']['httponly'] === true);
T::is('and SameSite=Lax', 'Lax', $recorder->sent['ch247_language']['options']['samesite']);
T::is('and scoped to the whole site', '/', $recorder->sent['ch247_language']['options']['path']);

$cookies = array(
    'ch247_language' => $recorder->sent['ch247_language']['value'],
    'ch247_currency' => $recorder->sent['ch247_currency']['value'],
);

// Next page, next request: a completely different browser and country.
$i18n = ch247_visit(array(
    'ip'              => '192.0.2.44',     // US  -> USD
    'accept_language' => 'fr-FR,fr;q=0.9', // French
    'cookie'          => $cookies,
));
T::is('the explicit language survives the next page', 'de', $i18n->preferences()->getLanguage());
T::is('the explicit currency survives the next page', 'NGN', $i18n->preferences()->getCurrency()->getCode());
T::is('the language is not described as detected', DetectionResult::SOURCE_STORED, $i18n->preferences()->getLanguageSource());
T::is('nor is the currency', DetectionResult::SOURCE_STORED, $i18n->preferences()->getCurrencySource());

// Detection is not even asked once an explicit choice exists: point the
// locator at a country that would change everything and nothing moves.
$i18n = ch247_visit(array('ip' => '198.51.100.9', 'accept_language' => 'es', 'cookie' => $cookies));
T::is('a later visit in another country keeps the language', 'de', $i18n->preferences()->getLanguage());
T::is('and keeps the currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());

// Choosing again replaces the previous choice rather than appending to it.
$recorder2 = ch247_cookie_recorder();
$i18n = ch247_visit(array(
    'ip'            => '198.51.100.9',
    'cookie'        => $cookies,
    'store'         => ch247_store_chain(array(), array(), $cookies, $recorder2->sender()),
    'cookie_sender' => $recorder2->sender(),
));
$i18n->applyRequest(array('language' => 'pt-BR', 'currency' => 3));
$i18n->flushCookies();
T::is('the new language replaced the old one', 'pt-BR', $recorder2->sent['ch247_language']['value']);
T::is('the new currency replaced the old one', '3', $recorder2->sent['ch247_currency']['value']);

/* =========================================================== scenario 8 ==== */

section('8. switching currency through cart and checkout');

$prices = array(
    'starter' => array('NGN' => 250000, 'USD' => 2500, 'EUR' => 2300),
    'vps'     => array('NGN' => 1200000),
);

// The catalogue amounts are per-currency prices, so switching currency must
// change the *number* as well as the symbol.
$i18n = ch247_visit(array('ip' => '203.0.113.7', 'accept_language' => 'en'));
$resolver = $i18n->prices();
$ngn = $resolver->resolve($prices['starter'], $i18n->preferences()->getCurrency());
T::is('the Naira price is exact', 'NGN', $ngn->getDisplayCurrency());
T::ok('and is not an estimate', $ngn->isExact());
T::is('and carries the real catalogue amount', 250000.0, $ngn->getMoney()->toFloat(), 0.001);

$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'en'));
$usd = $i18n->prices()->resolve($prices['starter'], $i18n->preferences()->getCurrency());
T::is('the US price is the US catalogue price', 'USD', $usd->getDisplayCurrency());
T::ok('and is exact, not converted', $usd->isExact());
T::is('and is a different number, not the same one with a $ in front', 2500.0, $usd->getMoney()->toFloat(), 0.001);

// A product priced only in the base currency: conversion is allowed but must
// be labelled as an estimate, using the configured rate.
$gbp = $i18n->prices()->resolve($prices['vps'], $i18n->currencies()->findByCode('GBP'));
T::is('an unpriced currency is converted', 'GBP', $gbp->getDisplayCurrency());
T::ok('and flagged as indicative', $gbp->isIndicative());
T::is('using the configured WHMCS rate', 1200000 * 0.00051, $gbp->getMoney()->toFloat(), 0.001);
T::is('while the amount that will really be charged stays in NGN', 'NGN', $gbp->getTransactionCurrency());

// A currency with no rate is never converted into a number.
$jpy = $i18n->prices()->resolve($prices['vps'], $i18n->currencies()->findByCode('JPY'));
T::ok('an unrateable currency yields no amount', !$jpy->isAvailable());

// Guest cart: WHMCS prices a guest's cart from $_SESSION['currency'], which is
// exactly the key the session store mirrors.
$_SESSION = array();
$sessionStore = new \CloudHost247\I18n\Preference\SessionPreferenceStore();
$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'en'));
$sessionStore->set('currency', $i18n->preferences()->getCurrency()->getId());
T::is('the guest cart currency follows the selection', 2, $_SESSION['currency']);
$sessionStore->set('language', $i18n->preferences()->getLanguage());
T::is('and so does the WHMCS language key', 'english', $_SESSION['Language']);
unset($_SESSION);

// A currency switch must never rewrite the currency of an existing document.
$view = $i18n->switcher(array(), '/viewinvoice.php?id=1042', $i18n->currencies()->findByCode('EUR'));
T::is('the invoice keeps its own currency', 'EUR', $view['notice']['charged_in']);
T::is('the browsing currency is shown as the display currency', 'USD', $view['notice']['displayed_in']);
T::is('every option but the transaction one is flagged locked', 4, count(array_filter($view['currency']['options'], function ($option) {
    return $option['locked'];
})));
T::ok('the notice says the document total does not change', strpos($view['notice']['message'], 'EUR') !== false);

// Product pages: the presenter renders the browsing currency but always names
// the currency the order will actually be charged in.
$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'en')); // GB -> GBP
$presenter = new \CloudHost247\I18n\Whmcs\ProductPricePresenter($i18n);
$product = array('pricing' => array(
    'NGN' => array('monthly' => '250000'),
    'USD' => array('monthly' => '2500'),
));
$out = $presenter->present($product, $i18n->currencies()->findByCode('USD'));
T::is('the browsing currency is GBP', 'GBP', $i18n->preferences()->getCurrency()->getCode());
T::is('but the charge currency is the one the order will use', 2, $out['charge_ids']['monthly']);
T::contains('and the page says so', 'ch247-price-note', $out['formatted']['monthly']);
T::contains('naming the amount that will be charged', 'billed in USD', $out['formatted']['monthly']);

// A currency the product is not priced in and that has no rate gets nothing
// rather than a made-up number.
$out = $presenter->present(array('pricing' => array('NGN' => array('monthly' => '250000'))), $i18n->currencies()->findByCode('JPY'));
T::is('an unrateable charge currency shows no price', -1, $out['raw']['monthly']);

/* =========================================================== scenario 9 ==== */

section('9. geolocation or exchange-rate service unavailable');

/** A locator that always throws, like a provider that has gone away. */
final class Ch247BrokenGeoLocator implements GeoLocatorInterface
{
    public function locate(string $ip)
    {
        throw new \RuntimeException('connection refused');
    }

    public function sourceName(): string
    {
        return 'broken provider';
    }
}

$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'de', 'geo' => new Ch247BrokenGeoLocator()));
T::is('a failing provider does not break the page', 'de', $i18n->preferences()->getLanguage());
T::is('and the default currency is used', 'NGN', $i18n->preferences()->getCurrency()->getCode());
T::is('with the documented reason', 'no_geolocation', $i18n->preferences()->resolveCurrency()->getReason());

// A chain keeps working when one of its sources dies.
$chain = new \CloudHost247\I18n\Detection\ChainedGeoLocator(array(
    new Ch247BrokenGeoLocator(),
    ch247_geo_locator(array('198.51.100.4' => 'GB')),
));
T::is('the next source in the chain is used', 'GB', $chain->locate('198.51.100.4')->getCountry());
T::contains('the failure is recorded', 'broken provider', $chain->failures());

// An HTTP provider that times out, is unreachable or answers nonsense.
foreach (array(
    'timeout'    => function () { throw new \RuntimeException('timeout'); },
    'empty body' => function () { return ''; },
    'html'       => function () { return '<html>nope</html>'; },
    'no country' => function () { return '{"ip":"198.51.100.4"}'; },
    'bad shape'  => function () { return '{"country_code":{"nested":true}}'; },
) as $label => $fetcher) {
    $locator = new HttpGeoLocator(
        array('endpoint' => 'https://geo.example.test/{ip}', 'allowed_hosts' => array('geo.example.test')),
        $fetcher
    );
    T::is('[' . $label . '] yields no country', null, $locator->locate('198.51.100.4'));
}

// A working provider is parsed and validated.
$locator = new HttpGeoLocator(
    array('endpoint' => 'https://geo.example.test/{ip}', 'allowed_hosts' => array('geo.example.test')),
    function () { return '{"country_code":"GB","ip":"198.51.100.4"}'; }
);
T::is('a valid answer is used', 'GB', $locator->locate('198.51.100.4')->getCountry());

// The endpoint is dialled once per IP, not once per question.
$calls = 0;
$counting = function () use (&$calls) { $calls++; return '{"country_code":"GB"}'; };
$locator = new HttpGeoLocator(
    array('endpoint' => 'https://geo.example.test/{ip}', 'allowed_hosts' => array('geo.example.test')),
    $counting
);
$cached = new \CloudHost247\I18n\Detection\CachedGeoLocator($locator, new \CloudHost247\I18n\Detection\ArrayGeoCache(), 3600);
$cached->locate('198.51.100.4');
$cached->locate('198.51.100.4');
$cached->locate('198.51.100.4');
T::is('the provider is asked once', 1, $calls);

// No endpoint configured means no request at all.
$unconfigured = new HttpGeoLocator(array('endpoint' => '', 'allowed_hosts' => array('geo.example.test')), $counting);
T::is('an unconfigured provider is never called', null, $unconfigured->locate('198.51.100.4'));
T::is('and says it is not configured', false, $unconfigured->isConfigured());

// A host outside the allowlist is refused even with an endpoint set.
$rogue = new HttpGeoLocator(
    array('endpoint' => 'https://evil.example.test/{ip}', 'allowed_hosts' => array('geo.example.test')),
    $counting
);
T::is('an endpoint outside the allowlist is refused', null, $rogue->locate('198.51.100.4'));

// Plain HTTP is refused unless explicitly allowed.
$insecure = new HttpGeoLocator(array('endpoint' => 'http://geo.example.test/{ip}', 'allowed_hosts' => array('geo.example.test')), $counting);
T::is('plain HTTP is refused by default', null, $insecure->locate('198.51.100.4'));

// Exchange rates: no rate means no number, never an invented rate.
$i18n = ch247_visit(array('ip' => '203.0.113.7'));
$emptyRates = new \CloudHost247\I18n\PriceResolver(
    ch247_registry(),
    new \CloudHost247\I18n\RateTable('NGN', array(), 'unavailable')
);
T::ok('without rates nothing is converted', !$emptyRates->resolve(array('NGN' => 1200000), $i18n->currencies()->findByCode('GBP'))->isAvailable());
T::is('and the price stays in its own currency', 'NGN', $emptyRates->resolve(array('NGN' => 1200000), $i18n->currencies()->findByCode('NGN'))->getDisplayCurrency());

/* ========================================================== scenario 10 ==== */

section('10. unsupported language, invalid currency, untrusted parameters');

// Unsupported / hostile language parameters leave the language alone.
$base = array('ip' => '203.0.113.7', 'accept_language' => 'de', 'cookie' => array('ch247_language' => 'de'));
foreach (array('klingon', '../../etc/passwd', '<script>alert(1)</script>', 'zz-ZZ', '', 'en_US" onload="x') as $bad) {
    $i18n = ch247_visit($base);
    $result = $i18n->applyRequest(array('language' => $bad));
    T::ok('[' . $bad . '] is refused', !$result['language']->isOk());
    T::is('[' . $bad . '] leaves the language untouched', 'de', $i18n->preferences()->getLanguage());
}

// Invalid currency parameters leave the currency alone.
foreach (array('99999', 'XYZ', '0', '-1', '1;DROP TABLE', 'NaN') as $bad) {
    $i18n = ch247_visit(array('ip' => '203.0.113.7', 'cookie' => array('ch247_currency' => '4')));
    $result = $i18n->applyRequest(array('currency' => $bad));
    T::ok('[' . $bad . '] is refused', !$result['currency']->isOk());
    T::is('[' . $bad . '] leaves the currency untouched', 'GBP', $i18n->preferences()->getCurrency()->getCode());
}

// Arbitrary client-supplied parameters are not a channel into this feature.
$i18n = ch247_visit(array('ip' => '192.0.2.44', 'accept_language' => 'de', 'cookie' => array('ch247_currency' => '4')));
$i18n->applyRequest(array(
    'country'  => 'US',
    'lang'     => 'fr',
    'cur'      => 'EUR',
    'locale'   => 'fr-FR',
    'geo'      => 'GB',
    'currency' => '4',
));
T::is('an untrusted ?country= is ignored', 'GBP', $i18n->preferences()->getCurrency()->getCode());
T::is('an untrusted ?lang= is ignored', 'de', $i18n->preferences()->getLanguage());

// A tampered cookie is treated exactly like no cookie.
$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'fr', 'cookie' => array('ch247_language' => 'klingon', 'ch247_currency' => '99999')));
T::is('a forged language cookie is ignored', 'fr', $i18n->preferences()->getLanguage());
T::is('a forged currency cookie is ignored', 'GBP', $i18n->preferences()->getCurrency()->getCode());

$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'fr', 'cookie' => array('ch247_language' => '../../../etc/passwd', 'ch247_currency' => '1 OR 1=1')));
T::is('a path-traversal cookie is ignored', 'fr', $i18n->preferences()->getLanguage());
T::is('an SQL-shaped cookie is ignored', 'GBP', $i18n->preferences()->getCurrency()->getCode());

// The cookie store never persists anything but the two explicit keys.
$store = ch247_cookie_store();
$store->set('detected_language', 'fr');
$store->set('detected_currency', 3);
$store->set('country', 'DE');
T::is('detected values are not cookie material', array(), $store->pending());

/* ------------------------------------------------------- trusted proxies -- */

section('10b. forwarded headers are only believed from a trusted proxy');

// Direct connection: the visitor's own X-Forwarded-For is worthless.
$context = RequestContext::fixture(array(
    'ip'              => '203.0.113.7',
    'server'          => array('HTTP_X_FORWARDED_FOR' => '198.51.100.4, 8.8.8.8'),
    'trusted_proxies' => array(),
));
T::is('an untrusted XFF is ignored', '203.0.113.7', $context->clientIp());
T::is('and the proxy is not trusted', false, $context->cameFromTrustedProxy());

// Behind a configured proxy: the right-most untrusted hop is the client.
$context = RequestContext::fixture(array(
    'ip'              => '10.0.0.1',
    'server'          => array('HTTP_X_FORWARDED_FOR' => '203.0.113.7, 198.51.100.4, 10.0.0.2'),
    'trusted_proxies' => array('10.0.0.0/8'),
));
T::is('our own hops are skipped', '198.51.100.4', $context->clientIp());
T::is('and the proxy is trusted', true, $context->cameFromTrustedProxy());

// Cloudflare's single-address header wins when present.
$context = RequestContext::fixture(array(
    'ip'              => '10.0.0.1',
    'server'          => array(
        'HTTP_CF_CONNECTING_IP' => '198.51.100.9',
        'HTTP_X_FORWARDED_FOR'  => '203.0.113.7',
    ),
    'trusted_proxies' => array('10.0.0.0/8'),
));
T::is('CF-Connecting-IP leads', '198.51.100.9', $context->clientIp());

// A country header is only believed when the peer is trusted.
$edgeOnly = function ($request) {
    return new \CloudHost247\I18n\Detection\ChainedGeoLocator(array(
        new \CloudHost247\I18n\Detection\HeaderGeoLocator($request, 'CF-IPCountry'),
        ch247_geo_locator(array()), // no database fallback, for a clean assertion
    ));
};

$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'country'         => 'DE',
    'accept_language' => 'en',
    'trusted_proxies' => array(),
    'geo'             => $edgeOnly,
));
T::is('an untrusted country header is ignored', null, $i18n->detector()->country());
T::is('so the default currency stands', 'NGN', $i18n->preferences()->getCurrency()->getCode());

$i18n = ch247_visit(array(
    'ip'              => '10.0.0.9',
    'country'         => 'DE',
    'accept_language' => 'en',
    'trusted_proxies' => array('10.0.0.0/8'),
    'geo'             => $edgeOnly,
));
T::is('a trusted country header is used', 'DE', $i18n->detector()->country()->getCountry());
T::is('and suggests the euro', 'EUR', $i18n->preferences()->getCurrency()->getCode());

// A visitor cannot forge a country by sending the header themselves.
$i18n = ch247_visit(array(
    'ip'              => '203.0.113.7',
    'server'          => array('HTTP_CF_IPCOUNTRY' => 'DE', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'),
    'accept_language' => 'en',
    'trusted_proxies' => array(),
    'geo'             => $edgeOnly,
));
T::is('a client-supplied country header is worthless', null, $i18n->detector()->country());
T::is('and cannot change the currency', 'NGN', $i18n->preferences()->getCurrency()->getCode());

// CIDR matching covers IPv6 and rejects junk.
$ip = new ClientIp(array('2606:4700::/32', '10.0.0.0/8', 'nonsense'));
T::is('IPv6 ranges match', true, $ip->isTrusted('2606:4700:dead::1'));
T::is('IPv4 ranges match', true, $ip->isTrusted('10.1.2.3'));
T::is('other addresses do not', false, $ip->isTrusted('8.8.8.8'));
T::is('malformed entries are skipped', false, $ip->isTrusted('nonsense'));

/* ------------------------------------------------------------- the header -- */

section('Accept-Language parsing');

$parsed = AcceptLanguage::parse('fr-CH, fr;q=0.9, en;q=0.8, *;q=0.5');
T::is('four entries survive', 4, count($parsed));
T::is('the highest quality comes first', 'fr-CH', $parsed[0]['tag']);
T::is('with its quality', 0.9, $parsed[1]['quality'], 0.0001);

T::is('a malformed header is empty', array(), AcceptLanguage::parse(''));
T::is('a junk header is empty', array(), AcceptLanguage::parse(';;;,,,'));
T::is('quality zero is dropped', 1, count(AcceptLanguage::parse('en;q=0,fr;q=0.9')));
T::is('a bad q value falls back to 1.0', 1.0, AcceptLanguage::parse('en;q=abc')[0]['quality'], 0.0001);
T::is('an over-long q is clamped', 1.0, AcceptLanguage::parse('en;q=9')[0]['quality'], 0.0001);

T::is('script subtags expand', array('zh-Hans-CN', 'zh-Hans', 'zh'), AcceptLanguage::preferredTags('zh-Hans-CN'));
T::is('underscores are normalised', array('pt-BR', 'pt'), AcceptLanguage::preferredTags('pt_BR'));
T::is('a wildcard accepts anything', true, AcceptLanguage::acceptsAnything('fr, *;q=0.5'));
T::is('a plain list does not', false, AcceptLanguage::acceptsAnything('fr, de'));
T::is('the region is readable', 'GB', AcceptLanguage::preferredRegion('en-GB,en;q=0.9'));
T::is('a language-only tag has no region', null, AcceptLanguage::preferredRegion('en, de;q=0.9'));

/* ------------------------------------------------------------ persistence -- */

section('detection is remembered, but only as a suggestion');

// A detected value goes to the session under its own key, never to the
// customer's account and never to a long-lived cookie.
$recorder3 = ch247_cookie_recorder();
$store = ch247_store_chain(array(), array(), array(), $recorder3->sender());
$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'fr', 'store' => $store, 'cookie_sender' => $recorder3->sender()));
$i18n->preferences()->getLanguage();
$i18n->preferences()->getCurrency();
$i18n->flushCookies();
T::is('nothing is written to a cookie by detection alone', array(), $recorder3->sent);
T::is('the detected language is remembered for the session', 'fr', $store->get('detected_language'));
T::is('the detected currency is remembered for the session', 4, (int) $store->get('detected_currency'));
T::is('neither is stored as an explicit preference', null, $store->get('language'));

// An explicit choice clears the detected one so detection cannot restore it.
$store2 = ch247_store_chain(array(), array(), array(), $recorder3->sender());
$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'fr', 'store' => $store2));
$i18n->applyRequest(array('language' => 'es', 'currency' => 1));
T::is('the detected language was cleared', null, $store2->get('detected_language'));
T::is('the detected currency was cleared', null, $store2->get('detected_currency'));
T::is('the explicit language is stored', 'es', $store2->get('language'));
T::is('the explicit currency is stored', 1, (int) $store2->get('currency'));

// Detection never redirects: it applies to the response being built, so there
// is no second request and therefore no loop to get stuck in. Asserted here so
// a future optimisation cannot introduce one.
$i18n = ch247_visit(array('ip' => '198.51.100.4', 'accept_language' => 'fr'));
$uri = '/cart.php?a=view&pid=42';
$i18n->applyRequest(array());
T::is('the requested URI is untouched', $uri, $i18n->currentUri(array('REQUEST_URI' => $uri)));

$view = $i18n->switcher(array(), $uri);
T::is('the switcher keeps the visitor on the same page', '/cart.php?a=view&pid=42&language=de',
    $view['language']['options'][1]['url']);
T::is('and carries the cart parameters into the next currency', '/cart.php?a=view&pid=42&currency=2',
    $view['currency']['options'][1]['url']);
T::is('detection is reported to the template', 'browser', $view['detection']['language']['source']);
T::is('including the currency', 'geolocation', $view['detection']['currency']['source']);
T::ok('and the template is told it was automatic', $view['detection']['detected'] === true);
T::ok('with a sentence explaining it', strpos((string) $view['detection']['message'], 'GBP') !== false);

T::summary();
