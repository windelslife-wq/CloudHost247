<?php
/** Host-owned boundary for normal WHMCS session and 2FA transitions. */

namespace CloudHost247\Passkey\Integration;

interface WhmcsAuthBridgeInterface
{
    /**
     * Complete the existing WHMCS authentication transition for this identity.
     * Implementations must use the supported WHMCS session/2FA mechanism and
     * must never write a synthetic $_SESSION login or return a session secret.
     */
    public function handoff(WhmcsIdentity $identity, PasskeyLoginContext $context);
}
