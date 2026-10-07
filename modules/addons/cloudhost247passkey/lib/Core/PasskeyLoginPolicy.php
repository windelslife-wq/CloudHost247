<?php
/** Fail-closed policy gate for the opt-in Phase 4 authentication handoff. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;

class PasskeyLoginPolicy
{
    private $serviceEnabled;
    private $clientPolicy;
    private $adminPolicy;
    private $passwordFallback;

    public function __construct(array $settings)
    {
        $this->serviceEnabled = isset($settings['service_enabled'])
            && in_array($settings['service_enabled'], ['1', 1], true);
        $this->clientPolicy = self::policyValue($settings, 'client_policy');
        $this->adminPolicy = self::policyValue($settings, 'admin_policy');
        $this->passwordFallback = isset($settings['password_fallback'])
            ? (string) $settings['password_fallback']
            : 'allowed';
        if (!in_array($this->passwordFallback, ['allowed', 'disabled'], true)) {
            throw new \InvalidArgumentException('Passkey password-fallback policy is invalid.');
        }
    }

    /** The global service switch and audience-specific policy must both opt in. */
    public function assertLoginAllowed($userType)
    {
        if (!in_array($userType, [IdentityScope::CLIENT, IdentityScope::ADMIN], true)) {
            throw new \RuntimeException('Passkey login audience is not supported.');
        }
        if (!$this->serviceEnabled) {
            throw new \RuntimeException('Passkey authentication is disabled.');
        }
        $policy = $this->policyFor($userType);
        if (!in_array($policy, ['optional', 'required'], true)) {
            throw new \RuntimeException('Passkey login policy is not explicitly enabled for this audience.');
        }
    }

    public function isEnabledFor($userType)
    {
        try {
            $this->assertLoginAllowed($userType);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** This flag never disables the existing password route by itself. */
    public function passwordFallbackAllowed()
    {
        return $this->passwordFallback === 'allowed';
    }

    public function policyFor($userType)
    {
        if ($userType === IdentityScope::CLIENT) {
            return $this->clientPolicy;
        }
        if ($userType === IdentityScope::ADMIN) {
            return $this->adminPolicy;
        }
        throw new \InvalidArgumentException('Unsupported Passkey login audience.');
    }

    private static function policyValue(array $settings, $key)
    {
        if (!array_key_exists($key, $settings)) {
            throw new \InvalidArgumentException('Passkey ' . $key . ' is missing.');
        }
        $value = (string) $settings[$key];
        if (!in_array($value, ['default', 'optional', 'required'], true)) {
            throw new \InvalidArgumentException('Passkey ' . $key . ' is invalid.');
        }
        return $value;
    }
}
