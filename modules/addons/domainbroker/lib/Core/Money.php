<?php
/**
 * Domain Broker — money handling.
 *
 * Amounts are stored and computed as integer minor units (cents, pence, …) so
 * no acquisition value, broker fee or tax figure is ever subject to binary
 * floating point drift. Currencies with zero or three decimals are handled via
 * the exponent table rather than an assumed /100.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Money
{
    /** Currencies whose minor unit is not 1/100. */
    const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function exponent($currency)
    {
        $code = strtoupper((string) $currency);
        return isset(self::EXPONENTS[$code]) ? self::EXPONENTS[$code] : 2;
    }

    public static function factor($currency)
    {
        return (int) pow(10, self::exponent($currency));
    }

    /**
     * Parse a human-entered amount into minor units.
     *
     * Accepts "10,000.50", "10 000,50", "10000.5" and bare integers. Rejects
     * anything that is not a plain positive decimal number.
     *
     * @throws ValidationException
     */
    public static function toMinor($amount, $currency)
    {
        if (is_int($amount)) {
            $normalised = (string) $amount;
        } elseif (is_float($amount)) {
            $normalised = rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.');
        } else {
            $normalised = trim((string) $amount);
        }

        if ($normalised === '') {
            throw new ValidationException('Amount is required.', ['amount' => 'Amount is required.']);
        }

        // Strip spaces and thin spaces used as thousands separators.
        $normalised = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $normalised);

        // Normalise separators: if both are present the right-most is decimal.
        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            if ($lastComma > $lastDot) {
                $normalised = str_replace('.', '', $normalised);
                $normalised = str_replace(',', '.', $normalised);
            } else {
                $normalised = str_replace(',', '', $normalised);
            }
        } elseif ($lastComma !== false) {
            // A single comma is a decimal separator when it leaves 1-2 digits,
            // otherwise it is a thousands separator.
            $tail = substr($normalised, $lastComma + 1);
            $normalised = (strlen($tail) === 3 && substr_count($normalised, ',') >= 1 && strlen($normalised) > 4
                && preg_match('/^\d{1,3}(,\d{3})+$/', $normalised))
                ? str_replace(',', '', $normalised)
                : str_replace(',', '.', $normalised);
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $normalised)) {
            throw new ValidationException('Amount must be a positive number.', ['amount' => 'Amount must be a positive number.']);
        }

        $exp = self::exponent($currency);
        $parts = explode('.', $normalised, 2);
        $whole = $parts[0];
        $frac = isset($parts[1]) ? $parts[1] : '';

        if (strlen($frac) > $exp) {
            // Round half-up at the minor unit boundary.
            $keep = substr($frac, 0, $exp);
            $nextDigit = (int) substr($frac, $exp, 1);
            $minor = (int) ($whole . str_pad($keep, $exp, '0'));
            if ($nextDigit >= 5) {
                $minor++;
            }
        } else {
            $frac = str_pad($frac, $exp, '0');
            $minor = (int) ($whole . $frac);
        }

        if ($minor < 0) {
            throw new ValidationException('Amount must be positive.', ['amount' => 'Amount must be positive.']);
        }
        if ($minor > PHP_INT_MAX / 2) {
            throw new ValidationException('Amount is too large.', ['amount' => 'Amount is too large.']);
        }

        return $minor;
    }

    /** Minor units → decimal string, e.g. 1050 USD → "10.50". */
    public static function toDecimalString($minor, $currency)
    {
        $minor = (int) $minor;
        $exp = self::exponent($currency);
        $neg = $minor < 0;
        $abs = (string) abs($minor);
        if ($exp === 0) {
            return ($neg ? '-' : '') . $abs;
        }
        $abs = str_pad($abs, $exp + 1, '0', STR_PAD_LEFT);
        $whole = substr($abs, 0, -$exp);
        $frac = substr($abs, -$exp);
        return ($neg ? '-' : '') . $whole . '.' . $frac;
    }

    /** Minor units → float, for handing off to WHMCS' decimal invoice columns. */
    public static function toFloat($minor, $currency)
    {
        return (float) self::toDecimalString($minor, $currency);
    }

    /**
     * Format for display. Prefix/suffix come from the currency record supplied
     * by the caller (WHMCS currency row) — never hardcoded.
     */
    public static function format($minor, $currency, array $currencyMeta = [])
    {
        $value = self::toDecimalString($minor, $currency);
        $exp = self::exponent($currency);
        $parts = explode('.', $value);
        $parts[0] = number_format((float) $parts[0], 0, '.', ',');
        $display = $exp === 0 ? $parts[0] : $parts[0] . '.' . $parts[1];

        $prefix = isset($currencyMeta['prefix']) ? $currencyMeta['prefix'] : '';
        $suffix = isset($currencyMeta['suffix']) ? $currencyMeta['suffix'] : '';
        if ($prefix === '' && $suffix === '') {
            $suffix = ' ' . strtoupper($currency);
        }
        return $prefix . $display . $suffix;
    }

    /**
     * Percentage of an amount, in minor units, rounded half-up.
     *
     * @param int        $minor
     * @param string|int $percent e.g. "10", "7.5"
     */
    public static function percentOf($minor, $percent)
    {
        $minor = (int) $minor;
        // Work in basis points to stay integral: 7.5% → 750 bp.
        $bp = (int) round(((float) $percent) * 100);
        $product = $minor * $bp;
        $sign = $product < 0 ? -1 : 1;
        return $sign * (int) floor((abs($product) + 5000) / 10000);
    }

    public static function clamp($minor, $min = null, $max = null)
    {
        $minor = (int) $minor;
        if ($min !== null && $minor < (int) $min) {
            $minor = (int) $min;
        }
        if ($max !== null && (int) $max > 0 && $minor > (int) $max) {
            $minor = (int) $max;
        }
        return $minor;
    }

    public static function isValidCurrency($code)
    {
        return is_string($code) && preg_match('/^[A-Z]{3}$/', strtoupper($code)) === 1;
    }
}
