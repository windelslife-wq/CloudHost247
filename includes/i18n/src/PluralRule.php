<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * CLDR cardinal plural-category selection.
 *
 * Only the rule sets actually reachable from the shipped catalogs are
 * implemented in full. Any unknown rule id falls back to the two-form
 * English rule rather than throwing, so registering a new language can never
 * take a page down.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class PluralRule
{
    /**
     * CLDR category for a count in a given rule set.
     *
     * @param string    $ruleId one|two|few|many rule identifier, e.g. "fr", "pt", "ru"
     * @param int|float $number
     *
     * @return string one|two|few|many|other|zero
     */
    public static function category(string $ruleId, $number): string
    {
        $n = is_numeric($number) ? (float) $number : 0.0;
        $abs = abs($n);
        $isInt = self::isInteger($n);
        $i = (int) floor($abs);

        switch (strtolower($ruleId)) {
            case 'ar':
                return self::arabic($n, $isInt, $i);
            case 'ru':
            case 'uk':
            case 'be':
            case 'sr':
            case 'hr':
            case 'bs':
                return self::slavic($n, $isInt, $i);
            case 'fr':
            case 'pt':
            case 'pt-br':
                return self::romanceWithMillions($n, $isInt, $i);
            case 'es':
            case 'ca':
            case 'it':
                return self::iberian($n, $isInt, $i);
            case 'de':
            case 'en':
            case 'nl':
            case 'sv':
            case 'da':
            case 'nb':
            case 'no':
            case 'et':
            case 'tr':
                return self::oneOther($n, $isInt);
            case 'pl':
                return self::polish($n, $isInt, $i);
            case 'cs':
                return self::czech($n, $isInt);
            case 'he':
                return self::hebrew($n, $isInt, $i);
            case 'fa':
                return $n == 0 || ($isInt && $i == 1) ? 'one' : 'other';
            default:
                return self::oneOther($n, $isInt);
        }
    }

    /**
     * Every category a message may need to define, in CLDR order.
     *
     * @return string[]
     */
    public static function allCategories(): array
    {
        return array('zero', 'one', 'two', 'few', 'many', 'other');
    }

    /**
     * Validate that a plural message array is well formed: at least an
     * "other" form, nothing outside the CLDR set, no empty strings.
     *
     * @param array $forms
     *
     * @return string[] Human-readable problems; empty when valid.
     */
    public static function validateForms(array $forms): array
    {
        $problems = array();
        $known = self::allCategories();

        foreach ($forms as $category => $value) {
            if (!in_array((string) $category, $known, true)) {
                $problems[] = sprintf('unknown plural category "%s"', $category);
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                $problems[] = sprintf('empty plural form for category "%s"', $category);
            }
        }

        if (!isset($forms['other'])) {
            $problems[] = 'missing required "other" plural form';
        }

        return $problems;
    }

    /**
     * @param float $n
     * @param bool  $isInt
     *
     * @return string
     */
    private static function oneOther(float $n, bool $isInt): string
    {
        return ($isInt && (int) floor(abs($n)) === 1) ? 'one' : 'other';
    }

    /**
     * French / Portuguese: 0 and 1 are singular, and exact millions use the
     * "many" form ("1 000 000 d'articles").
     *
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function romanceWithMillions(float $n, bool $isInt, int $i): string
    {
        if (!$isInt) {
            // 0.0–1.9 behave like the singular in fr/pt.
            return ($n >= 0 && $n < 2) ? 'one' : 'other';
        }
        if ($i === 0 || $i === 1) {
            return 'one';
        }
        if ($i % 1000000 === 0) {
            return 'many';
        }

        return 'other';
    }

    /**
     * Spanish / Catalan / Italian: singular for 1, "many" for exact millions.
     *
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function iberian(float $n, bool $isInt, int $i): string
    {
        if (!$isInt) {
            return 'other';
        }
        if ($i === 1) {
            return 'one';
        }
        if ($i % 1000000 === 0 && $i !== 0) {
            return 'many';
        }

        return 'other';
    }

    /**
     * Russian and friends: one / few / many / other.
     *
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function slavic(float $n, bool $isInt, int $i): string
    {
        if (!$isInt) {
            return 'other';
        }
        $mod100 = $i % 100;
        $mod10 = $i % 10;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'one';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
            return 'few';
        }

        return 'many';
    }

    /**
     * Arabic: zero / one / two / few / many / other.
     *
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function arabic(float $n, bool $isInt, int $i): string
    {
        if ($n == 0) {
            return 'zero';
        }
        if (!$isInt) {
            return 'other';
        }
        if ($i === 1) {
            return 'one';
        }
        if ($i === 2) {
            return 'two';
        }
        $mod100 = $i % 100;
        if ($mod100 >= 3 && $mod100 <= 10) {
            return 'few';
        }
        if ($mod100 >= 11 && $mod100 <= 99) {
            return 'many';
        }

        return 'other';
    }

    /**
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function polish(float $n, bool $isInt, int $i): string
    {
        if (!$isInt) {
            return 'other';
        }
        $mod100 = $i % 100;
        $mod10 = $i % 10;

        if ($i === 1) {
            return 'one';
        }
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) {
            return 'few';
        }
        if ($i !== 1 && ($mod10 === 0 || $mod10 === 1 || ($mod10 >= 5 && $mod10 <= 9))) {
            return 'many';
        }
        if ($mod100 >= 12 && $mod100 <= 14) {
            return 'many';
        }

        return 'other';
    }

    /**
     * @param float $n
     * @param bool  $isInt
     *
     * @return string
     */
    private static function czech(float $n, bool $isInt): string
    {
        if (!$isInt) {
            return 'few';
        }
        $i = (int) floor(abs($n));
        if ($i === 1) {
            return 'one';
        }
        if ($i >= 2 && $i <= 4) {
            return 'few';
        }

        return 'other';
    }

    /**
     * @param float $n
     * @param bool  $isInt
     * @param int   $i
     *
     * @return string
     */
    private static function hebrew(float $n, bool $isInt, int $i): string
    {
        if (!$isInt) {
            return 'other';
        }
        if ($i === 1) {
            return 'one';
        }
        if ($i === 2) {
            return 'two';
        }
        $mod10 = $i % 10;
        if ($i > 10 && $mod10 === 0) {
            return 'many';
        }

        return 'other';
    }

    private static function isInteger(float $n): bool
    {
        return floor($n) == $n && abs($n) < 1.0e15;
    }
}
