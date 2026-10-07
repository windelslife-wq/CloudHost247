<?php
/**
 * Phase 4 Passkey-to-WHMCS authentication coordinator.
 *
 * WebAuthn verification, identity eligibility, and WHMCS session/2FA handoff
 * remain separate boundaries. This class never writes $_SESSION and never
 * creates, copies, or replaces a WHMCS account.
 */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\PasskeyLoginContext;
use CloudHost247\Passkey\Integration\PasskeyLoginResult;
use CloudHost247\Passkey\Integration\WhmcsAuthBridgeInterface;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\IdentityScope;
use RuntimeException;

class PasskeyLoginCoordinator
{
    private $verifier;
    private $identityProvider;
    private $authBridge;
    private $policy;
    private $policyResolver;

    public function __construct(
        PasskeyAssertionVerifierInterface $verifier,
        WhmcsIdentityProviderInterface $identityProvider,
        WhmcsAuthBridgeInterface $authBridge,
        PasskeyLoginPolicy $policy,
        PasskeyPolicyResolver $policyResolver = null
    ) {
        $this->verifier = $verifier;
        $this->identityProvider = $identityProvider;
        $this->authBridge = $authBridge;
        $this->policy = $policy;
        $this->policyResolver = $policyResolver ?: new PasskeyPolicyResolver($policy);
    }

    /**
     * Verify one assertion and hand the existing identity to WHMCS's own auth
     * transition. The response JSON is never passed beyond the verifier.
     */
    public function authenticate(
        WebAuthnConfig $config,
        $requestedUserType,
        $requestedUserId,
        $credentialResponseJson,
        array $context
    ) {
        list($requestedUserType, $requestedUserId) = IdentityScope::validate(
            $requestedUserType,
            $requestedUserId,
            true
        );
        if ($requestedUserId === null) {
            // Discoverable credentials cannot be policy-resolved until the
            // verifier returns their existing WHMCS identity.
            $this->policy->assertLoginAllowed($requestedUserType);
        } else {
            $this->policyResolver->assertAllowed($requestedUserType, $requestedUserId);
        }
        $loginContext = PasskeyLoginContext::fromArray($context);
        self::assertContextAudience($loginContext, $requestedUserType);

        // A known-identity ceremony is checked against current WHMCS account
        // status before the assertion is accepted, without exposing profile data.
        if ($requestedUserId !== null && $this->identityProvider->resolve($requestedUserType, $requestedUserId) === null) {
            throw new RuntimeException('The requested WHMCS identity is not loginable.');
        }

        $verified = $this->verifier->finishAuthentication(
            $config,
            $requestedUserType,
            $requestedUserId,
            $credentialResponseJson
        );
        if (!is_array($verified) || !isset($verified['user_type'], $verified['user_id'])) {
            throw new RuntimeException('WebAuthn verification returned an incomplete identity.');
        }
        list($verifiedType, $verifiedId) = IdentityScope::validate($verified['user_type'], $verified['user_id']);
        if ($verifiedType !== $requestedUserType
            || ($requestedUserId !== null && $verifiedId !== $requestedUserId)) {
            throw new RuntimeException('Verified Passkey identity is outside the requested audience.');
        }
        $this->policyResolver->assertAllowed($verifiedType, $verifiedId);

        $identity = $this->identityProvider->resolve($verifiedType, $verifiedId);
        if (!$identity instanceof WhmcsIdentity) {
            throw new RuntimeException('The verified WHMCS identity is not loginable.');
        }
        $handoff = $this->authBridge->handoff($identity, $loginContext);
        if (!$handoff instanceof \CloudHost247\Passkey\Integration\WhmcsAuthHandoff) {
            throw new RuntimeException('WHMCS authentication handoff returned an invalid result.');
        }
        return new PasskeyLoginResult($identity, $handoff);
    }

    private static function assertContextAudience(PasskeyLoginContext $context, $userType)
    {
        $expectedSource = $userType === IdentityScope::ADMIN ? 'admin_login' : 'client_login';
        if ($context->source() !== $expectedSource) {
            throw new RuntimeException('Passkey login context is bound to a different WHMCS audience.');
        }
    }
}
