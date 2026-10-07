<?php
/** The WebAuthn assertion boundary used by the WHMCS login coordinator. */

namespace CloudHost247\Passkey\Core;

interface PasskeyAssertionVerifierInterface
{
    /**
     * Verify the browser assertion through the selected WebAuthn implementation
     * and return only the existing local identity reference.
     */
    public function finishAuthentication(WebAuthnConfig $config, $userType, $userId, $credentialResponseJson);
}
