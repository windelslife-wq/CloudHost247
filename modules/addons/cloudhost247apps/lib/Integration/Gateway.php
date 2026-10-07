<?php
/**
 * CloudHost247 App Cloud — gateway resolver.
 *
 * Holds the one instance the platform talks to. Production resolves to
 * WhmcsGateway; the test harness (and the CLI worker under --offline) installs
 * FakeGateway with Gateway::set().
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Integration;

use Ch247Apps\Core\ConfigurationException;

class Gateway
{
    /** @var GatewayInterface|null */
    private static $instance;

    public static function set(GatewayInterface $gateway = null)
    {
        self::$instance = $gateway;
    }

    /** @return GatewayInterface */
    public static function get()
    {
        if (self::$instance instanceof GatewayInterface) {
            return self::$instance;
        }
        if (class_exists('\\WHMCS\\Database\\Capsule') || function_exists('localAPI')) {
            self::$instance = new WhmcsGateway();
            return self::$instance;
        }
        // Offline context (unit test, standalone CLI) without an explicit fake:
        // hand back a recording fake rather than failing, but never pretend to
        // be connected to a real installation.
        self::$instance = new FakeGateway();
        self::$instance->setOffline(true);
        return self::$instance;
    }

    public static function isLive()
    {
        return self::$instance instanceof WhmcsGateway;
    }

    /** @throws ConfigurationException */
    public static function requireLive()
    {
        if (!self::isLive()) {
            throw new ConfigurationException(
                'This operation requires a live WHMCS installation.'
            );
        }
        return self::get();
    }

    public static function reset()
    {
        self::$instance = null;
    }
}
