<?php
/**
 * CloudHost247 App Cloud — input validation.
 *
 * Fluent, dependency-free validation used by every API controller, portal form
 * and manifest field. It collects *all* failures and throws one
 * ValidationException carrying a field → message map, so a customer sees every
 * problem at once instead of one per round trip.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Validator
{
    /** @var array */
    private $input;

    /** @var array field => value (validated, cast) */
    private $values = [];

    /** @var array field => message */
    private $errors = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    public static function make(array $input)
    {
        return new self($input);
    }

    public function has($field)
    {
        return array_key_exists($field, $this->input);
    }

    public function raw($field, $default = null)
    {
        return array_key_exists($field, $this->input) ? $this->input[$field] : $default;
    }

    /** Required, non-empty after trimming. */
    public function required($field, $label = null)
    {
        $value = $this->raw($field);
        $label = $label ?: Str::label($field);
        if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
            $this->errors[$field] = $label . ' is required.';
            return $this;
        }
        $this->values[$field] = is_string($value) ? trim($value) : $value;
        return $this;
    }

    /** Optional field: records the value when present and non-null. */
    public function optional($field, $default = null)
    {
        $value = $this->raw($field, $default);
        if (is_string($value)) {
            $value = trim($value);
        }
        $this->values[$field] = ($value === null || $value === '') ? $default : $value;
        return $this;
    }

    public function string($field, $max = 191, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        if (!array_key_exists($field, $this->values)) {
            $this->optional($field, '');
        }
        $value = $this->values[$field];
        $label = $label ?: Str::label($field);
        if ($value !== null && !is_scalar($value)) {
            $this->errors[$field] = $label . ' must be text.';
            return $this;
        }
        $value = (string) $value;
        if (function_exists('mb_strlen') ? mb_strlen($value) > $max : strlen($value) > $max) {
            $this->errors[$field] = $label . ' must be at most ' . (int) $max . ' characters.';
            return $this;
        }
        $this->values[$field] = $value;
        return $this;
    }

    public function slug($field, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? (string) $this->values[$field] : '';
        $label = $label ?: Str::label($field);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,78}[a-z0-9])?$/', $value)) {
            $this->errors[$field] = $label . ' must be lowercase letters, numbers and dashes only.';
        }
        return $this;
    }

    public function in($field, array $allowed, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? $this->values[$field] : $this->raw($field);
        $label = $label ?: Str::label($field);
        if (!in_array($value, $allowed, true) && !in_array((string) $value, array_map('strval', $allowed), true)) {
            $this->errors[$field] = $label . ' must be one of: ' . implode(', ', array_map('strval', $allowed)) . '.';
            return $this;
        }
        $this->values[$field] = $value;
        return $this;
    }

    public function integer($field, $min = null, $max = null, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? $this->values[$field] : $this->raw($field);
        $label = $label ?: Str::label($field);
        // An absent optional field has nothing to validate. A missing *required*
        // field already carries an error from required(), so this cannot be used
        // to sneak an empty value past a mandatory number.
        if ($value === null || $value === '') {
            return $this;
        }
        if (!is_numeric($value)) {
            $this->errors[$field] = $label . ' must be a whole number.';
            return $this;
        }
        $int = (int) $value;
        if ($min !== null && $int < $min) {
            $this->errors[$field] = $label . ' must be at least ' . (int) $min . '.';
            return $this;
        }
        if ($max !== null && $int > $max) {
            $this->errors[$field] = $label . ' must be at most ' . (int) $max . '.';
            return $this;
        }
        $this->values[$field] = $int;
        return $this;
    }

    public function boolean($field)
    {
        $value = isset($this->values[$field]) ? $this->values[$field] : $this->raw($field, false);
        if (is_string($value)) {
            $value = in_array(strtolower(trim($value)), ['1', 'on', 'true', 'yes'], true);
        }
        $this->values[$field] = (bool) $value;
        return $this;
    }

    public function email($field, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? (string) $this->values[$field] : '';
        $label = $label ?: Str::label($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = $label . ' must be a valid email address.';
        }
        return $this;
    }

    public function url($field, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? (string) $this->values[$field] : '';
        $label = $label ?: Str::label($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->errors[$field] = $label . ' must be a valid URL.';
        }
        return $this;
    }

    /** Fully qualified domain name (RFC 1035 length limits, punycode allowed). */
    public function domain($field, $allowWildcard = false, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? strtolower(trim((string) $this->values[$field])) : '';
        $label = $label ?: Str::label($field);
        if ($value === '') {
            return $this;
        }
        $test = $value;
        if ($allowWildcard && Str::startsWith($test, '*.')) {
            $test = substr($test, 2);
        }
        $valid = preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $test) === 1
            && strlen($test) <= 253;
        if (!$valid) {
            $this->errors[$field] = $label . ' must be a valid domain name.';
        } else {
            $this->values[$field] = $value;
        }
        return $this;
    }

    public function ip($field, $label = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? (string) $this->values[$field] : '';
        $label = $label ?: Str::label($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) === false) {
            $this->errors[$field] = $label . ' must be a valid IP address.';
        }
        return $this;
    }

    public function arrayField($field, $label = null)
    {
        $value = $this->raw($field, []);
        $label = $label ?: Str::label($field);
        if ($value === null || $value === '') {
            $value = [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            $this->errors[$field] = $label . ' must be a list or object.';
            $value = [];
        }
        $this->values[$field] = $value;
        return $this;
    }

    /** Environment variable keys: [A-Za-z_][A-Za-z0-9_]*, no duplicates. */
    public function environment($field = 'environment')
    {
        $value = isset($this->values[$field]) ? $this->values[$field] : [];
        if (!is_array($value)) {
            $this->errors[$field] = 'Environment must be a mapping of KEY to value.';
            return $this;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $key)) {
                $this->errors[$field] = 'Invalid environment key: ' . Str::clip($key, 40);
                break;
            }
            $clean[$key] = is_scalar($item) || $item === null ? (string) $item : Str::jsonEncode($item);
        }
        $this->values[$field] = $clean;
        return $this;
    }

    public function matches($field, $pattern, $label = null, $message = null)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = isset($this->values[$field]) ? (string) $this->values[$field] : '';
        if ($value !== '' && !preg_match($pattern, $value)) {
            $this->errors[$field] = $message ?: (($label ?: Str::label($field)) . ' has an invalid format.');
        }
        return $this;
    }

    /** Custom rule: fn($value, Validator $v) returning an error string or null. */
    public function rule($field, callable $rule)
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = array_key_exists($field, $this->values) ? $this->values[$field] : $this->raw($field);
        $error = $rule($value, $this);
        if (is_string($error) && $error !== '') {
            $this->errors[$field] = $error;
        }
        return $this;
    }

    public function errors()
    {
        return $this->errors;
    }

    public function fails()
    {
        return $this->errors !== [];
    }

    public function values()
    {
        return $this->values;
    }

    public function value($field, $default = null)
    {
        return array_key_exists($field, $this->values) ? $this->values[$field] : $default;
    }

    /**
     * Return validated values or throw.
     *
     * @throws ValidationException
     */
    public function validate()
    {
        if ($this->fails()) {
            throw new ValidationException('The submitted data is not valid.', [
                'errors' => $this->errors,
                'fields' => array_keys($this->errors),
            ]);
        }
        return $this->values;
    }
}
