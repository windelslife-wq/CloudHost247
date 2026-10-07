<?php
/** Authenticated enrollment and lifecycle management for existing WHMCS identities. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\PasskeyRegistrationContext;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\IdentityScope;
use RuntimeException;

class PasskeyCredentialManagementService
{
    private $ceremonies;
    private $identityProvider;
    private $policy;
    private $policyResolver;
    private $credentials;

    public function __construct(
        PasskeyRegistrationCeremonyInterface $ceremonies,
        WhmcsIdentityProviderInterface $identityProvider,
        PasskeyLoginPolicy $policy,
        CredentialManagementRepository $credentials = null,
        PasskeyPolicyResolver $policyResolver = null
    ) {
        $this->ceremonies = $ceremonies;
        $this->identityProvider = $identityProvider;
        $this->policy = $policy;
        $this->credentials = $credentials ?: new CredentialManagementRepository();
        $this->policyResolver = $policyResolver ?: new PasskeyPolicyResolver($policy);
    }

    /** Begin enrollment only from an authenticated, current WHMCS identity. */
    public function beginRegistration(WebAuthnConfig $config, WhmcsIdentity $identity, $userName, $displayName)
    {
        $this->assertAuthenticatedIdentity($identity);
        $this->assertCapacity($identity);
        return $this->ceremonies->beginRegistration(
            $config,
            $identity->userType(),
            $identity->userId(),
            $userName,
            $displayName
        );
    }

    /** Finish enrollment while retaining the active-session and identity scope checks. */
    public function finishRegistration(
        WebAuthnConfig $config,
        WhmcsIdentity $identity,
        $credentialResponseJson,
        $deviceName,
        PasskeyRegistrationContext $context
    ) {
        $this->assertAuthenticatedIdentity($identity);
        $this->assertCapacity($identity);
        $deviceName = CredentialManagementRepository::validateDeviceName($deviceName);
        if (!is_string($credentialResponseJson) || $credentialResponseJson === '' || strlen($credentialResponseJson) > 262144) {
            throw new \InvalidArgumentException('Passkey registration response is missing or too large.');
        }
        return $this->ceremonies->finishRegistration(
            $config,
            $identity->userType(),
            $identity->userId(),
            $credentialResponseJson,
            $deviceName,
            $context->ipAddress(),
            $context->userAgent()
        );
    }

    /** Return only masked public summaries owned by the current identity. */
    public function listCredentials(WhmcsIdentity $identity, $includeInactive = true)
    {
        $this->assertAuthenticatedIdentity($identity);
        return $this->credentials->listForIdentity(
            $identity->userType(),
            $identity->userId(),
            (bool) $includeInactive
        );
    }

    public function renameCredential(WhmcsIdentity $identity, $credentialRecordId, $deviceName)
    {
        $this->assertAuthenticatedIdentity($identity);
        return $this->credentials->rename(
            $identity->userType(),
            $identity->userId(),
            $credentialRecordId,
            $deviceName
        );
    }

    public function revokeCredential(WhmcsIdentity $identity, $credentialRecordId)
    {
        $this->assertAuthenticatedIdentity($identity);
        $this->assertNotRemovingLastCredential($identity);
        return $this->credentials->revoke(
            $identity->userType(),
            $identity->userId(),
            $credentialRecordId
        );
    }

    public function disableCredential(WhmcsIdentity $identity, $credentialRecordId)
    {
        $this->assertAuthenticatedIdentity($identity);
        $this->assertNotRemovingLastCredential($identity);
        return $this->credentials->setDisabled(
            $identity->userType(),
            $identity->userId(),
            $credentialRecordId,
            true
        );
    }

    public function enableCredential(WhmcsIdentity $identity, $credentialRecordId)
    {
        $this->assertAuthenticatedIdentity($identity);
        return $this->credentials->setDisabled(
            $identity->userType(),
            $identity->userId(),
            $credentialRecordId,
            false
        );
    }

    private function assertAuthenticatedIdentity(WhmcsIdentity $identity)
    {
        if (!$identity instanceof WhmcsIdentity) {
            throw new \InvalidArgumentException('An existing WHMCS identity is required for Passkey management.');
        }
        // This is the same binding used by WebAuthn challenges. Management
        // operations never run without the existing WHMCS PHP session.
        SessionBinding::currentHash();
        $this->policyResolver->assertAllowed($identity->userType(), $identity->userId());
        $resolved = $this->identityProvider->resolve($identity->userType(), $identity->userId());
        if (!$resolved instanceof WhmcsIdentity
            || $resolved->userType() !== $identity->userType()
            || $resolved->userId() !== $identity->userId()) {
            throw new RuntimeException('The WHMCS identity is missing, disabled, or outside the current session scope.');
        }
    }

    private function assertCapacity(WhmcsIdentity $identity)
    {
        if ($this->credentials->countActive($identity->userType(), $identity->userId())
            >= $this->policy->maxCredentialsFor($identity->userType())) {
            throw new RuntimeException('The maximum number of active Passkeys for this WHMCS identity has been reached.');
        }
    }

    private function assertNotRemovingLastCredential(WhmcsIdentity $identity)
    {
        if (!$this->policy->passwordFallbackAllowed()
            && $this->credentials->countActive($identity->userType(), $identity->userId()) <= 1) {
            throw new RuntimeException('The last active Passkey cannot be disabled or revoked while password fallback is disabled.');
        }
    }
}
