<?php
/**
 * Page-content translation layer tests.
 *
 * The root landing pages (legal.php, faqs.php, the policy pages) hold their
 * copy in PHP arrays, which a WHMCS language file cannot reach. This suite
 * covers includes/i18n/src/PageContent.php — the layer that lets those pages
 * stay exactly as they are while their copy becomes translatable.
 *
 * Run: php tests/i18n/PageContentTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\PageContent;
use CloudHost247\Ci\PageReferenceReader;
require_once CH247_REPO_ROOT . '/ci/lib/PageReferenceReader.php';

$root = __DIR__ . '/../../';

/* ------------------------------------------------------------------ setup -- */

section('an empty catalog leaves the English untouched');

$emptyDir = sys_get_temp_dir() . '/ch247-pagecontent-empty-' . getmypid();
@mkdir($emptyDir . '/lang/pages/fr', 0777, true);
$helper = new PageContent($emptyDir);

$english = array(
    array('id' => 'terms-of-service', 'title' => 'Terms of Service', 'link' => 'terms-of-service.php', 'icon' => 'fa-file-contract'),
);
T::is('missing catalog returns the English array', $english, $helper->content('legal', $english, 'fr'));
T::is('a locale with no catalog returns English', $english, $helper->content('legal', $english, 'de'));

section('a translation replaces only the strings it supplies');

$catalogDir = sys_get_temp_dir() . '/ch247-pagecontent-' . getmypid();
@mkdir($catalogDir . '/lang/pages/fr', 0777, true);
@mkdir($catalogDir . '/lang/pages/de', 0777, true);

file_put_contents($catalogDir . '/lang/pages/fr/legal.php', '<?php return ' . var_export(array(
    array('id' => 'terms-of-service', 'title' => 'Conditions d’utilisation', 'desc' => 'Les conditions générales.'),
), true) . ';');

$helper = new PageContent($catalogDir);

$english = array(
    array(
        'id'    => 'terms-of-service',
        'title' => 'Terms of Service',
        'desc'  => 'The terms and conditions that govern your use of our platform.',
        'link'  => 'terms-of-service.php',
        'icon'  => 'fa-file-contract',
    ),
);

$out = $helper->content('legal', $english, 'fr');

T::is('the title is translated', 'Conditions d’utilisation', $out[0]['title']);
T::is('the description is translated', 'Les conditions générales.', $out[0]['desc']);
T::is('the link is NOT translated', 'terms-of-service.php', $out[0]['link']);
T::is('the icon is NOT translated', 'fa-file-contract', $out[0]['icon']);
T::is('the id is NOT translated', 'terms-of-service', $out[0]['id']);
T::is('no keys are added', 5, count($out[0]));


section('technical fields are protected even if a translator changes them');
$malformed = array(array('id' => 'terms-of-service', 'title' => 'Titre', 'link' => 'https://example.org', 'icon' => 'evil'));
$out = $helper->merge($english, $malformed);
T::is('a changed link is rejected', 'terms-of-service.php', $out[0]['link']);
T::is('a changed icon is rejected', 'fa-file-contract', $out[0]['icon']);
T::is('an unrelated type cannot replace a string', $english, $helper->merge($english, array(array('id' => 'terms-of-service', 'title' => array('bad')))));
T::is('dot-dot locale cannot escape', null, $helper->load('legal', '..'));
T::is('a mixed path locale cannot escape', null, $helper->load('legal', 'fr/..'));

section('ids are matched, not positions');

// Reorder the English array: the translation must still land on the right item.
$reordered = array($english[0]);
$reordered[] = array('id' => 'privacy-policy', 'title' => 'Privacy Policy', 'link' => 'privacy-policy.php', 'icon' => 'fa-user-shield');

$out = $helper->content('legal', $reordered, 'fr');
T::is('the first item is still translated', 'Conditions d’utilisation', $out[0]['title']);
T::is('an item with no translation keeps English', 'Privacy Policy', $out[1]['title']);
T::is('the second link is preserved', 'privacy-policy.php', $out[1]['link']);

section('an empty translation value degrades to English');

@mkdir($catalogDir . '/lang/pages/legal/' , 0777, true);
file_put_contents($catalogDir . '/lang/pages/de/legal.php', '<?php return ' . var_export(array(
    array('id' => 'terms-of-service', 'title' => '', 'desc' => '   '),
), true) . ';');

$out = $helper->content('legal', $english, 'de');
T::is('an empty string falls back to English', 'Terms of Service', $out[0]['title']);
T::is('a whitespace-only string falls back to English', 'The terms and conditions that govern your use of our platform.', $out[0]['desc']);

section('nested structures are walked');

file_put_contents($catalogDir . '/lang/pages/fr/faqs.php', '<?php return ' . var_export(array(
    array('id' => 'faq-1', 'question' => 'Qu’est-ce que CloudHost247 ?', 'answer' => 'Un hébergeur.'),
), true) . ';');

$faq = array(
    array('id' => 'faq-1', 'question' => 'What is CloudHost247?', 'answer' => 'A host.'),
    array('id' => 'faq-2', 'question' => 'How do I pay?', 'answer' => 'See billing.'),
);
$out = $helper->content('faqs', $faq, 'fr');
T::is('the first question is translated', 'Qu’est-ce que CloudHost247 ?', $out[0]['question']);
T::is('the second keeps English', 'How do I pay?', $out[1]['question']);
T::is('the item count is unchanged', 2, count($out));

section('keyed (non-list) structures are merged by key');

@mkdir($catalogDir . '/lang/pages/fr', 0777, true);
file_put_contents($catalogDir . '/lang/pages/fr/policy.php', '<?php return ' . var_export(array(
    'hero'         => array('title' => 'Politique de remboursement'),
    'introduction' => array('title' => '', 'content' => 'Texte traduit.'),
), true) . ';');

$policy = array(
    'hero'         => array('title' => 'Refund Policy', 'subtitle' => 'Effective 2025-08-14'),
    'introduction' => array('title' => 'Introduction', 'content' => 'English body.'),
    'contact'      => array('title' => 'Contact', 'email' => 'support@cloudhost247.com'),
);

$out = $helper->content('policy', $policy, 'fr');
T::is('a nested title is translated', 'Politique de remboursement', $out['hero']['title']);
T::is('a sibling key is untouched', 'Effective 2025-08-14', $out['hero']['subtitle']);
T::is('an empty nested value falls back', 'Introduction', $out['introduction']['title']);
T::is('a nested body is translated', 'Texte traduit.', $out['introduction']['content']);
T::is('an untranslated section keeps English', 'Contact', $out['contact']['title']);
T::is('an email is never translated', 'support@cloudhost247.com', $out['contact']['email']);

section('HTML inside a value survives translation');

file_put_contents($catalogDir . '/lang/pages/de/faqs.php', '<?php return ' . var_export(array(
    array(
        'id'      => 'faq-1',
        'question' => 'Wie bezahle ich?',
        'answer'  => 'Wir akzeptieren: <ul><li>Kreditkarten</li><li>PayPal</li></ul>',
    ),
), true) . ';');

$faq = array(
    array('id' => 'faq-1', 'question' => 'How do I pay?', 'answer' => 'We accept: <ul><li>Credit Cards</li><li>PayPal</li></ul>'),
);
$out = $helper->content('faqs', $faq, 'de');
T::ok('the translated markup is carried through', strpos($out[0]['answer'], '<ul><li>Kreditkarten</li><li>PayPal</li></ul>') !== false);
T::is('the untranslated markup is gone', false, strpos($out[0]['answer'], 'Credit Cards'));
T::ok('the translated answer still contains the list markup', strpos($out[0]['answer'], '<ul>') !== false && strpos($out[0]['answer'], '</ul>') !== false);

section('path traversal cannot escape the catalog directory');

T::is('a page name with slashes is flattened', array(), $helper->availableLocales('../../etc'));
T::is('a locale with slashes is flattened', null, $helper->load('legal', '../../../etc/passwd'));

/* ------------------------------------------------------- shipped catalogs -- */

section('the shipped catalogs parse and cover every item');

$shipped = new PageContent($root);

$legalLocales = $shipped->availableLocales('legal');
sort($legalLocales);
T::is('legal.php has all four translations', array('de', 'es', 'fr', 'pt-BR'), $legalLocales);

$faqLocales = $shipped->availableLocales('faqs');
sort($faqLocales);
T::is('faqs.php has all four translations', array('de', 'es', 'fr', 'pt-BR'), $faqLocales);

$legalEnglish = PageReferenceReader::read(CH247_REPO_ROOT . '/legal.php', array('catalog' => 'legal'));

foreach ($legalLocales as $locale) {
    $out = $shipped->content('legal', $legalEnglish, $locale);
    T::is('[legal/' . $locale . '] item count matches', count($legalEnglish), count($out));

    $untranslated = 0;
    foreach ($out as $index => $item) {
        if ($item['title'] === $legalEnglish[$index]['title']) {
            $untranslated++;
        }
        // Technical fields must be identical in every language.
        T::is('[legal/' . $locale . '] link preserved for ' . $item['id'], $legalEnglish[$index]['link'], $item['link']);
        T::is('[legal/' . $locale . '] icon preserved for ' . $item['id'], $legalEnglish[$index]['icon'], $item['icon']);
    }
    T::is('[legal/' . $locale . '] every card title is translated', 0, $untranslated);
}

$faqEnglish = PageReferenceReader::read(CH247_REPO_ROOT . '/faqs.php', array('catalog' => 'faqs'));

foreach ($faqLocales as $locale) {
    $out = $shipped->content('faqs', $faqEnglish, $locale);
    T::is('[faqs/' . $locale . '] item count matches', 12, count($out));

    $untranslated = 0;
    foreach ($out as $index => $item) {
        if ($item['question'] === $faqEnglish[$index]['question']) {
            $untranslated++;
        }
        T::is('[faqs/' . $locale . '] id preserved', $faqEnglish[$index]['id'], $item['id']);
    }
    T::is('[faqs/' . $locale . '] every question is translated', 0, $untranslated);
}

section('translated values are free of encoding damage');

foreach (array('legal', 'faqs') as $page) {
    foreach (array('fr', 'es', 'de', 'pt-BR') as $locale) {
        $data = $shipped->load($page, $locale);
        T::ok('[' . $page . '/' . $locale . '] catalog loads as an array', is_array($data));
        $flat = json_encode($data, JSON_UNESCAPED_UNICODE);
        T::is('[' . $page . '/' . $locale . '] no U+F0B7', false, strpos($flat, "\xEF\x82\xB7"));
        T::is('[' . $page . '/' . $locale . '] no replacement char', false, strpos($flat, "\xEF\xBF\xBD"));
        T::is('[' . $page . '/' . $locale . '] no mojibake', 0, preg_match('/\xc3\x83[\xc2\x80-\xc2\xbf]/', $flat));
    }
}

section('markup is balanced in every shipped translation');

foreach (array('legal', 'faqs') as $page) {
    foreach (array('fr', 'es', 'de', 'pt-BR') as $locale) {
        $data = $shipped->load($page, $locale);
        $html = '';
        foreach ($data as $item) {
            foreach ($item as $key => $value) {
                if ($key !== 'id' && is_string($value)) {
                    $html .= $value;
                }
            }
        }
        $openUl = preg_match_all('/<ul>/', $html);
        $closeUl = preg_match_all('/<\/ul>/', $html);
        $openLi = preg_match_all('/<li>/', $html);
        $closeLi = preg_match_all('/<\/li>/', $html);
        $openA = preg_match_all('/<a\s/', $html);
        $closeA = preg_match_all('/<\/a>/', $html);

        T::is('[' . $page . '/' . $locale . '] <ul> balanced', $openUl, $closeUl);
        T::is('[' . $page . '/' . $locale . '] <li> balanced', $openLi, $closeLi);
        T::is('[' . $page . '/' . $locale . '] <a> balanced', $openA, $closeA);
    }
}

/* ------------------------------------------------------------------ done -- */

// Clean up the scratch catalogs.

foreach (array($emptyDir, $catalogDir) as $dir) {
    if (is_dir($dir)) {
        foreach (glob($dir . '/lang/pages/*/*.php') as $file) {
            @unlink($file);
        }
    }
}

T::summary();
