<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Parses an RFC 9110 `Accept-Language` header and matches it against the
 * languages the site actually ships.
 *
 * The header is untrusted input. This class never lets it select anything that
 * is not already a supported, fully translated locale: matching is a lookup in
 * the LocaleRegistry, so a header can only ever choose from the languages the
 * catalog gate has already approved.
 *
 * What it handles:
 *
 *  - quality weighting     `de-DE,de;q=0.9,en;q=0.8`
 *  - explicit rejection    `en;q=0` means "never send me English"
 *  - wildcards             `*` at any position
 *  - script subtags        `zh-Hans-CN` -> `zh-Hans` -> `zh`
 *  - underscores/casing    `pt_BR` and `PT-br` are the same language
 *  - junk                  empty values, malformed q, over-long headers
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

use CloudHost247\I18n\Locale;
use CloudHost247\I18n\LocaleRegistry;

final class AcceptLanguage
{
    /** Ignore absurdly long headers; a real browser sends well under 1 KB. */
    const MAX_HEADER_LENGTH = 2048;

    /**
     * Parse into an ordered list of candidates, highest quality first.
     *
     * Order is stable: entries with the same quality keep document order,
     * which is what browsers use to express "more specific first".
     *
     * @param string $header Raw header value.
     *
     * @return array<int,array{tag:string,quality:float}> Empty when unusable.
     */
    public static function parse(string $header): array
    {
        $header = trim(str_replace(array("\r", "\n", "\0"), '', $header));
        if ($header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return array();
        }

        $entries = array();

        foreach (explode(',', $header) as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $pieces = explode(';', $part);
            $tag = Locale::normalise(trim(array_shift($pieces)));
            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($pieces as $parameter) {
                $parameter = trim($parameter);
                if (stripos($parameter, 'q=') !== 0) {
                    continue;
                }
                $value = trim(substr($parameter, 2));
                // A malformed q parameter must not silently become "1.0" or
                // "0"; treat it as absent and keep the default.
                if (is_numeric($value)) {
                    $quality = (float) $value;
                }
                break;
            }
            if (!is_finite($quality) || $quality < 0) {
                $quality = 0.0;
            }
            if ($quality > 1) {
                $quality = 1.0;
            }
            if ($quality <= 0) {
                continue; // Explicitly not acceptable.
            }

            $entries[] = array('tag' => $tag, 'quality' => $quality, 'order' => $index);
        }

        usort($entries, function (array $a, array $b) {
            if ($a['quality'] === $b['quality']) {
                return $a['order'] <=> $b['order'];
            }

            return $a['quality'] > $b['quality'] ? -1 : 1;
        });

        $out = array();
        foreach ($entries as $entry) {
            $out[] = array('tag' => $entry['tag'], 'quality' => $entry['quality']);
        }

        return $out;
    }

    /**
     * Every language tag the header asks for, in preference order, including
     * the less specific fallbacks of each tag.
     *
     * `zh-Hans-CN` expands to `zh-Hans-CN`, `zh-Hans`, `zh`.
     *
     * @param string $header
     *
     * @return string[] Normalised tags, de-duplicated.
     */
    public static function preferredTags(string $header): array
    {
        $tags = array();
        $seen = array();

        foreach (self::parse($header) as $entry) {
            $candidates = self::expansions($entry['tag']);
            if ($entry['tag'] === '*') {
                continue; // A wildcard names no language of its own.
            }
            foreach ($candidates as $candidate) {
                if (isset($seen[$candidate])) {
                    continue;
                }
                $seen[$candidate] = true;
                $tags[] = $candidate;
            }
        }

        return $tags;
    }

    /**
     * Whether the header accepts a language that is not listed explicitly.
     *
     * `Accept-Language: fr, *;q=0.5` means French is preferred but anything
     * else is tolerable — which is not the same as "no preference", and is not
     * evidence that English was requested.
     *
     * @param string $header
     *
     * @return bool
     */
    public static function acceptsAnything(string $header): bool
    {
        foreach (self::parse($header) as $entry) {
            if ($entry['tag'] === '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * The best supported locale for this header, or null when the visitor
     * asked only for languages the site does not offer.
     *
     * Only *selectable* locales (fully translated catalogs) can be returned,
     * so a detected language is never a half-translated site.
     *
     * @param string          $header
     * @param LocaleRegistry  $locales
     *
     * @return Locale|null
     */
    public static function match(string $header, LocaleRegistry $locales)
    {
        foreach (self::preferredTags($header) as $tag) {
            $locale = $locales->find($tag);
            if ($locale !== null && $locale->isSelectable()) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * The region subtag of the highest-quality entry, e.g. "GB" for `en-GB`.
     *
     * Used only as an optional, low-confidence currency hint; it is never used
     * to choose a language.
     *
     * @param string $header
     *
     * @return string|null
     */
    public static function preferredRegion(string $header)
    {
        foreach (self::parse($header) as $entry) {
            $parts = explode('-', $entry['tag']);
            if (count($parts) < 2) {
                continue;
            }
            $region = strtoupper(end($parts));

            return strlen($region) === 2 ? $region : null;
        }

        return null;
    }

    /**
     * Sub-tag truncations of a tag, most specific first.
     *
     * @param string $tag
     *
     * @return string[]
     */
    private static function expansions(string $tag): array
    {
        if ($tag === '' || $tag === '*') {
            return array($tag);
        }

        $parts = explode('-', $tag);
        $out = array();

        while ($parts) {
            $out[] = implode('-', $parts);
            array_pop($parts);
        }

        return $out;
    }
}
