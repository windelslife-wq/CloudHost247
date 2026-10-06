<?php
/**
 * Domain Broker — input validation.
 *
 * Collects every field error before throwing so the UI can render a complete
 * form state, and normalises values on the way through (trim, case, punycode)
 * so callers always receive canonical data.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Validator
{
    /** @var array */
    protected $input;

    /** @var array */
    protected $clean = [];

    /** @var array field => message */
    protected $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    public static function make(array $input)
    {
        return new self($input);
    }

    public function raw($field, $default = null)
    {
        return array_key_exists($field, $this->input) ? $this->input[$field] : $default;
    }

    protected function fail($field, $message)
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function errors()
    {
        return $this->errors;
    }

    public function fails()
    {
        return (bool) $this->errors;
    }

    /** @throws ValidationException */
    public function validate()
    {
        if ($this->errors) {
            throw new ValidationException('Please correct the highlighted fields.', $this->errors);
        }
        return $this->clean;
    }

    public function value($field, $default = null)
    {
        return array_key_exists($field, $this->clean) ? $this->clean[$field] : $default;
    }

    /* -------------------------------------------------------------- rules */

    public function required($field, $label = null)
    {
        $value = $this->raw($field);
        if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && !$value)) {
            $this->fail($field, ($label ?: Str::label($field)) . ' is required.');
        }
        return $this;
    }

    public function domain($field, $required = true, $label = null)
    {
        $value = trim((string) $this->raw($field, ''));
        if ($value === '') {
            if ($required) {
                $this->fail($field, ($label ?: 'Domain name') . ' is required.');
            }
            return $this;
        }
        $normalised = DomainName::normalise($value);
        if ($normalised === null) {
            $this->fail($field, 'Enter a valid domain name, for example example.com.');
            return $this;
        }
        $this->clean[$field] = $normalised;
        return $this;
    }

    public function currency($field, array $allowed = [], $required = true)
    {
        $value = strtoupper(trim((string) $this->raw($field, '')));
        if ($value === '') {
            if ($required) {
                $this->fail($field, 'Select a currency.');
            }
            return $this;
        }
        if (!Money::isValidCurrency($value)) {
            $this->fail($field, 'Select a valid three-letter currency code.');
            return $this;
        }
        if ($allowed && !in_array($value, $allowed, true)) {
            $this->fail($field, 'That currency is not available for domain brokerage.');
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /**
     * Monetary amount → minor units, validated against configured bounds.
     */
    public function money($field, $currency, $minMinor = null, $maxMinor = null, $required = true)
    {
        $raw = $this->raw($field);
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($required) {
                $this->fail($field, Str::label($field) . ' is required.');
            }
            return $this;
        }
        try {
            $minor = Money::toMinor($raw, $currency);
        } catch (ValidationException $e) {
            $this->fail($field, 'Enter a valid amount.');
            return $this;
        }
        if ($minor <= 0) {
            $this->fail($field, 'Enter an amount greater than zero.');
            return $this;
        }
        if ($minMinor !== null && $minor < (int) $minMinor) {
            $this->fail($field, 'The minimum is ' . Money::toDecimalString($minMinor, $currency) . ' ' . $currency . '.');
            return $this;
        }
        if ($maxMinor !== null && (int) $maxMinor > 0 && $minor > (int) $maxMinor) {
            $this->fail($field, 'The maximum is ' . Money::toDecimalString($maxMinor, $currency) . ' ' . $currency . '.');
            return $this;
        }
        $this->clean[$field] = $minor;
        return $this;
    }

    public function text($field, $maxLength = 5000, $required = false, $minLength = 0)
    {
        $value = $this->raw($field, '');
        if (!is_scalar($value) && $value !== null) {
            $this->fail($field, 'Invalid value.');
            return $this;
        }
        $value = Str::cleanText((string) $value, $maxLength + 1);
        if ($value === '') {
            if ($required) {
                $this->fail($field, Str::label($field) . ' is required.');
            } else {
                $this->clean[$field] = '';
            }
            return $this;
        }
        if (mb_strlen($value) > $maxLength) {
            $this->fail($field, Str::label($field) . ' must be ' . $maxLength . ' characters or fewer.');
            return $this;
        }
        if ($minLength > 0 && mb_strlen($value) < $minLength) {
            $this->fail($field, Str::label($field) . ' must be at least ' . $minLength . ' characters.');
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function boolean($field, $default = false)
    {
        $value = $this->raw($field, null);
        if ($value === null) {
            $this->clean[$field] = (bool) $default;
            return $this;
        }
        if (is_bool($value)) {
            $this->clean[$field] = $value;
            return $this;
        }
        $this->clean[$field] = in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true);
        return $this;
    }

    public function in($field, array $allowed, $required = true, $default = null)
    {
        $value = $this->raw($field);
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($field, 'Select a valid option.');
            } elseif ($default !== null) {
                $this->clean[$field] = $default;
            }
            return $this;
        }
        if (!in_array($value, $allowed, true)) {
            $this->fail($field, 'Select a valid option.');
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function integer($field, $min = null, $max = null, $required = true, $default = null)
    {
        $value = $this->raw($field);
        if ($value === null || $value === '') {
            if ($required) {
                $this->fail($field, Str::label($field) . ' is required.');
            } elseif ($default !== null) {
                $this->clean[$field] = (int) $default;
            }
            return $this;
        }
        if (!is_numeric($value) || (string) (int) $value !== (string) trim((string) $value)) {
            $this->fail($field, Str::label($field) . ' must be a whole number.');
            return $this;
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            $this->fail($field, Str::label($field) . ' must be at least ' . $min . '.');
            return $this;
        }
        if ($max !== null && $int > $max) {
            $this->fail($field, Str::label($field) . ' must be at most ' . $max . '.');
            return $this;
        }
        $this->clean[$field] = $int;
        return $this;
    }

    public function email($field, $required = true)
    {
        $value = trim((string) $this->raw($field, ''));
        if ($value === '') {
            if ($required) {
                $this->fail($field, 'Email address is required.');
            }
            return $this;
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 190) {
            $this->fail($field, 'Enter a valid email address.');
            return $this;
        }
        $this->clean[$field] = strtolower($value);
        return $this;
    }

    public function phone($field, $required = false)
    {
        $value = trim((string) $this->raw($field, ''));
        if ($value === '') {
            if ($required) {
                $this->fail($field, 'Phone number is required.');
            }
            return $this;
        }
        $stripped = preg_replace('/[^0-9+]/', '', $value);
        if (strlen($stripped) < 6 || strlen($stripped) > 20) {
            $this->fail($field, 'Enter a valid phone number.');
            return $this;
        }
        $this->clean[$field] = $stripped;
        return $this;
    }

    /** Pass a value through untouched after a callable check. */
    public function custom($field, callable $check, $message)
    {
        $value = $this->raw($field);
        if (!$check($value)) {
            $this->fail($field, $message);
            return $this;
        }
        $this->clean[$field] = $value;
        return $this;
    }

    /** Copy an already-trusted value into the clean set. */
    public function set($field, $value)
    {
        $this->clean[$field] = $value;
        return $this;
    }
}
