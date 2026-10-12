<?php
/**
 * Locale-aware formatting of dates, numbers, percentages and money.
 *
 * Two things are being proved here:
 *
 *  1. The pure-PHP fallback produces correct, pinned output for every shipped
 *     locale — so the site is still right on a host without ext/intl.
 *  2. Wherever ext/intl is available, ICU and the fallback agree on the things
 *     that matter (separators, digits, symbol placement). ICU's exact strings
 *     for long/full dates drift between ICU releases, so those are asserted
 *     structurally rather than byte-for-byte.
 *
 * Run: php tests/i18n/FormatterTest.php
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

$off = array('intl' => Formatter::INTL_OFF);
$hasIntl = Formatter::detectIntl();

/* --------------------------------------------------------------- numbers --- */

section('numbers — pure-PHP fallback');

$f = new Formatter('en', $off);
T::is('en groups with commas', '1,234,567', $f->number(1234567));
T::is('en decimals use a point', '1,234.57', $f->decimal(1234.567));
T::is('en rounds half up', '1,234.57', $f->decimal(1234.565));
T::is('en negatives keep the sign', '-1,234.57', $f->decimal(-1234.567));
T::is('en zero decimals', '1,235', $f->decimal(1234.567, 0));
T::is('en percent', '15.50%', $f->percent(0.155));

$f = new Formatter('fr', $off);
T::is('fr groups with a space', '1 234 567', $f->number(1234567));
T::is('fr decimals use a comma', '1 234,57', $f->decimal(1234.567));
T::is('fr percent has a space', '15,50 %', $f->percent(0.155));

$f = new Formatter('de', $off);
T::is('de groups with a full stop', '1.234,57', $f->decimal(1234.567));
T::is('de percent has a space', '15,50 %', $f->percent(0.155));

$f = new Formatter('es', $off);
T::is('es decimals use a comma, groups use a stop', '1.234,57', $f->decimal(1234.567));

$f = new Formatter('pt-BR', $off);
T::is('pt-BR decimals use a comma, groups use a stop', '1.234,57', $f->decimal(1234.567));
T::is('pt-BR percent has no space, matching ICU', '15,50%', $f->percent(0.155));

$f = new Formatter('en', $off);
T::is('grouping can be disabled per call', '1234567.89', $f->number(1234567.89, 2, array('grouping' => false)));
T::is('grouping is on by default', '1,234,567.89', $f->decimal(1234567.89));
T::is('grouping off via the currency hint', '1234567.89', $f->moneyAmount(Money::fromMajor('1234567.89', 'USD'), false));
if ($hasIntl) {
    T::is(
        'grouping can be disabled with intl too',
        '1234567.89',
        (new Formatter('en'))->number(1234567.89, 2, array('grouping' => false))
    );
}

/* ----------------------------------------------------------------- money --- */

section('money — symbol placement follows the currency, digits follow the locale');

$usd = new Currency(2, 'USD', '$', '', 'US Dollar', 2, 0.00065);
$eur = new Currency(3, 'EUR', '', ' €', 'Euro', 2, 0.00060);
$ngn = new Currency(1, 'NGN', '₦', '', 'Nigerian Naira', 2, 1.0);
$jpy = new Currency(5, 'JPY', '¥', '', 'Japanese Yen', 2, 0.0);

T::is('en + USD prefix, no space', '$1,234.56', (new Formatter('en', $off))->money(Money::fromMajor('1234.56', 'USD'), $usd));
T::is('fr + USD prefix, with space', '$ 1 234,56', (new Formatter('fr', $off))->money(Money::fromMajor('1234.56', 'USD'), $usd));
T::is('fr + EUR suffix, with space', '1 234,56 €', (new Formatter('fr', $off))->money(Money::fromMajor('1234.56', 'EUR'), $eur));
T::is('de + EUR suffix, with space', '1.234,56 €', (new Formatter('de', $off))->money(Money::fromMajor('1234.56', 'EUR'), $eur));
T::is('en + EUR suffix', '1,234.56 €', (new Formatter('en', $off))->money(Money::fromMajor('1234.56', 'EUR'), $eur));
T::is('en + NGN prefix', '₦185,000.00', (new Formatter('en', $off))->money(Money::fromMajor('185000', 'NGN'), $ngn));
T::is('JPY has no decimals', '¥185,000', (new Formatter('en', $off))->money(Money::fromMinor(185000, 'JPY', 0), $jpy));
T::is(
    'the ISO code can be appended',
    '1 234,56 € EUR',
    (new Formatter('fr', $off))->money(Money::fromMajor('1234.56', 'EUR'), $eur, true)
);

// Grouping follows the amount, not `format`. `format` only says where the
// symbol goes; deriving grouping from it used to un-group every prefix-symbol
// currency (NGN, USD) and render 1200000 as "1200000" instead of "1,200,000".
// Both formats must therefore group identically.
$prefixed = new Currency(9, 'USD', '$', '', 'US Dollar', 1, 0.00065);
$suffixed = new Currency(10, 'USD', '', ' $', 'US Dollar', 2, 0.00065);
T::is('a prefix-symbol currency still groups', '$1,234.56', (new Formatter('en', $off))->money(Money::fromMajor('1234.56', 'USD'), $prefixed));
T::is('a suffix-symbol currency still groups', '1,234.56 $', (new Formatter('en', $off))->money(Money::fromMajor('1234.56', 'USD'), $suffixed));
T::is('format does not change grouping', true, $prefixed->useGrouping() === $suffixed->useGrouping());

// Grouping can still be switched off for the one place it is wanted:
// compact price badges and inline chart labels.
T::is(
    'grouping can be disabled per call',
    '1234.56',
    (new Formatter('en', $off))->moneyAmount(Money::fromMajor('1234.56', 'USD'), false)
);

/* ----------------------------------------------------------- money amounts -- */

section('money never loses precision');

T::is('minor units round-trip', '1234.56', Money::fromMajor('1234.56', 'USD')->toMajorString());
T::is('float input is still exact', '1234.56', Money::fromMajor(1234.56, 'USD')->toMajorString());
T::is('string with separators parses', '1234.56', Money::fromMajor('1,234.56', 'USD')->toMajorString());
T::is('zero-padded minor units', '0.05', Money::fromMinor(5, 'USD')->toMajorString());
T::is('negative amounts', '-12.30', Money::fromMinor(-1230, 'USD')->toMajorString());
T::is('half-up at the scale boundary', '0.01', Money::fromMajor('0.005', 'USD')->toMajorString());
T::is('scale is respected', '1,234.567', (new Formatter('en', $off))->decimal(Money::fromMinor(1234567, 'USD', 3)->toMajorString(), 3));
T::ok('zero detection', Money::zero('USD')->isZero());
T::ok('equality is by value and currency', Money::fromMajor('1.00', 'USD')->equals(Money::fromMinor(100, 'USD')));
T::ok('different currencies are not equal', !Money::fromMajor('1.00', 'USD')->equals(Money::fromMinor(100, 'EUR')));

/* ----------------------------------------------------------------- dates -- */

section('dates — pure-PHP fallback');

// A fixed instant so the expectations below never drift with the clock.
$utc = new DateTimeZone('UTC');
$dt = new DateTimeImmutable('2026-03-12 15:07:09', $utc);
$ts = $dt->getTimestamp();

T::is('en short date', '3/12/26', (new Formatter('en', $off))->date($dt, 'short'));
T::is('en medium date', 'Mar 12, 2026', (new Formatter('en', $off))->date($dt, 'medium'));
T::is('en long date', 'March 12, 2026', (new Formatter('en', $off))->date($dt, 'long'));
T::is('en full date', 'Thursday, March 12, 2026', (new Formatter('en', $off))->date($dt, 'full'));
T::is('en short time (12h clock)', '3:07 PM', (new Formatter('en', $off))->time($dt, 'short'));

T::is('fr short date', '12/03/2026', (new Formatter('fr', $off))->date($dt, 'short'));
T::is('fr medium date', '12 mars 2026', (new Formatter('fr', $off))->date($dt, 'medium'));
T::is('fr time (24h clock)', '15:07', (new Formatter('fr', $off))->time($dt, 'short'));
T::is('fr date and time', '12 mars 2026 15:07', (new Formatter('fr', $off))->dateTime($dt, 'medium', 'short'));

T::is('de short date', '12.03.26', (new Formatter('de', $off))->date($dt, 'short'));
T::is('de medium date', '12.03.2026', (new Formatter('de', $off))->date($dt, 'medium'));
T::is('de long date', '12. März 2026', (new Formatter('de', $off))->date($dt, 'long'));

T::is('es medium date', '12 mar 2026', (new Formatter('es', $off))->date($dt, 'medium'));
T::is('es long date uses the "de" infix', '12 de marzo de 2026', (new Formatter('es', $off))->date($dt, 'long'));

T::is('pt-BR medium date uses the "de" infix', '12 de mar de 2026', (new Formatter('pt-BR', $off))->date($dt, 'medium'));
T::is('pt-BR long date', '12 de março de 2026', (new Formatter('pt-BR', $off))->date($dt, 'long'));
T::is('pt-BR short time', '15:07', (new Formatter('pt-BR', $off))->time($dt, 'short'));

T::is('an unparseable date renders empty', '', (new Formatter('en', $off))->date('not a date'));
T::is('a null date renders empty', '', (new Formatter('en', $off))->date(null));
T::is('ISO dates are locale-independent', '2026-03-12', (new Formatter('fr', $off))->isoDate($dt));
T::is('timestamps are accepted', 'Mar 12, 2026', (new Formatter('en', $off))->date($ts, 'medium'));
T::is('date strings are accepted', 'Mar 12, 2026', (new Formatter('en', $off))->date('2026-03-12 15:07:09', 'medium'));

/* ------------------------------------------------------------ date inputs -- */

section('date inputs survive every caller shape');

$en = new Formatter('en', $off);
T::is('DateTimeImmutable', 'Mar 12, 2026', $en->date($dt, 'medium'));
T::is('DateTime', 'Mar 12, 2026', $en->date(new DateTime('@' . $ts), 'medium'));
T::is('unix timestamp int', 'Mar 12, 2026', $en->date($ts, 'medium'));
T::is('numeric string timestamp', 'Mar 12, 2026', $en->date((string) $ts, 'medium'));
T::is('parseable string', 'Mar 12, 2026', $en->date('2026-03-12', 'medium'));

/* ------------------------------------------------------- intl agreement ---- */

section('ICU and the fallback agree');

if (!$hasIntl) {
    T::ok('ext/intl is unavailable here, ICU agreement checks skipped', true);
} else {
    foreach (array('en', 'fr', 'de', 'es', 'pt-BR') as $code) {
        $withIntl = new Formatter($code);
        $without = new Formatter($code, $off);

        T::is('[' . $code . '] intl is actually active', true, $withIntl->usingIntl());
        T::is('[' . $code . '] fallback is actually active', false, $without->usingIntl());

        // Numbers must match exactly: ICU and the fallback share separators.
        T::is(
            '[' . $code . '] grouped number matches ICU',
            $withIntl->number(1234567),
            $without->number(1234567)
        );
        T::is(
            '[' . $code . '] decimal matches ICU',
            $withIntl->decimal(1234.567),
            $without->decimal(1234.567)
        );
        T::is(
            '[' . $code . '] percent matches ICU',
            $withIntl->percent(0.155),
            $without->percent(0.155)
        );

        // Structural checks on dates: ICU wording for long/full styles varies
        // between ICU releases, so pin the parts, not the prose.
        $icuMedium = $withIntl->date($dt, 'medium');
        $fallbackMedium = $without->date($dt, 'medium');
        T::ok('[' . $code . '] ICU medium date contains the year (' . $icuMedium . ')', strpos($icuMedium, '2026') !== false);
        T::ok('[' . $code . '] ICU medium date contains the day (' . $icuMedium . ')', strpos($icuMedium, '12') !== false);
        T::ok(
            '[' . $code . '] both paths use the same separator shape',
            preg_match('/,/', $icuMedium) === preg_match('/,/', $fallbackMedium)
        );

        T::is(
            '[' . $code . '] short time matches ICU',
            $withIntl->time($dt, 'short'),
            $without->time($dt, 'short')
        );
    }
}

/* ------------------------------------------------------------- normalising -- */

section('exotic whitespace is normalised');

$en = new Formatter('en', $off);
T::is('NBSP becomes a plain space', '1 234', $en->normaliseSpaces("1\xC2\xA0234"));
T::is('narrow NBSP becomes a plain space', '1 234', $en->normaliseSpaces("1\xE2\x80\xAF234"));
T::is('runs collapse', '1 234', $en->normaliseSpaces("1   234"));
T::is(
    'ICU NBSP group separators are normalised in output',
    '1 234',
    $en->normaliseSpaces((new Formatter('fr'))->number(1234))
);

T::summary();
