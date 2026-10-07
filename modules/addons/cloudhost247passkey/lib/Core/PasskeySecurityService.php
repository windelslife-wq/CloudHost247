<?php
/** Phase 6 policy, recovery-grant, rate-limit, and audit orchestration. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;

class PasskeySecurityService
{
    private $policies;
    private $rateLimiter;
    private $recoveryGrants;
    private $events;

    public function __construct(
        PasskeyPolicyResolver $policies,
        PasskeyRateLimiter $rateLimiter = null,
        PasskeyRecoveryGrantService $recoveryGrants = null,
        SecurityEventRepository $events = null
    ) {
        $this->policies = $policies;
        $this->rateLimiter = $rateLimiter ?: new PasskeyRateLimiter();
        $this->recoveryGrants = $recoveryGrants ?: new PasskeyRecoveryGrantService();
        $this->events = $events;
    }

    public function effectivePolicy($userType, $userId, $utcNow = null)
    {
        return $this->policies->effectivePolicy($userType, $userId, $utcNow);
    }

    public function assertIdentityAllowed($userType, $userId)
    {
        $this->policies->assertAllowed($userType, $userId);
    }

    /** Consume a named fixed-window bucket before a host ceremony starts. */
    public function consumeRateLimit($action, $principal, $limit, $windowSeconds = PasskeyRateLimiter::DEFAULT_WINDOW_SECONDS, array $audit = [], $nowEpoch = null)
    {
        $decision = $this->rateLimiter->consume($action, $principal, $limit, $windowSeconds, $nowEpoch);
        if (!$decision['allowed'] && $this->events !== null) {
            $this->events->append($this->eventInput(
                'rate_limit.exceeded',
                false,
                $audit,
                'rate_limited',
                ['action_code' => (string) $action]
            ));
        }
        return $decision;
    }

    /**
     * Issue a session-bound recovery capability without exposing its raw value
     * from this service. The host callback is the only delivery boundary.
     */
    public function issueRecoveryGrant($userType, $userId, callable $deliverToken, $challengeId = null, $ttlSeconds = PasskeyRecoveryGrantService::DEFAULT_TTL_SECONDS, array $audit = [])
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $wrappedDelivery = function ($token) use ($userType, $userId, $deliverToken, $challengeId, $audit) {
            if ($this->events !== null) {
                $this->events->append($this->eventInput(
                    'recovery_grant.issued',
                    true,
                    array_merge($audit, ['user_type' => $userType, 'user_id' => $userId]),
                    null,
                    ['action_code' => 'recovery_grant']
                ));
            }
            // The token exists only in this callback chain and is not returned
            // or placed in metadata.
            call_user_func($deliverToken, $token);
        };
        return $this->recoveryGrants->issue($userType, $userId, $wrappedDelivery, $challengeId, $ttlSeconds);
    }

    /** Consume the raw value supplied by the host boundary; it is not returned. */
    public function consumeRecoveryGrant($token, $userType, $userId, $challengeId = null, array $audit = [])
    {
        $capability = $this->recoveryGrants->consume($token, $userType, $userId, $challengeId);
        if ($this->events !== null) {
            $this->events->append($this->eventInput(
                'recovery_grant.consumed',
                true,
                array_merge($audit, ['user_type' => $capability['user_type'], 'user_id' => $capability['user_id']]),
                null,
                ['action_code' => 'recovery_grant']
            ));
        }
        return $capability;
    }

    private function eventInput($eventType, $success, array $audit, $reasonCode, array $metadata)
    {
        $input = [
            'user_type' => $audit['user_type'] ?? IdentityScope::CLIENT,
            'user_id' => $audit['user_id'] ?? null,
            'passkey_id' => $audit['passkey_id'] ?? null,
            'event_type' => $eventType,
            'success' => $success,
            'reason_code' => $reasonCode,
            'ip_address' => $audit['ip_address'] ?? null,
            'user_agent' => $audit['user_agent'] ?? null,
            'metadata' => array_merge($audit['metadata'] ?? [], $metadata),
        ];
        return $input;
    }
}
