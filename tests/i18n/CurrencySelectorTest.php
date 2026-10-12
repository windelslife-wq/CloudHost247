<?php
/**
 * Currency switching: selection, validation, persistence and provenance.
 *
 * Run: php tests/i18n/CurrencySelectorTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\CurrencySelector;
use CloudHost247\I18n\Preference\ArrayPreferenceStore;
use CloudHost247\I18n\SelectionResult;
use CloudHost247\I18n\StaticCurrencySource;
use CloudHost247\I18n\CurrencyRegistry;

/* ---------------------------------------------------------- source of truth - */

section('currencies come from configuration, not from a hard-coded list');

$registry = ch247_make_registry();
T::is('five configured currencies are exposed', 5, $registry->count());
T::is('the source is named', 'test fixture', $registry->sourceName());
T::ok('NGN is present', $registry->findByCode('NGN') !== null);
T::ok('USD is present', $registry->findByCode('USD') !== null);
T::ok('EUR is present', $registry->findByCode('EUR') !== null);
T::ok('GBP is present', $registry->findByCode('GBP') !== null);
T::is('lookup is case-insensitive', 2, $registry->findByCode('usd')->getId());
T::is('NGN is the default', 'NGN', $registry->default()->getCode());

// Adding a currency to the source makes it appear; nothing lists currencies
// inside the switcher itself.
$extended = new CurrencyRegistry(new StaticCurrencySource(
    array_merge(ch247_fixture_currencies(), array(
        array('id' => 6, 'code' => 'CAD', 'prefix' => 'C$', 'suffix' => '', 'format' => 2, 'rate' => 0.00089, 'default' => 0),
    )),
    'extended fixture'
));
T::is('a newly configured currency is offered immediately', 6, $extended->count());
T::ok('and it is findable', $extended->findByCode('CAD') !== null);

// A WHMCS-shaped row with missing columns must not explode.
$sparse = new CurrencyRegistry(new StaticCurrencySource(array(
    array('id' => 1, 'code' => 'USD'),
)));
T::is('a sparse row still yields a usable currency', 'USD', $sparse->findById(1)->getCode());
T::is('a missing symbol falls back to the code', 'USD', $sparse->findById(1)->getSymbol());
T::is('a missing name falls back to the known name', 'US Dollar', $sparse->findById(1)->getName());

/* --------------------------------------------------------------- selection -- */

section('selecting a currency');

$store = new ArrayPreferenceStore();
$selector = new CurrencySelector(ch247_make_registry(), $store);

T::is('the default currency is used first', 'NGN', $selector->current()->getCode());

T::ok('selecting by id works', $selector->select(2)->isOk());
T::is('the current currency is now USD', 'USD', $selector->current()->getCode());
T::is('the id was persisted', 2, $store->get('currency'));

T::ok('selecting by ISO code works', $selector->select('EUR')->isOk());
T::is('the current currency is now EUR', 'EUR', $selector->current()->getCode());

T::ok('selecting by lower-case code works', $selector->select('gbp')->isOk());
T::is('the current currency is now GBP', 'GBP', $selector->current()->getCode());

T::ok('selecting by numeric string works', $selector->select('1')->isOk());
T::is('a numeric string is treated as an id', 'NGN', $selector->current()->getCode());

/* ------------------------------------------------- invalid / unsupported ---- */

section('unsupported currency selections fall back safely');

$store2 = new ArrayPreferenceStore();
$selector2 = new CurrencySelector(ch247_make_registry(), $store2);
$selector2->select(3); // EUR

$result = $selector2->select(999);
T::ok('an unknown id fails', !$result->isOk());
T::is('the failure is labelled unknown', SelectionResult::ERROR_UNKNOWN, $result->getError());
T::ok('a fallback was used', $result->usedFallback());
T::is('the currency did not change', 'EUR', $selector2->current()->getCode());
T::is('the bad id was not persisted', 3, $store2->get('currency'));

$result = $selector2->select('XYZ');
T::ok('an unknown code fails', !$result->isOk());
T::is('the currency still did not change', 'EUR', $selector2->current()->getCode());

$result = $selector2->select('');
T::ok('an empty selection fails', !$result->isOk());
T::is('the failure is labelled empty', SelectionResult::ERROR_EMPTY, $result->getError());

$result = $selector2->select(null);
T::ok('a null selection fails', !$result->isOk());
T::is('the currency is untouched', 'EUR', $selector2->current()->getCode());

// A stale preference pointing at a withdrawn currency must self-heal rather
// than fail on every request.
$staleStore = new ArrayPreferenceStore(array('currency' => 4242));
$staleSelector = new CurrencySelector(ch247_make_registry(), $staleStore);
T::is('a stale stored currency resolves to the default', 'NGN', $staleSelector->current()->getCode());
$staleSelector->select(2);
T::is('and it can be repaired', 'USD', $staleSelector->current()->getCode());
T::is('the repair was persisted', 2, $staleStore->get('currency'));

// validate() must not mutate anything.
$selector2->select(3);
$before = $selector2->current()->getCode();
$verdict = $selector2->validate(4);
T::ok('validate() reports success for a good id', $verdict->isOk());
T::is('validate() did not change the current currency', $before, $selector2->current()->getCode());
T::ok('validate() reports failure for a bad id', !$selector2->validate(0)->isOk());

/* ------------------------------------------------------------ switcher data - */

section('switcher options carry everything the template needs');

$selector3 = new CurrencySelector(ch247_make_registry(), new ArrayPreferenceStore());
$selector3->select(2);
$options = $selector3->options('/cart.php?a=confproduct&i=0&pid=42');

T::is('every configured currency is offered', 5, count($options));
T::is('exactly one is marked selected', 1, count(array_filter($options, function ($o) {
    return $o['selected'];
})));
T::ok('the selected one is USD', $options[1]['selected']);

foreach ($options as $option) {
    T::ok('[' . $option['code'] . '] has an id', is_int($option['id']));
    T::ok('[' . $option['code'] . '] has a readable label', strpos($option['label'], $option['code']) === 0);
    T::ok('[' . $option['code'] . '] has a symbol', $option['symbol'] !== '');
    T::ok('[' . $option['code'] . '] has a name', $option['name'] !== '');
    T::contains('[' . $option['code'] . '] URL keeps the page', '/cart.php', $option['url']);
    T::contains('[' . $option['code'] . '] URL keeps the cart item id', 'i=0', $option['url']);
    T::contains('[' . $option['code'] . '] URL keeps the product id', 'pid=42', $option['url']);
    T::contains('[' . $option['code'] . '] URL sets the currency', 'currency=' . $option['id'], $option['url']);
}

$defaultOption = null;
foreach ($options as $option) {
    if ($option['code'] === 'NGN') {
        $defaultOption = $option;
    }
}
T::ok('the default currency is flagged', $defaultOption !== null && $defaultOption['is_default'] === true);

// On an invoice page the historical currency is locked: options still render,
// but each non-matching one is flagged so the UI can say "total unchanged".
$locked = $selector3->options('/viewinvoice.php?id=1042', $selector3->current());
$flaggedLocked = 0;
foreach ($locked as $option) {
    if ($option['locked']) {
        $flaggedLocked++;
    }
}
T::is('every other currency is flagged locked on an invoice', 4, $flaggedLocked);

/* ------------------------------------------------------------- rate tables -- */

section('rate tables never invent a rate');

$table = new \CloudHost247\I18n\RateTable('NGN', ch247_fixture_rates(), 'test fixture', '2026-10-12T06:00:00+00:00');
T::is('the base rate is 1.0', 1.0, $table->rate('NGN'));
T::is('a known rate is returned', 0.00065, $table->rate('USD'));
T::is('an unknown rate is null, not 1.0', null, $table->rate('JPY'));
T::is('conversion NGN -> USD', 65, $table->convertMinor(100000, 'NGN', 'USD'));
T::is('conversion USD -> NGN', 100000, $table->convertMinor(65, 'USD', 'NGN'));
T::is('same-currency conversion is a no-op', 1234, $table->convertMinor(1234, 'USD', 'USD'));
T::is('a missing rate blocks conversion', null, $table->convertMinor(1000, 'NGN', 'JPY'));
T::is('the source is recorded', 'test fixture', $table->getSource());
T::is('the timestamp is recorded', '2026-10-12T06:00:00+00:00', $table->getAsOf());

// Nonsense rates WHMCS writes before its first update are discarded.
$dirty = new \CloudHost247\I18n\RateTable('NGN', array('USD' => 0, 'EUR' => -1, 'GBP' => '0.00051'), 'dirty');
T::is('a zero rate is discarded', null, $dirty->rate('USD'));
T::is('a negative rate is discarded', null, $dirty->rate('EUR'));
T::is('a valid rate survives', 0.00051, $dirty->rate('GBP'));
T::is('the base is always present', 1.0, $dirty->rate('NGN'));

$now = 1773330429;
T::ok('a fresh table is not stale', !(new \CloudHost247\I18n\RateTable('NGN', array(), 'x', null, $now))->isStale(21600, $now));
T::ok('an old table is stale', (new \CloudHost247\I18n\RateTable('NGN', array(), 'x', null, $now - 90000))->isStale(21600, $now));
T::is('age is reported in seconds', 90000, (new \CloudHost247\I18n\RateTable('NGN', array(), 'x', null, $now - 90000))->age($now));

/* ------------------------------------------------------------ rate caching -- */

section('rate caching and the stale-while-error path');

$inner = new \CloudHost247\I18n\Rate\StaticRateSource('NGN', array('USD' => 0.00065));
$cache = new \CloudHost247\I18n\Rate\ArrayRateCache();
$clockNow = 1000000;
$cached = new \CloudHost247\I18n\Rate\CachedRateSource($inner, $cache, 21600, function () use (&$clockNow) {
    return $clockNow;
});

$cached->table();
T::is('the source is consulted once', 1, $inner->callCount());
$cached->table();
T::is('a fresh cache prevents a second fetch', 1, $inner->callCount());

$clockNow += 21601; // past the TTL
$cached->table();
T::is('a stale cache triggers a refresh', 2, $inner->callCount());

// When the source breaks, a stale table is better than nothing.
$broken = new \CloudHost247\I18n\Rate\CachedRateSource(
    new class implements \CloudHost247\I18n\Contracts\RateSourceInterface {
        public function table(): \CloudHost247\I18n\RateTable
        {
            throw new \RuntimeException('feed unavailable');
        }
    },
    new \CloudHost247\I18n\Rate\ArrayRateCache(),
    21600
);
$brokenTable = $broken->table();
T::ok('a broken source does not throw', $brokenTable instanceof \CloudHost247\I18n\RateTable);
T::is('a broken source with no cache yields an empty table', false, $brokenTable->has('USD'));

T::summary();
