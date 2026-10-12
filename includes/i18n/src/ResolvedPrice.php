<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The outcome of "show me this product's price in the customer's currency".
 *
 * The three cases a caller has to handle are made explicit rather than hidden
 * behind a formatted string, because they have very different legal and
 * commercial meanings:
 *
 *  - EXACT       the shop has a configured price in the display currency.
 *                If billing is locked elsewhere, it is still reference-only.
 *  - INDICATIVE  the shop has no price in that currency, so the amount was
 *                converted with a configured rate. Display-only guidance; the
 *                checkout still charges the transaction currency.
 *  - UNAVAILABLE no price and no rate. Never guess: the caller must fall back
 *                to a currency the shop does have a price for.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class ResolvedPrice
{
    const SOURCE_CATALOG = 'catalog';
    const SOURCE_CONVERTED = 'converted';
    const SOURCE_NONE = 'none';

    /** @var Money|null */
    private $money;

    /** @var string */
    private $source;

    /** @var string Currency the shop will actually charge in. */
    private $transactionCurrency;

    /** @var Money|null The exact catalogue price in the transaction currency. */
    private $transactionAmount;

    /** @var float|null */
    private $rate;

    /** @var string|null */
    private $rateAsOf;

    /** @var string|null */
    private $rateSource;

    /** @var string|null */
    private $convertedFrom;

    private function __construct(
        $money,
        string $source,
        string $transactionCurrency,
        $transactionAmount = null,
        $rate = null,
        $rateAsOf = null,
        $rateSource = null,
        $convertedFrom = null
    ) {
        $this->money = $money;
        $this->source = $source;
        $this->transactionCurrency = strtoupper($transactionCurrency);
        $this->transactionAmount = $transactionAmount;
        $this->rate = $rate;
        $this->rateAsOf = $rateAsOf;
        $this->rateSource = $rateSource;
        $this->convertedFrom = $convertedFrom;
    }

    /**
     * @param Money  $money
     * @param string $transactionCurrency
     *
     * @return self
     */
    public static function exact(Money $money, string $transactionCurrency, Money $transactionAmount = null)
    {
        return new self($money, self::SOURCE_CATALOG, $transactionCurrency,
            $transactionAmount !== null ? $transactionAmount : ($money->getCurrency() === strtoupper($transactionCurrency) ? $money : null));
    }

    /**
     * @param Money       $money
     * @param string      $transactionCurrency
     * @param Money       $transactionAmount
     * @param float       $rate
     * @param string|null $convertedFrom
     * @param string|null $rateAsOf
     * @param string|null $rateSource
     *
     * @return self
     */
    public static function indicative(
        Money $money,
        string $transactionCurrency,
        Money $transactionAmount,
        float $rate,
        $convertedFrom = null,
        $rateAsOf = null,
        $rateSource = null
    ) {
        return new self(
            $money,
            self::SOURCE_CONVERTED,
            $transactionCurrency,
            $transactionAmount,
            $rate,
            $rateAsOf,
            $rateSource,
            $convertedFrom
        );
    }

    /**
     * @param string $transactionCurrency
     *
     * @return self
     */
    public static function unavailable(string $transactionCurrency, Money $transactionAmount = null)
    {
        return new self(null, self::SOURCE_NONE, $transactionCurrency, $transactionAmount);
    }

    public function isAvailable(): bool
    {
        return $this->money !== null;
    }

    public function isExact(): bool
    {
        return $this->source === self::SOURCE_CATALOG;
    }

    /**
     * True when the number shown is a conversion and must be labelled as
     * approximate in the UI.
     *
     * @return bool
     */
    public function isIndicative(): bool
    {
        return $this->source === self::SOURCE_CONVERTED
            || ($this->money !== null && $this->money->getCurrency() !== $this->transactionCurrency);
    }

    /**
     * @return Money|null
     */
    public function getMoney()
    {
        return $this->money;
    }

    /**
     * The amount to display, or null when unavailable.
     *
     * @return string|null
     */
    public function getDisplayAmount()
    {
        return $this->money !== null ? $this->money->toMajorString() : null;
    }

    public function getDisplayCurrency(): string
    {
        return $this->money !== null ? $this->money->getCurrency() : $this->transactionCurrency;
    }

    public function getTransactionCurrency(): string
    {
        return $this->transactionCurrency;
    }

    /**
     * Amount the customer will actually be charged.
     *
     * @return Money|null
     */
    public function getTransactionAmount()
    {
        return $this->transactionAmount;
    }

    public function getRate()
    {
        return $this->rate;
    }

    public function getRateAsOf(): ?string
    {
        return $this->rateAsOf;
    }

    public function getRateSource(): ?string
    {
        return $this->rateSource;
    }

    public function getConvertedFrom(): ?string
    {
        return $this->convertedFrom;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'available' => $this->isAvailable(),
            'exact' => $this->isExact(),
            'indicative' => $this->isIndicative(),
            'display' => $this->money !== null ? $this->money->toArray() : null,
            'transaction' => $this->transactionAmount !== null ? $this->transactionAmount->toArray() : null,
            'transaction_currency' => $this->transactionCurrency,
            'rate' => $this->rate,
            'rate_as_of' => $this->rateAsOf,
            'rate_source' => $this->rateSource,
            'converted_from' => $this->convertedFrom,
        );
    }
}
