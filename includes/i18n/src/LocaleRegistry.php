<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The set of locales the site knows about, and the resolution rules between
 * them.
 *
 * Locales are discovered from the catalog directory, then optionally merged
 * with the languages WHMCS itself has installed (`lang/*.php` / the
 * `$locales` template variable) so an admin who enables a WHMCS language sees
 * it appear here too — marked `planned` until its catalog is finished, and
 * therefore hidden from the switcher.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class LocaleRegistry
{
    /** @var string */
    private $defaultCode;

    /** @var Locale[] keyed by normalised code */
    private $locales = array();

    /** @var CatalogRepository */
    private $catalogs;

    /**
     * @param CatalogRepository $catalogs
     * @param string            $defaultCode
     * @param Locale[]          $extra Locales known from outside the catalog dir.
     */
    public function __construct(CatalogRepository $catalogs, string $defaultCode = 'en', array $extra = array())
    {
        $this->catalogs = $catalogs;
        $this->defaultCode = Locale::normalise($defaultCode) ?: 'en';

        foreach ($extra as $locale) {
            if ($locale instanceof Locale) {
                $this->locales[$locale->getCode()] = $locale;
            }
        }

        foreach ($catalogs->availableLocales() as $code) {
            $locale = Locale::fromCatalogMeta($catalogs->meta($code));
            // The file name wins over any inherited guess about its own code.
            if ($locale->getCode() !== $code) {
                $locale = new Locale(
                    $code,
                    $locale->getEnglishName(),
                    $locale->getNativeName(),
                    $locale->getFlagCode(),
                    $locale->getDirection(),
                    $locale->getStatus(),
                    $locale->getFallback(),
                    $locale->getPluralRuleId(),
                    $locale->getIntlTag()
                );
            }
            $this->locales[$code] = $locale;
        }

        if (!isset($this->locales[$this->defaultCode])) {
            $this->locales[$this->defaultCode] = new Locale(
                $this->defaultCode,
                'English',
                'English',
                'us',
                'ltr',
                Locale::STATUS_COMPLETE
            );
        }
    }

    public function getDefaultCode(): string
    {
        return $this->defaultCode;
    }

    /**
     * @param string $code
     *
     * @return bool
     */
    public function has(string $code): bool
    {
        return isset($this->locales[Locale::normalise($code)]);
    }

    /**
     * Resolve a possibly-messy code ("PT_br", "fr-FR", "") to a registered
     * Locale, falling back to the default. Never throws.
     *
     * @param string|null $code
     *
     * @return Locale
     */
    public function resolve($code): Locale
    {
        if ($code === null) {
            return $this->getDefault();
        }

        $normalised = Locale::normalise((string) $code);
        if ($normalised !== '' && isset($this->locales[$normalised])) {
            return $this->locales[$normalised];
        }

        // pt-XX with no catalog of its own falls back to plain pt.
        $base = explode('-', $normalised);
        $base = $base[0];
        if ($base !== '' && isset($this->locales[$base])) {
            return $this->locales[$base];
        }

        return $this->getDefault();
    }

    /**
     * Strict lookup used when an invalid code must be reported rather than
     * silently repaired.
     *
     * @param string $code
     *
     * @return Locale|null
     */
    public function find(string $code)
    {
        $normalised = Locale::normalise($code);

        return isset($this->locales[$normalised]) ? $this->locales[$normalised] : null;
    }

    public function getDefault(): Locale
    {
        return $this->locales[$this->defaultCode];
    }

    /**
     * All registered locales in a stable order (default first, then
     * alphabetical by code).
     *
     * @return Locale[]
     */
    public function all(): array
    {
        $locales = $this->locales;
        uasort($locales, function (Locale $a, Locale $b) {
            if ($a->getCode() === $this->defaultCode) {
                return -1;
            }
            if ($b->getCode() === $this->defaultCode) {
                return 1;
            }

            return strcmp($a->getCode(), $b->getCode());
        });

        return array_values($locales);
    }

    /**
     * Locales the switcher offers: fully translated ones only.
     *
     * A language is not "supported" until its catalog is complete — this is
     * the single gate that keeps half-translated languages off the site.
     *
     * @return Locale[]
     */
    public function selectable(): array
    {
        return array_values(array_filter($this->all(), function (Locale $locale) {
            return $locale->isSelectable();
        }));
    }

    /**
     * The fallback chain used for message lookup, e.g.
     * ["pt-BR", "pt", "en"]. Duplicates removed, default always last.
     *
     * @param string $code
     *
     * @return string[]
     */
    public function fallbackChain(string $code): array
    {
        $chain = array();
        $seen = array();
        $current = $this->resolve($code);
        $guard = 0;

        while ($guard++ < 8) {
            if (isset($seen[$current->getCode()])) {
                break;
            }
            $seen[$current->getCode()] = true;
            $chain[] = $current->getCode();

            $parent = $current->getFallback();
            if ($parent === null || $parent === '' || isset($seen[Locale::normalise($parent)])) {
                // Region locale without an explicit parent: try the base
                // language, then stop at the global default.
                $base = explode('-', $current->getCode());
                $base = $base[0];
                if ($base !== $current->getCode() && isset($this->locales[$base]) && !isset($seen[$base])) {
                    $current = $this->locales[$base];
                    continue;
                }
                break;
            }
            $current = $this->resolve($parent);
        }

        $default = $this->defaultCode;
        if (!isset($seen[$default])) {
            $chain[] = $default;
        }

        return $chain;
    }

    /**
     * @return array<array<string,mixed>>
     */
    public function toArray(): array
    {
        $out = array();
        foreach ($this->all() as $locale) {
            $out[] = $locale->toArray();
        }

        return $out;
    }
}
