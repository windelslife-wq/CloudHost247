<?php
/**
 * Language switching: selection, persistence and independence from currency.
 *
 * The independence requirement is the headline: a customer may prefer English
 * with NGN, French with USD, or any other pairing. Every test in the
 * "independence" section below switches one preference and asserts the other
 * did not move.
 *
 * Run: php tests/i18n/LocalePreferenceTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\CatalogRepository;
use CloudHost247\I18n\Locale;
use CloudHost247\I18n\LocaleRegistry;
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\Preference\ChainedPreferenceStore;
use CloudHost247\I18n\SelectionResult;

/* ------------------------------------------------------ registry & status -- */

section('locale registry marks incomplete languages as unselectable');

$catalogs = new CatalogRepository(ch247_catalog_dir());
$locales = new LocaleRegistry($catalogs, 'en');

T::ok('English is registered', $locales->has('en'));
T::ok('French is registered', $locales->has('fr'));
T::ok('case and separators are normalised', $locales->has('PT_br'));
T::ok('an unknown locale is not registered', !$locales->has('xx'));

foreach (array('en', 'fr', 'es', 'de', 'pt-BR') as $code) {
    T::is('[' . $code . '] is complete', Locale::STATUS_COMPLETE, $locales->resolve($code)->getStatus());
    T::ok('[' . $code . '] is selectable', $locales->resolve($code)->isSelectable());
}

T::is('five locales are offered to customers', 5, count($locales->selectable()));
T::is('the default locale is English', 'en', $locales->getDefaultCode());

// A registered-but-unfinished locale must never reach the switcher.
$partial = new LocaleRegistry($catalogs, 'en', array(
    new Locale('nl', 'Dutch', 'Nederlands', 'nl', 'ltr', Locale::STATUS_PARTIAL),
    new Locale('it', 'Italian', 'Italiano', 'it', 'ltr', Locale::STATUS_PLANNED),
));
T::ok('a partial locale is not selectable', !$partial->resolve('nl')->isSelectable());
T::ok('a planned locale is not selectable', !$partial->resolve('it')->isSelectable());
T::is('neither appears in the selectable list', 5, count($partial->selectable()));

/* -------------------------------------------------------- language switch -- */

section('selecting a language');

$store = new ArrayPreferenceStore();
$i18n = ch247_make_i18n(array('store' => $store));
$prefs = $i18n->preferences();

T::is('the default language is English', 'en', $prefs->getLanguage());

T::ok('switching to French succeeds', $prefs->setLanguage('fr')->isOk());
T::is('the active language is now French', 'fr', $prefs->getLanguage());
T::is('the preference was persisted', 'fr', $store->get('language'));

T::ok('switching to Brazilian Portuguese succeeds', $prefs->setLanguage('pt-BR')->isOk());
T::is('the active language is now pt-BR', 'pt-BR', $prefs->getLanguage());

T::ok('messy casing is accepted', $prefs->setLanguage('DE_de')->isOk());
T::is('DE_de resolves to German', 'de', $prefs->getLanguage());

/* ---------------------------------------------------- invalid selections --- */

section('invalid language selections fall back safely');

$store2 = new ArrayPreferenceStore();
$prefs2 = ch247_make_i18n(array('store' => $store2))->preferences();
$prefs2->setLanguage('fr');

$result = $prefs2->setLanguage('xx');
T::ok('an unknown language fails', !$result->isOk());
T::is('the failure is labelled unknown', SelectionResult::ERROR_UNKNOWN, $result->getError());
T::ok('a fallback was used', $result->usedFallback());
T::is('the language did not change', 'fr', $prefs2->getLanguage());
T::is('the stored preference did not change', 'fr', $store2->get('language'));

$result = $prefs2->setLanguage('');
T::ok('an empty language fails', !$result->isOk());
T::is('the failure is labelled empty', SelectionResult::ERROR_EMPTY, $result->getError());
T::is('the language still did not change', 'fr', $prefs2->getLanguage());

$result = $prefs2->setLanguage(null);
T::ok('a null language fails', !$result->isOk());
T::is('the language is untouched', 'fr', $prefs2->getLanguage());

// A registered-but-incomplete locale is refused too.
$partialPrefs = ch247_make_i18n(array(
    'store' => new ArrayPreferenceStore(),
    'locales' => array(new Locale('nl', 'Dutch', 'Nederlands', 'nl', 'ltr', Locale::STATUS_PARTIAL)),
))->preferences();
$result = $partialPrefs->setLanguage('nl');
T::ok('a partial language is refused', !$result->isOk());
T::is('the failure is labelled disabled', SelectionResult::ERROR_DISABLED, $result->getError());
T::is('the language falls back to the default', 'en', $partialPrefs->getLanguage());

/* ------------------------------------------------------------ persistence -- */

section('preferences persist across requests');

$sharedBag = array('language' => 'es', 'currency' => 3);

// Request 1: a fresh object over a store that already holds a choice.
$first = ch247_make_i18n(array('store' => new ArrayPreferenceStore($sharedBag)))->preferences();
T::is('a returning visitor gets their stored language', 'es', $first->getLanguage());
T::is('a returning visitor gets their stored currency', 'EUR', $first->getCurrency()->getCode());

// Request 2: switch language, then simulate the next request with a new object.
$first->setLanguage('de');
$sharedBag['language'] = 'de';
$second = ch247_make_i18n(array('store' => new ArrayPreferenceStore($sharedBag)))->preferences();
T::is('the new language survives into the next request', 'de', $second->getLanguage());
T::is('the currency survives too', 'EUR', $second->getCurrency()->getCode());

// A store that holds a locale which has since been withdrawn must not break.
$stale = ch247_make_i18n(array('store' => new ArrayPreferenceStore(array('language' => 'zz'))))->preferences();
T::is('a withdrawn language resolves to the default', 'en', $stale->getLanguage());

// A store holding an incomplete locale resolves to the default as well.
$stalePartial = ch247_make_i18n(array(
    'store' => new ArrayPreferenceStore(array('language' => 'nl')),
    'locales' => array(new Locale('nl', 'Dutch', 'Nederlands', 'nl', 'ltr', Locale::STATUS_PARTIAL)),
))->preferences();
T::is('a stored partial language resolves to the default', 'en', $stalePartial->getLanguage());

/* ----------------------------------------------------------- independence -- */

section('language and currency are independent');

$store3 = new ArrayPreferenceStore();
$prefs3 = ch247_make_i18n(array('store' => $store3))->preferences();

// English + NGN (the shop default).
T::is('start: language en', 'en', $prefs3->getLanguage());
T::is('start: currency NGN', 'NGN', $prefs3->getCurrency()->getCode());

// Switch currency only.
$prefs3->setCurrency(2);
T::is('currency is now USD', 'USD', $prefs3->getCurrency()->getCode());
T::is('language is still English', 'en', $prefs3->getLanguage());

// Switch language only — the currency must not move.
$prefs3->setLanguage('fr');
T::is('language is now French', 'fr', $prefs3->getLanguage());
T::is('currency is still USD', 'USD', $prefs3->getCurrency()->getCode());

// And back again.
$prefs3->setCurrency(4);
T::is('currency is now GBP', 'GBP', $prefs3->getCurrency()->getCode());
T::is('language is still French', 'fr', $prefs3->getLanguage());

$prefs3->setLanguage('pt-BR');
T::is('language is now pt-BR', 'pt-BR', $prefs3->getLanguage());
T::is('currency is still GBP', 'GBP', $prefs3->getCurrency()->getCode());

T::is('the two preferences live under separate keys', array('currency', 'language'), array_keys($store3->all()));

// A failed switch of one must not disturb the other.
$prefs3->setLanguage('nope');
T::is('a failed language switch leaves the currency alone', 'GBP', $prefs3->getCurrency()->getCode());
$prefs3->setCurrency(999);
T::is('a failed currency switch leaves the language alone', 'pt-BR', $prefs3->getLanguage());

/* ------------------------------------------------------- request handling -- */

section('?language= / ?currency= request parameters');

$store4 = new ArrayPreferenceStore();
$prefs4 = ch247_make_i18n(array('store' => $store4))->preferences();

$applied = $prefs4->applyRequest(array('language' => 'es', 'currency' => '3'));
T::ok('a language parameter was applied', $applied['language'] !== null && $applied['language']->isOk());
T::ok('a currency parameter was applied', $applied['currency'] !== null && $applied['currency']->isOk());
T::is('language is now Spanish', 'es', $prefs4->getLanguage());
T::is('currency is now EUR', 'EUR', $prefs4->getCurrency()->getCode());

// A plain page visit without the parameters must not reset anything.
$prefs4->applyRequest(array());
T::is('an empty request keeps the language', 'es', $prefs4->getLanguage());
T::is('an empty request keeps the currency', 'EUR', $prefs4->getCurrency()->getCode());

// Only one parameter present: the other is untouched.
$prefs4->applyRequest(array('language' => 'de'));
T::is('language switched', 'de', $prefs4->getLanguage());
T::is('currency untouched', 'EUR', $prefs4->getCurrency()->getCode());

// A bad parameter is reported, and the previous choice is kept.
$bad = $prefs4->applyRequest(array('language' => 'klingon'));
T::ok('a bad language parameter is reported as a failure', $bad['language'] !== null && !$bad['language']->isOk());
T::is('the previous language is kept', 'de', $prefs4->getLanguage());

/* -------------------------------------------------------- chained stores --- */

section('chained preference stores resolve in priority order');

$session = new ArrayPreferenceStore(array('language' => 'fr'));
$account = new ArrayPreferenceStore(array('language' => 'de', 'currency' => 4));
$chain = new ChainedPreferenceStore(array($session, $account));

T::is('the higher-priority store wins', 'fr', $chain->get('language'));
T::is('a key only in the lower store is still found', 4, $chain->get('currency'));
T::is('a missing key returns the default', 'fallback', $chain->get('nope', 'fallback'));
T::ok('the chain is writable', $chain->isWritable());

$chain->set('language', 'es');
T::is('writes land in every store', 'es', $session->get('language'));
T::is('the lower store is updated too, so they cannot drift', 'es', $account->get('language'));

$readOnly = new ChainedPreferenceStore(array(new ArrayPreferenceStore(array(), false)));
T::ok('a chain of read-only stores reports not writable', !$readOnly->isWritable());
$readOnly->set('language', 'fr');
T::is('writing to a read-only chain still works in memory', 'fr', $readOnly->get('language'));

T::summary();
