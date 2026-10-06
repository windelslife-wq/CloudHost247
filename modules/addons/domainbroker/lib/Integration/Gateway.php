<?php
/**
 * Domain Broker — gateway locator.
 *
 * Resolves to the live WHMCS gateway in production and to whatever has been
 * injected in tests. Deliberately tiny: no service container, no magic.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Integration;

class Gateway
{
    /** @var GatewayInterface|null */
    protected static $instance;

    public static function set(GatewayInterface $gateway)
    {
        self::$instance = $gateway;
    }

    public static function reset()
    {
        self::$instance = null;
    }

    /** @return GatewayInterface */
    public static function get()
    {
        if (self::$instance === null) {
            self::$instance = new WhmcsGateway();
        }
        return self::$instance;
    }
}
