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
require_once __DIR__ . '/smarty-lite.php';

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\EquivalentUrl;
use CloudHost247\I18n\I18n;
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
    return array(
        'home'          => array('uri' => '/index.php', 'title' => 'Home'),
        'hosting'       => array('uri' => '/web-hosting.php', 'title' => 'Web hosting'),
        'cart'          => array('uri' => '/cart.php?a=view', 'title' => 'Cart'),
        'invoice'       => array('uri' => '/viewinvoice.php?id=1042', 'title' => 'Invoice 1042'),
        'clientarea'    => array('uri' => '/clientarea.php', 'title' => 'Client area'),
    );
}

/**
 * @return array
 */
function ch247_preview_route_map(): array
{
    return array(
        '/web-hosting.php'   => array('fr' => '/hebergement-web.php', 'es' => '/alojamiento-web.php', 'de' => '/webhosting.php', 'pt-BR' => '/hospedagem-web.php'),
        '/vps-hosting.php'   => array('fr' => '/hebergement-vps.php', 'es' => '/alojamiento-vps.php', 'de' => '/vps-hosting.php', 'pt-BR' => '/hospedagem-vps.php'),
        '/domain-search.php' => array('fr' => '/recherche-domaine.php', 'es' => '/buscar-dominio.php', 'de' => '/domainsuche.php', 'pt-BR' => '/busca-dominio.php'),
        '/help-center.php'   => array('fr' => '/centre-aide.php', 'es' => '/centro-ayuda.php', 'de' => '/hilfecenter.php', 'pt-BR' => '/central-ajuda.php'),
    );
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
        (float) $row['rate']
    );
}

$rates = array();
foreach (ch247_preview_currencies() as $row) {
    if ($row['rate'] > 0) {
        $rates[$row['code']] = (float) $row['rate'];
    }
}

$themeDir = $root . '/templates/hostx';
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
            'rate_source'     => new StaticRateSource('NGN', $rates, 'staging fixture (deterministic)', '2026-10-12'),
        ));

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
                'template'        => '',
                'WEB_ROOT'        => '/assets/theme',
            );

            $renderer = new SmartyLite($themeDir, $view);
            $desktopHtml = $renderer->render('includes/language-currency-switcher.tpl');
            $mobileHtml = $renderer->render('hostx_includes/language-currency-switcher-mobile.tpl');

            $name = sprintf('%s-%s-%s', $locale, strtolower($currency->getCode()), $pageKey);
            $snapshot = array(
                'name'        => $name,
                'locale'      => $locale,
                'locale_name' => $i18n->preferences()->getLocale()->getNativeName(),
                'currency'    => $currency->getCode(),
                'page'        => $pageKey,
                'page_title'  => $page['title'],
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
                'page_title'  => $page['title'],
                'dir'         => $snapshot['dir'],
            );

            I18n::forget();
        }
    }
}

file_put_contents($pagesDir . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo sprintf("Rendered %d snapshots into %s\n", count($index), $pagesDir);
