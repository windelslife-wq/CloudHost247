<?php
/**
 * Holds the active host-gateway implementation. WHMCS entry points bind the
 * live WhmcsGateway; tests bind a FakeGateway. Services ask here, never
 * construct a gateway themselves.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

use Chs\Providers\Whmcs\GatewayInterface;
use Chs\Providers\Whmcs\WhmcsGateway;

class Platform
{
    /** @var GatewayInterface|null */
    private static $gateway;

    public static function setGateway(GatewayInterface $gateway)
    {
        self::$gateway = $gateway;
    }

    public static function reset()
    {
        self::$gateway = null;
    }

    /** @return GatewayInterface */
    public static function gateway()
    {
        if (self::$gateway === null) {
            self::$gateway = new WhmcsGateway();
        }
        return self::$gateway;
    }

    /** True when a live (non-recording) host gateway is in use. */
    public static function isLive()
    {
        return self::$gateway === null || self::$gateway instanceof WhmcsGateway;
    }
}
