<?php
/**
 * Translator behaviour: lookup, fallback chain, interpolation and plurals.
 *
 * The fallback chain is the part most likely to regress silently — a missing
 * French string that quietly renders English is a bug the customer sees and
 * the developer does not — so every step of it is pinned here.
 *
 * Run: php tests/i18n/TranslatorTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\CatalogRepository;
use CloudHost247\I18n\LocaleRegistry;
use CloudHost247\I18n\PluralRule;
use CloudHost247\I18n\Translator;

$catalogs = new CatalogRepository(ch247_catalog_dir());
$locales = new LocaleRegistry($catalogs, 'en');
$translator = new Translator($locales, $catalogs);

/* ------------------------------------------------------------ basic lookup -- */

section('lookup and missing keys');

T::is('English resolves a nav key', 'Hosting', $translator->trans('nav_hosting'));
T::is('French resolves the same key', 'Hébergement', $translator->trans('nav_hosting', array(), 'fr'));
T::is('German resolves the same key', 'Hosting', $translator->trans('nav_hosting', array(), 'de'));
T::is('Spanish resolves the same key', 'Alojamiento', $translator->trans('nav_hosting', array(), 'es'));
T::is('Brazilian Portuguese resolves the same key', 'Hospedagem', $translator->trans('nav_hosting', array(), 'pt-BR'));

$translator->resetDiagnostics();
T::is('an unknown key renders readably', 'does not exist here', $translator->trans('does_not_exist_here'));
T::is('an unknown key is recorded as a miss', 1, count($translator->getMisses()));
T::is('the miss names the key', 'does_not_exist_here', $translator->getMisses()[0]['key']);

/* ------------------------------------------------------------ fallback chain */

section('fallback chain');

// Only *registered* locales join the chain. pt-BR declares a parent of "pt",
// but no pt catalog ships yet, so the chain is pt-BR then the default. Drop a
// pt catalog in lang/site/ and it joins automatically — proved below.
T::is('pt-BR falls back to en while pt is unregistered', array('pt-BR', 'en'), $locales->fallbackChain('pt-BR'));
T::is('fr has no parent, so en is next', array('fr', 'en'), $locales->fallbackChain('fr'));
T::is('en is its own chain', array('en'), $locales->fallbackChain('en'));
T::is('an unregistered locale still ends at en', array('en'), $locales->fallbackChain('kl'));
T::is('messy casing is normalised', array('pt-BR', 'en'), $locales->fallbackChain('PT_br'));

// Prove the parent hookup works once the parent exists.
$chainDir = sys_get_temp_dir() . '/ch247-chain-' . getmypid();
if (!is_dir($chainDir)) {
    mkdir($chainDir, 0777, true);
}
file_put_contents($chainDir . '/en.php', "<?php return array('meta' => array('locale' => 'en', 'status' => 'complete'), 'messages' => array('greeting' => 'Hello'));");
file_put_contents($chainDir . '/pt.php', "<?php return array('meta' => array('locale' => 'pt', 'status' => 'complete', 'fallback' => 'en'), 'messages' => array('greeting' => 'Olá'));");
file_put_contents($chainDir . '/pt-BR.php', "<?php return array('meta' => array('locale' => 'pt-BR', 'status' => 'complete', 'fallback' => 'pt'), 'messages' => array('greeting' => 'Oi'));");

$chainCatalogs = new CatalogRepository($chainDir);
$chainLocales = new LocaleRegistry($chainCatalogs, 'en');
T::is('a registered parent joins the chain', array('pt-BR', 'pt', 'en'), $chainLocales->fallbackChain('pt-BR'));

$chainTranslator = new Translator($chainLocales, $chainCatalogs);
$chainTranslator->setLocale('pt-BR');
T::is('pt-BR wins over pt for its own key', 'Oi', $chainTranslator->trans('greeting'));
unlink($chainDir . '/pt-BR.php');
$chainCatalogs->reset();
$chainLocales2 = new LocaleRegistry($chainCatalogs, 'en');
T::is('without pt-BR, the chain still reaches pt', array('pt', 'en'), $chainLocales2->fallbackChain('pt-BR'));

unlink($chainDir . '/en.php');
unlink($chainDir . '/pt.php');
rmdir($chainDir);

// A key deliberately absent from pt-BR and pt must still render, in English,
// and be reported as a fallback rather than a miss.
$translator->resetDiagnostics();
$translator->setLocale('pt-BR');
T::is('pt-BR renders its own string when present', 'Hospedagem', $translator->trans('nav_hosting'));
T::is('no fallback was needed for a translated key', array(), $translator->getFallbacks());

/* -------------------------------------------------------- empty-string skip -- */

section('empty strings are treated as untranslated');

$tempDir = sys_get_temp_dir() . '/ch247-i18n-test-' . getmypid();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}
file_put_contents($tempDir . '/en.php', '<?php return array('
    . "'meta' => array('locale' => 'en', 'status' => 'complete', 'english_name' => 'English'),"
    . "'messages' => array('greeting' => 'Hello', 'body' => 'Body text'));");
file_put_contents($tempDir . '/fr.php', '<?php return array('
    . "'meta' => array('locale' => 'fr', 'status' => 'complete', 'english_name' => 'French'),"
    . "'messages' => array('greeting' => '', 'body' => 'Texte'));");

$tempCatalogs = new CatalogRepository($tempDir);
$tempLocales = new LocaleRegistry($tempCatalogs, 'en');
$tempTranslator = new Translator($tempLocales, $tempCatalogs);
$tempTranslator->setLocale('fr');

T::is('an empty French value falls back to English', 'Hello', $tempTranslator->trans('greeting'));
T::is('a present French value is used', 'Texte', $tempTranslator->trans('body'));
T::is('the fallback is reported', 'greeting', $tempTranslator->getFallbacks()[0]['key']);
T::is('the fallback names the locale that answered', 'en', $tempTranslator->getFallbacks()[0]['resolved']);

unlink($tempDir . '/en.php');
unlink($tempDir . '/fr.php');
rmdir($tempDir);

/* ---------------------------------------------------------- interpolation --- */

section('interpolation and escaping');

$translator->setLocale('en');
T::is(
    'a single placeholder is replaced',
    '© 2026 CloudHost247. All rights reserved.',
    $translator->trans('footer_copyright', array('year' => '2026', 'company' => 'CloudHost247'))
);
T::is(
    'several placeholders are replaced',
    'Invoice #1042',
    $translator->trans('client_invoice_number', array('number' => '1042'))
);
T::is(
    'an unknown placeholder is left visible',
    'Invoice #{number}',
    $translator->trans('client_invoice_number')
);
T::is(
    'values are HTML-escaped by default',
    '© 2026 A &amp; B. All rights reserved.',
    $translator->trans('footer_copyright', array('year' => '2026', 'company' => 'A & B'))
);
T::is(
    'a script tag in a value is neutralised',
    '© 2026 &lt;script&gt;alert(1)&lt;/script&gt;. All rights reserved.',
    $translator->trans('footer_copyright', array('year' => '2026', 'company' => '<script>alert(1)</script>'))
);
T::is(
    'escaping is applied to the interpolated value, not the message',
    '© 2026 &lt;b&gt;bold&lt;/b&gt;. All rights reserved.',
    $translator->trans('footer_copyright', array('year' => '2026', 'company' => '<b>bold</b>'))
);
T::is(
    'transRaw() skips escaping',
    '© 2026 A & B. All rights reserved.',
    $translator->transRaw('footer_copyright', array('year' => '2026', 'company' => 'A & B'))
);
T::is(
    'booleans render as 1/0',
    'Hosting',
    $translator->trans('nav_hosting', array('unused' => true))
);

// A raw translator instance must not escape.
$rawTranslator = new Translator($locales, $catalogs, false);
T::is(
    'escape:false leaves values untouched',
    '© 2026 A & B. All rights reserved.',
    $rawTranslator->trans('footer_copyright', array('year' => '2026', 'company' => 'A & B'))
);

/* ----------------------------------------------------------- pluralisation -- */

section('CLDR pluralisation');

T::is('en: 1 -> one', 'one', PluralRule::category('en', 1));
T::is('en: 0 -> other', 'other', PluralRule::category('en', 0));
T::is('en: 2 -> other', 'other', PluralRule::category('en', 2));
T::is('fr: 0 -> one', 'one', PluralRule::category('fr', 0));
T::is('fr: 1 -> one', 'one', PluralRule::category('fr', 1));
T::is('fr: 2 -> other', 'other', PluralRule::category('fr', 2));
T::is('fr: 1000000 -> many', 'many', PluralRule::category('fr', 1000000));
T::is('pt: 1 -> one', 'one', PluralRule::category('pt', 1));
T::is('pt: 2 -> other', 'other', PluralRule::category('pt', 2));
T::is('es: 1 -> one', 'one', PluralRule::category('es', 1));
T::is('de: 1 -> one', 'one', PluralRule::category('de', 1));
T::is('ru: 1 -> one', 'one', PluralRule::category('ru', 1));
T::is('ru: 2 -> few', 'few', PluralRule::category('ru', 2));
T::is('ru: 5 -> many', 'many', PluralRule::category('ru', 5));
T::is('ar: 0 -> zero', 'zero', PluralRule::category('ar', 0));
T::is('ar: 3 -> few', 'few', PluralRule::category('ar', 3));
T::is('an unknown rule degrades to the one/other rule (singular)', 'one', PluralRule::category('xx-unknown', 1));
T::is('an unknown rule degrades to the one/other rule (plural)', 'other', PluralRule::category('xx-unknown', 2));

$translator->setLocale('en');
T::is('en: 1 item', '1 item', $translator->transChoice('cart_item_count', 1));
T::is('en: 0 items', '0 items', $translator->transChoice('cart_item_count', 0));
T::is('en: 5 items', '5 items', $translator->transChoice('cart_item_count', 5));
T::is('en groups large counts', '1,234 items', $translator->transChoice('cart_item_count', 1234));

T::is('fr: 1 article', '1 article', $translator->transChoice('cart_item_count', 1, array(), 'fr'));
T::is('fr: 0 article (French singular for zero)', '0 article', $translator->transChoice('cart_item_count', 0, array(), 'fr'));
T::is('fr: 5 articles', '5 articles', $translator->transChoice('cart_item_count', 5, array(), 'fr'));
T::is('fr groups with a space', '1 234 articles', $translator->transChoice('cart_item_count', 1234, array(), 'fr'));

T::is('de: 1 Artikel', '1 Artikel', $translator->transChoice('cart_item_count', 1, array(), 'de'));
T::is('es: 1 artículo', '1 artículo', $translator->transChoice('cart_item_count', 1, array(), 'es'));
T::is('es: 3 artículos', '3 artículos', $translator->transChoice('cart_item_count', 3, array(), 'es'));
T::is('pt-BR: 1 item', '1 item', $translator->transChoice('cart_item_count', 1, array(), 'pt-BR'));
T::is('pt-BR: 4 itens', '4 itens', $translator->transChoice('cart_item_count', 4, array(), 'pt-BR'));

// trans() on a plural message must pick the "other" form, not blow up.
T::is('trans() on a plural key uses the other form', '5 items', $translator->trans('cart_item_count', array('count' => '5')));

/* ------------------------------------------------- plural form validation -- */

section('plural form validation');

T::is('a valid pair passes', array(), PluralRule::validateForms(array('one' => 'a', 'other' => 'b')));
T::is('a missing other is reported', array('missing required "other" plural form'), PluralRule::validateForms(array('one' => 'a')));
T::is(
    'an unknown category is reported',
    array('unknown plural category "several"', 'missing required "other" plural form'),
    PluralRule::validateForms(array('several' => 'a'))
);
T::is(
    'an empty form is reported',
    array('empty plural form for category "one"'),
    PluralRule::validateForms(array('one' => '', 'other' => 'b'))
);

/* ------------------------------------------------------------ has() probe --- */

section('has() distinguishes present from absent');

T::ok('has() is true for a translated key', $translator->has('nav_hosting'));
T::ok('has() is false for an unknown key', !$translator->has('nope_not_here'));
T::ok('has() honours a locale argument', $translator->has('nav_hosting', 'fr'));

T::summary();
