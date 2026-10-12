<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Translation layer for the content arrays that the root landing pages build
 * in PHP: legal.php's section cards, faqs.php's question/answer list, and the
 * policy pages' hero / introduction / sections / contact structures.
 *
 * WHY THIS EXISTS
 *
 * Those pages were written with their copy inline:
 *
 *     $ca->assign('legalSections', [
 *         ['id' => 'terms-of-service', 'title' => 'Terms of Service', 'link' => 'terms-of-service.php'],
 *     ]);
 *
 * That copy cannot be translated by a WHMCS language file, because it never
 * goes through `$_LANG`. Every one of those pages would have to be rewritten to
 * support a second language — which is precisely the outcome the brief rules
 * out.
 *
 * THE SHAPE OF THE FIX
 *
 * A page keeps its English array exactly as it is and simply wraps it:
 *
 *     $ca->assign('legalSections', ch247_content('legal', [ … English … ]));
 *
 * The English array stays in the file, so:
 *   - it remains the reference text and the fallback;
 *   - the page still renders correctly with no catalog present;
 *   - `link`, `icon`, `id` and other technical fields are never translated,
 *     because technical fields are explicitly protected even if a catalog supplies them.
 *
 * A translation lives in `lang/pages/<locale>/<page>.php` and mirrors only the
 * translatable parts. Catalogs are grouped by locale — the same convention as
 * `lang/site/` and `lang/overrides/` — so adding a language is adding one
 * directory rather than editing every page.
 *
 *     return [
 *         ['id' => 'terms-of-service', 'title' => 'Conditions d\'utilisation'],
 *     ];
 *
 * Adding a language is therefore one new file per page, with no change to the
 * page itself.
 *
 * MATCHING
 *
 * Items in a list are matched on their `id` field when both sides have one, so
 * reordering the English array does not scramble the translations. Lists
 * without ids fall back to positional matching.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class PageContent
{
    /** Relative location of the page content catalogs. */
    const PAGE_DIR = 'lang/pages';

    /** @var string */
    private $directory;

    /** @var array<string,array|null> */
    private $cache = array();

    /**
     * @param string $root Repository root; catalogs live at <root>/lang/pages.
     */
    public function __construct(string $root)
    {
        $this->directory = rtrim($root, '/') . '/' . self::PAGE_DIR;
    }

    /**
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Locales that have a catalog for this page.
     *
     * @param string $page
     *
     * @return array<int,string>
     */
    public function availableLocales(string $page): array
    {
        $page = $this->safePageName($page);
        if ($page === '' || !is_dir($this->directory)) {
            return array();
        }

        $out = array();
        foreach (scandir($this->directory) as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }
            if (is_file($this->directory . '/' . $entry . '/' . $page . '.php')) {
                $out[] = $entry;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Load a page catalog for a locale, or null when there isn't one.
     *
     * @param string $page
     * @param string $locale
     *
     * @return array|null
     */
    public function load(string $page, string $locale)
    {
        $page = $this->safePageName($page);
        $locale = $this->safeLocale($locale);
        if ($page === '' || $locale === '') {
            return null;
        }
        $key = $page . '/' . $locale;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $file = $this->directory . '/' . $locale . '/' . $page . '.php';
        if (!is_file($file)) {
            $this->cache[$key] = null;

            return null;
        }

        $data = require $file;
        $this->cache[$key] = is_array($data) ? $data : null;

        return $this->cache[$key];
    }

    /**
     * Merge a translated catalog over the English array shipped in the page.
     *
     * Only strings are replaced. Arrays are walked; anything the translation
     * does not mention keeps its English value. A translation that is present
     * but empty is ignored, so an untranslated key degrades to English rather
     * than to a blank.
     *
     * @param array       $english    The array the page declares inline.
     * @param array|null  $translated The catalog for the active locale.
     *
     * @return array
     */
    public function merge(array $english, $translated): array
    {
        if (!is_array($translated)) {
            return $english;
        }

        // A list of records: match on `id` when both sides carry one.
        if ($this->isList($english) && $this->isList($translated)) {
            return $this->mergeList($english, $translated);
        }

        foreach ($translated as $key => $value) {
            if (self::isTechnicalField($key) || !array_key_exists($key, $english)) {
                // Never invent structure the English array does not have.
                continue;
            }

            if (is_array($value)) {
                if (is_array($english[$key])) {
                    $english[$key] = $this->merge($english[$key], $value);
                }
                continue;
            }

            if (is_string($english[$key]) && !self::isTechnicalValue($english[$key]) && is_string($value) && trim($value) !== '') {
                $english[$key] = $value;
            }
        }

        return $english;
    }

    /**
     * Convenience: resolve a page's content in one call.
     *
     * @param string $page
     * @param array  $english
     * @param string $locale
     *
     * @return array
     */
    public function content(string $page, array $english, string $locale): array
    {
        return $this->merge($english, $this->load($page, $locale));
    }

    /**
     * Merge two lists of records, matching on `id` where available.
     *
     * @param array $english
     * @param array $translated
     *
     * @return array
     */
    private function mergeList(array $english, array $translated): array
    {
        // Index the translations by id; keep the positionals for the rest.
        $byId = array();
        $positional = array();
        foreach ($translated as $index => $item) {
            if (is_array($item) && isset($item['id']) && is_scalar($item['id'])) {
                $byId[(string) $item['id']] = $item;
            } else {
                $positional[$index] = $item;
            }
        }

        foreach ($english as $index => $item) {
            if (is_array($item)) {
                if (isset($item['id']) && is_scalar($item['id']) && isset($byId[(string) $item['id']])) {
                    $english[$index] = $this->merge($item, $byId[(string) $item['id']]);
                    continue;
                }
                if (isset($positional[$index])) {
                    $english[$index] = $this->merge($item, $positional[$index]);
                }
                continue;
            }

            if (is_string($item) && !self::isTechnicalValue($item) && isset($positional[$index]) && is_string($positional[$index]) && trim($positional[$index]) !== '') {
                $english[$index] = $positional[$index];
            }
        }

        return $english;
    }

    /**
     * @param array $value
     *
     * @return bool
     */
    private function isList(array $value): bool
    {
        if ($value === array()) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /** Fields representing identifiers, links or customer input are immutable. */
    public static function isTechnicalField($key): bool
    {
        if (!is_string($key)) {
            return false;
        }
        return in_array(strtolower($key), array(
            'id', 'icon', 'link', 'url', 'href', 'email', 'website', 'canonical',
            'code', 'sku', 'pid', 'gid', 'product_id', 'currency_id', 'domain',
            'template', 'action', 'target', 'rel', 'class', 'type', 'method',
            'companyname', 'companyurl', 'supportemail', 'prefill',
        ), true) || (bool) preg_match('/(?:Url|Email|Id|Code)$/', $key);
    }

    public static function isTechnicalValue(string $value): bool
    {
        return (bool) preg_match('~^(?:[a-z][a-z0-9+.-]*://|mailto:|www\.|[^\s@]+@[^\s@]+\.[^\s@]+$)~i', trim($value));
    }

    private function safePageName(string $page): string
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $page) ? $page : '';
    }

    private function safeLocale(string $locale): string
    {
        return preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{2,8})*$/D', $locale)
            ? Locale::normalise($locale) : '';
    }
}
