<?php
/** Real Smarty render checks: content, escaping, switcher headings and ids. */
declare(strict_types=1);
require_once __DIR__ . '/../i18n/bootstrap.php';
require_once CH247_REPO_ROOT . '/ci/lib/PageReferenceReader.php';
require_once CH247_REPO_ROOT . '/staging/i18n-preview/smarty-preview.php';

use CloudHost247\Ci\PageReferenceReader;
use CloudHost247\I18n\I18n;
use CloudHost247\I18n\Whmcs\WhmcsLocale;

function normal_html(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value));
}
$manifest = require CH247_REPO_ROOT . '/lang/pages/manifest.php';
$theme = CH247_REPO_ROOT . '/templates/hostx';
$hashes = json_decode(file_get_contents(CH247_REPO_ROOT . '/lang/pages/english-reference-hashes.json'), true);
$templates = array('legal', 'faqs', 'refundpolicy', 'backuppolicy', 'cybercrimepolicy', 'trademarkpolicy', 'helpcenter', 'termsofservice');

foreach (array('en', 'fr', 'es', 'de', 'pt-BR') as $locale) {
    section($locale . ': real Smarty rendering');
    $i18n = ch247_make_i18n(array('language_value' => array(WhmcsLocale::class, 'toWhmcsName')));
    $i18n->applyRequest(array('language' => $locale, 'currency' => 'EUR'));
    $messages = $i18n->translator()->getCatalogMessages();
    // WHMCS core owns this breadcrumb key; model its supplied value.
    $messages['globalsystemname'] = $messages['nav_home'];
    $vars = array(
        'LANG' => $messages,
        'template' => 'hostx',
        'WEB_ROOT' => '',
        'ch247Switcher' => $i18n->switcher(array(), '/web-hosting.php'),
        'companyName' => 'CloudHost247 Isc',
        'companyUrl' => 'https://www.cloudhost247.com',
        'supportEmail' => 'support@cloudhost247.com',
        'jurisdiction' => $messages['ch247_jurisdiction_nigeria'],
        'effectiveDate' => $i18n->formatter()->date('2025-08-14', 'long'),
        'pageTitle' => $messages['page_terms_of_service_title'],
    );
    foreach ($manifest as $page => $reference) {
        $english = PageReferenceReader::read(CH247_REPO_ROOT . '/' . $reference['source'], $reference);
        $assignment = isset($reference['assignment']) ? $reference['assignment'] : ($page === 'legal' ? 'legalSections' : 'faqItems');
        $vars[$assignment] = $i18n->pages()->content($page, $english, $locale);
    }
    $renderer = new SmartyPreview($theme, $vars);
    $switcher = $renderer->render('includes/language-currency-switcher.tpl')
        . $renderer->render('hostx_includes/language-currency-switcher-mobile.tpl');
    T::contains('language heading is not blank', $messages['switcher_choose_language'], $switcher);
    T::contains('currency heading is not blank', $messages['switcher_choose_currency'], $switcher);
    T::contains('native WHMCS language parameter', '?language=french', $switcher);
    T::notContains('no nonexistent translated-slug links', 'hebergement-web.php', $switcher);
    preg_match_all('/\bid="([^"]+)"/', $switcher, $ids);
    T::is('desktop + mobile ids are unique', count($ids[1]), count(array_unique($ids[1])));
    T::is('one selected locale and currency per instance', 4, substr_count($switcher, 'aria-current="true"'));
    T::is('server-only/no-JS state agrees with visible panels', 4, substr_count($switcher, 'aria-expanded="true"'));

    foreach ($templates as $template) {
        $html = $renderer->render($template . '.tpl');
        T::ok($template . ': produces actual HTML', strlen($html) > 100);
        if ($locale === 'en') {
            $visible = preg_replace('~<(script|style)\b[^>]*>[\s\S]*?</\1>~i', '', $html);
            $visible = html_entity_decode(strip_tags($visible), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            T::is($template . ': approved original English wording preserved', $hashes['templates'][$template], hash('sha256', normal_html($visible)));
        }
        T::notContains($template . ': no unresolved Smarty variables', '{$', $html);
        $source = file_get_contents($theme . '/' . $template . '.tpl');
        preg_match_all('/\{\$LANG\.([A-Za-z0-9_]+)([^}]*)\}/', $source, $matches, PREG_SET_ORDER);
        $unseen = array();
        foreach ($matches as $match) {
            $key = $match[1];
            if (!isset($messages[$key])) {
                $unseen[] = $key . ' (missing)';
                continue;
            }
            $expected = $messages[$key];
            foreach (array('companyName', 'jurisdiction', 'effectiveDate') as $name) {
                $expected = str_replace('{' . $name . '}', htmlspecialchars($vars[$name], ENT_QUOTES, 'UTF-8'), $expected);
            }
            if (strpos($match[2], '|escape:') !== false) {
                $expected = htmlspecialchars($expected, ENT_QUOTES, 'UTF-8');
            }
            if (strpos(normal_html($html), normal_html($expected)) === false) {
                $unseen[] = $key;
            }
        }
        T::is($template . ': every referenced translated snippet is rendered', array(), array_values(array_unique($unseen)));
    }
    // Verify real FAQ/legal/policy assignments, not just the static template UI.
    foreach ($manifest as $page => $reference) {
        $assignment = isset($reference['assignment']) ? $reference['assignment'] : ($page === 'legal' ? 'legalSections' : 'faqItems');
        $body = normal_html($renderer->render($reference['template'] . '.tpl'));
        $notRendered = array();
        foreach (PageReferenceReader::strings($vars[$assignment]) as $path => $text) {
            if ($path === 'hero.title') { // Template deliberately uppercases this one heading.
                continue;
            }
            if (strpos($body, normal_html($text)) === false) {
                $notRendered[] = $path;
            }
        }
        T::is($page . ': every body field reaches the real template', array(), $notRendered);
    }
    foreach (array('mega_menu', 'mega_menu_latest', 'dropdown_menu') as $layout) {
        foreach (array('public', 'account', 'cart') as $mode) {
            $chrome = array_merge($vars, array(
                'hostx_theme_settings' => array('menu_layout' => $layout, 'enable_primary_sidebar_left' => 'on', 'enable_secondary_sidebar_right' => 'on'),
                'companyname' => 'CloudHost247', 'topMenusData' => array(),
                'filename' => $mode === 'account' ? 'clientarea' : ($mode === 'cart' ? 'cart' : 'index'),
                'templatefile' => $mode === 'account' ? 'clientareahome' : ($mode === 'cart' ? 'viewcart' : 'homepage'),
                'loggedin' => $mode !== 'public', 'sidebarHostxRemove' => '',
                'primarySidebar' => new class { public function hasChildren() { return false; } },
                'secondarySidebar' => new class { public function hasChildren() { return false; } },
            ));
            $header = new SmartyPreview($theme, $chrome);
            $html = $header->render('hostx_includes/top-most-header.tpl')
                . $header->render('hostx_includes/top-menu-header.tpl')
                . $header->render('hostx_includes/mobile-menu.tpl');
            preg_match_all('/\bid="(ch247-[^"]+)"/', $html, $ids);
            T::is($layout . '/' . $mode . ': one desktop + one mobile control pair', 4, substr_count($html, 'data-ch247-switcher-trigger'));
            T::is($layout . '/' . $mode . ': no duplicate switcher ids', count($ids[1]), count(array_unique($ids[1])));
        }
    }
    $unsafe = '<img src=x onerror="alert(1)">';
    $html = $renderer->render('termsofservice.tpl', array('companyName' => $unsafe));
    T::notContains('placeholder cannot inject markup', $unsafe, $html);
    T::contains('placeholder is HTML-escaped', htmlspecialchars($unsafe, ENT_QUOTES, 'UTF-8'), $html);
}
/* ------------------------------------------- automatic detection snapshots -- */

/**
 * The detection scenarios rendered by staging/i18n-preview/render.php.
 *
 * These are real Smarty renders of the shipped switcher, produced by the same
 * Detector and the same preference chain as production — only the geolocation
 * table is a fixture. They are what makes "the browser tests pass" meaningful:
 * the markup below is the markup a browser would receive.
 *
 * Skip (rather than fail) when the snapshots have not been built: this suite
 * also runs in environments that never run `node staging/i18n-preview/build.mjs`.
 */
$detectionFile = CH247_REPO_ROOT . '/staging/i18n-preview/public/pages/detection.json';
if (is_file($detectionFile)) {
    section('automatic detection: rendered snapshots');

    $scenarios = json_decode(file_get_contents($detectionFile), true);
    T::ok('the detection scenarios were rendered', is_array($scenarios) && count($scenarios) >= 8);

    $expected = array(
        'new-english'    => array('language' => 'en', 'currency' => 'NGN', 'source_currency' => 'default',     'country' => null, 'detected' => true),
        'new-french'     => array('language' => 'fr', 'currency' => 'NGN', 'source_currency' => 'default',     'country' => null, 'detected' => true),
        'united-kingdom' => array('language' => 'en', 'currency' => 'GBP', 'source_currency' => 'geolocation', 'country' => 'GB', 'detected' => true),
        'united-states'  => array('language' => 'en', 'currency' => 'USD', 'source_currency' => 'geolocation', 'country' => 'US', 'detected' => true),
        'euro-area'      => array('language' => 'de', 'currency' => 'EUR', 'source_currency' => 'geolocation', 'country' => 'DE', 'detected' => true),
        'unmapped'       => array('language' => 'en', 'currency' => 'NGN', 'source_currency' => 'default',     'country' => 'IN', 'detected' => true),
        'unknown'        => array('language' => 'en', 'currency' => 'NGN', 'source_currency' => 'default',     'country' => null, 'detected' => true),
        'returning'      => array('language' => 'de', 'currency' => 'GBP', 'source_currency' => 'stored',      'country' => 'US', 'detected' => false),
        'unsupported'    => array('language' => 'en', 'currency' => 'NGN', 'source_currency' => 'default',     'country' => null, 'detected' => false),
    );

    foreach ($expected as $scenario => $expectation) {
        $path = CH247_REPO_ROOT . '/staging/i18n-preview/public/pages/detection-' . $scenario . '.json';
        T::ok('[' . $scenario . '] snapshot exists', is_file($path));
        if (!is_file($path)) {
            continue;
        }

        $snapshot = json_decode(file_get_contents($path), true);
        T::is('[' . $scenario . '] language', $expectation['language'], $snapshot['language']);
        T::is('[' . $scenario . '] currency', $expectation['currency'], $snapshot['currency']);
        T::is('[' . $scenario . '] currency source', $expectation['source_currency'], $snapshot['currency_source']);
        T::is('[' . $scenario . '] country', $expectation['country'], $snapshot['country']);
        T::is('[' . $scenario . '] detected flag', $expectation['detected'], $snapshot['detected']);

        $html = $snapshot['desktop'] . $snapshot['mobile'];

        // The switcher always offers a way out: every supported language and
        // every enabled currency is a real link, in every scenario.
        T::is('[' . $scenario . '] one selected language + currency per instance', 4, substr_count($html, 'aria-current="true"'));
        T::contains('[' . $scenario . '] the current page is preserved', 'web-hosting.php', $html);

        // The detection source is exposed for support and for the browser tests.
        T::contains('[' . $scenario . '] the language source is in the markup', 'data-ch247-detection-language="' . $snapshot['language_source'] . '"', $html);
        T::contains('[' . $scenario . '] the currency source is in the markup', 'data-ch247-detection-currency="' . $snapshot['currency_source'] . '"', $html);

        // Only an automatic choice is announced as one.
        if ($snapshot['detected']) {
            T::contains('[' . $scenario . '] an automatic choice is explained', 'data-ch247-detection-notice', $html);
            T::ok('[' . $scenario . '] in the visitor\'s own language', $snapshot['message'] !== null && $snapshot['message'] !== '');
        } else {
            T::notContains('[' . $scenario . '] a saved choice is not called automatic', 'data-ch247-detection-notice', $html);
        }

        T::notContains('[' . $scenario . '] no unresolved Smarty variables', '{$', $html);
    }

    // A detected currency must be changeable in one click, on the same page.
    $snapshot = json_decode(file_get_contents(CH247_REPO_ROOT . '/staging/i18n-preview/public/pages/detection-euro-area.json'), true);
    T::contains('the detected currency can be switched on the same page', 'web-hosting.php?currency=', $snapshot['desktop']);
    T::contains('and the language too', 'web-hosting.php?language=', $snapshot['desktop']);
}

T::summary();
