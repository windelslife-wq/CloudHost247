<?php
/**
 * Explicit, separate registry for control-panel account adapters.
 *
 * Registering code here means an adapter implementation exists; it does not
 * mean a server is configured, credentials are valid, a panel is licensed, or
 * an operation is enabled for customers.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\ControlPanels;

use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\PanelAdapterUnavailableException;
use Ch247Apps\Core\ValidationException;

class ControlPanelAdapterRegistry
{
    const CATALOG = [
        'cpanel_whm' => 'cPanel & WHM',
        'plesk' => 'Plesk',
        'directadmin' => 'DirectAdmin',
    ];

    const CAPABILITY_KEYS = [
        'account.verify',
        'account.get',
        'account.domains.list',
        'account.domains.aliases.list',
        'account.usage.quota.read',
        'account.usage.bandwidth.read',
        'account.create',
        'account.suspend',
        'account.unsuspend',
        'account.terminate',
    ];

    /** @var ControlPanelAdapterInterface[] */
    private static $adapters = [];

    /** @var bool */
    private static $booted = false;

    /** Register built-in adapters once. No fake or mock is installed here. */
    public static function boot()
    {
        if (self::$booted) {
            return self::catalogWithoutBoot();
        }
        self::$booted = true;
        if (!isset(self::$adapters['cpanel_whm'])) {
            self::register(new CpanelWhmAdapter());
        }
        return self::catalogWithoutBoot();
    }

    public static function register(ControlPanelAdapterInterface $adapter)
    {
        $key = strtolower(trim((string) $adapter->key()));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $key)) {
            throw new ValidationException('The control-panel adapter returned an invalid key.');
        }
        if (isset(self::$adapters[$key])) {
            throw new ConflictException('A control-panel adapter is already registered for "' . $key . '".');
        }
        if (trim((string) $adapter->name()) === '') {
            throw new ValidationException('The control-panel adapter must provide a display name.');
        }
        if (!is_array($adapter->capabilities())) {
            throw new ValidationException('The control-panel adapter capabilities must be a map.');
        }
        self::$adapters[$key] = $adapter;
        return $adapter;
    }

    /** @throws PanelAdapterUnavailableException */
    public static function forPanel($key)
    {
        self::boot();
        $key = strtolower(trim((string) $key));
        if (!isset(self::$adapters[$key])) {
            $name = isset(self::CATALOG[$key]) ? self::CATALOG[$key] : $key;
            throw new PanelAdapterUnavailableException(
                'No control-panel adapter is implemented for ' . $name . '.',
                ['panel_key' => $key, 'error_code' => 'PANEL_ADAPTER_UNAVAILABLE']
            );
        }
        return self::$adapters[$key];
    }

    public static function hasAdapter($key)
    {
        self::boot();
        return isset(self::$adapters[strtolower(trim((string) $key))]);
    }

    /**
     * Code-registration inventory only. It deliberately avoids an `available`
     * or `operational` flag because those require per-server verification.
     */
    public static function catalog()
    {
        self::boot();
        return self::catalogWithoutBoot();
    }

    public static function normaliseCapabilities($capabilities)
    {
        if (!is_array($capabilities)) {
            return [];
        }
        $out = [];
        foreach (self::CAPABILITY_KEYS as $key) {
            $out[$key] = isset($capabilities[$key]) && in_array(
                $capabilities[$key], [true, 1, '1', 'true', 'on'], true
            );
        }
        return $out;
    }

    public static function assertSupports(ControlPanelAdapterInterface $adapter, array $required)
    {
        $capabilities = self::normaliseCapabilities($adapter->capabilities());
        foreach ($required as $capability) {
            if (!in_array($capability, self::CAPABILITY_KEYS, true) || empty($capabilities[$capability])) {
                throw new PanelAdapterUnavailableException(
                    'The ' . $adapter->name() . ' adapter does not implement ' . (string) $capability . '.',
                    ['panel_key' => $adapter->key(), 'capability' => (string) $capability,
                        'error_code' => 'PANEL_CAPABILITY_UNAVAILABLE']
                );
            }
        }
        return true;
    }

    public static function reset()
    {
        self::$adapters = [];
        self::$booted = false;
    }

    private static function catalogWithoutBoot()
    {
        $keys = array_unique(array_merge(array_keys(self::CATALOG), array_keys(self::$adapters)));
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            $adapter = isset(self::$adapters[$key]) ? self::$adapters[$key] : null;
            $out[] = [
                'panel_key' => $key,
                'name' => $adapter ? (string) $adapter->name()
                    : (isset(self::CATALOG[$key]) ? self::CATALOG[$key] : $key),
                'code_registered' => $adapter !== null,
                'capabilities' => $adapter
                    ? self::normaliseCapabilities($adapter->capabilities())
                    : self::normaliseCapabilities([]),
            ];
        }
        return $out;
    }
}
