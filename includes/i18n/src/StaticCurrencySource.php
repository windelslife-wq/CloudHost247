<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A currency source backed by a plain PHP array.
 *
 * Used by the test suite, by the staging harness, and as the safe fallback
 * when WHMCS's database is unreachable. It exists precisely so that no
 * currency list is ever hard-coded inside the switcher itself.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\CurrencySourceInterface;

final class StaticCurrencySource implements CurrencySourceInterface
{
    /** @var Currency[] */
    private $currencies;

    /** @var string */
    private $sourceName;

    /**
     * @param array  $rows        Each row: id, code, prefix, suffix, name,
     *                            format, rate, default, updated_at.
     * @param string $sourceName
     */
    public function __construct(array $rows, string $sourceName = 'static fixture')
    {
        $this->currencies = array();
        foreach ($rows as $row) {
            $currency = $row instanceof Currency ? $row : Currency::fromRow($row);
            $this->currencies[$currency->getId()] = $currency;
        }
        ksort($this->currencies);
        $this->sourceName = $sourceName;
    }

    public function all(): array
    {
        return $this->currencies;
    }

    public function enabled(): array
    {
        return array_values(array_filter($this->currencies, function (Currency $currency) {
            return $currency->getCode() !== '';
        }));
    }

    public function findById(int $id)
    {
        return isset($this->currencies[$id]) ? $this->currencies[$id] : null;
    }

    public function findByCode(string $code)
    {
        $code = strtoupper(trim($code));
        foreach ($this->currencies as $currency) {
            if ($currency->getCode() === $code) {
                return $currency;
            }
        }

        return null;
    }

    public function default(): Currency
    {
        foreach ($this->currencies as $currency) {
            if ($currency->isDefault()) {
                return $currency;
            }
        }

        $first = reset($this->currencies);

        return $first instanceof Currency
            ? $first
            : new Currency(0, '', '', '', 'Unconfigured', 2, null, true);
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }
}
