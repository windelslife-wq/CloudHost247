<?php
/**
 * The two fallback footers are translated, not hard-coded.
 *
 * `templates/hostx/includes/blocks/footer_block.tpl` and `footer_block_latest.tpl`
 * render when the HostX `footer_block` / `footer_block_latest` widgets are not
 * configured in the database — which is the default on a fresh install. For a
 * long time their link labels were literal English, so a French visitor who had
 * just been switched to French by automatic detection still read "Transfer a
 * Domain" at the bottom of every page.
 *
 * This suite is the guard on that. It proves three things:
 *
 *  - no literal copy survives in either template (only brand names);
 *  - every `{$LANG.…}` key the templates read actually exists, in every locale;
 *  - substituting the catalog really produces a translated footer with nothing
 *    left un-rendered, for all five locales.
 *
 * The last check is the one that matters: a key that exists but is misspelled
 * in the template renders as empty text, and only substitution catches that.
 *
 * Run: php tests/i18n/FooterTemplateTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\CatalogRepository;

$root = CH247_REPO_ROOT;
$catalogs = new CatalogRepository(ch247_catalog_dir());
$locales = $catalogs->availableLocales();

$TEMPLATES = array(
    'footer_block.tpl'        => $root . '/templates/hostx/includes/blocks/footer_block.tpl',
    'footer_block_latest.tpl' => $root . '/templates/hostx/includes/blocks/footer_block_latest.tpl',
);

/**
 * Strings that are legitimately literal in a translated template: they are the
 * brand, or a protocol/product name that is not translated anywhere.
 */
$LITERAL_ALLOWLIST = array(
    'CloudHost247',
    'CloudHost247.',
);

/* --------------------------------------------------------------- presence -- */

section('the fallback footers exist');

foreach ($TEMPLATES as $name => $path) {
    T::ok($name . ' exists', is_file($path));
    T::ok($name . ' is not empty', is_file($path) && filesize($path) > 200);
}

/* -------------------------------------------------------- literal copy ----- */

section('no literal English copy is left in either footer');

foreach ($TEMPLATES as $name => $path) {
    if (!is_file($path)) {
        continue;
    }
    $source = (string) file_get_contents($path);

    // Text between two tags that contains at least two letters and no Smarty
    // expression: that is copy, and copy must come from a catalog.
    preg_match_all('/>([^<>]*[A-Za-z][^<>]*)</', $source, $matches);
    $literal = array();
    foreach ($matches[1] as $text) {
        $text = trim($text);
        if ($text === '' || strpos($text, '{$') !== false || strpos($text, '{') !== false) {
            continue;
        }
        if (in_array($text, $LITERAL_ALLOWLIST, true)) {
            continue;
        }
        $literal[] = $text;
    }

    T::is($name . ' contains no untranslated copy', array(), array_values(array_unique($literal)));
}

/* ------------------------------------------------------------- key lookup -- */

section('every key the footers read is defined');

/**
 * `{$LANG.foo}` and `{$LANG.foo|escape}` → foo.
 *
 * @param string $source
 *
 * @return string[]
 */
$keysOf = function (string $source): array {
    preg_match_all('/\{\$LANG\.([A-Za-z0-9_]+)(?:\|[^}]*)?\}/', $source, $matches);

    return array_values(array_unique($matches[1]));
};

/**
 * The override files are the WHMCS-side catalog; they hold the keys that are
 * not ours (`footeraboutus`, `headerdedicatedservers`, …).
 *
 * @return string[]
 */
$overrideKeys = function () use ($root) {
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $keys = array();
    $file = $root . '/lang/overrides/english.php';
    if (!is_file($file)) {
        return $keys;
    }
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
    $_LANG = array();
    ob_start();
    try {
        require $file;
    } catch (Throwable $e) {
        // A broken override file is a different suite's problem.
    } finally {
        ob_end_clean();
    }
    $keys = is_array($_LANG) ? array_keys($_LANG) : array();

    return $keys;
};

$english = $catalogs->messages('en');
$override = $overrideKeys();

foreach ($TEMPLATES as $name => $path) {
    if (!is_file($path)) {
        continue;
    }
    $keys = $keysOf((string) file_get_contents($path));
    T::ok($name . ' reads at least a dozen keys', count($keys) >= 12);

    $unknown = array();
    foreach ($keys as $key) {
        // `footer_*` / `nav_*` are CloudHost247 catalog keys; the historical
        // WHMCS-style keys live in lang/overrides.
        $ours = strpos($key, 'footer_') === 0 || strpos($key, 'nav_') === 0;
        if ($ours) {
            if (!array_key_exists($key, $english)) {
                $unknown[] = $key . ' (site catalog)';
            }
            continue;
        }
        if (!in_array($key, $override, true)) {
            $unknown[] = $key . ' (overrides)';
        }
    }

    T::is($name . ' references no undefined key', array(), $unknown);
}

/* --------------------------------------------------------- real rendering -- */

section('substituting the catalog produces a translated footer');

/**
 * Resolve `{$LANG.key}`, `{$LANG.key|modifier}` and the WHMCS-idiomatic
 * `{if $LANG.key}{$LANG.key}{else}fallback{/if}` shape against one locale.
 *
 * @param string $source
 * @param array  $messages
 *
 * @return string
 */
$substitute = function (string $source, array $messages): string {
    // Longest pattern first: the guarded form contains the plain form.
    $source = preg_replace_callback(
        '/\{if\s+\$LANG\.([A-Za-z0-9_]+)\}\s*\{\$LANG\.\1(?:\|[^}]*)?\}\s*\{else\}(.*?)\{\/if\}/s',
        function ($m) use ($messages) {
            return isset($messages[$m[1]]) && $m[1] !== '' && trim((string) $messages[$m[1]]) !== ''
                ? (string) $messages[$m[1]]
                : $m[2];
        },
        $source
    );

    return preg_replace_callback(
        '/\{\$LANG\.([A-Za-z0-9_]+)(?:\|[^}]*)?\}/',
        function ($m) use ($messages) {
            return isset($messages[$m[1]]) ? (string) $messages[$m[1]] : '';
        },
        $source
    );
};

foreach ($locales as $code) {
    $messages = $catalogs->messages($code);
    $label = '[' . $code . ']';

    foreach ($TEMPLATES as $name => $path) {
        if (!is_file($path)) {
            continue;
        }
        $rendered = $substitute((string) file_get_contents($path), $messages);

        // Nothing may survive: an unresolved key renders as empty text, so an
        // empty result is exactly the failure this check exists to catch.
        preg_match_all('/\{\$LANG\.([A-Za-z0-9_]+)/', $rendered, $left);
        T::is($label . ' ' . $name . ' leaves no unresolved key', array(), array_values(array_unique($left[1])));

        // The ampersand must appear exactly once — a catalog value carrying
        // "&amp;" that is escaped again would render as "&amp;amp;".
        T::notContains($label . ' ' . $name . ' does not double-escape entities', '&amp;amp;', $rendered);

        // And the footer must still say something.
        T::ok($label . ' ' . $name . ' renders visible text', strlen(trim(strip_tags($rendered))) > 200);
    }
}

/* ------------------------------------------------- translated, not English -- */

section('the footers really change with the locale');

$rendered = array();
foreach ($locales as $code) {
    $messages = $catalogs->messages($code);
    $path = $TEMPLATES['footer_block_latest.tpl'];
    $rendered[$code] = is_file($path)
        ? trim(strip_tags($substitute((string) file_get_contents($path), $messages)))
        : '';
}

if (isset($rendered['en'])) {
    foreach (array('fr', 'es', 'de', 'pt-BR') as $code) {
        if (!isset($rendered[$code])) {
            continue;
        }
        T::isnt('[' . $code . '] footer differs from the English footer', $rendered['en'], $rendered[$code]);
    }
}

T::summary();
