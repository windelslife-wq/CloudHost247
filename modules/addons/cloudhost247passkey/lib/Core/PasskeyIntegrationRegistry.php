<?php
/** Per-request registry for explicitly supplied WHMCS integration adapters. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\WhmcsAuthBridgeInterface;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;

class PasskeyIntegrationRegistry
{
    private static $identityProvider;
    private static $authBridge;

    public static function configure(
        WhmcsIdentityProviderInterface $identityProvider,
        WhmcsAuthBridgeInterface $authBridge
    ) {
        if (self::$identityProvider !== null || self::$authBridge !== null) {
            throw new \RuntimeException('Passkey WHMCS integration is already configured for this request.');
        }
        self::$identityProvider = $identityProvider;
        self::$authBridge = $authBridge;
    }

    public static function isConfigured()
    {
        return self::$identityProvider instanceof WhmcsIdentityProviderInterface
            && self::$authBridge instanceof WhmcsAuthBridgeInterface;
    }

    public static function coordinator(PasskeyAssertionVerifierInterface $verifier, PasskeyLoginPolicy $policy)
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Passkey WHMCS authentication integration is not configured.');
        }
        return new PasskeyLoginCoordinator($verifier, self::$identityProvider, self::$authBridge, $policy);
    }

    /** Test/request cleanup only; no persisted authentication state is removed. */
    public static function reset()
    {
        self::$identityProvider = null;
        self::$authBridge = null;
    }
}
