<?php
/** Optional external identity link metadata; email is not an identity key. */

namespace CloudHost247\Passkey\Model;

class ExternalIdentityRecord
{
    private $row;

    public function __construct(array $row)
    {
        ModelValidation::rejectKeys($row, ['access_token', 'refresh_token', 'client_secret', 'id_token']);
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null);
        $provider = ModelValidation::text($row['provider'] ?? '', 'provider', 40);
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $provider)) {
            throw new \InvalidArgumentException('Invalid external identity provider.');
        }
        $tenantId = ModelValidation::text($row['tenant_id'] ?? '', 'tenant_id', 191);
        $subjectId = ModelValidation::text($row['subject_id'] ?? '', 'subject_id', 255);
        $expectedHash = self::identityHash($provider, $tenantId, $subjectId);
        $providedHash = ModelValidation::hash($row['identity_hash'] ?? '', 'identity_hash');
        if (!hash_equals($expectedHash, $providedHash)) {
            throw new \InvalidArgumentException('External identity hash does not match its provider, tenant, and subject.');
        }
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'provider' => $provider,
            'tenant_id' => $tenantId,
            'subject_id' => $subjectId,
            'identity_hash' => $providedHash,
            'user_type' => $userType,
            'user_id' => $userId,
            'linked_at' => ModelValidation::timestamp($row['linked_at'] ?? null, 'linked_at'),
            'last_authenticated_at' => ModelValidation::timestamp($row['last_authenticated_at'] ?? null, 'last_authenticated_at', true),
            'revoked_at' => ModelValidation::timestamp($row['revoked_at'] ?? null, 'revoked_at', true),
        ];
    }

    public static function identityHash($provider, $tenantId, $subjectId)
    {
        return hash('sha256', (string) $provider . "\0" . (string) $tenantId . "\0" . (string) $subjectId);
    }

    public function isActive()
    {
        return $this->row['revoked_at'] === null;
    }

    public function toArray()
    {
        return $this->row;
    }
}
