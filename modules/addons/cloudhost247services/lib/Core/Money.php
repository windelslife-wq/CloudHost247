<?php
/**
 * Money as integer minor units (cents). Arithmetic never touches floats;
 * string conversion happens exactly once — here.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Money
{
    /** Currency code => minor-unit digits (0dp currencies keep whole units). */
    private const EXPONENTS = [
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'XOF' => 0,
    ];

    /** Currency code => display symbol. */
    private const SYMBOLS = [
        'USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}", 'JPY' => "\u{00A5}",
        'CNY' => "\u{00A5}", 'AUD' => 'A$', 'CAD' => 'CA$', 'CHF' => 'CHF ',
    ];

    /**
     * Minor units -> human decimal string, e.g. 12345 => '123.45' (USD),
     * 12345 => '12345' (JPY).
     */
    public static function toDecimal($minor, $currency = 'USD')
    {
        $currency = strtoupper((string) $currency);
        $digits = isset(self::EXPONENTS[$currency]) ? self::EXPONENTS[$currency] : 2;
        $minor = (int) $minor;
        if ($digits === 0) {
            return (string) $minor;
        }
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $unit = intdiv($abs, 100);
        $frac = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        return $sign . $unit . '.' . $frac;
    }

    /**
     * Human decimal -> minor units. Accepts grouped strings ('1,234.56'),
     * currency symbols and floats for convenience; always integer math.
     */
    public static function fromDecimal($amount, $currency = 'USD')
    {
        $currency = strtoupper((string) $currency);
        $digits = isset(self::EXPONENTS[$currency]) ? self::EXPONENTS[$currency] : 2;
        if (is_string($amount)) {
            $amount = str_replace([',', ' '], '', $amount);
            $amount = preg_replace('/[^0-9.\-]/', '', $amount);
        }
        if ($amount === '' || $amount === null || !is_numeric($amount)) {
            return 0;
        }
        $float = (float) $amount;
        return (int) round($float * pow(10, $digits));
    }

    /** Formatted with currency symbol/prefix, e.g. '$1,234.56' / '-$1,234.56' / 'XBT 42.00'. */
    public static function format($minor, $currency = 'USD')
    {
        $currency = strtoupper((string) $currency);
        $decimal = self::toDecimal((int) $minor, $currency);
        $negative = strpos($decimal, '-') === 0;
        if ($negative) {
            $decimal = substr($decimal, 1);
        }
        if (strpos($decimal, '.') !== false) {
            list($whole, $frac) = explode('.', $decimal, 2);
            $decimal = number_format((int) $whole) . '.' . $frac;
        } else {
            $decimal = number_format((int) $decimal);
        }
        $symbol = isset(self::SYMBOLS[$currency]) ? self::SYMBOLS[$currency] : $currency . ' ';
        return ($negative ? '-' : '') . $symbol . $decimal;
    }

    /**
     * Apply a percent discount (0–100, clamped) to a minor-unit amount.
     * Rounds half-up to the nearest minor unit.
     */
    public static function discount($minor, $percent)
    {
        $percent = max(0.0, min(100.0, (float) $percent));
        $keep = 100.0 - $percent;
        return (int) round((int) $minor * $keep / 100.0);
    }
}
