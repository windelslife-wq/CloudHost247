<?php
/**
 * Catalog integrity gate.
 *
 * This is the test that backs the promise "a language is not marked supported
 * until its translations are complete". It checks, for every catalog on disk:
 *
 *  - the meta block is well formed (code, names, direction, status, plural rule)
 *  - every key in the English reference exists, with a non-empty value
 *  - no key exists that English does not define (no drift, no typos)
 *  - placeholder sets match English exactly
 *  - plural forms are valid CLDR categories with a usable "other"
 *  - any locale declaring status "complete" passes all of the above, and is
 *    therefore the only kind of locale the switcher will offer
 *  - no catalog contains the malformed private-use bullet U+F0B7 or other
 *    encoding damage — the content-side half of the repository hygiene check
 *
 * Run: php tests/i18n/CatalogIntegrityTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\CatalogRepository;
use CloudHost247\I18n\Locale;
use CloudHost247\I18n\PluralRule;
use CloudHost247\I18n\Translator;

$dir = ch247_catalog_dir();
$catalogs = new CatalogRepository($dir);
$locales = $catalogs->availableLocales();

/* --------------------------------------------------------------- presence -- */

section('catalog directory and reference locale');

T::ok('lang/site exists', is_dir($dir));
T::ok('at least two locales are present', count($locales) >= 2);
T::contains('English is present', 'en', $locales);
T::contains('French is present', 'fr', $locales);
T::contains('Spanish is present', 'es', $locales);
T::contains('German is present', 'de', $locales);
T::contains('Portuguese (Brazil) is present', 'pt-BR', $locales);

$reference = $catalogs->messages('en');
$referenceKeys = array_keys($reference);
T::ok('the reference catalog is non-trivial', count($referenceKeys) > 150);

/* ------------------------------------------------------------------ meta --- */

section('every catalog declares usable metadata');

foreach ($locales as $code) {
    $meta = $catalogs->meta($code);
    $label = '[' . $code . ']';

    T::is($label . ' meta.locale matches the file name', $code, isset($meta['locale']) ? $meta['locale'] : null);
    T::ok($label . ' has an English name', !empty($meta['english_name']));
    T::ok($label . ' has a native name', !empty($meta['native_name']));
    T::ok($label . ' has a flag code', !empty($meta['flag']));
    T::ok(
        $label . ' direction is ltr or rtl',
        isset($meta['direction']) && in_array($meta['direction'], array('ltr', 'rtl'), true)
    );
    T::ok(
        $label . ' status is one of the known values',
        isset($meta['status']) && in_array($meta['status'], Locale::validStatuses(), true)
    );
    T::ok($label . ' declares a plural rule', !empty($meta['plural_rule']));
    T::ok($label . ' declares an intl tag', !empty($meta['intl_tag']));

    // The plural rule must actually be one PluralRule understands; an unknown
    // id silently degrades to the English rule, which is a bug not a feature.
    $ruleId = isset($meta['plural_rule']) ? $meta['plural_rule'] : '';
    $knownRules = array('en', 'de', 'fr', 'es', 'pt', 'pt-br', 'it', 'nl', 'sv', 'da', 'nb', 'no', 'et', 'tr', 'ru', 'ar', 'pl', 'cs', 'he', 'fa');
    T::ok($label . ' plural rule "' . $ruleId . '" is implemented', in_array(strtolower($ruleId), $knownRules, true));
}

/* ------------------------------------------------------------- key parity -- */

section('key parity against the English reference');

$placeholdersOf = function ($value) {
    if (is_array($value)) {
        $out = array();
        foreach ($value as $form) {
            preg_match_all(Translator::PLACEHOLDER_PATTERN, (string) $form, $m);
            foreach ($m[1] as $name) {
                $out[$name] = true;
            }
        }
        ksort($out);

        return array_keys($out);
    }
    preg_match_all(Translator::PLACEHOLDER_PATTERN, (string) $value, $m);
    $found = array_unique($m[1]);
    sort($found);

    return $found;
};

foreach ($locales as $code) {
    if ($code === 'en') {
        continue;
    }
    $messages = $catalogs->messages($code);
    $label = '[' . $code . ']';

    $missing = array();
    $extra = array();
    $empty = array();
    $placeholderDrift = array();

    foreach ($referenceKeys as $key) {
        if (!array_key_exists($key, $messages)) {
            $missing[] = $key;
            continue;
        }
        $value = $messages[$key];

        if (is_array($value)) {
            $problems = PluralRule::validateForms($value);
            if ($problems) {
                $empty[] = $key . ' (' . implode('; ', $problems) . ')';
            }
        } elseif (!is_string($value) || trim($value) === '') {
            $empty[] = $key;
        }

        $expected = $placeholdersOf($reference[$key]);
        $actual = $placeholdersOf($value);
        if ($expected !== $actual) {
            $placeholderDrift[] = $key . ' expected {' . implode('},{', $expected)
                . '} got {' . implode('},{', $actual) . '}';
        }
    }

    foreach (array_keys($messages) as $key) {
        if (!array_key_exists($key, $reference)) {
            $extra[] = $key;
        }
    }

    T::is($label . ' defines every English key', array(), $missing);
    T::is($label . ' defines no unknown keys', array(), $extra);
    T::is($label . ' has no empty or invalid values', array(), $empty);
    T::is($label . ' placeholders match English', array(), $placeholderDrift);
}

/* ------------------------------------------------------- "complete" gates -- */

section('locales marked complete really are complete');

$complete = array();
foreach ($locales as $code) {
    $meta = $catalogs->meta($code);
    if (isset($meta['status']) && $meta['status'] === Locale::STATUS_COMPLETE) {
        $complete[] = $code;
    }
}

T::ok('at least one locale is complete', count($complete) >= 1);
T::contains('English is complete', 'en', $complete);
T::contains('French is complete', 'fr', $complete);
T::contains('Spanish is complete', 'es', $complete);
T::contains('German is complete', 'de', $complete);
T::contains('Portuguese (Brazil) is complete', 'pt-BR', $complete);

// A locale may not declare itself complete while missing keys: that is the
// exact failure mode the switcher's isSelectable() gate exists to prevent.
foreach ($locales as $code) {
    $meta = $catalogs->meta($code);
    if (!isset($meta['status']) || $meta['status'] !== Locale::STATUS_COMPLETE) {
        continue;
    }
    $messages = $catalogs->messages($code);
    $missingCount = 0;
    foreach ($referenceKeys as $key) {
        if (!array_key_exists($key, $messages) || (is_string($messages[$key]) && trim($messages[$key]) === '')) {
            $missingCount++;
        }
    }
    T::is('[' . $code . '] complete implies zero missing keys', 0, $missingCount);
    T::is('[' . $code . '] complete implies full key count', count($referenceKeys), count($messages));
}

/* ---------------------------------------------------- translation quality -- */

section('translations actually differ from English');

/**
 * Keys whose value is legitimately identical in every language.
 *
 * Each entry is justified: either the string is nothing but placeholders, or
 * it names a product that ships under its English name. Adding to this list
 * needs a reason in the comment, which is the point.
 */
$identicalAllowlist = array(
    'currency_aria_price',        // "{amount} {code}" — placeholders only
    'nav_vps_public_cloud',       // product name, sold as "VPS Public Cloud"
    'nav_vps_private_cloud',      // product name, sold as "VPS Private Cloud"
    'nav_cpanel_hosting',         // cPanel is a trademark
    'nav_plesk_hosting',          // Plesk is a trademark
    'nav_wordpress_hosting',      // WordPress is a trademark
    'nav_windows_hosting',        // Windows is a trademark
    'nav_ssl_certificates',       // "SSL" is not translated
    'nav_whois',                  // WHOIS is a protocol name
    'nav_tld_directory',          // TLD is not translated
);

foreach ($locales as $code) {
    if ($code === 'en') {
        continue;
    }
    $messages = $catalogs->messages($code);
    $identical = array();
    foreach ($referenceKeys as $key) {
        if (in_array($key, $identicalAllowlist, true)) {
            continue;
        }
        $ref = $reference[$key];
        $val = isset($messages[$key]) ? $messages[$key] : null;
        if (!is_array($ref) && is_string($ref) && $ref === $val) {
            // Strings that are only placeholders ("{amount} {code}") are
            // identical by design; anything longer that matches English is an
            // untranslated entry that slipped through.
            if (strlen($ref) > 12 && strpos($ref, '{') === false) {
                $identical[] = $key;
            }
        }
    }
    T::is('[' . $code . '] untranslated long strings', array(), $identical);
}

// The allowlist itself must not rot: every entry has to exist in English.
$staleAllowlist = array_diff($identicalAllowlist, $referenceKeys);
T::is('the identical-string allowlist references real keys', array(), array_values($staleAllowlist));

/* --------------------------------------------------------- encoding damage -- */

section('catalogs carry no encoding damage');

/**
 * Characters that must never reach a published catalog:
 *  - U+F0B7  the Wingdings private-use bullet this project keeps re-importing
 *  - U+FFFD  the replacement character (a decode that already went wrong)
 *  - C1 controls, which indicate a bad cp1252 → UTF-8 conversion
 */
$forbidden = array(
    "" => 'U+F0B7 private-use bullet',
    "�" => 'U+FFFD replacement character',
    "\xC2\x80" => 'C1 control U+0080',
);

foreach ($locales as $code) {
    $path = $dir . '/' . $code . '.php';
    $raw = is_file($path) ? file_get_contents($path) : '';
    T::ok('[' . $code . '] catalog file is readable', $raw !== '');

    foreach ($forbidden as $needle => $name) {
        T::is('[' . $code . '] contains no ' . $name, false, strpos($raw, $needle) !== false);
    }

    // Two-space runs and stray tabs are how a Word paste reveals itself.
    T::is(
        '[' . $code . '] has no mojibake "Ã©"-style sequences',
        false,
        (bool) preg_match('/\xC3\x83[\xC2\x80-\xC2\xBF]/', $raw)
    );
}

/* -------------------------------------------------------- translate smoke --- */

section('every supported locale renders a real page string');

foreach ($complete as $code) {
    $i18n = null;
    $out = array();
    try {
        $i18n = ch247_make_i18n(array('default_locale' => 'en'));
        $i18n->preferences()->setLanguage($code);
        $translator = $i18n->translator();
        foreach ($referenceKeys as $key) {
            $out[$key] = $translator->trans($key, array(
                'count' => 3,
                'name' => 'Ada',
                'company' => 'CloudHost247',
                'year' => '2026',
                'field' => 'Email',
                'min' => '2',
                'max' => '64',
                'number' => '1042',
                'amount' => '12.00',
                'date' => '2026-10-12',
                'days' => '30',
                'domain' => 'example.com',
                'query' => 'ssl',
                'minutes' => '60',
                'displayed' => 'USD',
                'charged' => 'NGN',
                'from' => 'NGN',
                'source' => 'WHMCS',
                'code' => 'USD',
                'linebreak' => "\n",
            ));
        }
    } catch (Throwable $e) {
        T::ok('[' . $code . '] translation pass threw: ' . $e->getMessage(), false);
        continue;
    }

    $unrendered = array();
    foreach ($out as $key => $rendered) {
        // A rendered string still containing "{...}" means a placeholder was
        // not supplied by the caller — fine — but a *raw key* means the
        // message itself is missing.
        if ($rendered === str_replace('_', ' ', $key)) {
            $unrendered[] = $key;
        }
    }
    T::is('[' . $code . '] no message fell back to its key', array(), $unrendered);
    T::is('[' . $code . '] translator recorded no misses', array(), $i18n->translator()->getMisses());
}

T::summary();
