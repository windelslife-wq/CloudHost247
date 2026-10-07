<?php
/** Short-lived, single-use password-reset authorization metadata. */

namespace CloudHost247\Passkey\Model;

class ResetGrantRecord
{
    private $row;

    public function __construct(array $row)
    {
        ModelValidation::rejectKeys($row, ['token', 'reset_token', 'session_id', 'password', 'private_key']);
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null);
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'token_hash' => ModelValidation::hash($row['token_hash'] ?? '', 'token_hash'),
            'user_type' => $userType,
            'user_id' => $userId,
            'session_binding_hash' => ModelValidation::hash($row['session_binding_hash'] ?? '', 'session_binding_hash'),
            'challenge_id' => ModelValidation::positiveInt($row['challenge_id'] ?? null, 'challenge_id', true),
            'expires_at' => ModelValidation::timestamp($row['expires_at'] ?? null, 'expires_at'),
            'consumed_at' => ModelValidation::timestamp($row['consumed_at'] ?? null, 'consumed_at', true),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
        ];
    }

    public function isUsableAt($utcNow)
    {
        $utcNow = ModelValidation::timestamp($utcNow, 'utcNow');
        return $this->row['consumed_at'] === null && $this->row['expires_at'] > $utcNow;
    }

    public function toArray()
    {
        return $this->row;
    }
}
