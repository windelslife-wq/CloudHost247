<?php
/** One-use ceremony challenge metadata; raw challenge/session values are not stored. */

namespace CloudHost247\Passkey\Model;

class ChallengeRecord
{
    const REGISTRATION = 'registration';
    const AUTHENTICATION = 'authentication';
    const ACTION_CONFIRMATION = 'action_confirmation';
    const PASSWORD_RESET = 'password_reset';
    const ENTRA_LINK = 'entra_link';

    private $row;

    public function __construct(array $row)
    {
        ModelValidation::rejectKeys($row, ['challenge', 'raw_challenge', 'session_id', 'assertion', 'signature']);
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null, true);
        $type = ModelValidation::text($row['challenge_type'] ?? '', 'challenge_type', 32);
        if (!in_array($type, [self::REGISTRATION, self::AUTHENTICATION, self::ACTION_CONFIRMATION, self::PASSWORD_RESET, self::ENTRA_LINK], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey challenge type.');
        }
        $actionCode = $row['action_code'] ?? null;
        if ($type === self::ACTION_CONFIRMATION) {
            $actionCode = ModelValidation::text($actionCode, 'action_code', 128);
        } elseif ($actionCode !== null && $actionCode !== '') {
            $actionCode = ModelValidation::text($actionCode, 'action_code', 128);
        } else {
            $actionCode = null;
        }
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'challenge_hash' => ModelValidation::hash($row['challenge_hash'] ?? '', 'challenge_hash'),
            'user_type' => $userType,
            'user_id' => $userId,
            'challenge_type' => $type,
            'action_code' => $actionCode,
            'session_binding_hash' => ModelValidation::hash($row['session_binding_hash'] ?? '', 'session_binding_hash'),
            'rp_id' => ModelValidation::text($row['rp_id'] ?? '', 'rp_id', 253),
            'origin' => ModelValidation::text($row['origin'] ?? '', 'origin', 512),
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
