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
T::summary();
