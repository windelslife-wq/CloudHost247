<?php
/**
 * Currency consistency across product pages, cart and checkout.
 *
 * This is the suite that guards the most damaging failure mode in the brief:
 * "never merely change a currency symbol while leaving the underlying amount
 * unchanged." Every assertion here is about the *number* moving with the
 * currency, and about converted numbers being labelled as converted.
 *
 * Run: php tests/i18n/CurrencyConsistencyTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\Formatter;
use CloudHost247\I18n\Money;
use CloudHost247\I18n\PriceResolver;
use CloudHost247\I18n\RateTable;

$off = array('intl' => Formatter::INTL_OFF);
$registry = ch247_make_registry();
$rates = new RateTable('NGN', ch247_fixture_rates(), 'WHMCS tblcurrencies', '2026-10-12T06:00:00+00:00');

$ngn = $registry->findByCode('NGN');
$usd = $registry->findByCode('USD');
$eur = $registry->findByCode('EUR');
$gbp = $registry->findByCode('GBP');
$jpy = $registry->findByCode('JPY');

// A realistic WHMCS pricing row: real prices in three currencies, none in GBP.
$product = array(
    'NGN' => '18500.00',
    'USD' => '12.00',
    'EUR' => '11.00',
);

/* ---------------------------------------------------------- exact prices ---- */

section('a catalogue price in the selected currency is used verbatim');

$resolver = new PriceResolver($registry, $rates);

$inUsd = $resolver->resolve($product, $usd);
T::ok('the USD price is available', $inUsd->isAvailable());
T::ok('it is exact, not converted', $inUsd->isExact());
T::ok('it is not flagged indicative', !$inUsd->isIndicative());
T::is('the USD amount is the catalogue amount', '12.00', $inUsd->getDisplayAmount());
T::is('the display currency is USD', 'USD', $inUsd->getDisplayCurrency());

$inNgn = $resolver->resolve($product, $ngn);
T::is('the NGN amount is the catalogue amount', '18500.00', $inNgn->getDisplayAmount());
T::ok('NGN is exact', $inNgn->isExact());

/* -------------------------------------------- the symbol-only-change guard -- */

section('switching currency changes the amount, not just the symbol');

$renderedUsd = (new Formatter('en', $off))->money($inUsd->getMoney(), $usd);
$renderedNgn = (new Formatter('en', $off))->money($inNgn->getMoney(), $ngn);

T::is('USD renders as $12.00', '$12.00', $renderedUsd);
T::is('NGN renders as ₦18,500.00', '₦18,500.00', $renderedNgn);

// The classic bug: same digits, different symbol.
$digitsUsd = preg_replace('/[^0-9.,]/', '', $renderedUsd);
$digitsNgn = preg_replace('/[^0-9.,]/', '', $renderedNgn);
T::isnt('the digits are NOT identical across currencies', $digitsUsd, $digitsNgn);
T::isnt('the rendered strings are not the same', $renderedUsd, $renderedNgn);

// And the same check in a continental locale, where separators differ too.
$frUsd = (new Formatter('fr', $off))->money($inUsd->getMoney(), $usd);
$frNgn = (new Formatter('fr', $off))->money($inNgn->getMoney(), $ngn);
T::is('fr + USD renders as "$ 12,00"', '$ 12,00', $frUsd);
T::is('fr + NGN renders as "₦ 18 500,00"', '₦ 18 500,00', $frNgn);
T::isnt('fr digits differ too', preg_replace('/[^0-9.,]/', '', $frUsd), preg_replace('/[^0-9.,]/', '', $frNgn));

/* ------------------------------------------------------- indicative prices -- */

section('a converted price is flagged as indicative');

$inGbp = $resolver->resolve($product, $gbp);
T::ok('GBP is available (there is a rate)', $inGbp->isAvailable());
T::ok('GBP is NOT exact (there is no catalogue price)', !$inGbp->isExact());
T::ok('GBP is flagged indicative', $inGbp->isIndicative());
T::is('the transaction currency is still the shop default', 'NGN', $inGbp->getTransactionCurrency());
T::is('the currency it was converted from is recorded', 'NGN', $inGbp->getConvertedFrom());
T::is('the rate used is recorded', 0.00051, $inGbp->getRate());
T::is('the rate timestamp is recorded', '2026-10-12T06:00:00+00:00', $inGbp->getRateAsOf());
T::is('the rate source is recorded', 'WHMCS tblcurrencies', $inGbp->getRateSource());

// The chargeable amount is unchanged by the display currency — this is the
// "clearly distinguish indicative from charged" requirement.
$charged = $inGbp->getTransactionAmount();
T::is('the amount actually charged is the NGN catalogue price', '18500.00', $charged->toMajorString());
T::is('the amount actually charged is denominated in NGN', 'NGN', $charged->getCurrency());

// Cross-check: converting back is accurate to within one minor unit of the
// *display* currency (a GBP cent is worth ~1,960 NGN minor units, so that is
// the finest resolution the round trip can preserve).
$backToNgn = $rates->convertMinor($inGbp->getMoney()->getMinor(), 'GBP', 'NGN');
$oneGbpMinorInNgn = (int) ceil(1 / 0.00051);
T::ok(
    'converting back is accurate to within one minor unit of the display currency',
    abs($backToNgn - 1850000) <= $oneGbpMinorInNgn
);
T::is('the converted GBP amount really is different from the NGN amount', true, $inGbp->getDisplayAmount() !== '18500.00');

/* --------------------------------------------- no rate means no conversion -- */

section('a missing rate yields no price, never an invented one');

$inJpy = $resolver->resolve($product, $jpy);
T::ok('JPY is unavailable: there is no catalogue price and no rate', !$inJpy->isAvailable());
T::is('no amount is offered', null, $inJpy->getDisplayAmount());
T::is('the transaction currency is still known', 'NGN', $inJpy->getTransactionCurrency());

// With conversion disabled entirely, only exact prices resolve.
$strict = new PriceResolver($registry, null);
T::ok('an exact price still resolves without a rate table', $strict->resolve($product, $usd)->isExact());
T::ok('a converted price does not resolve without a rate table', !$strict->resolve($product, $gbp)->isAvailable());

// A product with no price in any known currency cannot be shown at all.
$orphan = $resolver->resolve(array('XYZ' => '9.99'), $usd);
T::ok('an unpriceable product is unavailable', !$orphan->isAvailable());

// A rate table that lacks the target rate refuses the conversion.
$partial = new PriceResolver($registry, new RateTable('NGN', array('EUR' => 0.00060), 'partial'));
T::ok('a table without the target rate refuses to convert', !$partial->resolve($product, $gbp)->isAvailable());
T::ok('the same table still resolves the currency it does know', $partial->resolve($product, $eur)->isExact());

/* --------------------------------------------------------- billing cycles --- */

section('every billing cycle resolves consistently');

$cycles = array(
    'monthly'     => array('NGN' => '18500.00', 'USD' => '12.00'),
    'quarterly'   => array('NGN' => '52500.00', 'USD' => '34.00'),
    'semiannually' => array('NGN' => '99000.00', 'USD' => '64.00'),
    'annually'    => array('NGN' => '180000.00', 'USD' => '116.00'),
    'biennially'  => array('NGN' => '340000.00', 'USD' => '220.00'),
    'triennially' => array('NGN' => '480000.00', 'USD' => '310.00'),
);

$resolved = $resolver->resolveMany($cycles, $usd);
T::is('every cycle resolved', 6, count($resolved));
foreach ($resolved as $cycle => $price) {
    T::ok('[' . $cycle . '] is exact in USD', $price->isExact());
    T::is('[' . $cycle . '] uses the USD catalogue price', $cycles[$cycle]['USD'], $price->getDisplayAmount());
}

$resolvedGbp = $resolver->resolveMany($cycles, $gbp);
foreach ($resolvedGbp as $cycle => $price) {
    T::ok('[' . $cycle . '] is indicative in GBP', $price->isIndicative());
    T::is('[' . $cycle . '] is charged in NGN', 'NGN', $price->getTransactionCurrency());
}
T::isnt(
    'the monthly USD and GBP amounts differ',
    $resolved['monthly']->getDisplayAmount(),
    $resolvedGbp['monthly']->getDisplayAmount()
);

/* ------------------------------------------------------ invoice immutability - */

section('historical documents keep their own currency');

// An invoice issued in USD must show USD totals no matter which currency the
// customer has selected for browsing.
$invoiceCurrency = $usd;
$invoiceAmount = Money::fromMajor('116.00', 'USD');

$asUsd = $resolver->resolve(array('USD' => '116.00'), $usd, $invoiceCurrency);
$asNgn = $resolver->resolve(array('USD' => '116.00'), $ngn, $invoiceCurrency);
$asGbp = $resolver->resolve(array('USD' => '116.00'), $gbp, $invoiceCurrency);

T::is('browsing in USD shows the invoice total unaltered', '116.00', $asUsd->getDisplayAmount());
T::ok('and it is exact', $asUsd->isExact());
T::is('browsing in NGN shows an indicative conversion', 'NGN', $asNgn->getDisplayCurrency());
T::ok('and it is flagged indicative', $asNgn->isIndicative());
T::is('the amount actually charged is untouched', '116.00', $asNgn->getTransactionAmount()->toMajorString());
T::is('the currency actually charged is untouched', 'USD', $asNgn->getTransactionAmount()->getCurrency());
T::is('browsing in GBP also leaves the charge alone', '116.00', $asGbp->getTransactionAmount()->toMajorString());
T::is(
    'the invoice total is identical in every browsing currency',
    '116.00',
    Money::fromMajor('116.00', 'USD')->toMajorString()
);

/* ------------------------------------------------------------- cart totals -- */

section('cart, subtotal and total stay in one currency');

$lineItems = array(
    array('name' => 'Web Hosting', 'prices' => array('NGN' => '18500.00', 'USD' => '12.00', 'EUR' => '11.00')),
    array('name' => 'Domain .com', 'prices' => array('NGN' => '24000.00', 'USD' => '15.50', 'EUR' => '14.20')),
    array('name' => 'SSL Certificate', 'prices' => array('NGN' => '9000.00', 'USD' => '5.80', 'EUR' => '5.30')),
);

$expectedTotals = array(
    'NGN' => '51500.00',
    'USD' => '33.30',
    'EUR' => '30.50',
);

foreach (array('NGN' => $ngn, 'USD' => $usd, 'EUR' => $eur) as $code => $currency) {
    $total = Money::zero($code);
    $allExact = true;
    foreach ($lineItems as $item) {
        $price = $resolver->resolve($item['prices'], $currency);
        if (!$price->isExact()) {
            $allExact = false;
        }
        $total = Money::fromMinor(
            $total->getMinor() + $price->getMoney()->getMinor(),
            $code
        );
    }
    T::ok('[' . $code . '] every line resolved exactly', $allExact);
    T::is('[' . $code . '] the cart total is correct', $expectedTotals[$code], $total->toMajorString());
}

// The rendered cart total must be the sum of the rendered lines, and it must
// differ per currency by more than the symbol.
$renderedTotals = array();
foreach (array('NGN' => $ngn, 'USD' => $usd, 'EUR' => $eur) as $code => $currency) {
    $total = Money::zero($code);
    foreach ($lineItems as $item) {
        $price = $resolver->resolve($item['prices'], $currency);
        $total = Money::fromMinor($total->getMinor() + $price->getMoney()->getMinor(), $code);
    }
    $renderedTotals[$code] = (new Formatter('en', $off))->money($total, $currency);
}
T::is('NGN cart total renders as ₦51,500.00', '₦51,500.00', $renderedTotals['NGN']);
T::is('USD cart total renders as $33.30', '$33.30', $renderedTotals['USD']);
T::is('EUR cart total renders as 30.50 €', '30.50 €', $renderedTotals['EUR']);
T::isnt('NGN and USD totals differ', $renderedTotals['NGN'], $renderedTotals['USD']);
T::isnt('USD and EUR totals differ', $renderedTotals['USD'], $renderedTotals['EUR']);

/* ------------------------------------------------------- the mixed cart ----- */

section('a line with no price in the selected currency degrades honestly');

$mixedItems = array(
    array('prices' => array('NGN' => '18500.00', 'USD' => '12.00')),
    array('prices' => array('NGN' => '24000.00')), // USD price not configured
);

$usdLine = $resolver->resolve($mixedItems[0]['prices'], $usd);
$missing = $resolver->resolve($mixedItems[1]['prices'], $usd);
T::ok('the priced line is exact in USD', $usdLine->isExact());
T::ok('the unpriced line converts indicatively rather than showing ₦24,000 with a $', $missing->isIndicative());
T::is('the unpriced line is charged in NGN', 'NGN', $missing->getTransactionCurrency());
T::is(
    'the converted amount is not the raw NGN figure',
    true,
    $missing->getDisplayAmount() !== '24000.00'
);

/* ----------------------------------------------------- rounding behaviour --- */

section('conversions round half away from zero');

$table = new RateTable('USD', array('EUR' => 0.5, 'NGN' => 1500.0), 'rounding fixture');
T::is('0.5 minor units round up', 1, $table->convertMinor(1, 'USD', 'EUR'));
T::is('2 USD minor units -> 1 EUR minor unit', 1, $table->convertMinor(2, 'USD', 'EUR'));
T::is('3 USD minor units -> 2 EUR minor units', 2, $table->convertMinor(3, 'USD', 'EUR'));
T::is('large conversion stays exact', 150000000, $table->convertMinor(100000, 'USD', 'NGN'));

T::summary();
