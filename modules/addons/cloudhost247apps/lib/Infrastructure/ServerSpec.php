<?php
/** Normalizes the small, secret-free provider VM request contract. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\ValidationException;

class ServerSpec
{
    const MAX_CPU_CORES = 256;
    const MAX_MEMORY_MB = 2097152;
    const MAX_STORAGE_GB = 1000000;

    public static function normalise(array $input)
    {
        $allowed = ['name', 'hostname', 'region', 'image', 'cpu_cores', 'memory_mb', 'storage_gb'];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ValidationException('Unsupported server specification field.', [
                    'field' => (string) $key,
                ]);
            }
        }

        $name = trim((string) (isset($input['name']) ? $input['name'] : ''));
        $region = trim((string) (isset($input['region']) ? $input['region'] : ''));
        $image = trim((string) (isset($input['image']) ? $input['image'] : ''));
        if ($name === '' || strlen($name) > 120) {
            throw new ValidationException('A server name of 1–120 characters is required.');
        }
        if (!preg_match('/^[\pL\pN][\pL\pN ._()\-]{0,118}[\pL\pN)]?$/u', $name)) {
            throw new ValidationException('The server name contains unsupported characters.');
        }
        if ($region === '' || strlen($region) > 80 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $region)) {
            throw new ValidationException('A valid provider region is required.');
        }
        if ($image === '' || strlen($image) > 120 || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@-]*$#', $image)) {
            throw new ValidationException('A valid provider image identifier is required.');
        }

        $hostname = isset($input['hostname']) ? strtolower(trim((string) $input['hostname'])) : '';
        if ($hostname !== '') {
            if (strlen($hostname) > 253 || substr($hostname, -1) === '.'
                || filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new ValidationException('The server hostname is invalid.');
            }
        }

        $cpu = self::positiveInt(isset($input['cpu_cores']) ? $input['cpu_cores'] : null, 'cpu_cores', 1, self::MAX_CPU_CORES);
        $memory = self::positiveInt(isset($input['memory_mb']) ? $input['memory_mb'] : null, 'memory_mb', 512, self::MAX_MEMORY_MB);
        $storage = self::positiveInt(isset($input['storage_gb']) ? $input['storage_gb'] : null, 'storage_gb', 10, self::MAX_STORAGE_GB);

        return [
            'name' => $name,
            'hostname' => $hostname !== '' ? $hostname : null,
            'region' => $region,
            'image' => $image,
            'cpu_cores' => $cpu,
            'memory_mb' => $memory,
            'storage_gb' => $storage,
        ];
    }

    private static function positiveInt($value, $field, $min, $max)
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value)))) {
            throw new ValidationException('The ' . $field . ' value must be an integer.', ['field' => $field]);
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new ValidationException('The ' . $field . ' value is outside the allowed range.', [
                'field' => $field, 'min' => $min, 'max' => $max,
            ]);
        }
        return $value;
    }
}
