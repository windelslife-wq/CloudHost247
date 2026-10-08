<?php
/** Explicit registry for read-only DNS inventory providers (separate from compute adapters). */

namespace Ch247Apps\Domains;

use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\ValidationException;

class DnsInventoryProviderRegistry
{
    /** @var DnsInventoryProviderInterface[] */
    private static $providers = [];

    public static function register(DnsInventoryProviderInterface $provider)
    {
        $key = self::normalizeKey($provider->key());
        if ($key === '') {
            throw new ValidationException('The DNS inventory provider returned an invalid provider key.');
        }
        if (isset(self::$providers[$key])) {
            throw new ConflictException('A DNS inventory provider is already registered for this key.');
        }
        self::$providers[$key] = $provider;
        return $provider;
    }

    /** No default provider is installed; callers must explicitly register one. */
    public static function forProvider($key)
    {
        $key = self::normalizeKey($key);
        if ($key === '' || !isset(self::$providers[$key])) {
            throw new ProviderUnavailableException(
                'No DNS inventory provider is explicitly registered for this operation.',
                ['provider_code' => preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $key) ? $key : '',
                    'error_code' => 'DNS_PROVIDER_UNAVAILABLE']
            );
        }
        return self::$providers[$key];
    }

    public static function hasProvider($key)
    {
        $key = self::normalizeKey($key);
        return $key !== '' && isset(self::$providers[$key]);
    }

    private static function normalizeKey($key)
    {
        if (!is_string($key) && !is_numeric($key)) return '';
        $key = strtolower(trim((string) $key));
        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $key) ? $key : '';
    }

    /** Exposed for lifecycle tests and isolated worker boots. */
    public static function reset()
    {
        self::$providers = [];
    }

    public static function registeredKeys()
    {
        $keys = array_keys(self::$providers);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
