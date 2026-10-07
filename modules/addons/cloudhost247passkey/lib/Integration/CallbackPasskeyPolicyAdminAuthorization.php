<?php
/** Explicit callback adapter for the host's administrator authorization policy. */

namespace CloudHost247\Passkey\Integration;

class CallbackPasskeyPolicyAdminAuthorization implements PasskeyPolicyAdminAuthorizationInterface
{
    private $authorizer;

    /**
     * The callback receives only existing identity references and an operation
     * code; it never receives passwords, sessions, tokens, or request bodies.
     */
    public function __construct(callable $authorizer)
    {
        $this->authorizer = $authorizer;
    }

    public function authorize(WhmcsIdentity $administrator, WhmcsIdentity $target, $operation)
    {
        $authorized = call_user_func($this->authorizer, $administrator, $target, (string) $operation);
        if ($authorized !== true) {
            throw new \RuntimeException('WHMCS administrator is not authorized for this Passkey policy operation.');
        }
        return true;
    }
}
