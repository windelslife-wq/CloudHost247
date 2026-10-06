<?php
/**
 * Domain Broker — escrow provider registry.
 *
 * Resolves the configured provider and lets tests (or a future module) swap
 * one in. Providers are registered by name; unknown names fall back to the
 * internal ledger rather than failing open.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Escrow;

use DomainBroker\Core\Settings;

class EscrowManager
{
    /** @var array name => callable|EscrowProviderInterface */
    protected static $providers = [];

    /** @var EscrowProviderInterface|null forced instance (tests) */
    protected static $forced;

    public static function register($name, $provider)
    {
        self::$providers[$name] = $provider;
    }

    public static function force(EscrowProviderInterface $provider = null)
    {
        self::$forced = $provider;
    }

    public static function reset()
    {
        self::$forced = null;
        self::$providers = [];
    }

    /** @return EscrowProviderInterface */
    public static function provider($name = null)
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $name = $name ?: Settings::string('escrow_provider', 'internal');

        if (isset(self::$providers[$name])) {
            $candidate = self::$providers[$name];
            return is_callable($candidate) ? $candidate() : $candidate;
        }

        switch ($name) {
            case 'manual':
                return new ManualEscrowProvider();
            case 'http':
                return new HttpEscrowProvider();
            case 'internal':
            default:
                return new InternalEscrowProvider();
        }
    }

    /** @return array name => label */
    public static function available()
    {
        $out = [
            'internal' => (new InternalEscrowProvider())->label(),
            'manual'   => (new ManualEscrowProvider())->label(),
            'http'     => (new HttpEscrowProvider())->label(),
        ];
        foreach (self::$providers as $name => $provider) {
            $instance = is_callable($provider) ? $provider() : $provider;
            $out[$name] = $instance->label();
        }
        return $out;
    }
}
