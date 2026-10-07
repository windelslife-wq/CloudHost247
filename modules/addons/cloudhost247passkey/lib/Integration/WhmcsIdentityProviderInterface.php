<?php
/** Host-owned resolver for existing WHMCS identities. */

namespace CloudHost247\Passkey\Integration;

interface WhmcsIdentityProviderInterface
{
    /**
     * Return a currently loginable existing WHMCS identity, or null when the
     * referenced account is missing, closed, disabled, or otherwise ineligible.
     */
    public function resolve($userType, $userId);
}
