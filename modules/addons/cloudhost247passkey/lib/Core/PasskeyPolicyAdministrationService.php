<?php
/** Phase 10 administrator policy-management orchestration without a UI or endpoint. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Integration\PasskeyPolicyAdminAuthorizationInterface;
use CloudHost247\Passkey\Integration\WhmcsIdentity;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;
use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\ModelValidation;
use CloudHost247\Passkey\Model\UserPolicyRecord;

class PasskeyPolicyAdministrationService
{
    const SET_OPERATION = 'policy.set';
    const CLEAR_OPERATION = 'policy.clear';
    const MAX_TEMPORARY_DISABLED_SECONDS = 7776000;

    private $identityProvider;
    private $authorization;
    private $policies;
    private $events;

    public function __construct(
        WhmcsIdentityProviderInterface $identityProvider,
        PasskeyPolicyAdminAuthorizationInterface $authorization,
        IdentityPolicyAdministrationRepository $policies = null,
        SecurityEventRepository $events = null
    ) {
        $this->identityProvider = $identityProvider;
        $this->authorization = $authorization;
        $this->policies = $policies ?: new IdentityPolicyAdministrationRepository();
        $this->events = $events ?: new SecurityEventRepository();
    }

    /** Set an optional, required, or explicitly expiring temporary policy. */
    public function setPolicy(
        WhmcsIdentity $administrator,
        WhmcsIdentity $target,
        $policy,
        $temporaryDisabledUntil = null,
        $reasonCode = 'administrator_change',
        array $audit = []
    ) {
        return $this->changePolicy(
            $administrator,
            $target,
            $policy,
            $temporaryDisabledUntil,
            $reasonCode,
            self::SET_OPERATION,
            $audit
        );
    }

    /** Restore the target to the audience's global policy. */
    public function clearPolicy(
        WhmcsIdentity $administrator,
        WhmcsIdentity $target,
        $reasonCode = 'administrator_clear',
        array $audit = []
    ) {
        return $this->changePolicy(
            $administrator,
            $target,
            UserPolicyRecord::DEFAULT_POLICY,
            null,
            $reasonCode,
            self::CLEAR_OPERATION,
            $audit
        );
    }

    private function changePolicy(
        WhmcsIdentity $administrator,
        WhmcsIdentity $target,
        $policy,
        $temporaryDisabledUntil,
        $reasonCode,
        $operation,
        array $audit
    ) {
        $policy = $this->validatePolicy($policy);
        $temporaryDisabledUntil = $this->validateExpiry($policy, $temporaryDisabledUntil);
        $reasonCode = $this->validateReason($reasonCode);
        ModelValidation::allowKeys($audit, ['ip_address', 'user_agent', 'metadata'], 'policy audit');
        if (isset($audit['metadata']) && !is_array($audit['metadata'])) {
            throw new \InvalidArgumentException('Policy audit metadata must be an array.');
        }
        $this->assertRequestScope($administrator, $target, $operation);
        $nowEpoch = time();
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);

        return Db::transaction(function () use (
            $administrator,
            $target,
            $policy,
            $temporaryDisabledUntil,
            $reasonCode,
            $operation,
            $audit,
            $now
        ) {
            $record = $this->policies->upsert(
                $target->userType(),
                $target->userId(),
                $policy,
                $temporaryDisabledUntil,
                $reasonCode,
                $administrator->userId(),
                $now
            );
            $eventId = $this->events->append([
                'user_type' => $target->userType(),
                'user_id' => $target->userId(),
                'event_type' => 'policy.changed',
                'success' => 1,
                'reason_code' => $reasonCode,
                'ip_address' => $audit['ip_address'] ?? null,
                'user_agent' => $audit['user_agent'] ?? null,
                'metadata' => array_merge($audit['metadata'] ?? [], [
                    'action_code' => $operation,
                    'policy_source' => 'administrator',
                ]),
            ]);
            $data = $record->toArray();
            return [
                'status' => 'updated',
                'policy_id' => $data['id'],
                'user_type' => $data['user_type'],
                'user_id' => $data['user_id'],
                'policy' => $data['policy'],
                'temporary_disabled_until' => $data['temporary_disabled_until'],
                'reason_code' => $data['reason_code'],
                'updated_by_admin_id' => $data['updated_by_admin_id'],
                'updated_at' => $data['updated_at'],
                'event_id' => $eventId,
            ];
        });
    }

    private function assertRequestScope(WhmcsIdentity $administrator, WhmcsIdentity $target, $operation)
    {
        if ($administrator->userType() !== IdentityScope::ADMIN) {
            throw new \RuntimeException('Only an existing WHMCS administrator may change Passkey policy.');
        }
        if (!in_array($target->userType(), [IdentityScope::CLIENT, IdentityScope::ADMIN], true)) {
            throw new \InvalidArgumentException('Passkey policy administration does not support this identity scope.');
        }
        SessionBinding::currentHash();
        $resolvedAdministrator = $this->identityProvider->resolve(
            $administrator->userType(),
            $administrator->userId()
        );
        $resolvedTarget = $this->identityProvider->resolve($target->userType(), $target->userId());
        if (!$resolvedAdministrator instanceof WhmcsIdentity
            || $resolvedAdministrator->userType() !== $administrator->userType()
            || $resolvedAdministrator->userId() !== $administrator->userId()) {
            throw new \RuntimeException('The acting WHMCS administrator is missing or disabled.');
        }
        if (!$resolvedTarget instanceof WhmcsIdentity
            || $resolvedTarget->userType() !== $target->userType()
            || $resolvedTarget->userId() !== $target->userId()) {
            throw new \RuntimeException('The target WHMCS identity is missing or disabled.');
        }
        $this->authorization->authorize($resolvedAdministrator, $resolvedTarget, $operation);
    }

    private function validatePolicy($policy)
    {
        $policy = (string) $policy;
        if (!in_array($policy, [
            UserPolicyRecord::DEFAULT_POLICY,
            UserPolicyRecord::OPTIONAL,
            UserPolicyRecord::REQUIRED,
            UserPolicyRecord::TEMPORARILY_DISABLED,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey administrator policy.');
        }
        return $policy;
    }

    private function validateExpiry($policy, $temporaryDisabledUntil)
    {
        if ($policy !== UserPolicyRecord::TEMPORARILY_DISABLED) {
            if ($temporaryDisabledUntil !== null) {
                throw new \InvalidArgumentException('Only a temporary Passkey policy may have an expiry.');
            }
            return null;
        }
        if ($temporaryDisabledUntil === null) {
            throw new \InvalidArgumentException('A temporary Passkey policy requires an expiry.');
        }
        $temporaryDisabledUntil = ModelValidation::timestamp(
            $temporaryDisabledUntil,
            'temporary_disabled_until'
        );
        $expiry = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $temporaryDisabledUntil,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$expiry || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Temporary Passkey policy expiry is invalid.');
        }
        $seconds = $expiry->getTimestamp() - time();
        if ($seconds < 1 || $seconds > self::MAX_TEMPORARY_DISABLED_SECONDS) {
            throw new \InvalidArgumentException('Temporary Passkey policy expiry is outside the supported range.');
        }
        return $temporaryDisabledUntil;
    }

    private function validateReason($reasonCode)
    {
        $reasonCode = ModelValidation::text($reasonCode, 'reason_code', 64);
        if (!preg_match('/^[a-z0-9_.-]+$/', $reasonCode)) {
            throw new \InvalidArgumentException('Passkey policy reason code is invalid.');
        }
        return $reasonCode;
    }
}
