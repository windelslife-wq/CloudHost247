<?php
/** Host-owned authorization boundary for administrator policy changes. */

namespace CloudHost247\Passkey\Integration;

interface PasskeyPolicyAdminAuthorizationInterface
{
    /**
     * Authorize one operation for an existing administrator and target identity.
     * Implementations must return true only after checking WHMCS permissions.
     */
    public function authorize(WhmcsIdentity $administrator, WhmcsIdentity $target, $operation);
}
