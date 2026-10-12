<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Locale-aware formatting of numbers, percentages, dates, times and money.
 *
 * Design notes
 * ------------
 * `ext/intl` is used when it is available, because ICU data is far more
 * accurate than anything we would maintain by hand. But a formatter whose
 * output silently depends on which extensions a host compiled is a testing
 * liability, so:
 *
 *  - the pure-PHP fallback covers the shipped locales exactly;
 *  - both paths normalise whitespace (ICU uses NBSP / NNBSP as group and
 *    currency separators for fr, de, pt-BR …) so the two paths agree;
 *  - `intl` can be forced off per instance, which lets the test suite pin
 *    both implementations.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class Formatter
{
    /** Ask the environment. */
    const INTL_AUTO = 'auto';

    const INTL_ON = 'on';
    const INTL_OFF = 'off';

    /**
     * Whitespace characters ICU emits that we collapse to a plain space so
     * the intl and fallback paths (and the test assertions) agree.
     */
    const SPACE_CLASS = '/[\x{00A0}\x{2007}\x{202F}\x{2060}\x{FEFF}]/u';

    /** @var string */
    private $localeCode;

    /** @var string BCP-47 tag used for ICU. */
    private $intlTag;

    /** @var array<string,mixed> */
    private $options;

    /** @var array<string,mixed> */
    private $rules;

    /** @var bool */
    private $useIntl;

    /** @var array<string,\NumberFormatter> */
    private static $numberFormatters = array();

    /** @var array<string,\IntlDateFormatter> */
    private static $dateFormatters = array();

    /**
     * @param string $localeCode Canonical locale code, e.g. "pt-BR".
     * @param array  $options    'intl' => 'auto'|'on'|'off'; 'intl_tag' => override.
     */
    public function __construct(string $localeCode, array $options = array())
    {
        $this->localeCode = Locale::normalise($localeCode) ?: 'en';
        $this->options = $options;
        $this->intlTag = isset($options['intl_tag']) && $options['intl_tag'] !== ''
            ? (string) $options['intl_tag']
            : (new Locale($this->localeCode))->getIntlTag();
        $this->rules = self::rulesFor($this->localeCode);
        $this->useIntl = $this->decideIntl(isset($options['intl']) ? (string) $options['intl'] : self::INTL_AUTO);
    }

    /**
     * Whether the running PHP can use ext/intl at all.
     *
     * @return bool
     */
    public static function detectIntl(): bool
    {
        return class_exists('NumberFormatter') && class_exists('IntlDateFormatter');
    }

    /**
     * @return bool
     */
    public function usingIntl(): bool
    {
        return $this->useIntl;
    }

    public function getLocaleCode(): string
    {
        return $this->localeCode;
    }

    // ------------------------------------------------------------- numbers --

    /**
     * Format a number with locale-appropriate grouping.
     *
     * @param float|int|string $value
     * @param int              $decimals
     * @param array            $options Per-call overrides (e.g. 'intl' => 'off').
     *
     * @return string
     */
    public function number($value, int $decimals = 0, array $options = array()): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;
        $grouping = isset($options['grouping']) ? (bool) $options['grouping'] : true;

        if ($this->resolveIntl($options)) {
            $formatter = $this->numberFormatter(\NumberFormatter::DECIMAL, $decimals, $grouping);
            if ($formatter !== null) {
                return $this->normaliseSpaces((string) $formatter->format($numeric));
            }
        }

        return $this->fallbackNumber($numeric, $decimals, $grouping);
    }

    /**
     * @param float|int|string $value
     * @param int              $decimals
     *
     * @return string
     */
    public function decimal($value, int $decimals = 2): string
    {
        return $this->number($value, $decimals);
    }

    /**
     * Format a ratio as a percentage (0.155 -> "15.50 %" in fr, "15.50%" in en).
     *
     * @param float|int|string $value
     * @param int              $decimals
     *
     * @return string
     */
    public function percent($value, int $decimals = 2): string
    {
        $numeric = is_numeric($value) ? (float) $value : 0.0;

        if ($this->useIntl) {
            $formatter = $this->numberFormatter(\NumberFormatter::PERCENT, $decimals);
            if ($formatter !== null) {
                return $this->normaliseSpaces((string) $formatter->format($numeric));
            }
        }

        return $this->fallbackNumber($numeric * 100, $decimals)
            . ($this->rules['percent_space'] ? ' %' : '%');
    }

    // ---------------------------------------------------------------- dates --

    /**
     * @param int|string|\DateTimeInterface|null $value Timestamp or parseable date.
     * @param string                             $style short|medium|long|full
     *
     * @return string
     */
    public function date($value, string $style = 'medium'): string
    {
        return $this->formatDate($value, $style, null);
    }

    /**
     * @param int|string|\DateTimeInterface|null $value
     * @param string                             $style short|medium|long|full
     *
     * @return string
     */
    public function time($value, string $style = 'short'): string
    {
        return $this->formatDate($value, null, $style);
    }

    /**
     * @param int|string|\DateTimeInterface|null $value
     * @param string                             $dateStyle
     * @param string                             $timeStyle
     *
     * @return string
     */
    public function dateTime($value, string $dateStyle = 'medium', string $timeStyle = 'short'): string
    {
        return $this->formatDate($value, $dateStyle, $timeStyle);
    }

    /**
     * ISO-8601 date only, for machine-readable attributes (datetime="…").
     *
     * @param int|string|\DateTimeInterface|null $value
     *
     * @return string
     */
    public function isoDate($value): string
    {
        $dt = $this->toDateTime($value);

        return $dt === null ? '' : $dt->format('Y-m-d');
    }

    // -------------------------------------------------------------- money ---

    /**
     * Format money using the currency's own symbol placement.
     *
     * Presentation only: the amount is never converted here. Pass a Money
     * already denominated in `$currency`.
     *
     * @param Money    $money
     * @param Currency $currency
     * @param bool     $withCode Append the ISO code, e.g. "1 234,56 € EUR".
     *
     * @return string
     */
    public function money(Money $money, Currency $currency, bool $withCode = false): string
    {
        $amount = $this->moneyAmount($money, $currency->useGrouping(), $money->getScale());

        $prefix = trim($currency->getPrefix());
        $suffix = trim($currency->getSuffix());
        // A prefix runs into the digits in English ("$1,234.56") but takes a
        // space in the continental locales ("1 234,56 €"). A suffix always
        // takes a space, because "1,234.56€" is hard to read in any script.
        $prefixSpace = $this->rules['currency_space'] ? ' ' : '';

        $out = '';
        if ($prefix !== '') {
            $out .= $prefix . $prefixSpace;
        }
        $out .= $amount;
        if ($suffix !== '') {
            $out .= ' ' . $suffix;
        }
        if ($withCode) {
            $out .= ' ' . $currency->getCode();
        }

        return $this->normaliseSpaces($out);
    }

    /**
     * The numeric part of a money value, grouped per currency+locale rules.
     *
     * @param Money $money
     * @param bool  $grouping
     * @param int   $decimals
     *
     * @return string
     */
    public function moneyAmount(Money $money, bool $grouping = true, int $decimals = 2): string
    {
        $major = $money->toMajorString();

        if ($this->useIntl) {
            $formatter = $this->numberFormatter(\NumberFormatter::DECIMAL, $decimals, $grouping);
            if ($formatter !== null) {
                return $this->normaliseSpaces((string) $formatter->format((float) $major));
            }
        }

        return $this->fallbackNumber((float) $major, $decimals, $grouping);
    }

    // ------------------------------------------------------------ internals --

    /**
     * @param int|string|\DateTimeInterface|null $value
     * @param string|null                        $dateStyle
     * @param string|null                        $timeStyle
     *
     * @return string
     */
    private function formatDate($value, $dateStyle, $timeStyle): string
    {
        $dt = $this->toDateTime($value);
        if ($dt === null) {
            return '';
        }

        if ($this->useIntl) {
            $formatter = $this->dateFormatter($dateStyle, $timeStyle);
            if ($formatter !== null) {
                $formatted = $formatter->format($dt);
                if (is_string($formatted) && $formatted !== '') {
                    return $this->normaliseSpaces($formatted);
                }
            }
        }

        return $this->fallbackDate($dt, $dateStyle, $timeStyle);
    }

    /**
     * @param int|string|\DateTimeInterface|null $value
     *
     * @return \DateTimeImmutable|null
     */
    private function toDateTime($value)
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            $immutable = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $value->format('Y-m-d H:i:s'),
                $value->getTimezone()
            );

            return $immutable !== false ? $immutable : null;
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $dt = new \DateTimeImmutable('@' . (int) $value);

            return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @param float $value
     * @param int   $decimals
     * @param bool  $grouping
     *
     * @return string
     */
    private function fallbackNumber(float $value, int $decimals = 0, bool $grouping = true): string
    {
        $negative = $value < 0;
        $absolute = abs($value);

        $formatted = number_format(round($absolute, $decimals), $decimals, '.', '');
        if (strpos($formatted, '.') !== false) {
            list($whole, $fraction) = explode('.', $formatted, 2);
        } else {
            $whole = $formatted;
            $fraction = '';
        }

        if ($grouping) {
            $whole = $this->groupDigits($whole);
        }

        $out = $whole . ($decimals > 0 ? $this->rules['decimal_separator'] . $fraction : '');

        return ($negative ? '-' : '') . $this->normaliseSpaces($out);
    }

    /**
     * @param string $digits
     *
     * @return string
     */
    private function groupDigits(string $digits): string
    {
        $separator = (string) $this->rules['group_separator'];
        $size = (int) $this->rules['group_size'];

        if (strlen($digits) <= $size) {
            return $digits;
        }

        // group_size 0 selects the Indian 3-2-2 (lakh/crore) grouping.
        if ($size === 0) {
            $last3 = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $chunks = array();
            foreach (str_split(strrev($rest), 2) as $chunk) {
                $chunks[] = strrev($chunk);
            }
            $chunks = array_reverse($chunks);

            return implode($separator, $chunks) . $separator . $last3;
        }

        return strrev(implode($separator, str_split(strrev($digits), $size)));
    }

    /**
     * @param \DateTimeImmutable $dt
     * @param string|null        $dateStyle
     * @param string|null        $timeStyle
     *
     * @return string
     */
    private function fallbackDate(\DateTimeImmutable $dt, $dateStyle, $timeStyle): string
    {
        $parts = array();

        if ($dateStyle !== null) {
            $parts[] = $this->renderPattern((string) $this->rules['date_' . $dateStyle], $dt);
        }
        if ($timeStyle !== null) {
            $parts[] = $this->renderPattern((string) $this->rules['time_' . $timeStyle], $dt);
        }

        $separator = ($dateStyle !== null && $timeStyle !== null)
            ? (string) $this->rules['datetime_separator']
            : '';

        return $this->normaliseSpaces(implode($separator, $parts));
    }

    /**
     * Render a compact date/time pattern.
     *
     * Supported tokens (single character each): Y y F M l D j d n m H G g h i
     * s A a. A backslash escapes the next character as a literal, which is how
     * patterns embed words such as Spanish "de".
     *
     * @param string             $pattern
     * @param \DateTimeImmutable $dt
     *
     * @return string
     */
    private function renderPattern(string $pattern, \DateTimeImmutable $dt): string
    {
        static $cache = array();
        $cacheKey = $this->localeCode . '|' . $pattern;
        if (isset($cache[$cacheKey])) {
            $map = $cache[$cacheKey];
        } else {
            $map = array(
                'Y' => $dt->format('Y'),
                'y' => $dt->format('y'),
                'F' => $this->rules['months'][(int) $dt->format('n') - 1],
                'M' => $this->rules['months_short'][(int) $dt->format('n') - 1],
                'l' => $this->rules['days'][(int) $dt->format('w')],
                'D' => $this->rules['days_short'][(int) $dt->format('w')],
                'j' => $dt->format('j'),
                'd' => $dt->format('d'),
                'n' => $dt->format('n'),
                'm' => $dt->format('m'),
                'H' => $dt->format('H'),
                'G' => $dt->format('G'),
                'g' => $dt->format('g'),
                'h' => $dt->format('h'),
                'i' => $dt->format('i'),
                's' => $dt->format('s'),
                'A' => $dt->format('A'),
                'a' => $dt->format('a'),
            );
            $cache[$cacheKey] = $map;
        }

        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $pattern[$i + 1];
                $i++;
                continue;
            }
            $out .= isset($map[$char]) ? $map[$char] : $char;
        }

        return $out;
    }

    /**
     * @param int  $style
     * @param int  $decimals
     * @param bool $grouping
     *
     * @return \NumberFormatter|null
     */
    private function numberFormatter(int $style, int $decimals, bool $grouping = true)
    {
        $key = $this->intlTag . '|' . $style . '|' . $decimals . '|' . ($grouping ? 'g' : 'n');

        if (isset(self::$numberFormatters[$key])) {
            return self::$numberFormatters[$key];
        }

        try {
            $formatter = new \NumberFormatter($this->intlTag, $style);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::GROUPING_USED, $grouping ? 1 : 0);
            $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
        } catch (\Throwable $e) {
            return null;
        }

        self::$numberFormatters[$key] = $formatter;

        return $formatter;
    }

    /**
     * @param string|null $dateStyle
     * @param string|null $timeStyle
     *
     * @return \IntlDateFormatter|null
     */
    private function dateFormatter($dateStyle, $timeStyle)
    {
        $map = array(
            'short' => \IntlDateFormatter::SHORT,
            'medium' => \IntlDateFormatter::MEDIUM,
            'long' => \IntlDateFormatter::LONG,
            'full' => \IntlDateFormatter::FULL,
            null => \IntlDateFormatter::NONE,
        );

        $dateConst = $dateStyle !== null && isset($map[$dateStyle]) ? $map[$dateStyle] : \IntlDateFormatter::NONE;
        $timeConst = $timeStyle !== null && isset($map[$timeStyle]) ? $map[$timeStyle] : \IntlDateFormatter::NONE;

        if ($dateConst === \IntlDateFormatter::NONE && $timeConst === \IntlDateFormatter::NONE) {
            return null;
        }

        $key = $this->intlTag . '|' . $dateConst . '|' . $timeConst;
        if (isset(self::$dateFormatters[$key])) {
            return self::$dateFormatters[$key];
        }

        try {
            // No pattern argument: passing one overrides $dateConst/$timeConst
            // entirely, which is how a "short time" silently became a full
            // timestamp in an earlier revision of this file.
            $formatter = new \IntlDateFormatter($this->intlTag, $dateConst, $timeConst);
        } catch (\Throwable $e) {
            return null;
        }

        self::$dateFormatters[$key] = $formatter;

        return $formatter;
    }

    /**
     * @param array $options
     *
     * @return bool
     */
    private function resolveIntl(array $options): bool
    {
        if (isset($options['intl'])) {
            return $this->decideIntl((string) $options['intl']);
        }

        return $this->useIntl;
    }

    /**
     * @param string $mode
     *
     * @return bool
     */
    private function decideIntl(string $mode): bool
    {
        if ($mode === self::INTL_ON) {
            return self::detectIntl();
        }
        if ($mode === self::INTL_OFF) {
            return false;
        }

        return self::detectIntl();
    }

    /**
     * Collapse exotic spaces to plain U+0020 and squeeze runs.
     *
     * @param string $value
     *
     * @return string
     */
    public function normaliseSpaces(string $value): string
    {
        $value = preg_replace(self::SPACE_CLASS, ' ', $value);

        return preg_replace('/ {2,}/', ' ', (string) $value);
    }

    // ---------------------------------------------------------------- data ---

    /**
     * Formatting rules for the shipped locales. Values mirror ICU so the intl
     * and fallback paths produce the same string for short/medium dates,
     * numbers and percentages.
     *
     * @param string $locale
     *
     * @return array<string,mixed>
     */
    public static function rulesFor(string $locale): array
    {
        $locale = Locale::normalise($locale);
        $base = explode('-', $locale);
        $base = $base[0];

        $en = array(
            'decimal_separator' => '.',
            'group_separator' => ',',
            'group_size' => 3,
            'percent_space' => false,
            'currency_space' => false,
            'datetime_separator' => ', ',
            'date_short' => 'n/j/y',
            'date_medium' => 'M j, Y',
            'date_long' => 'F j, Y',
            'date_full' => 'l, F j, Y',
            'time_short' => 'g:i A',
            'time_medium' => 'g:i:s A',
            'time_long' => 'g:i:s A',
            'time_full' => 'g:i:s A',
            'months' => array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'),
            'months_short' => array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'),
            'days' => array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
            'days_short' => array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'),
        );

        $continental = array(
            'decimal_separator' => ',',
            'group_separator' => ' ',
            'group_size' => 3,
            'percent_space' => true,
            'currency_space' => true,
            'datetime_separator' => ' ',
        );

        $overrides = array();

        $overrides['fr'] = $continental + array(
            'date_short' => 'd/m/Y',
            'date_medium' => 'd M Y',
            'date_long' => 'd F Y',
            'date_full' => 'l d F Y',
            'time_short' => 'H:i',
            'time_medium' => 'H:i:s',
            'time_long' => 'H:i:s',
            'time_full' => 'H:i:s',
            'months' => array('janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'),
            'months_short' => array('janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'),
            'days' => array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'),
            'days_short' => array('dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'),
        );

        // German groups with a full stop: 1.234,57 (ICU de-DE).
        $german = array(
            'decimal_separator' => ',',
            'group_separator' => '.',
            'group_size' => 3,
            'percent_space' => true,
            'currency_space' => true,
            'datetime_separator' => ' ',
        );

        // Iberian and Brazilian Portuguese group with a full stop too, but
        // ICU keeps the space before the percent sign in Spanish only.
        $iberian = array(
            'decimal_separator' => ',',
            'group_separator' => '.',
            'group_size' => 3,
            'percent_space' => true,
            'currency_space' => true,
            'datetime_separator' => ' ',
        );

        $brazilian = $iberian;
        $brazilian['percent_space'] = false;

        $overrides['de'] = $german + array(
            'date_short' => 'd.m.y',
            'date_medium' => 'd.m.Y',
            'date_long' => 'd. F Y',
            'date_full' => 'l, d. F Y',
            'time_short' => 'H:i',
            'time_medium' => 'H:i:s',
            'time_long' => 'H:i:s',
            'time_full' => 'H:i:s',
            'months' => array('Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'),
            'months_short' => array('Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'),
            'days' => array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'),
            'days_short' => array('So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'),
        );

        $overrides['es'] = $iberian + array(
            'date_short' => 'd/n/y',
            'date_medium' => 'd M Y',
            'date_long' => 'd \d\e F \d\e Y',
            'date_full' => 'l, d \d\e F \d\e Y',
            'time_short' => 'H:i',
            'time_medium' => 'H:i:s',
            'time_long' => 'H:i:s',
            'time_full' => 'H:i:s',
            'months' => array('enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'),
            'months_short' => array('ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'),
            'days' => array('domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'),
            'days_short' => array('dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'),
        );

        $overrides['pt'] = $iberian + array(
            'date_short' => 'd/m/y',
            'date_medium' => 'd \d\e M \d\e Y',
            'date_long' => 'd \d\e F \d\e Y',
            'date_full' => 'l, d \d\e F \d\e Y',
            'time_short' => 'H:i',
            'time_medium' => 'H:i:s',
            'time_long' => 'H:i:s',
            'time_full' => 'H:i:s',
            'months' => array('janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'),
            'months_short' => array('jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'),
            'days' => array('domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'),
            'days_short' => array('dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'),
        );

        $overrides['pt-br'] = $brazilian + $overrides['pt'];

        // Override keys are lower-case; locale codes are canonical (pt-BR).
        $lookup = strtolower($locale);
        $lookupBase = strtolower($base);

        if (isset($overrides[$lookup])) {
            return $overrides[$lookup] + $en;
        }
        if (isset($overrides[$lookupBase])) {
            return $overrides[$lookupBase] + $en;
        }

        return $en;
    }
}
