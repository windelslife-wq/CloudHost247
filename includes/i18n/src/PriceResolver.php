<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Turns WHMCS's per-currency price matrix into the amount to display for the
 * customer's selected currency.
 *
 * WHMCS stores a real price per currency in `tblpricing`, so the honest
 * answer for "what does this cost in NGN" is almost always "look it up", not
 * "convert it". Conversion is a fallback for the gap between the currencies a
 * product is priced in and the currencies the shop sells in — and whenever it
 * happens, the result is flagged indicative so the UI can say so.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class PriceResolver
{
    /** @var CurrencyRegistry */
    private $currencies;

    /** @var RateTable|null */
    private $rates;

    /** @var callable|null */
    private $logger;

    /**
     * @param CurrencyRegistry $currencies
     * @param RateTable|null   $rates      Null disables conversion entirely.
     * @param callable|null    $logger     fn(string $message): void
     */
    public function __construct(CurrencyRegistry $currencies, RateTable $rates = null, $logger = null)
    {
        $this->currencies = $currencies;
        $this->rates = $rates;
        $this->logger = $logger;
    }

    /**
     * Resolve one price for display.
     *
     * @param array                  $priceByCurrency WHMCS-style matrix, e.g.
     *                                                ['USD' => '12.00', 'NGN' => '18500.00'].
     * @param Currency               $target          Currency to display in.
     * @param Currency|null          $transaction     Currency the order will be
     *                                                charged in; defaults to the target.
     *
     * @return ResolvedPrice
     */
    public function resolve(array $priceByCurrency, Currency $target, Currency $transaction = null): ResolvedPrice
    {
        $normalised = $this->normaliseMatrix($priceByCurrency);

        // 1. The honest case: a real price in the requested currency.
        $direct = $this->pick($normalised, $target->getCode());
        if ($direct !== null) {
            $transaction = $transaction !== null ? $transaction : $target;

            return ResolvedPrice::exact($direct, $transaction->getCode());
        }

        // 2. No catalogue price in the requested currency. Whatever we show
        //    will be a conversion, so the amount actually charged lives in the
        //    currency that does have a price: an explicit one if the caller
        //    passed it (an invoice's own currency), otherwise the target, then
        //    the shop default.
        $transaction = $transaction !== null ? $transaction : $this->chargeableCurrency($normalised, $target);

        $transactionAmount = $this->pick($normalised, $transaction->getCode());
        if ($transactionAmount === null) {
            $default = $this->currencies->default();
            if ($default->getCode() !== '') {
                $transactionAmount = $this->pick($normalised, $default->getCode());
                if ($transactionAmount !== null) {
                    $transaction = $default;
                }
            }
        }

        if ($transactionAmount === null) {
            $this->log(sprintf(
                'No catalogue price for any known currency (wanted %s); refusing to invent one.',
                $target->getCode()
            ));

            return ResolvedPrice::unavailable($transaction->getCode());
        }

        $from = $transactionAmount->getCurrency();

        if ($from === $target->getCode()) {
            return ResolvedPrice::exact($transactionAmount, $transaction->getCode());
        }

        if ($this->rates === null) {
            $this->log(sprintf('No rate table available to convert %s -> %s.', $from, $target->getCode()));

            return ResolvedPrice::unavailable($transaction->getCode());
        }
        if (!$this->rates->has($from) || !$this->rates->has($target->getCode())) {
            $this->log(sprintf(
                'Missing rate for %s and/or %s in table from %s; not converting.',
                $from,
                $target->getCode(),
                $this->rates->getSource()
            ));

            return ResolvedPrice::unavailable($transaction->getCode());
        }

        $convertedMinor = $this->rates->convertMinor($transactionAmount->getMinor(), $from, $target->getCode());
        if ($convertedMinor === null) {
            return ResolvedPrice::unavailable($transaction->getCode());
        }

        $converted = Money::fromMinor($convertedMinor, $target->getCode(), $transactionAmount->getScale());
        $rate = $this->rates->rate($target->getCode());

        return ResolvedPrice::indicative(
            $converted,
            $transaction->getCode(),
            $transactionAmount,
            $rate !== null ? $rate : 0.0,
            $from,
            $this->rates->getAsOf(),
            $this->rates->getSource()
        );
    }

    /**
     * Resolve a whole billing-cycle matrix (monthly, quarterly, …) at once.
     *
     * @param array<string,array> $matrixByCycle cycle => [code => amount]
     * @param Currency            $target
     * @param Currency|null       $transaction
     *
     * @return array<string,ResolvedPrice>
     */
    public function resolveMany(array $matrixByCycle, Currency $target, Currency $transaction = null): array
    {
        $out = array();
        foreach ($matrixByCycle as $cycle => $priceByCurrency) {
            if (!is_array($priceByCurrency)) {
                continue;
            }
            $out[$cycle] = $this->resolve($priceByCurrency, $target, $transaction);
        }

        return $out;
    }

    /**
     * The currency an order would actually be settled in: the requested one
     * when it has a catalogue price, otherwise the shop default when it has
     * one, otherwise the requested currency (which will leave the price
     * unavailable rather than guessed).
     *
     * @param array<string,Money> $matrix
     * @param Currency            $target
     *
     * @return Currency
     */
    private function chargeableCurrency(array $matrix, Currency $target): Currency
    {
        if ($this->pick($matrix, $target->getCode()) !== null) {
            return $target;
        }

        $default = $this->currencies->default();
        if ($default->getCode() !== '' && $this->pick($matrix, $default->getCode()) !== null) {
            return $default;
        }

        return $target;
    }

    /**
     * Normalise a WHMCS price matrix into Money objects keyed by currency.
     *
     * WHMCS hands us either `['USD' => '12.00', ...]` or, from GetProducts,
     * `['USD' => ['monthly' => '12.00', ...], ...]`. Flattening happens in
     * resolveMany(); here we only accept scalar amounts.
     *
     * @param array $matrix
     *
     * @return array<string,Money>
     */
    private function normaliseMatrix(array $matrix): array
    {
        $out = array();
        foreach ($matrix as $code => $amount) {
            if (!is_string($code) || trim($code) === '') {
                continue;
            }
            if (is_array($amount)) {
                continue;
            }
            if ($amount === null || $amount === '') {
                continue;
            }
            $value = is_numeric($amount) ? (float) $amount : null;
            if ($value === null) {
                continue;
            }
            $out[strtoupper(trim($code))] = Money::fromMajor($amount, strtoupper(trim($code)), 2);
        }

        return $out;
    }

    /**
     * @param array<string,Money> $matrix
     * @param string              $code
     *
     * @return Money|null
     */
    private function pick(array $matrix, string $code)
    {
        $code = strtoupper($code);

        return isset($matrix[$code]) ? $matrix[$code] : null;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
