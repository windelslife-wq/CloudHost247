<?php
/** Verify page catalogs against the actual English arrays in the controllers. */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once CH247_REPO_ROOT . '/ci/lib/PageReferenceReader.php';

use CloudHost247\Ci\PageReferenceReader;
use CloudHost247\I18n\PageContent;

$manifest = require CH247_REPO_ROOT . '/lang/pages/manifest.php';
$helper = new PageContent(CH247_REPO_ROOT);
$required = array('fr', 'es', 'de', 'pt-BR');
$total = 0;
$hashes = json_decode(file_get_contents(CH247_REPO_ROOT . '/lang/pages/english-reference-hashes.json'), true);

function page_markup(string $text): array
{
    preg_match_all('/<\/?[a-z][^>]*>/i', $text, $matches);
    return $matches[0];
}
function page_numbers(string $text): array
{
    // Numeric deadlines, hours, fees and section numbers cannot change in a
    // legal translation. Order may change (localised dates), values may not.
    preg_match_all('/[0-9]+/', strip_tags($text), $matches);
    $values = $matches[0];
    sort($values);
    return $values;
}
function page_identifiers(string $text): array
{
    preg_match_all('~(?:https?://[^\s<>"\']+|[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}|www\.[A-Za-z0-9.-]+|\b(?:CloudHost247|ICANN|NIRA|UDRP|DMCA|GDPR|CCPA|HTTP|FTP/SFTP|ASN|DDoS|MySQL/MariaDB|UTC|cPanel|phpMyAdmin|PayPal)\b)~', $text, $matches);
    $values = $matches[0];
    sort($values);
    return $values;
}

foreach ($manifest as $page => $reference) {
    section($page . ': actual source parity');
    $english = PageReferenceReader::read(CH247_REPO_ROOT . '/' . $reference['source'], $reference);
    T::is('approved English array wording/structure unchanged', $hashes['arrays'][$page],
        hash('sha256', json_encode($english, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    $strings = PageReferenceReader::strings($english);
    $total += count($strings);
    T::ok('the source contains a nonempty English reference', count($strings) > 0);
    T::contains('the real controller uses the content layer', "ch247_content('" . $page . "'", file_get_contents(CH247_REPO_ROOT . '/' . $reference['source']));
    $locales = $helper->availableLocales($page);
    foreach ($required as $locale) {
        T::ok($locale . ': catalog exists', in_array($locale, $locales, true));
        $catalog = $helper->load($page, $locale);
        T::ok($locale . ': returns an array', is_array($catalog));
        if (!is_array($catalog)) {
            continue;
        }
        $translated = PageReferenceReader::strings($catalog);
        $missing = array_diff(array_keys($strings), array_keys($translated));
        $extra = array_diff(array_keys($translated), array_keys($strings));
        T::is($locale . ': every English path is translated (ids, not list positions)', array(), array_values($missing));
        T::is($locale . ': no orphan ids/unknown paths', array(), array_values($extra));
        $merged = $helper->content($page, $english, $locale);
        $actual = PageReferenceReader::strings($merged);
        $failed = array();
        foreach ($strings as $path => $text) {
            if (!isset($translated[$path]) || !isset($actual[$path]) || $actual[$path] !== $translated[$path]) {
                $failed[] = $path;
            }
        }
        T::is($locale . ': every translation actually reaches the rendered array', array(), $failed);
        $htmlDrift = $numberDrift = $identifierDrift = array();
        foreach ($translated as $path => $text) {
            if (!isset($strings[$path])) {
                continue;
            }
            if (page_markup($text) !== page_markup($strings[$path])) {
                $htmlDrift[] = $path;
            }
            // FAQ availability wording such as '24/7' may be written out
            // ('rund um die Uhr'). Legal-policy numeric obligations may not.
            if ($page !== 'faqs' && page_numbers($text) !== page_numbers($strings[$path])) {
                $numberDrift[] = $path;
            }
            if (page_identifiers($text) !== page_identifiers($strings[$path])) {
                $identifierDrift[] = $path;
            }
        }
        T::is($locale . ': HTML tags and link attributes unchanged', array(), $htmlDrift);
        T::is($locale . ': numeric deadlines/limits/times unchanged', array(), $numberDrift);
        T::is($locale . ': URLs/emails/technical identifiers unchanged', array(), $identifierDrift);
    }
}
echo "\nPage-content reference: " . count($manifest) . " pages, {$total} translatable strings per locale.\n";
T::summary();
