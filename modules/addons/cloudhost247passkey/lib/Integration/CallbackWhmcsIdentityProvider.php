<?php
/** Explicit callback adapter for the host's existing WHMCS identity records. */

namespace CloudHost247\Passkey\Integration;

use CloudHost247\Passkey\Model\IdentityScope;

class CallbackWhmcsIdentityProvider implements WhmcsIdentityProviderInterface
{
    private $resolver;

    /**
     * The callback must perform the normal WHMCS status/authorization lookup.
     * It receives only a scope and scalar ID and may return a WhmcsIdentity,
     * an equivalent array, or null. No credential or password data is accepted.
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $resolved = call_user_func($this->resolver, $userType, $userId);
        if ($resolved === null) {
            return null;
        }
        if ($resolved instanceof WhmcsIdentity) {
            if ($resolved->userType() !== $userType || $resolved->userId() !== $userId) {
                throw new \RuntimeException('WHMCS identity resolver returned a different identity scope.');
            }
            return $resolved;
        }
        if (!is_array($resolved)
            || !array_key_exists('user_type', $resolved)
            || !array_key_exists('user_id', $resolved)
            || !array_key_exists('loginable', $resolved)
            || $resolved['loginable'] !== true) {
            throw new \RuntimeException('WHMCS identity resolver did not return an explicitly loginable identity.');
        }
        list($resolvedType, $resolvedId) = IdentityScope::validate($resolved['user_type'], $resolved['user_id']);
        if ($resolvedType !== $userType || $resolvedId !== $userId) {
            throw new \RuntimeException('WHMCS identity resolver returned a different identity scope.');
        }
        return new WhmcsIdentity($resolvedType, $resolvedId);
    }
}
