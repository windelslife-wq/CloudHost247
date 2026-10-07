<?php
/** Resolves global and expiring per-identity Passkey policy without changing WHMCS auth. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\UserPolicyRecord;

class PasskeyPolicyResolver
{
    private $globalPolicy;
    private $identityPolicies;

    public function __construct(PasskeyLoginPolicy $globalPolicy, IdentityPolicyRepository $identityPolicies = null)
    {
        $this->globalPolicy = $globalPolicy;
        // Existing addon-owned policy rows are part of the normal gate; a
        // caller may still inject a repository double for isolated tests.
        $this->identityPolicies = $identityPolicies ?: new IdentityPolicyRepository();
    }

    /**
     * Return the effective policy for an existing local identity.
     *
     * A missing or expired override resolves to the audience's global policy.
     * A live temporary exemption remains an explicit deny state and is never
     * silently treated as optional or required.
     */
    public function effectivePolicy($userType, $userId, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        if ($utcNow === null) {
            $utcNow = gmdate('Y-m-d H:i:s');
        }
        if (!is_string($utcNow)) {
            throw new \InvalidArgumentException('Policy evaluation time must be a UTC SQL timestamp.');
        }

        $override = $this->identityPolicies === null
            ? UserPolicyRecord::DEFAULT_POLICY
            : $this->identityPolicies->effectivePolicy($userType, $userId, $utcNow);
        if ($override === UserPolicyRecord::DEFAULT_POLICY) {
            return $this->globalPolicy->policyFor($userType);
        }
        return $override;
    }

    /** Fail closed for both the global audience switch and a live override. */
    public function assertAllowed($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $this->globalPolicy->assertServiceEnabled();
        $effective = $this->effectivePolicy($userType, $userId);
        if ($effective === UserPolicyRecord::TEMPORARILY_DISABLED) {
            throw new \RuntimeException('Passkey authentication is temporarily disabled for this WHMCS identity.');
        }
        if (!in_array($effective, [UserPolicyRecord::OPTIONAL, UserPolicyRecord::REQUIRED], true)) {
            throw new \RuntimeException('Passkey login policy is not explicitly enabled for this identity.');
        }
    }

    public function globalPolicy()
    {
        return $this->globalPolicy;
    }
}
