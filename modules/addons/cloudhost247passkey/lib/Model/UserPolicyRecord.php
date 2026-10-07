<?php
/** Per-WHMCS-identity policy override. Temporary exemptions always expire. */

namespace CloudHost247\Passkey\Model;

class UserPolicyRecord
{
    const DEFAULT_POLICY = 'default';
    const OPTIONAL = 'optional';
    const REQUIRED = 'required';
    const TEMPORARILY_DISABLED = 'temporarily_disabled';

    private $row;

    public function __construct(array $row)
    {
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null);
        $policy = ModelValidation::text($row['policy'] ?? self::DEFAULT_POLICY, 'policy', 32);
        if (!in_array($policy, [self::DEFAULT_POLICY, self::OPTIONAL, self::REQUIRED, self::TEMPORARILY_DISABLED], true)) {
            throw new \InvalidArgumentException('Unsupported per-user Passkey policy.');
        }
        $disabledUntil = ModelValidation::timestamp($row['temporary_disabled_until'] ?? null, 'temporary_disabled_until', true);
        if ($policy === self::TEMPORARILY_DISABLED && $disabledUntil === null) {
            throw new \InvalidArgumentException('A temporary Passkey exemption must have an expiry.');
        }
        if ($policy !== self::TEMPORARILY_DISABLED && $disabledUntil !== null) {
            throw new \InvalidArgumentException('Only a temporary exemption may set an expiry.');
        }
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'user_type' => $userType,
            'user_id' => $userId,
            'policy' => $policy,
            'temporary_disabled_until' => $disabledUntil,
            'reason_code' => isset($row['reason_code']) ? ModelValidation::text($row['reason_code'], 'reason_code', 96) : null,
            'updated_by_admin_id' => ModelValidation::positiveInt($row['updated_by_admin_id'] ?? null, 'updated_by_admin_id', true),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
            'updated_at' => ModelValidation::timestamp($row['updated_at'] ?? null, 'updated_at'),
        ];
    }

    public function effectivePolicy($utcNow)
    {
        $utcNow = ModelValidation::timestamp($utcNow, 'utcNow');
        if ($this->row['policy'] === self::TEMPORARILY_DISABLED && $this->row['temporary_disabled_until'] <= $utcNow) {
            return self::DEFAULT_POLICY;
        }
        return $this->row['policy'];
    }

    public function toArray()
    {
        return $this->row;
    }
}
