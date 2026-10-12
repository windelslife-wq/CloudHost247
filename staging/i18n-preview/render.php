<?php
/**
 * CloudHost247 — i18n staging preview renderer.
 *
 * Renders the real switcher templates with the real i18n library for every
 * (language × currency × page) combination, writing static HTML snapshots that
 * the preview server then serves.
 *
 * No WHMCS is involved: currencies come from a StaticCurrencySource and
 * preferences from an ArrayPreferenceStore, both of which the library accepts
 * by design. That is the point — the same code path runs here and in WHMCS,
 * only the data source differs.
 *
 * Usage:  php staging/i18n-preview/render.php
 * Writes: staging/i18n-preview/public/pages/<locale>-<currency>.html
 *         staging/i18n-preview/public/pages/index.json
 *
 * @package    CloudHost247\Staging
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/i18n/autoload.php';
require_once __DIR__ . '/smarty-preview.php';
require_once $root . '/ci/lib/PageReferenceReader.php';

use CloudHost247\I18n\Currency;
use CloudHost247\Ci\PageReferenceReader;
use CloudHost247\I18n\Whmcs\WhmcsLocale;
use CloudHost247\I18n\EquivalentUrl;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Detection\ArrayGeoLocator;
use CloudHost247\I18n\Detection\RequestContext;
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\Rate\StaticRateSource;
use CloudHost247\I18n\StaticCurrencySource;

/* ------------------------------------------------------------- fixtures ---- */

/**
 * Mirrors a realistic WHMCS `tblcurrencies` row set: NGN is the shop default,
 * the others are enabled with exchange rates relative to it. JPY is present but
 * deliberately unrateable so the "no rate -> do not convert" path is visible in
 * the preview rather than only in a unit test.
 *
 * @return array
 */
function ch247_preview_currencies(): array
{
    return array(
        array('id' => 1, 'code' => 'NGN', 'prefix' => '₦', 'suffix' => '', 'name' => 'Nigerian Naira', 'format' => 1, 'rate' => 1.0, 'default' => true),
        array('id' => 2, 'code' => 'USD', 'prefix' => '$', 'suffix' => '', 'name' => 'US Dollar', 'format' => 1, 'rate' => 0.00065, 'default' => false),
        array('id' => 3, 'code' => 'EUR', 'prefix' => '', 'suffix' => ' €', 'name' => 'Euro', 'format' => 3, 'rate' => 0.00060, 'default' => false),
        array('id' => 4, 'code' => 'GBP', 'prefix' => '£', 'suffix' => '', 'name' => 'British Pound', 'format' => 1, 'rate' => 0.00051, 'default' => false),
        array('id' => 5, 'code' => 'JPY', 'prefix' => '¥', 'suffix' => '', 'name' => 'Japanese Yen', 'format' => 1, 'rate' => 0.0, 'default' => false),
    );
}

/**
 * The sample pages the preview shows, so equivalent-page navigation can be
 * checked by clicking through them.
 *
 * @return array
 */
function ch247_preview_pages(): array
{
    $pages = array(
        'home'          => array('uri' => '/index.php', 'title' => 'Home'),
        'hosting'       => array('uri' => '/web-hosting.php', 'title' => 'Web hosting'),
        'cart'          => array('uri' => '/cart.php?a=view', 'title' => 'Cart'),
        'invoice'       => array('uri' => '/viewinvoice.php?id=1042', 'title' => 'Invoice 1042'),
        'clientarea'    => array('uri' => '/clientarea.php', 'title' => 'Client area'),
    );
    $root = dirname(__DIR__, 2);
    foreach (require $root . '/lang/pages/manifest.php' as $page => $reference) {
        $pages[$page] = array('uri' => '/' . $reference['source'], 'title' => $page);
    }
    $pages['help-center'] = array('uri' => '/help-center.php', 'title' => 'Help center', 'template' => 'helpcenter');
    $pages['terms-of-service'] = array('uri' => '/terms-of-service.php', 'title' => 'Terms & Conditions', 'template' => 'termsofservice');
    return $pages;
}

/**
 * @return array
 */
function ch247_preview_route_map(): array
{
    return array(); // Real routes are preserved; no untranslated-slug 404s.
}

/**
 * The automatic-detection scenarios the preview renders.
 *
 * These are the same cases the PHP suite asserts on, rendered here through the
 * real Smarty templates so the switcher markup, the "we set this
 * automatically" hint and every URL can be inspected in a browser rather than
 * only in a unit test.
 *
 * @return array
 */
function ch247_preview_detection_scenarios(): array
{
    return array(
        'new-english'   => array(
            'label'  => 'First visit, English browser, no location',
            'expect' => 'English + NGN (default)',
            'ip'     => '203.0.113.250',
            'accept_language' => 'en-US,en;q=0.9',
        ),
        'new-french'    => array(
            'label'  => 'First visit, French browser',
            'expect' => 'French + NGN (default)',
            'ip'     => '203.0.113.250',
            'accept_language' => 'fr-FR,fr;q=0.9,en;q=0.5',
        ),
        'united-kingdom' => array(
            'label'  => 'First visit from the United Kingdom',
            'expect' => 'English + GBP',
            'ip'     => '198.51.100.4',
            'accept_language' => 'en-GB,en;q=0.9',
        ),
        'united-states' => array(
            'label'  => 'First visit from the United States',
            'expect' => 'English + USD',
            'ip'     => '192.0.2.44',
            'accept_language' => 'en-US,en;q=0.9',
        ),
        'euro-area'     => array(
            'label'  => 'First visit from Germany (euro area)',
            'expect' => 'German + EUR',
            'ip'     => '198.51.100.9',
            'accept_language' => 'de-DE,de;q=0.9',
        ),
        'unmapped'      => array(
            'label'  => 'First visit from a country whose currency is not enabled',
            'expect' => 'English + NGN (default)',
            'ip'     => '198.51.100.13', // India -> INR, which this shop does not sell in
            'accept_language' => 'en-IN,en;q=0.9',
        ),
        'unknown'       => array(
            'label'  => 'First visit from an unknown network',
            'expect' => 'English + NGN (default)',
            'ip'     => '203.0.113.250',
            'accept_language' => 'en',
        ),
        'returning'     => array(
            'label'  => 'Returning visitor with a saved choice, now in the US',
            'expect' => 'German + GBP (saved, detection ignored)',
            'ip'     => '192.0.2.44',
            'accept_language' => 'fr-FR,fr;q=0.9',
            'cookie' => array('ch247_language' => 'de', 'ch247_currency' => '4'),
        ),
        'unsupported'   => array(
            'label'  => 'Browser asks only for languages the site does not ship',
            'expect' => 'English + NGN (default)',
            'ip'     => '203.0.113.250',
            'accept_language' => 'ja-JP,ja;q=0.9,ko;q=0.5',
        ),
    );
}

/**
 * Fixture geolocation for the preview: the same countries the PHP suite uses.
 *
 * @return ArrayGeoLocator
 */
function ch247_preview_geo(): ArrayGeoLocator
{
    return new ArrayGeoLocator(array(
        '203.0.113.7'   => 'NG',
        '198.51.100.4'  => 'GB',
        '192.0.2.44'    => 'US',
        '198.51.100.9'  => 'DE',
        '198.51.100.11' => 'FR',
        '198.51.100.12' => 'JP',
        '198.51.100.13' => 'IN',
        '198.51.100.16' => 'BR',
    ), 'staging fixture (deterministic)');
}

/**
 * Catalogue prices in NGN minor units, used to show indicative conversion.
 *
 * @return array
 */
function ch247_preview_prices(): array
{
    return array(
        'shared-starter' => array('NGN' => 250000, 'USD' => 2500, 'EUR' => 2300),
        'shared-pro'     => array('NGN' => 550000, 'USD' => 5500, 'EUR' => 5100),
        'vps-basic'      => array('NGN' => 1200000),
    );
}

/* -------------------------------------------------------------- rendering -- */

$publicDir = __DIR__ . '/public';
$pagesDir = $publicDir . '/pages';
if (!is_dir($pagesDir) && !mkdir($pagesDir, 0775, true) && !is_dir($pagesDir)) {
    echo "Could not create {$pagesDir}\n";
    exit(1);
}

$currencies = array();
foreach (ch247_preview_currencies() as $row) {
    $currencies[] = new Currency(
        (int) $row['id'],
        $row['code'],
        $row['prefix'],
        $row['suffix'],
        $row['name'],
        (int) $row['format'],
        (float) $row['rate'],
        (bool) $row['default']
    );
}

$rates = array();
foreach (ch247_preview_currencies() as $row) {
    if ($row['rate'] > 0) {
        $rates[$row['code']] = (float) $row['rate'];
    }
}

$themeDir = $root . '/templates/hostx';
$manifest = require $root . '/lang/pages/manifest.php';
$index = array();

foreach (array('en', 'fr', 'es', 'de', 'pt-BR') as $locale) {
    foreach ($currencies as $currency) {
        $store = new ArrayPreferenceStore(array(
            'language' => $locale,
            'currency' => $currency->getId(),
        ));

        $i18n = I18n::boot(array(
            'root'            => $root,
            'default_locale'  => 'en',
            'currency_source' => new StaticCurrencySource($currencies),
            'store'           => $store,
            'language_value'  => array(WhmcsLocale::class, 'toWhmcsName'),
            'rate_source'     => new StaticRateSource('NGN', $rates, 'staging fixture (deterministic)', '2026-10-12'),
        ));

        $i18n->applyRequest(array());
        foreach (ch247_preview_pages() as $pageKey => $page) {
            $uri = $page['uri'];

            // An invoice keeps its own currency: assert that in the preview too.
            $transactionCurrency = null;
            if ($pageKey === 'invoice') {
                $transactionCurrency = $i18n->currencies()->findByCode('USD');
            }

            $switcher = $i18n->switcher(ch247_preview_route_map(), $uri, $transactionCurrency);
            $formatter = $i18n->formatter();

            // Prices for the sample catalogue, resolved through the real resolver.
            $prices = array();
            foreach (ch247_preview_prices() as $slug => $matrix) {
                $resolved = $i18n->prices()->resolve($matrix, $currency, $transactionCurrency);
                // Format through the real formatter and the real Currency
                // objects, so what the preview shows is exactly what the
                // theme will render.
                $displayCurrency = $i18n->currencies()->findByCode($resolved->getDisplayCurrency());
                $chargeCurrency = $i18n->currencies()->findByCode($resolved->getTransactionCurrency());

                $prices[$slug] = array(
                    'slug'              => $slug,
                    'available'         => $resolved->isAvailable(),
                    'indicative'        => $resolved->isIndicative(),
                    'exact'             => $resolved->isExact(),
                    'display'           => ($resolved->isAvailable() && $resolved->getMoney() !== null && $displayCurrency !== null)
                        ? $formatter->money($resolved->getMoney(), $displayCurrency)
                        : '',
                    'display_code'      => $resolved->getDisplayCurrency(),
                    'transaction'       => ($resolved->isAvailable() && $resolved->getTransactionAmount() !== null && $chargeCurrency !== null)
                        ? $formatter->money($resolved->getTransactionAmount(), $chargeCurrency)
                        : '',
                    'transaction_code'  => $resolved->getTransactionCurrency(),
                    'converted_from'    => $resolved->getConvertedFrom(),
                    'rate'              => $resolved->getRate(),
                    'rate_as_of'        => $resolved->getRateAsOf(),
                    'rate_source'       => $resolved->getRateSource(),
                );
            }

            $view = array(
                'ch247Switcher'   => $switcher,
                'ch247LocaleTag'  => $i18n->preferences()->getLocale()->getIntlTag(),
                'ch247Direction'  => $i18n->preferences()->getLocale()->getDirection(),
                'template'        => 'hostx',
                'LANG'            => $i18n->translator()->getCatalogMessages(),
                'WEB_ROOT'        => '/assets/theme',
            );

            $messages = $i18n->translator()->getCatalogMessages();
            $view['LANG']['globalsystemname'] = $messages['nav_home'];
            $view['companyName'] = 'CloudHost247 Isc';
            $view['companyUrl'] = 'https://www.cloudhost247.com';
            $view['supportEmail'] = 'support@cloudhost247.com';
            $view['jurisdiction'] = $messages['ch247_jurisdiction_nigeria'];
            $view['effectiveDate'] = $formatter->date('2025-08-14', 'long');
            $view['pageTitle'] = isset($messages['page_' . str_replace('-', '_', $pageKey) . '_title'])
                ? $messages['page_' . str_replace('-', '_', $pageKey) . '_title'] : $page['title'];
            $bodyTemplate = isset($page['template']) ? $page['template'] : null;
            if (isset($manifest[$pageKey])) {
                $ref = $manifest[$pageKey];
                $english = PageReferenceReader::read($root . '/' . $ref['source'], $ref);
                $assignment = isset($ref['assignment']) ? $ref['assignment'] : ($pageKey === 'legal' ? 'legalSections' : 'faqItems');
                $view[$assignment] = $i18n->pages()->content($pageKey, $english, $locale);
                $bodyTemplate = $ref['template'];
            }
            $renderer = new SmartyPreview($themeDir, $view);
            $desktopHtml = $renderer->render('includes/language-currency-switcher.tpl');
            $mobileHtml = $renderer->render('hostx_includes/language-currency-switcher-mobile.tpl');

            $name = sprintf('%s-%s-%s', $locale, strtolower($currency->getCode()), $pageKey);
            $snapshot = array(
                'name'        => $name,
                'locale'      => $locale,
                'locale_name' => $i18n->preferences()->getLocale()->getNativeName(),
                'currency'    => $currency->getCode(),
                'page'        => $pageKey,
                'page_title'  => $view['pageTitle'],
                'body'        => $bodyTemplate !== null ? $renderer->render($bodyTemplate . '.tpl') : '',
                'currency_id' => $currency->getId(),
                'language_value' => WhmcsLocale::toWhmcsName($locale),
                'uri'         => $uri,
                'canonical'   => EquivalentUrl::canonical($uri),
                'dir'         => $i18n->preferences()->getLocale()->getDirection(),
                'lang'        => $i18n->preferences()->getLocale()->getIntlTag(),
                'desktop'     => $desktopHtml,
                'mobile'      => $mobileHtml,
                'prices'      => $prices,
                'sample'      => array(
                    'date_medium' => $formatter->date(strtotime('2026-10-12 15:07:09 UTC'), 'medium'),
                    'date_full'   => $formatter->date(strtotime('2026-10-12 15:07:09 UTC'), 'full'),
                    'number'      => $formatter->number(1234567),
                    'decimal'     => $formatter->decimal(1234.567),
                    'percent'     => $formatter->percent(0.155),
                ),
                'transaction_currency' => $transactionCurrency !== null ? $transactionCurrency->getCode() : null,
            );

            file_put_contents($pagesDir . '/' . $name . '.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $index[] = array(
                'name'        => $name,
                'locale'      => $locale,
                'locale_name' => $snapshot['locale_name'],
                'currency'    => $currency->getCode(),
                'page'        => $pageKey,
                'page_title'  => $view['pageTitle'],
                'uri'         => $uri,
                'currency_id' => $currency->getId(),
                'language_value' => WhmcsLocale::toWhmcsName($locale),
                'dir'         => $snapshot['dir'],
            );

        }
        I18n::forget();
    }
}

file_put_contents($pagesDir . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

/* ------------------------------------------------- automatic detection ---- */

/**
 * Render one detection scenario through the real switcher templates.
 *
 * Everything here is the production code path: the real RequestContext, the
 * real Detector, the real preference chain and the real Smarty templates. The
 * only fixture is the geolocation table, because there is no WHMCS database
 * and no live IP feed in a staging checkout.
 */
$detectionIndex = array();

foreach (ch247_preview_detection_scenarios() as $key => $scenario) {
    $request = new RequestContext(
        array(
            'REQUEST_METHOD'      => 'GET',
            'REQUEST_URI'         => '/web-hosting.php',
            'REMOTE_ADDR'         => $scenario['ip'],
            'HTTP_HOST'           => 'preview.cloudhost247.test',
            'HTTP_ACCEPT_LANGUAGE' => $scenario['accept_language'],
        ),
        isset($scenario['cookie']) ? $scenario['cookie'] : array()
    );

    $i18n = I18n::boot(array(
        'root'            => $root,
        'default_locale'  => 'en',
        'currency_source' => new StaticCurrencySource($currencies),
        'request'         => $request,
        'rate_source'     => new StaticRateSource('NGN', $rates, 'staging fixture (deterministic)', '2026-10-12'),
        'language_value'  => array(WhmcsLocale::class, 'toWhmcsName'),
        'cookie'          => array('ttl' => 15552000, 'path' => '/', 'secure' => false),
        'detection'       => array('geo' => ch247_preview_geo()),
    ));

    // No ?language= / ?currency=: this is a plain first page view.
    $i18n->applyRequest(array());

    $locale = $i18n->preferences()->getLocale();
    $currency = $i18n->preferences()->getCurrency();
    $uri = '/web-hosting.php';
    $switcher = $i18n->switcher(ch247_preview_route_map(), $uri);

    $view = array(
        'ch247Switcher'  => $switcher,
        'ch247LocaleTag' => $locale->getIntlTag(),
        'ch247Direction' => $locale->getDirection(),
        'template'       => 'hostx',
        'LANG'           => $i18n->translator()->getCatalogMessages(),
        'WEB_ROOT'       => '/assets/theme',
    );

    $renderer = new SmartyPreview($themeDir, $view);

    $snapshot = array(
        'name'            => 'detection-' . $key,
        'scenario'        => $key,
        'label'           => $scenario['label'],
        'expect'          => $scenario['expect'],
        'accept_language' => $scenario['accept_language'],
        'ip'              => $scenario['ip'],
        'country'         => $switcher['detection']['country'],
        'language'        => $locale->getCode(),
        'language_name'   => $locale->getNativeName(),
        'language_source' => $switcher['detection']['language']['source'],
        'currency'        => $currency->getCode(),
        'currency_id'     => $currency->getId(),
        'currency_source' => $switcher['detection']['currency']['source'],
        'detected'        => $switcher['detection']['detected'],
        'message'         => $switcher['detection']['message'],
        'uri'             => $uri,
        'canonical'       => $uri,
        'page'            => 'detection',
        'page_title'      => 'Automatic detection',
        'locale'          => $locale->getCode(),
        'locale_name'     => $locale->getNativeName(),
        'prices'          => array(),
        'body'            => '',
        'sample'          => array(
            'date_medium' => $i18n->formatter()->date(strtotime('2026-10-12 15:07:09 UTC'), 'medium'),
            'date_full'   => $i18n->formatter()->date(strtotime('2026-10-12 15:07:09 UTC'), 'full'),
            'number'      => $i18n->formatter()->number(1234567),
            'decimal'     => $i18n->formatter()->decimal(1234.567),
            'percent'     => $i18n->formatter()->percent(0.155),
        ),
        'dir'             => $locale->getDirection(),
        'lang'            => $locale->getIntlTag(),
        'desktop'         => $renderer->render('includes/language-currency-switcher.tpl'),
        'mobile'          => $renderer->render('hostx_includes/language-currency-switcher-mobile.tpl'),
    );

    file_put_contents(
        $pagesDir . '/detection-' . $key . '.json',
        json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $detectionIndex[] = array(
        'name'     => 'detection-' . $key,
        'scenario' => $key,
        'label'    => $scenario['label'],
        'expect'   => $scenario['expect'],
        'language' => $snapshot['language'],
        'currency' => $snapshot['currency'],
        'country'  => $snapshot['country'],
        'accept_language' => $snapshot['accept_language'],
        'ip'       => $snapshot['ip'],
        'source_language' => $snapshot['language_source'],
        'source_currency' => $snapshot['currency_source'],
        'detected' => $snapshot['detected'],
    );

    I18n::forget();
}

file_put_contents($pagesDir . '/detection.json', json_encode($detectionIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo sprintf(
    "Rendered %d snapshots and %d detection scenarios into %s\n",
    count($index),
    count($detectionIndex),
    $pagesDir
);
