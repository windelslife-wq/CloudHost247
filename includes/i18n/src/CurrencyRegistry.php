<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * The set of currencies the shop sells in, sourced from WHMCS's own
 * `tblcurrencies` table (or any CurrencySourceInterface).
 *
 * Nothing is hard-coded. A currency is offered because WHMCS has it
 * configured, which guarantees every option in the switcher is one WHMCS can
 * actually take payment in.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

use CloudHost247\I18n\Contracts\CurrencySourceInterface;

final class CurrencyRegistry
{
    /** @var CurrencySourceInterface */
    private $source;

    /** @var Currency[] keyed by id */
    private $byId = array();

    /** @var Currency[] keyed by upper-case code */
    private $byCode = array();

    /** @var bool */
    private $loaded = false;

    public function __construct(CurrencySourceInterface $source)
    {
        $this->source = $source;
    }

    /**
     * @return Currency[]
     */
    public function all(): array
    {
        $this->load();

        return $this->byId;
    }

    /**
     * Currencies the shop actually offers.
     *
     * @return Currency[]
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), function (Currency $currency) {
            return $currency->getCode() !== '';
        }));
    }

    /**
     * @param int $id
     *
     * @return Currency|null
     */
    public function findById(int $id)
    {
        $this->load();

        return isset($this->byId[$id]) ? $this->byId[$id] : null;
    }

    /**
     * @param string $code
     *
     * @return Currency|null
     */
    public function findByCode(string $code)
    {
        $this->load();
        $code = strtoupper(trim($code));

        return isset($this->byCode[$code]) ? $this->byCode[$code] : null;
    }

    /**
     * Accept either a numeric id (WHMCS's own `?currency=` parameter) or an
     * ISO code, because both appear in the wild.
     *
     * @param int|string|null $value
     *
     * @return Currency|null
     */
    public function find($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return $this->findById((int) $value);
        }

        return $this->findByCode((string) $value);
    }

    /**
     * @return Currency
     */
    public function default(): Currency
    {
        $this->load();

        foreach ($this->byId as $currency) {
            if ($currency->isDefault()) {
                return $currency;
            }
        }

        $source = $this->source->default();
        if ($source instanceof Currency) {
            return $source;
        }

        // Last resort: an empty currency rather than a fatal error, so a
        // misconfigured install still renders.
        return new Currency(0, '', '', '', 'Unconfigured', 2, null, true);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $this->load();

        return count($this->byId);
    }

    /**
     * @return string
     */
    public function sourceName(): string
    {
        return $this->source->sourceName();
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->byId = array();
        $this->byCode = array();
        $this->loaded = false;
    }

    /**
     * @return array<array<string,mixed>>
     */
    public function toArray(): array
    {
        $out = array();
        foreach ($this->enabled() as $currency) {
            $out[] = $currency->toArray();
        }

        return $out;
    }

    /**
     * @return void
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->source->all() as $currency) {
            if (!$currency instanceof Currency || $currency->getCode() === '') {
                continue;
            }
            $this->byId[$currency->getId()] = $currency;
            $this->byCode[$currency->getCode()] = $currency;
        }
    }
}
