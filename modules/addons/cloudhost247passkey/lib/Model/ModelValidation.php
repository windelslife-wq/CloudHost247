<?php
/** Shared strict validation for database record models. */

namespace CloudHost247\Passkey\Model;

class ModelValidation
{
    public static function identity($type, $id, $allowUnknownId = false)
    {
        $type = (string) $type;
        if (!in_array($type, [IdentityScope::CLIENT, IdentityScope::CLIENT_USER, IdentityScope::ADMIN], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey identity type.');
        }
        if ($id === null && $allowUnknownId) {
            return [$type, null];
        }
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false || (int) $id < 1) {
            throw new \InvalidArgumentException('A positive local identity ID is required.');
        }
        return [$type, (int) $id];
    }

    public static function hash($value, $field)
    {
        $value = strtolower((string) $value);
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new \InvalidArgumentException($field . ' must be a SHA-256 hex digest.');
        }
        return $value;
    }

    public static function text($value, $field, $maxLength, $allowEmpty = false)
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' must be text.');
        }
        $value = trim((string) $value);
        if ((!$allowEmpty && $value === '') || strlen($value) > (int) $maxLength) {
            throw new \InvalidArgumentException($field . ' is empty or too long.');
        }
        return $value;
    }

    public static function timestamp($value, $field, $nullable = false)
    {
        if ($value === null && $nullable) {
            return null;
        }
        $value = (string) $value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            throw new \InvalidArgumentException($field . ' must be a UTC SQL timestamp.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \InvalidArgumentException($field . ' must be a real UTC SQL timestamp.');
        }
        return $value;
    }

    public static function ipAddress($value, $field, $nullable = false)
    {
        if ($value === null && $nullable) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) > 45 || filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException($field . ' must be a valid IP address.');
        }
        return $value;
    }

    public static function positiveInt($value, $field, $nullable = false)
    {
        if ($value === null && $nullable) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 1) {
            throw new \InvalidArgumentException($field . ' must be a positive integer.');
        }
        return (int) $number;
    }

    public static function nonNegativeInt($value, $field)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 0) {
            throw new \InvalidArgumentException($field . ' must be a non-negative integer.');
        }
        return (int) $number;
    }

    public static function boolean($value, $field)
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new \InvalidArgumentException($field . ' must be boolean.');
        }
        return (int) (bool) $value;
    }

    public static function rejectKeys(array $row, array $forbidden)
    {
        $forbidden = array_map([self::class, 'normalizeKey'], $forbidden);
        foreach (array_keys($row) as $key) {
            if (in_array(self::normalizeKey($key), $forbidden, true)) {
                throw new \InvalidArgumentException('Secret or biometric material is not accepted by Passkey models.');
            }
        }
    }

    public static function allowKeys(array $row, array $allowed, $recordName = 'record')
    {
        foreach (array_keys($row) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported field in Passkey ' . (string) $recordName . '.');
            }
        }
    }

    private static function normalizeKey($key)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
    }
}
