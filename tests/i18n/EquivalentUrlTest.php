<?php
/**
 * Switching language keeps the customer on the equivalent page.
 *
 * The rule: preserve the path and every query parameter, replace only the
 * `language` / `currency` parameter, and never touch a technical identifier.
 * This suite also covers the open-redirect guard, because a switcher that
 * reflects a URL is a switcher that can be made to redirect off-site.
 *
 * Run: php tests/i18n/EquivalentUrlTest.php
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CloudHost247\I18n\EquivalentUrl;

/* --------------------------------------------------------------- languages -- */

section('language switch preserves the page and its parameters');

T::is(
    'a simple page',
    '/web-hosting.php?language=fr',
    EquivalentUrl::forLanguage('/web-hosting.php', 'fr')
);
T::is(
    'path and query are preserved',
    '/cart.php?a=confproduct&i=0&language=fr',
    EquivalentUrl::forLanguage('/cart.php?a=confproduct&i=0', 'fr')
);
T::is(
    'an existing language parameter is replaced, not appended',
    '/cart.php?a=view&language=de',
    EquivalentUrl::forLanguage('/cart.php?a=view&language=fr', 'de')
);
T::is(
    'an existing currency parameter survives a language switch',
    '/cart.php?a=view&currency=3&language=de',
    EquivalentUrl::forLanguage('/cart.php?a=view&currency=3', 'de')
);
T::is(
    'the fragment is preserved',
    '/help-center.php?language=es#refunds',
    EquivalentUrl::forLanguage('/help-center.php#refunds', 'es')
);
T::is(
    'a path with no parameters gets one',
    '/domain-search.php?language=pt-BR',
    EquivalentUrl::forLanguage('/domain-search.php', 'pt-BR')
);

/* --------------------------------------------------------------- currencies -- */

section('currency switch preserves the page and its parameters');

T::is(
    'a simple page',
    '/web-hosting.php?currency=3',
    EquivalentUrl::forCurrency('/web-hosting.php', 3)
);
T::is(
    'technical identifiers survive',
    '/cart.php?a=confproduct&i=0&currency=2',
    EquivalentUrl::forCurrency('/cart.php?a=confproduct&i=0', 2)
);
T::is(
    'an existing currency parameter is replaced',
    '/cart.php?a=view&currency=4',
    EquivalentUrl::forCurrency('/cart.php?a=view&currency=2', 4)
);
T::is(
    'an existing language parameter survives a currency switch',
    '/cart.php?a=view&language=fr&currency=4',
    EquivalentUrl::forCurrency('/cart.php?a=view&language=fr', 4)
);

/* -------------------------------------------------------- round-tripping ---- */

section('switching back and forth is stable');

$start = '/cart.php?a=confproduct&i=0&pid=42';
$toFrench = EquivalentUrl::forLanguage($start, 'fr');
$toEur = EquivalentUrl::forCurrency($toFrench, 3);
$backToEnglish = EquivalentUrl::forLanguage($toEur, 'en');

T::is('French + EUR', '/cart.php?a=confproduct&i=0&pid=42&language=fr&currency=3', $toEur);
T::is('back to English keeps EUR', '/cart.php?a=confproduct&i=0&pid=42&language=en&currency=3', $backToEnglish);
T::contains('the product id survived both switches', 'pid=42', $backToEnglish);
T::contains('the cart item index survived both switches', 'i=0', $backToEnglish);

// Canonical form drops both parameters — used for hreflang and canonical tags.
T::is('canonical strips the switcher parameters', '/cart.php?a=confproduct&i=0&pid=42', EquivalentUrl::canonical($toEur));
T::is('canonical on a clean URL is a no-op', '/hosting.php?x=1', EquivalentUrl::canonical('/hosting.php?x=1'));

/* ------------------------------------------------------------- identifiers -- */

section('technical identifiers are never rewritten');

$uri = '/cart.php?a=add&domain=register&sld=cloudhost247&tld=.com&pid=42&billingcycle=annually';
$switched = EquivalentUrl::forLanguage($uri, 'fr');

T::contains('the domain sld is untouched', 'sld=cloudhost247', $switched);
T::contains('the tld is untouched', 'tld=.com', $switched);
T::contains('the product id is untouched', 'pid=42', $switched);
T::contains('the billing cycle is untouched', 'billingcycle=annually', $switched);
T::contains('the action is untouched', 'a=add', $switched);
T::contains('the language was added', 'language=fr', $switched);

// Percent-encoded values must survive a round trip through the parser.
$encoded = '/tools/search?q=hello%20world%26more&language=de';
T::contains('encoded query values survive', 'q=hello%20world%26more', EquivalentUrl::forLanguage($encoded, 'fr'));

/* ------------------------------------------------------------- route maps --- */

section('declared localised slugs are honoured');

$routeMap = array(
    '/hosting' => array('fr' => '/hebergement', 'es' => '/alojamiento', 'de' => '/hosting'),
    '/domains' => array('fr' => '/domaines'),
);

T::is(
    'a French slug is used when declared',
    '/hebergement?language=fr',
    EquivalentUrl::forLanguage('/hosting', 'fr', $routeMap)
);
T::is(
    'switching back to English returns to the canonical slug',
    '/hosting?language=en',
    EquivalentUrl::forLanguage('/hebergement', 'en', $routeMap)
);
T::is(
    'an undeclared page keeps its path',
    '/vps-hosting.php?language=fr',
    EquivalentUrl::forLanguage('/vps-hosting.php', 'fr', $routeMap)
);
T::is(
    'a locale with no declared slug keeps the canonical path',
    '/hosting?language=pt-BR',
    EquivalentUrl::forLanguage('/hosting', 'pt-BR', $routeMap)
);

/* -------------------------------------------------------------- hardening --- */

section('the switcher cannot be turned into an open redirect');

T::is('an absolute external URL is refused', '/', EquivalentUrl::forLanguage('https://evil.example/steal', 'fr'));
T::is('a protocol-relative URL is refused', '/', EquivalentUrl::forCurrency('//evil.example/x', 2));
T::is('a javascript: URL is refused', '/', EquivalentUrl::forLanguage('javascript:alert(1)', 'fr'));
T::is('newlines are stripped', '/cart.php?a=view&x=1&language=fr', EquivalentUrl::forLanguage("/cart.php?a=view\n&x=1", 'fr'));
T::is('an empty URI becomes the home page', '/?language=fr', EquivalentUrl::forLanguage('', 'fr'));
T::is('a query-only URI still gets a path', '/?a=1&language=fr', EquivalentUrl::forLanguage('?a=1', 'fr'));

// Query values are re-encoded, so a value cannot smuggle in a delimiter.
$hostile = EquivalentUrl::forCurrency('/cart.php?next=' . rawurlencode('/admin'), 2);
T::contains('a redirect target inside a query value stays encoded', 'next=%2Fadmin', $hostile);

/* ----------------------------------------------------------------- parsing -- */

section('URI parsing edge cases');

$parts = EquivalentUrl::split('/a/b?x=1&y=&z#frag');
T::is('path', '/a/b', $parts['path']);
T::is('query map', array('x' => '1', 'y' => '', 'z' => ''), $parts['query']);
T::is('fragment', 'frag', $parts['fragment']);

$parts = EquivalentUrl::split('/a/b');
T::is('no query yields an empty map', array(), $parts['query']);
T::is('no fragment yields an empty string', '', $parts['fragment']);

T::is('rewrite() can remove a parameter', '/x.php?a=1', EquivalentUrl::rewrite('/x.php?a=1&currency=2', array('currency' => null)));
T::is('rewrite() can set several at once', '/x.php?a=1&language=fr&currency=3', EquivalentUrl::rewrite('/x.php?a=1', array('language' => 'fr', 'currency' => 3)));

T::summary();
