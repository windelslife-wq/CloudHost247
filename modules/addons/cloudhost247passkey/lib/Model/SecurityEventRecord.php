<?php
/** Append-only, redacted authentication/security event model. */

namespace CloudHost247\Passkey\Model;

class SecurityEventRecord
{
    const TYPES = [
        'registration.started', 'registration.succeeded', 'registration.failed',
        'authentication.succeeded', 'authentication.failed', 'credential.renamed',
        'credential.revoked', 'credential.disabled', 'credential.enabled',
        'password_reset.succeeded', 'action_confirmation.succeeded',
        'policy.changed', 'credential.admin_managed', 'rate_limit.exceeded',
        'recovery_grant.issued', 'recovery_grant.consumed',
        'entra.linked', 'entra.unlinked',
    ];

    private $row;

    public function __construct(array $row)
    {
        ModelValidation::rejectKeys($row, [
            'private_key', 'secret_key', 'biometric_data', 'password', 'challenge',
            'signature', 'assertion', 'raw_response', 'session_id',
        ]);
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null, true);
        $eventType = ModelValidation::text($row['event_type'] ?? '', 'event_type', 64);
        if (!in_array($eventType, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported Passkey event type.');
        }
        $reasonCode = $row['reason_code'] ?? null;
        if ($reasonCode !== null && $reasonCode !== '') {
            $reasonCode = ModelValidation::text($reasonCode, 'reason_code', 64);
            if (!preg_match('/^[a-z0-9_.-]+$/', $reasonCode)) {
                throw new \InvalidArgumentException('Invalid Passkey event reason code.');
            }
        } else {
            $reasonCode = null;
        }
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'user_type' => $userType,
            'user_id' => $userId,
            'passkey_id' => ModelValidation::positiveInt($row['passkey_id'] ?? null, 'passkey_id', true),
            'event_type' => $eventType,
            'success' => ModelValidation::boolean($row['success'] ?? 0, 'success'),
            'reason_code' => $reasonCode,
            'ip_address' => ModelValidation::ipAddress($row['ip_address'] ?? null, 'ip_address', true),
            'user_agent' => isset($row['user_agent']) ? ModelValidation::text($row['user_agent'], 'user_agent', 512, true) : null,
            'metadata_json' => SafeMetadata::eventJson($row['metadata_json'] ?? []),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
        ];
    }

    public function metadata()
    {
        return SafeMetadata::eventArray($this->row['metadata_json']);
    }

    public function toArray()
    {
        $row = $this->row;
        $row['metadata'] = $this->metadata();
        unset($row['metadata_json']);
        return $row;
    }
}
