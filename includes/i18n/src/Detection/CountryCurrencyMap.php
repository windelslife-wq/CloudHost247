<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Maps an ISO 3166-1 country to the ISO 4217 currencies used there, and —
 * this is the important half — resolves that against the currencies the shop
 * actually sells in.
 *
 * The table answers "what do people in Germany pay with?". It deliberately
 * does *not* answer "what should this customer pay in?". Only
 * CurrencyRegistry knows that, because only WHMCS knows which currencies are
 * configured. So:
 *
 *   CountryCurrencyMap::resolve('DE')  -> ['EUR']     (all candidates)
 *   -> filtered by CurrencyRegistry    -> EUR or nothing
 *
 * Everything about the data is documented at the top of
 * includes/i18n/data/country-currency.php. In short:
 *
 *  - it is generated from the ISO 4217 / ISO 3166 maintenance datasets, not
 *    invented per country;
 *  - a country does not get a unique currency: twenty countries share EUR, the
 *    CFA francs span whole regions, and several territories use USD;
 *  - countries with no universal currency (Antarctica, Palestine, …) are
 *    absent, so they fall back to the configured default;
 *  - a deployer can override any row through configuration.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n\Detection;

use CloudHost247\I18n\Currency;
use CloudHost247\I18n\CurrencyRegistry;

final class CountryCurrencyMap
{
    /** @var array<string,string[]> */
    private $table;

    /** @var int */
    private $size;

    /**
     * @param array<string,string[]>|null $table     Defaults to the shipped data file.
     * @param array<string,string[]>      $overrides Per-country overrides; they win.
     */
    public function __construct(array $table = null, array $overrides = array())
    {
        if ($table === null) {
            $file = dirname(__DIR__, 2) . '/data/country-currency.php';
            $loaded = is_file($file) ? require $file : array();
            $table = is_array($loaded) ? $loaded : array();
        }

        $normalised = array();
        foreach ($table as $country => $currencies) {
            $country = strtoupper(trim((string) $country));
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                continue;
            }
            $codes = self::normaliseCodes($currencies);
            if ($codes) {
                $normalised[$country] = $codes;
            }
        }

        foreach ($overrides as $country => $currencies) {
            $country = strtoupper(trim((string) $country));
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                continue;
            }
            $codes = self::normaliseCodes($currencies);
            if ($codes) {
                $normalised[$country] = $codes;
            } else {
                unset($normalised[$country]);
            }
        }

        $this->table = $normalised;
        $this->size = count($normalised);
    }

    /**
     * A map with the shipped default table plus overrides.
     *
     * @param array<string,string[]> $overrides
     *
     * @return self
     */
    public static function withOverrides(array $overrides = array()): self
    {
        return new self(null, $overrides);
    }

    /**
     * Candidate currencies for a country, in preference order.
     *
     * @param string|null $country ISO 3166-1 alpha-2.
     *
     * @return string[] ISO 4217 codes; empty when the country is unknown,
     *                  malformed, or has no universal currency.
     */
    public function candidates($country): array
    {
        $code = self::normaliseCountry($country);

        return $code !== null && isset($this->table[$code]) ? $this->table[$code] : array();
    }

    /**
     * Whether the country is known at all.
     *
     * @param string|null $country
     *
     * @return bool
     */
    public function has($country): bool
    {
        $code = self::normaliseCountry($country);

        return $code !== null && isset($this->table[$code]);
    }

    /**
     * The first candidate currency this shop actually sells in.
     *
     * This is where "map the detected country to an appropriate currency
     * *only if that currency is enabled and supported*" happens: a country
     * whose currency the shop does not take yields null, and the caller falls
     * back to the configured default. Nothing is ever invented here.
     *
     * @param string|null     $country
     * @param CurrencyRegistry $currencies
     *
     * @return Currency|null
     */
    public function resolve($country, CurrencyRegistry $currencies)
    {
        foreach ($this->candidates($country) as $code) {
            $currency = $currencies->findByCode($code);
            if ($currency !== null) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * Every candidate the shop sells in, in preference order.
     *
     * @param string|null       $country
     * @param CurrencyRegistry  $currencies
     *
     * @return Currency[]
     */
    public function resolveAll($country, CurrencyRegistry $currencies): array
    {
        $out = array();
        foreach ($this->candidates($country) as $code) {
            $currency = $currencies->findByCode($code);
            if ($currency !== null) {
                $out[] = $currency;
            }
        }

        return $out;
    }

    /**
     * How many countries the table covers.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * @return array<string,string[]>
     */
    public function toArray(): array
    {
        return $this->table;
    }

    /**
     * @param mixed $country
     *
     * @return string|null
     */
    private static function normaliseCountry($country)
    {
        if ($country === null || is_array($country) || is_object($country)) {
            return null;
        }
        $code = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * @param mixed $currencies
     *
     * @return string[]
     */
    private static function normaliseCodes($currencies): array
    {
        if (is_string($currencies)) {
            $currencies = explode(',', $currencies);
        }
        if (!is_array($currencies)) {
            return array();
        }

        $out = array();
        foreach ($currencies as $code) {
            if (!is_string($code) && !is_numeric($code)) {
                continue;
            }
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }
}
