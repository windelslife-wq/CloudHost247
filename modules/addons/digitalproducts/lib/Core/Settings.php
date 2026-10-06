<?php
namespace DigitalProducts\Core;

use WHMCS\Database\Capsule;

/** Centralised, validated addon settings. Secrets are environment-only. */
class Settings
{
    const DEFAULTS = [
        'download_limit' => '5',
        'link_expiry_hours' => '48',
        'access_mode' => 'current_version',
        'license_enabled' => 'on',
        'email_delivery' => 'on',
        'update_notifications' => 'off',
        'max_upload_size' => '524288000',
        'allowed_extensions' => 'zip,tar.gz,pdf,js,css,php,json,xml,txt,md',
        'storage_path' => '',
        'api_rate_limit' => '60',
        'log_retention_days' => '0',
        'debug_logging' => 'off',
    ];
    const SECRET_KEYS = ['encryption_key', 'api_secret', 'storage_secret'];
    protected static $cache;
    protected static $overrides = [];

    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$overrides)) return self::$overrides[$key];
        if (in_array($key, self::SECRET_KEYS, true)) {
            $env = getenv('DIGITALPRODUCTS_' . strtoupper($key));
            return $env !== false ? $env : $default;
        }
        if (self::$cache === null) {
            self::$cache = [];
            try {
                if (class_exists('WHMCS\\Database\\Capsule')) {
                    foreach (Capsule::table('tbladdonmodules')->where('module', 'digitalproducts')->get() as $row) {
                        self::$cache[$row->setting] = $row->value;
                    }
                }
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        if (isset(self::$cache[$key]) && self::$cache[$key] !== '') return self::$cache[$key];
        if ($default !== null) return $default;
        return isset(self::DEFAULTS[$key]) ? self::DEFAULTS[$key] : null;
    }

    public static function int($key, $default = 0)
    {
        return (int) self::get($key, (string) $default);
    }

    public static function bool($key, $default = false)
    {
        $value = strtolower((string) self::get($key, $default ? 'on' : ''));
        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    public static function extensions()
    {
        $raw = (string) self::get('allowed_extensions');
        $out = [];
        foreach (preg_split('/[,\s]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) as $extension) {
            $extension = ltrim($extension, '.');
            if (preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)?$/', $extension)) $out[] = $extension;
        }
        return array_values(array_unique($out));
    }

    public static function validate(array $values)
    {
        $limit = max(0, (int) ($values['download_limit'] ?? self::get('download_limit')));
        $expiry = max(0, (int) ($values['link_expiry_hours'] ?? self::get('link_expiry_hours')));
        $max = max(1, (int) ($values['max_upload_size'] ?? self::get('max_upload_size')));
        $mode = $values['access_mode'] ?? self::get('access_mode');
        if (!in_array($mode, ['current_version', 'purchase_version'], true)) {
            throw new ValidationException('Invalid version access mode.');
        }
        $path = trim((string) ($values['storage_path'] ?? self::get('storage_path')));
        if ($path !== '' && strpos($path, "\0") !== false) throw new ValidationException('Invalid storage path.');
        return ['download_limit' => $limit, 'link_expiry_hours' => $expiry, 'max_upload_size' => $max, 'access_mode' => $mode, 'storage_path' => $path];
    }

    public static function set($key, $value)
    {
        if (in_array($key, self::SECRET_KEYS, true)) throw new ValidationException('Secrets must be configured through the environment.');
        if (class_exists('WHMCS\\Database\\Capsule')) {
            Capsule::table('tbladdonmodules')->updateOrInsert(['module' => 'digitalproducts', 'setting' => $key], ['value' => (string) $value]);
        }
        if (self::$cache === null) self::$cache = [];
        self::$cache[$key] = (string) $value;
    }

    public static function setMany(array $values)
    {
        foreach ($values as $key => $value) self::set($key, $value);
    }

    public static function override($key, $value) { self::$overrides[$key] = $value; }
    public static function overrideMany(array $values) { foreach ($values as $k => $v) self::override($k, $v); }
    public static function clearCache() { self::$cache = null; self::$overrides = []; }
}
