<?php
/** Authentication ceremony boundary: options issuance plus library-backed verification. */

namespace CloudHost247\Passkey\Core;

interface PasskeyAuthenticationCeremonyInterface extends PasskeyAssertionVerifierInterface
{
    /**
     * Issue WebAuthn request options for one identity (or discoverable login
     * within a scope when $userId is null). No session is created.
     */
    public function beginAuthentication(WebAuthnConfig $config, $userType, $userId = null);
}
