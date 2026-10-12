<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Owns the two customer-facing preferences and the fact that they are
 * *independent*.
 *
 * Independence is the whole point of this class. Language and currency are
 * stored under separate keys, validated separately, and switching one never
 * reads, writes or resets the other. English + NGN and French + USD are both
 * first-class combinations.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class Preferences
{
    const LANGUAGE_KEY = 'language';
    const CURRENCY_KEY = 'currency';

    /** @var LocaleRegistry */
    private $locales;

    /** @var CurrencySelector */
    private $currencySelector;

    /** @var PreferenceStoreInterface */
    private $store;

    /** @var string|null */
    private $language;

    /** @var array<string,SelectionResult> */
    private $lastResults = array();

    public function __construct(
        LocaleRegistry $locales,
        CurrencySelector $currencySelector,
        PreferenceStoreInterface $store
    ) {
        $this->locales = $locales;
        $this->currencySelector = $currencySelector;
        $this->store = $store;
    }

    /**
     * The active language code.
     *
     * @return string
     */
    public function getLanguage(): string
    {
        if ($this->language !== null) {
            return $this->language;
        }

        $stored = $this->store->get(self::LANGUAGE_KEY);
        $locale = $stored !== null && $stored !== ''
            ? $this->findLocale((string) $stored)
            : null;

        if ($locale === null || !$locale->isSelectable()) {
            $locale = $this->locales->getDefault();
        }

        $this->language = $locale->getCode();

        return $this->language;
    }

    /**
     * @return Locale
     */
    public function getLocale(): Locale
    {
        return $this->locales->resolve($this->getLanguage());
    }

    /**
     * @return Currency
     */
    public function getCurrency(): Currency
    {
        return $this->currencySelector->current();
    }

    /**
     * Locales the switcher may offer: complete catalogs only.
     *
     * @return Locale[]
     */
    public function getSelectableLocales(): array
    {
        return $this->locales->selectable();
    }

    /**
     * Switch language. Returns a result describing whether the requested
     * locale was actually selectable.
     *
     * @param string|null $code
     *
     * @return SelectionResult
     */
    public function setLanguage($code): SelectionResult
    {
        if ($code !== null && !is_scalar($code)) {
            return $this->remember('language', SelectionResult::failure($this->getLanguage(), SelectionResult::ERROR_UNKNOWN, null));
        }
        if ($code === null || trim((string) $code) === '') {
            $result = SelectionResult::failure($this->getLanguage(), SelectionResult::ERROR_EMPTY, $code);
            $this->language = $result->getValue();

            return $this->remember('language', $result);
        }

        $locale = $this->findLocale((string) $code);

        if ($locale === null) {
            $result = SelectionResult::failure($this->getLanguage(), SelectionResult::ERROR_UNKNOWN, $code);
            $this->language = $result->getValue();

            return $this->remember('language', $result);
        }

        if (!$locale->isSelectable()) {
            // Registered but unfinished: not an error the customer can act on,
            // but we must not silently serve a half-translated site either.
            $result = SelectionResult::failure($this->getLanguage(), SelectionResult::ERROR_DISABLED, $code);
            $this->language = $result->getValue();

            return $this->remember('language', $result);
        }

        $this->language = $locale->getCode();
        $this->store->set(self::LANGUAGE_KEY, $locale->getCode());

        return $this->remember('language', SelectionResult::success($locale->getCode(), $code));
    }

    /**
     * Switch currency. Fully independent of setLanguage().
     *
     * @param int|string|null $value
     *
     * @return SelectionResult
     */
    public function setCurrency($value): SelectionResult
    {
        return $this->remember('currency', $this->currencySelector->select($value));
    }

    /**
     * Apply `?language=` / `?currency=` from a request.
     *
     * Missing or invalid parameters are ignored rather than clearing a stored
     * preference: a plain visit to a page must not reset the customer's choice.
     *
     * @param array $request Usually $_GET / $_REQUEST.
     *
     * @return array{language:SelectionResult|null,currency:SelectionResult|null}
     */
    public function applyRequest(array $request): array
    {
        $applied = array('language' => null, 'currency' => null);

        if (array_key_exists(EquivalentUrl::LANGUAGE_PARAM, $request)) {
            $applied['language'] = $this->setLanguage($request[EquivalentUrl::LANGUAGE_PARAM]);
        }
        if (array_key_exists(EquivalentUrl::CURRENCY_PARAM, $request)) {
            $applied['currency'] = $this->setCurrency($request[EquivalentUrl::CURRENCY_PARAM]);
        }

        return $applied;
    }

    /**
     * @param string $key
     *
     * @return SelectionResult|null
     */
    public function lastResult(string $key)
    {
        return isset($this->lastResults[$key]) ? $this->lastResults[$key] : null;
    }

    /**
     * Persist the current pair explicitly (used by the account-settings form).
     *
     * @return void
     */
    public function persist(): void
    {
        $this->store->set(self::LANGUAGE_KEY, $this->getLanguage());
        $this->store->set(self::CURRENCY_KEY, $this->getCurrency()->getId());
    }

    /**
     * Resolve a requested locale, tolerating region tags that have no catalog
     * of their own.
     *
     * `de-DE`, `de_DE` and `de` all select German when only a `de` catalog
     * ships; once someone adds `de-AT`, that exact tag wins and plain `de`
     * still resolves to German.
     *
     * @param string $code
     *
     * @return Locale|null
     */
    private function findLocale(string $code): ?Locale
    {
        $code = \CloudHost247\I18n\Whmcs\WhmcsLocale::toCode($code);
        $locale = $this->locales->find($code);
        if ($locale !== null) {
            return $locale;
        }

        $normalised = Locale::normalise($code);
        $base = explode('-', $normalised);
        $base = $base[0];

        if ($base !== '' && $base !== $normalised) {
            return $this->locales->find($base);
        }

        return null;
    }

    /**
     * @param string          $key
     * @param SelectionResult $result
     *
     * @return SelectionResult
     */
    private function remember(string $key, SelectionResult $result): SelectionResult
    {
        $this->lastResults[$key] = $result;

        return $result;
    }
}
