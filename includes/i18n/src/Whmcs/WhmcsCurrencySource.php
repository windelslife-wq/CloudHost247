<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Reads WHMCS's own currency configuration from `tblcurrencies`.
 *
 * This is the *only* production currency source. It is the whole answer to
 * "which currencies should the switcher offer": the ones WHMCS has
 * configured, with the symbols, exchange rates and default flag WHMCS itself
 * maintains.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Whmcs;

use CloudHost247\I18n\Contracts\CurrencySourceInterface;
use CloudHost247\I18n\Currency;

final class WhmcsCurrencySource implements CurrencySourceInterface
{
    /**
     * Callable returning an iterable of rows. Injectable so the class can be
     * exercised without a database; defaults to a Capsule query.
     *
     * @var callable|null
     */
    private $reader;

    /**
     * @param callable|null $reader fn(): iterable<array|object>
     */
    public function __construct($reader = null)
    {
        $this->reader = $reader;
    }

    /**
     * @return Currency[]
     */
    public function all(): array
    {
        $rows = $this->read();

        $currencies = array();
        foreach ($rows as $row) {
            $currency = Currency::fromRow($row);
            if ($currency->getCode() === '') {
                continue;
            }
            $currencies[$currency->getId()] = $currency;
        }
        ksort($currencies);

        return $currencies;
    }

    public function enabled(): array
    {
        return array_values($this->all());
    }

    public function findById(int $id)
    {
        foreach ($this->all() as $currency) {
            if ($currency->getId() === $id) {
                return $currency;
            }
        }

        return null;
    }

    public function findByCode(string $code)
    {
        $code = strtoupper(trim($code));
        foreach ($this->all() as $currency) {
            if ($currency->getCode() === $code) {
                return $currency;
            }
        }

        return null;
    }

    public function default(): Currency
    {
        foreach ($this->all() as $currency) {
            if ($currency->isDefault()) {
                return $currency;
            }
        }

        return new Currency(0, '', '', '', 'Unconfigured', 2, null, true);
    }

    public function sourceName(): string
    {
        return 'WHMCS tblcurrencies';
    }

    /**
     * @return array
     */
    private function read(): array
    {
        if ($this->reader !== null) {
            $rows = call_user_func($this->reader);

            return is_array($rows) ? $rows : iterator_to_array($rows);
        }

        if (!class_exists('WHMCS\\Database\\Capsule')) {
            return array();
        }

        try {
            $rows = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->orderBy('code')
                ->get();

            return is_array($rows) ? $rows : iterator_to_array($rows);
        } catch (\Throwable $e) {
            return array();
        }
    }
}
