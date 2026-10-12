<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Reads, validates, switches and persists the customer's display currency.
 *
 * Resolution order: stored preference → WHMCS default currency. An unknown or
 * disabled currency id never throws and never blanks the page; it falls back
 * and reports what happened so the UI can explain itself.
 *
 * The selector never converts anything. It decides *which* currency to show;
 * PriceResolver decides *what number* to show in it.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\PreferenceStoreInterface;

final class CurrencySelector
{
    /** Session / account key. Independent of the language key on purpose. */
    const PREFERENCE_KEY = 'currency';

    /** @var CurrencyRegistry */
    private $currencies;

    /** @var PreferenceStoreInterface */
    private $store;

    /** @var Currency|null The currency in force: explicit, detected or default. */
    private $resolved;

    /** @var Currency|null Set only by an explicit select(); cleared by override(). */
    private $explicit;

    /** @var callable|null fn(): ?Currency — supplies a detected currency. */
    private $detectedResolver;

    public function __construct(CurrencyRegistry $currencies, PreferenceStoreInterface $store)
    {
        $this->currencies = $currencies;
        $this->store = $store;
    }

    public function getPreferenceKey(): string
    {
        return self::PREFERENCE_KEY;
    }

    /**
     * Install the callback that supplies an automatically detected currency.
     *
     * It is consulted only when nothing has been explicitly chosen, so
     * detection can never outrank a customer's own selection. Passing null
     * removes it (detection disabled).
     *
     * @param callable|null $resolver
     *
     * @return void
     */
    public function setDetectedResolver(callable $resolver = null): void
    {
        $this->detectedResolver = $resolver;
    }

    /**
     * The currency currently in force, if one has been established.
     *
     * Unlike current() this never resolves anything, so a caller can ask
     * "has anything been decided yet?" without triggering detection.
     *
     * @return Currency|null
     */
    public function resolved()
    {
        return $this->resolved;
    }

    /**
     * The currency the customer explicitly chose this request, if any.
     *
     * @return Currency|null
     */
    public function resolvedExplicit()
    {
        return $this->explicit;
    }

    /**
     * Look a value up without touching any state.
     *
     * @param int|string|null $value Currency id or ISO code.
     *
     * @return Currency|null
     */
    public function find($value)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->currencies->find($value);
    }

    /**
     * @return Currency
     */
    public function defaultCurrency(): Currency
    {
        return $this->currencies->default();
    }

    /**
     * The currency to display right now.
     *
     * @return Currency
     */
    public function current(): Currency
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = $this->store->get(self::PREFERENCE_KEY);
        $currency = $this->currencies->find($stored);

        // Nothing explicitly stored: ask detection, which is only ever wired
        // up when the caller wants it. It cannot win against a stored value
        // because we only get here when there is none.
        if ($currency === null && $this->detectedResolver !== null) {
            $currency = call_user_func($this->detectedResolver);
        }

        if ($currency === null) {
            $currency = $this->currencies->default();
        }

        $this->resolved = $currency;

        return $currency;
    }

    /**
     * Validate a candidate selection without applying it.
     *
     * @param int|string|null $value Currency id or ISO code.
     *
     * @return SelectionResult
     */
    public function validate($value): SelectionResult
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return SelectionResult::failure($this->current(), SelectionResult::ERROR_EMPTY, $value);
        }

        $currency = $this->currencies->find($value);
        if ($currency === null) {
            return SelectionResult::failure($this->current(), SelectionResult::ERROR_UNKNOWN, $value);
        }

        return SelectionResult::success($currency, $value);
    }

    /**
     * Switch to a currency and persist the choice.
     *
     * An invalid selection leaves the previous currency in place and is
     * reported, never silently swallowed.
     *
     * @param int|string|null $value
     *
     * @return SelectionResult
     */
    public function select($value): SelectionResult
    {
        $result = $this->validate($value);

        if ($result->isOk()) {
            /** @var Currency $currency */
            $currency = $result->getValue();
            $this->resolved = $currency;
            $this->explicit = $currency;
            $this->store->set(self::PREFERENCE_KEY, $currency->getId());

            return $result;
        }

        // The requested currency is not one this shop sells in. Keep whatever
        // was already in force and, when the store really held a preference,
        // re-assert it so a stale row does not fail on every later request.
        $this->resolved = $result->getValue();
        if ($this->store->get(self::PREFERENCE_KEY) !== null) {
            $this->store->set(self::PREFERENCE_KEY, $this->resolved->getId());
        }

        return $result;
    }

    /**
     * Apply a currency for this request without persisting it.
     *
     * Used for a detected currency and for previewing. It deliberately clears
     * the explicit marker: a detected value is a suggestion, and must remain
     * replaceable by a real choice.
     *
     * @param Currency $currency
     *
     * @return void
     */
    public function override(Currency $currency): void
    {
        $this->resolved = $currency;
        $this->explicit = null;
    }

    /**
     * Where the currency list came from — surfaced in the switcher tooltip so
     * support can see at a glance that this is WHMCS's own configuration.
     *
     * @return string
     */
    public function getSourceName(): string
    {
        return $this->currencies->sourceName();
    }

    /**
     * Every currency offered in the switcher, with the URL that selects it on
     * the current page.
     *
     * @param string        $currentUri
     * @param Currency|null $transaction Currency an invoice/order is locked to;
     *                                   when set, options are still shown but
     *                                   flagged, because historical amounts do
     *                                   not change.
     *
     * @return array<array<string,mixed>>
     */
    public function options(string $currentUri, Currency $transaction = null): array
    {
        $current = $this->current();
        $options = array();

        foreach ($this->currencies->enabled() as $currency) {
            $options[] = array(
                'id' => $currency->getId(),
                'code' => $currency->getCode(),
                'symbol' => $currency->getSymbol(),
                'name' => $currency->getName(),
                'label' => $currency->getLabel(),
                'is_default' => $currency->isDefault(),
                'selected' => $currency->getId() === $current->getId(),
                'url' => EquivalentUrl::forCurrency($currentUri, $currency->getId()),
                'locked' => $transaction !== null && $transaction->getId() !== $currency->getId(),
            );
        }

        return $options;
    }
}
