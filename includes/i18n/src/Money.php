<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * An amount of money in a named currency, held as an integer number of minor
 * units so repeated currency arithmetic never accumulates float error.
 *
 * Money is intentionally dumb: no exchange rate lives here. Converting
 * requires a RateTable, which forces every conversion to carry its provenance.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class Money
{
    /** @var int */
    private $minor;

    /** @var string ISO 4217 */
    private $currency;

    /** @var int Number of decimal places the currency settles on. */
    private $scale;

    private function __construct(int $minor, string $currency, int $scale)
    {
        $this->minor = $minor;
        $this->currency = strtoupper($currency);
        $this->scale = $scale;
    }

    /**
     * Build from minor units (cents, kobo, ...).
     *
     * @param int    $minor
     * @param string $currency
     * @param int    $scale
     *
     * @return self
     */
    public static function fromMinor(int $minor, string $currency, int $scale = 2)
    {
        return new self($minor, $currency, $scale);
    }

    /**
     * Build from a human amount such as 1234.56, "1234.56" or "1,234.56".
     *
     * String input is preferred: a float like 1234.56 cannot represent the
     * value exactly, and money deserves better than "close enough".
     *
     * @param float|string|int $major
     * @param string           $currency
     * @param int              $scale
     *
     * @return self
     */
    public static function fromMajor($major, string $currency, int $scale = 2)
    {
        return new self(self::majorToMinor($major, $scale), $currency, $scale);
    }

    /**
     * @return self
     */
    public static function zero(string $currency, int $scale = 2)
    {
        return new self(0, $currency, $scale);
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getScale(): int
    {
        return $this->scale;
    }

    /**
     * Exact decimal string, e.g. "1234.56". Never float-formatted, so no
     * rounding surprises and no scientific notation on large balances.
     *
     * @return string
     */
    public function toMajorString(): string
    {
        $negative = $this->minor < 0;
        $digits = (string) abs($this->minor);
        if ($this->scale === 0) {
            return ($negative ? '-' : '') . $digits;
        }
        $digits = str_pad($digits, $this->scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $this->scale);
        $fraction = substr($digits, -$this->scale);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    /**
     * Float representation for interop with WHMCS helpers that take floats.
     *
     * @return float
     */
    public function toFloat(): float
    {
        return (float) $this->toMajorString();
    }

    /**
     * @param Money $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->minor === $other->minor
            && $this->currency === $other->currency
            && $this->scale === $other->scale;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'amount' => $this->toMajorString(),
            'minor' => $this->minor,
            'currency' => $this->currency,
            'scale' => $this->scale,
        );
    }

    /**
     * Convert a human amount to minor units without touching floats.
     *
     * @param float|string|int $major
     * @param int              $scale
     *
     * @return int
     */
    private static function majorToMinor($major, int $scale): int
    {
        if (is_int($major)) {
            return $major * self::pow10($scale);
        }

        $value = trim(str_replace(array(' ', ','), '', (string) $major));
        if ($value === '' || $value === '-') {
            return 0;
        }

        $negative = false;
        if ($value[0] === '-') {
            $negative = true;
            $value = substr($value, 1);
        } elseif ($value[0] === '+') {
            $value = substr($value, 1);
        }

        // Floats arrive as strings like "1234.5600000000001"; round half away
        // from zero at the requested scale instead of truncating.
        if (strpos($value, '.') === false) {
            $whole = $value;
            $fraction = '';
        } else {
            list($whole, $fraction) = explode('.', $value, 2);
        }
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr($fraction . str_repeat('0', $scale), 0, $scale + 1);

        $rounded = self::roundFraction($fraction);
        if ($rounded === self::pow10($scale)) {
            $whole = self::addNumericStrings($whole, '1');
            $rounded = 0;
        }

        $minor = (int) $whole * self::pow10($scale) + $rounded;

        return $negative ? -$minor : $minor;
    }

    /**
     * Round an (scale+1)-digit fraction half away from zero.
     *
     * @param string $fraction
     *
     * @return int
     */
    private static function roundFraction(string $fraction): int
    {
        $keep = substr($fraction, 0, -1);
        $next = (int) substr($fraction, -1);

        $value = (int) $keep;
        if ($next >= 5) {
            $value += 1;
        }

        return $value;
    }

    /**
     * Increment a non-negative integer string by one, without int overflow.
     *
     * @param string $digits
     *
     * @return string
     */
    private static function addNumericStrings(string $digits, string $add): string
    {
        $result = '';
        $carry = (int) $add;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum = (int) $digits[$i] + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') === '' ? '0' : ltrim($result, '0');
    }

    private static function pow10(int $scale): int
    {
        $value = 1;
        for ($i = 0; $i < $scale; $i++) {
            $value *= 10;
        }

        return $value;
    }
}
