<?php
/**
 * Explicit registry of installed infrastructure-provider adapters.
 *
 * The catalog is informational. A catalog entry is NOT a working integration;
 * only a registered implementation is reported as available or used to provision.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\ValidationException;

class ProviderRegistry
{
    /** Names are listed centrally, but none are supported until a real adapter registers. */
    const CATALOG = [
        'ovhcloud' => 'OVHcloud',
        'hetzner' => 'Hetzner',
        'aws' => 'Amazon Web Services',
        'digitalocean' => 'DigitalOcean',
        'vultr' => 'Vultr',
        'contabo' => 'Contabo',
    ];

    /** @var InfrastructureProviderInterface[] */
    private static $adapters = [];

    public static function register(InfrastructureProviderInterface $adapter)
    {
        $key = strtolower(trim((string) $adapter->key()));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $key)) {
            throw new ValidationException('The provider adapter returned an invalid provider key.');
        }
        if (isset(self::$adapters[$key])) {
            throw new ConflictException('A provider adapter is already registered for "' . $key . '".');
        }
        if (trim((string) $adapter->name()) === '') {
            throw new ValidationException('The provider adapter must supply a display name.');
        }
        self::$adapters[$key] = $adapter;
        return $adapter;
    }

    public static function reset()
    {
        self::$adapters = [];
    }

    public static function isKnownProvider($key)
    {
        $key = strtolower(trim((string) $key));
        return isset(self::CATALOG[$key]) || isset(self::$adapters[$key]);
    }

    /** @throws ProviderUnavailableException */
    public static function forProvider($key)
    {
        $key = strtolower(trim((string) $key));
        if (!isset(self::$adapters[$key])) {
            $name = isset(self::CATALOG[$key]) ? self::CATALOG[$key] : $key;
            throw new ProviderUnavailableException(
                'No real infrastructure-provider adapter is installed for ' . $name . '.',
                ['provider_code' => $key, 'error_code' => 'PROVIDER_UNAVAILABLE']
            );
        }
        return self::$adapters[$key];
    }

    public static function hasAdapter($key)
    {
        return isset(self::$adapters[strtolower(trim((string) $key))]);
    }

    /** @return array[] adapter status suitable for an API response */
    public static function catalog()
    {
        $keys = array_unique(array_merge(array_keys(self::CATALOG), array_keys(self::$adapters)));
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            $adapter = isset(self::$adapters[$key]) ? self::$adapters[$key] : null;
            $out[] = [
                'provider_code' => $key,
                'name' => $adapter ? (string) $adapter->name() : (isset(self::CATALOG[$key]) ? self::CATALOG[$key] : $key),
                'adapter_available' => $adapter !== null,
                'capabilities' => $adapter ? self::normaliseCapabilities($adapter->capabilities()) : [],
            ];
        }
        return $out;
    }

    /** Validate provider capability declarations and expose only known flags. */
    public static function normaliseCapabilities($capabilities)
    {
        $known = [
            'server.create', 'server.get', 'server.delete', 'server.reboot',
            'server.power_on', 'server.power_off', 'server.rebuild', 'server.resize',
            'snapshot.create', 'snapshot.delete', 'snapshot.restore', 'server.metrics',
        ];
        if (!is_array($capabilities)) {
            return [];
        }
        $out = [];
        foreach ($known as $capability) {
            $out[$capability] = !empty($capabilities[$capability]);
        }
        return $out;
    }

    public static function assertSupports(InfrastructureProviderInterface $adapter, array $required)
    {
        $capabilities = self::normaliseCapabilities($adapter->capabilities());
        foreach ($required as $capability) {
            if (empty($capabilities[$capability])) {
                throw new ProviderUnavailableException(
                    'The installed ' . $adapter->name() . ' adapter does not support ' . $capability . '.',
                    ['provider_code' => $adapter->key(), 'capability' => $capability,
                        'error_code' => 'PROVIDER_UNAVAILABLE']
                );
            }
        }
        return true;
    }
}
