<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Message lookup with a documented fallback chain, `{placeholder}`
 * interpolation and CLDR pluralisation.
 *
 * Fallback order for `trans('nav_home', [], 'pt-BR')`:
 *
 *   1. pt-BR catalog
 *   2. pt catalog (regional parent)
 *   3. en catalog (global default)
 *   4. the key itself, rendered readably ("nav home")
 *
 * Step 4 is deliberately last-resort and is recorded in `getMisses()` so a
 * missing string shows up in the completion report instead of silently
 * shipping English (or worse, a raw key) to a customer.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class Translator
{
    /** Placeholder syntax: {name} */
    const PLACEHOLDER_PATTERN = '/\{([A-Za-z0-9_.-]+)\}/';

    /** @var LocaleRegistry */
    private $locales;

    /** @var CatalogRepository */
    private $catalogs;

    /** @var string */
    private $locale;

    /** @var array<string,array> Catalogs of the current fallback chain, lazily fetched. */
    private $chainCache = array();

    /** @var array<int,array{key:string,locale:string}> */
    private $misses = array();

    /** @var array<int,array{key:string,requested:string,resolved:string}> */
    private $fallbacks = array();

    /** @var bool Whether replacement values are HTML-escaped. */
    private $escape;

    public function __construct(LocaleRegistry $locales, CatalogRepository $catalogs, bool $escape = true)
    {
        $this->locales = $locales;
        $this->catalogs = $catalogs;
        $this->locale = $locales->getDefaultCode();
        $this->escape = $escape;
    }

    /**
     * @param string $code
     *
     * @return void
     */
    public function setLocale(string $code): void
    {
        $resolved = $this->locales->resolve($code);
        if ($resolved->getCode() !== $this->locale) {
            $this->chainCache = array();
        }
        $this->locale = $resolved->getCode();
    }

    public function getLocale(): Locale
    {
        return $this->locales->resolve($this->locale);
    }

    public function getLocaleCode(): string
    {
        return $this->locale;
    }

    /**
     * Translate a key.
     *
     * @param string            $key
     * @param array             $params Placeholder values; `{count}` is added for plurals.
     * @param string|null       $locale Override the active locale for this call.
     *
     * @return string
     */
    public function trans(string $key, array $params = array(), $locale = null): string
    {
        return $this->lookup($key, $params, $locale, false);
    }

    /**
     * Translate without HTML-escaping the replacement values. Use only when
     * the caller has already escaped them (for example pre-rendered HTML from
     * a trusted template).
     *
     * @param string      $key
     * @param array       $params
     * @param string|null $locale
     *
     * @return string
     */
    public function transRaw(string $key, array $params = array(), $locale = null): string
    {
        return $this->lookup($key, $params, $locale, true);
    }

    /**
     * Translate a pluralised key.
     *
     * Plural messages are arrays keyed by CLDR category:
     *
     *     'cart_item_count' => ['one' => '{count} item', 'other' => '{count} items']
     *
     * A plain string is treated as a single invariant form (useful for
     * locales that do not distinguish, e.g. Japanese).
     *
     * @param string      $key
     * @param int|float   $count
     * @param array       $params
     * @param string|null $locale
     *
     * @return string
     */
    public function transChoice(string $key, $count, array $params = array(), $locale = null): string
    {
        $targetLocale = $locale !== null ? $locale : $this->locale;
        $message = $this->findMessage($key, $targetLocale, $resolvedLocale);
        $numeric = is_numeric($count) ? (float) $count : 0.0;
        $params['count'] = $this->formatCount($numeric, $resolvedLocale);

        if ($message === null) {
            $this->recordMiss($key, $resolvedLocale);

            return $this->interpolate($this->humaniseKey($key), $params, false);
        }

        if (is_array($message)) {
            $category = PluralRule::category($this->locales->resolve($resolvedLocale)->getPluralRuleId(), $numeric);
            $value = isset($message[$category]) ? $message[$category] : (isset($message['other']) ? $message['other'] : '');
            if ($value === '' || !is_string($value)) {
                $this->recordMiss($key, $resolvedLocale);
                $value = $this->humaniseKey($key);
            }
        } else {
            $value = (string) $message;
        }

        return $this->interpolate($value, $params, false);
    }

    /**
     * Whether any fallback chain contains a non-empty value for the key.
     *
     * @param string      $key
     * @param string|null $locale
     *
     * @return bool
     */
    public function has(string $key, $locale = null): bool
    {
        return $this->findMessage($key, $locale !== null ? $locale : $this->locale) !== null;
    }

    /**
     * The full message set the active locale should render: its own catalog
     * with every gap filled from the fallback chain.
     *
     * This is what the WHMCS hook merges into `$_LANG`, so a half-translated
     * locale still shows a complete page — English where the translation is
     * missing, never a blank and never a raw key.
     *
     * @param string|null $locale
     *
     * @return array
     */
    public function getCatalogMessages($locale = null): array
    {
        $target = $this->locales->resolve($locale !== null ? $locale : $this->locale)->getCode();
        $chain = array_reverse($this->chainFor($target));

        $merged = array();
        foreach ($chain as $code) {
            foreach ($this->messagesFor($code) as $key => $value) {
                if (is_array($value)) {
                    // Plural messages cannot be flattened into $LANG; use the
                    // "other" form so a template reading $LANG.cart_item_count
                    // still gets usable text.
                    $merged[$key] = isset($value['other']) ? $value['other'] : '';
                    continue;
                }
                if (trim((string) $value) === '') {
                    continue;
                }
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Keys requested in the active locale that had no translation anywhere in
     * the chain. Feed this to the completeness report / error log.
     *
     * @return array<int,array{key:string,locale:string}>
     */
    public function getMisses(): array
    {
        return $this->misses;
    }

    /**
     * Keys served from a fallback locale rather than the requested one.
     *
     * @return array<int,array{key:string,requested:string,resolved:string}>
     */
    public function getFallbacks(): array
    {
        return $this->fallbacks;
    }

    /**
     * @return void
     */
    public function resetDiagnostics(): void
    {
        $this->misses = array();
        $this->fallbacks = array();
    }

    /**
     * @param string      $key
     * @param array       $params
     * @param string|null $locale
     * @param bool        $skipEscape
     *
     * @return string
     */
    private function lookup(string $key, array $params, $locale, bool $skipEscape): string
    {
        $targetLocale = $locale !== null ? $locale : $this->locale;
        $message = $this->findMessage($key, $targetLocale, $resolvedLocale);

        if ($message === null) {
            $this->recordMiss($key, $resolvedLocale);

            return $this->interpolate($this->humaniseKey($key), $params, $skipEscape);
        }

        if (is_array($message)) {
            // A plural message used without a count: pick the "other" form,
            // which is the only category guaranteed to exist.
            $message = isset($message['other']) ? $message['other'] : '';
            if ($message === '') {
                $this->recordMiss($key, $resolvedLocale);

                return $this->interpolate($this->humaniseKey($key), $params, $skipEscape);
            }
        }

        return $this->interpolate((string) $message, $params, $skipEscape);
    }

    /**
     * Walk the fallback chain for a key.
     *
     * @param string      $key
     * @param string      $locale
     * @param string|null $resolvedLocale Out-param: the catalog that answered.
     *
     * @return string|array|null
     */
    private function findMessage(string $key, string $locale, &$resolvedLocale = null)
    {
        $requested = $this->locales->resolve($locale)->getCode();
        $resolvedLocale = $requested;

        foreach ($this->chainFor($requested) as $candidate) {
            $messages = $this->messagesFor($candidate);
            if (array_key_exists($key, $messages)) {
                $value = $messages[$key];
                $usable = is_array($value)
                    ? isset($value['other']) && trim((string) $value['other']) !== ''
                    : (is_string($value) && trim($value) !== '');

                if (!$usable) {
                    // An empty string in a catalog means "not translated yet",
                    // so keep walking rather than rendering a blank.
                    continue;
                }

                $resolvedLocale = $candidate;
                if ($candidate !== $requested) {
                    $this->fallbacks[] = array(
                        'key' => $key,
                        'requested' => $requested,
                        'resolved' => $candidate,
                    );
                }

                return $value;
            }
        }

        $resolvedLocale = $this->locales->getDefaultCode();

        return null;
    }

    /**
     * @param string $locale
     *
     * @return string[]
     */
    private function chainFor(string $locale): array
    {
        if (!isset($this->chainCache[$locale])) {
            $this->chainCache[$locale] = $this->locales->fallbackChain($locale);
        }

        return $this->chainCache[$locale];
    }

    /**
     * @param string $locale
     *
     * @return array
     */
    private function messagesFor(string $locale): array
    {
        if (!isset($this->chainCache['messages:' . $locale])) {
            $this->chainCache['messages:' . $locale] = $this->catalogs->messages($locale);
        }

        return $this->chainCache['messages:' . $locale];
    }

    /**
     * @param string $value
     * @param array  $params
     * @param bool   $skipEscape
     *
     * @return string
     */
    private function interpolate(string $value, array $params, bool $skipEscape): string
    {
        if (strpos($value, '{') === false || !$params) {
            return $value;
        }

        $escape = $this->escape && !$skipEscape;

        return preg_replace_callback(self::PLACEHOLDER_PATTERN, function ($matches) use ($params, $escape) {
            $name = $matches[1];
            if (!array_key_exists($name, $params)) {
                // Leave unknown placeholders visible: better a literal
                // "{total}" in staging than a silently dropped value.
                return $matches[0];
            }
            $replacement = $params[$name];
            if (is_bool($replacement)) {
                $replacement = $replacement ? '1' : '0';
            } elseif (is_array($replacement)) {
                return $matches[0];
            } elseif ($replacement instanceof \DateTimeInterface) {
                $replacement = $replacement->format('c');
            } elseif (is_object($replacement)) {
                $replacement = method_exists($replacement, '__toString') ? (string) $replacement : $matches[0];
                if ($replacement === $matches[0]) {
                    return $replacement;
                }
            } else {
                $replacement = (string) $replacement;
            }

            return $escape ? htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8') : $replacement;
        }, $value);
    }

    /**
     * Render `{count}` using the target locale's number shaping.
     *
     * @param float  $count
     * @param string $locale
     *
     * @return string
     */
    private function formatCount(float $count, string $locale): string
    {
        $formatter = new Formatter($locale);

        return $formatter->number($count, 0, array('intl' => Formatter::detectIntl()));
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function humaniseKey(string $key): string
    {
        return trim(str_replace(array('_', '.'), ' ', $key));
    }

    /**
     * @param string $key
     * @param string $locale
     *
     * @return void
     */
    private function recordMiss(string $key, string $locale): void
    {
        $this->misses[] = array('key' => $key, 'locale' => $locale);
    }
}
