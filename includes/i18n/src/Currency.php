<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Immutable representation of one WHMCS `tblcurrencies` row.
 *
 * Two things this class deliberately does *not* do:
 *
 *  - It does not reformat a foreign amount into its own symbol. Swapping the
 *    symbol while keeping the number is the single most damaging bug a
 *    currency switcher can have, so amount conversion lives in RateTable /
 *    PriceResolver and is always explicit.
 *  - It does not guess an exchange rate. `rate` is whatever WHMCS holds; when
 *    it is null the currency is sellable (WHMCS stores per-currency prices)
 *    but not convertible.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class Currency
{
    /** @var int */
    private $id;

    /** @var string ISO 4217, e.g. "NGN". */
    private $code;

    /** @var string Symbol placed before the amount, e.g. "$". */
    private $prefix;

    /** @var string Symbol placed after the amount, e.g. " kr". */
    private $suffix;

    /** @var string|null Currency display name, e.g. "US Dollar". */
    private $name;

    /**
     * WHMCS `format` column: 1 = no thousands separator, 2 = grouped,
     * anything else = follow the locale. See docs/I18N_AND_CURRENCY.md.
     *
     * @var int
     */
    private $format;

    /**
     * Exchange rate relative to WHMCS's default currency.
     *
     * @var float|null
     */
    private $rate;

    /** @var bool */
    private $isDefault;

    /** @var string|null ISO-8601 timestamp of the last rate update. */
    private $updatedAt;

    public function __construct(
        int $id,
        string $code,
        string $prefix = '',
        string $suffix = '',
        $name = null,
        int $format = 2,
        $rate = null,
        bool $isDefault = false,
        $updatedAt = null
    ) {
        $this->id = $id;
        $this->code = strtoupper($code);
        $this->prefix = (string) $prefix;
        $this->suffix = (string) $suffix;
        $this->name = $name !== null && $name !== '' ? (string) $name : null;
        $this->format = $format;
        $this->rate = $rate === null || $rate === '' ? null : (float) $rate;
        $this->isDefault = $isDefault;
        $this->updatedAt = $updatedAt !== null && $updatedAt !== '' ? (string) $updatedAt : null;
    }

    /**
     * Build from a database row (array or stdClass), tolerating missing
     * columns so an older WHMCS schema cannot break the switcher.
     *
     * @param array|object $row
     *
     * @return self
     */
    public static function fromRow($row)
    {
        $get = function ($key, $default = null) use ($row) {
            if (is_array($row)) {
                return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
            }
            if (is_object($row)) {
                return isset($row->{$key}) && $row->{$key} !== null ? $row->{$key} : $default;
            }

            return $default;
        };

        return new self(
            (int) $get('id', 0),
            (string) $get('code', ''),
            (string) $get('prefix', ''),
            (string) $get('suffix', ''),
            $get('name'),
            (int) $get('format', 2),
            $get('rate'),
            (bool) ((int) $get('default', 0) === 1),
            $get('updated_at', $get('last_updated'))
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getSuffix(): string
    {
        return $this->suffix;
    }

    /**
     * Symbol for compact UI (header chip). Falls back to the ISO code when the
     * currency has no symbol, which is better than showing nothing.
     *
     * @return string
     */
    public function getSymbol(): string
    {
        if (trim($this->prefix) !== '') {
            return trim($this->prefix);
        }
        if (trim($this->suffix) !== '') {
            return trim($this->suffix);
        }

        return $this->code;
    }

    /**
     * Readable name, e.g. "US Dollar (USD)".
     *
     * @return string
     */
    public function getName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return self::defaultName($this->code) ?: $this->code;
    }

    /**
     * Label used inside the switcher: "USD — US Dollar".
     *
     * @return string
     */
    public function getLabel(): string
    {
        return trim($this->getSymbol()) !== '' && $this->getSymbol() !== $this->code
            ? $this->code . ' — ' . $this->getName() . ' (' . $this->getSymbol() . ')'
            : $this->code . ' — ' . $this->getName();
    }

    public function getFormat(): int
    {
        return $this->format;
    }

    /**
     * Whether thousands separators should be used.
     *
     * This is a property of the amount, not of the currency: `format` only
     * says where the *symbol* goes (prefix vs suffix), which is unrelated to
     * whether 1200000 should read as 1,200,000. Deriving grouping from
     * `format` silently un-grouped every prefix-symbol currency — the Naira,
     * the Dollar — which is the single most common way to render money.
     *
     * Grouping is on by default and can be turned off per call through
     * Formatter::moneyAmount().
     *
     * @return bool
     */
    public function useGrouping(): bool
    {
        return true;
    }

    public function getRate()
    {
        return $this->rate;
    }

    /**
     * A usable rate is finite, strictly positive and not the "unset" 0.00000
     * WHMCS writes before the first rate update runs.
     *
     * @return bool
     */
    public function hasUsableRate(): bool
    {
        return $this->rate !== null && is_finite($this->rate) && $this->rate > 0;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'code' => $this->code,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'symbol' => $this->getSymbol(),
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'format' => $this->format,
            'rate' => $this->rate,
            'has_rate' => $this->hasUsableRate(),
            'is_default' => $this->isDefault,
            'updated_at' => $this->updatedAt,
        );
    }

    /**
     * Fallback display names for codes WHMCS leaves unnamed. Only used for
     * presentation — never for conversion.
     *
     * @param string $code
     *
     * @return string
     */
    public static function defaultName(string $code): string
    {
        $names = array(
            'AUD' => 'Australian Dollar',
            'BRL' => 'Brazilian Real',
            'CAD' => 'Canadian Dollar',
            'CHF' => 'Swiss Franc',
            'CNY' => 'Chinese Yuan',
            'CZK' => 'Czech Koruna',
            'DKK' => 'Danish Krone',
            'EUR' => 'Euro',
            'GBP' => 'Pound Sterling',
            'HKD' => 'Hong Kong Dollar',
            'HUF' => 'Hungarian Forint',
            'ILS' => 'Israeli New Shekel',
            'INR' => 'Indian Rupee',
            'JPY' => 'Japanese Yen',
            'KES' => 'Kenyan Shilling',
            'KRW' => 'South Korean Won',
            'MXN' => 'Mexican Peso',
            'NGN' => 'Nigerian Naira',
            'NOK' => 'Norwegian Krone',
            'NZD' => 'New Zealand Dollar',
            'PLN' => 'Polish Zloty',
            'RON' => 'Romanian Leu',
            'RUB' => 'Russian Ruble',
            'SEK' => 'Swedish Krona',
            'SGD' => 'Singapore Dollar',
            'TRY' => 'Turkish Lira',
            'USD' => 'US Dollar',
            'ZAR' => 'South African Rand',
        );
        $code = strtoupper($code);

        return isset($names[$code]) ? $names[$code] : '';
    }
}
