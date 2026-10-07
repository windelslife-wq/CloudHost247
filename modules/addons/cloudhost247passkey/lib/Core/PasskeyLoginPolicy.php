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
    private $maxCredentialsClient;
    private $maxCredentialsAdmin;

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
        $this->maxCredentialsClient = self::maxCredentials($settings, 'max_credentials_client');
        $this->maxCredentialsAdmin = self::maxCredentials($settings, 'max_credentials_admin');
    }

    /** The global service switch must always be enabled. */
    public function assertServiceEnabled()
    {
        if (!$this->serviceEnabled) {
            throw new \RuntimeException('Passkey authentication is disabled.');
        }
    }

    /** The global service switch and audience-specific policy must both opt in. */
    public function assertLoginAllowed($userType)
    {
        if (!in_array($userType, [IdentityScope::CLIENT, IdentityScope::ADMIN], true)) {
            throw new \RuntimeException('Passkey login audience is not supported.');
        }
        $this->assertServiceEnabled();
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

    public function maxCredentialsFor($userType)
    {
        if ($userType === IdentityScope::CLIENT) {
            return $this->maxCredentialsClient;
        }
        if ($userType === IdentityScope::ADMIN) {
            return $this->maxCredentialsAdmin;
        }
        throw new \InvalidArgumentException('Unsupported Passkey credential audience.');
    }

    private static function maxCredentials(array $settings, $key)
    {
        $value = array_key_exists($key, $settings) ? $settings[$key] : '5';
        if ($value === 'unlimited') {
            // Bounded practical equivalent of unlimited; prevents abuse while
            // satisfying deployments that do not want a low fixed ceiling.
            return 1000;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 1 || (int) $number > 50) {
            throw new \InvalidArgumentException('Passkey ' . $key . ' must be between 1 and 50, or unlimited.');
        }
        return (int) $number;
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
