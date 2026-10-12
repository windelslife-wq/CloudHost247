<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Keeps a customer on the *same page* when they switch language or currency.
 *
 * The rule is deliberately conservative: the path is preserved, every query
 * parameter is preserved, and only the `language` / `currency` parameter is
 * added or replaced. Product ids, cart ids, domain names, `a=add`, `pid=42`
 * and every other technical identifier survive untouched — translating a URL
 * is a different job from translating a page, and guessing at it breaks carts.
 *
 * An optional route map supports genuinely localised slugs (`/hebergement`
 * for `/hosting`), but only when a mapping is explicitly declared.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class EquivalentUrl
{
    /** Query parameter WHMCS itself uses to switch language. */
    const LANGUAGE_PARAM = 'language';

    /** Query parameter WHMCS itself uses to switch currency (a currency id). */
    const CURRENCY_PARAM = 'currency';

    /**
     * Build the URL of the current page in another language.
     *
     * @param string $currentUri Absolute or relative URI, e.g. "/cart.php?a=view&pid=3".
     * @param string $localeCode Target locale code, e.g. "fr".
     * @param array  $routeMap   Optional ['/hosting' => ['fr' => '/hebergement']].
     * @param string $paramName  Override the query parameter name.
     *
     * @return string
     */
    public static function forLanguage(
        string $currentUri,
        string $localeCode,
        array $routeMap = array(),
        string $paramName = self::LANGUAGE_PARAM
    ): string {
        $parts = self::split($currentUri);
        $path = self::localisePath($parts['path'], $localeCode, $routeMap);

        return self::build($path, $parts['query'], $parts['fragment'], array($paramName => $localeCode));
    }

    /**
     * Build the URL of the current page priced in another currency.
     *
     * @param string     $currentUri
     * @param int|string $currencyId WHMCS currency id (or code, which the
     *                               selector resolves to an id beforehand).
     * @param string     $paramName
     *
     * @return string
     */
    public static function forCurrency(string $currentUri, $currencyId, string $paramName = self::CURRENCY_PARAM): string
    {
        $parts = self::split($currentUri);

        return self::build($parts['path'], $parts['query'], $parts['fragment'], array($paramName => (string) $currencyId));
    }

    /**
     * Apply several overrides at once (used when the switcher changes language
     * and currency in a single request).
     *
     * @param string $currentUri
     * @param array  $overrides  name => value; a null value removes the param.
     *
     * @return string
     */
    public static function rewrite(string $currentUri, array $overrides): string
    {
        $parts = self::split($currentUri);

        return self::build($parts['path'], $parts['query'], $parts['fragment'], $overrides);
    }

    /**
     * Strip the switcher parameters — used for canonical/hreflang links so
     * search engines are not handed N copies of the same page.
     *
     * @param string $uri
     *
     * @return string
     */
    public static function canonical(string $uri): string
    {
        $parts = self::split($uri);

        return self::build($parts['path'], $parts['query'], $parts['fragment'], array(
            self::LANGUAGE_PARAM => null,
            self::CURRENCY_PARAM => null,
        ));
    }

    /**
     * Split a URI into path, query (as an ordered map) and fragment.
     *
     * @param string $uri
     *
     * @return array{path:string,query:array,fragment:string}
     */
    public static function split(string $uri): array
    {
        $uri = str_replace(array("\r", "\n", "\0"), '', $uri);
        $uri = trim($uri);

        $fragment = '';
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $fragment = substr($uri, $hash + 1);
            $uri = substr($uri, 0, $hash);
        }

        $query = '';
        $mark = strpos($uri, '?');
        if ($mark !== false) {
            $query = substr($uri, $mark + 1);
            $uri = substr($uri, 0, $mark);
        }

        $params = array();
        if ($query !== '') {
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    $key = urldecode($pair);
                    $params[$key] = '';
                } else {
                    $key = urldecode(substr($pair, 0, $eq));
                    $params[$key] = urldecode(substr($pair, $eq + 1));
                }
            }
        }

        return array('path' => $uri, 'query' => $params, 'fragment' => $fragment);
    }

    /**
     * @param string $path
     * @param string $localeCode
     * @param array  $routeMap
     *
     * @return string
     */
    private static function localisePath(string $path, string $localeCode, array $routeMap): string
    {
        if (!$routeMap || $path === '') {
            return $path;
        }

        $normalised = '/' . ltrim($path, '/');

        foreach ($routeMap as $canonical => $translations) {
            if (!is_array($translations)) {
                continue;
            }
            $canonicalPath = '/' . ltrim((string) $canonical, '/');

            $isCanonical = $canonicalPath === $normalised || $canonicalPath === rtrim($normalised, '/');
            $isTranslation = false;
            foreach ($translations as $locale => $translated) {
                if ('/' . ltrim((string) $translated, '/') === $normalised) {
                    $isTranslation = true;
                    break;
                }
            }

            if (!$isCanonical && !$isTranslation) {
                continue;
            }

            if (isset($translations[$localeCode])) {
                return '/' . ltrim((string) $translations[$localeCode], '/');
            }

            // Coming from a translated slug into a locale with no declared
            // slug of its own: fall back to the canonical path rather than
            // leaving the visitor on a French URL while reading English.
            if ($isTranslation) {
                return $canonicalPath;
            }
        }

        return $path;
    }

    /**
     * @param string $path
     * @param array  $query
     * @param string $fragment
     * @param array  $overrides
     *
     * @return string
     */
    private static function build(string $path, array $query, string $fragment, array $overrides): string
    {
        foreach ($overrides as $key => $value) {
            // Replace in place where the parameter already exists, so a switch
            // does not reshuffle the query string: stable URLs cache better
            // and diff cleaner in a browser history.
            $replaced = false;
            foreach (array_keys($query) as $existing) {
                if (strcasecmp((string) $existing, (string) $key) !== 0) {
                    continue;
                }
                if ($value === null) {
                    unset($query[$existing]);
                } elseif ($replaced) {
                    // A stray ?Language=fr must not shadow the new value.
                    unset($query[$existing]);
                } else {
                    $query[$existing] = (string) $value;
                    $replaced = true;
                }
            }
            if (!$replaced && $value !== null) {
                $query[$key] = (string) $value;
            }
        }

        // An empty path is the site root, not "no path at all".
        $uri = $path === '' ? '/' : $path;
        if ($query) {
            $pairs = array();
            foreach ($query as $key => $value) {
                $pairs[] = rawurlencode((string) $key) . ($value !== '' ? '=' . rawurlencode((string) $value) : '');
            }
            $uri .= '?' . implode('&', $pairs);
        }
        if ($fragment !== '') {
            $uri .= '#' . $fragment;
        }

        return self::guard($uri);
    }

    /**
     * Open-redirect guard: the switcher must never emit an absolute URL to
     * another origin, and must never emit control characters that could split
     * a response header or start a `javascript:` scheme.
     *
     * @param string $uri
     *
     * @return string
     */
    private static function guard(string $uri): string
    {
        $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
        $uri = (string) $uri;

        // Only same-document paths are allowed. The first pattern catches
        // "https://"; the second catches protocol-relative "//host"; the third
        // catches any other scheme, including "javascript:" and "data:", which
        // do not use "//" and would otherwise slip straight through. A relative
        // path cannot contain a colon before its first slash, so this is safe.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri)
            || strpos($uri, '//') === 0
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri)
        ) {
            return '/';
        }

        return $uri === '' ? '/' : $uri;
    }
}
