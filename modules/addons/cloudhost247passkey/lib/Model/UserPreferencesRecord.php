<?php
/** Per-identity preferences for optional security notifications. */

namespace CloudHost247\Passkey\Model;

class UserPreferencesRecord
{
    private $row;

    public function __construct(array $row)
    {
        list($userType, $userId) = IdentityScope::validate($row['user_type'] ?? '', $row['user_id'] ?? null);
        $this->row = [
            'id' => ModelValidation::positiveInt($row['id'] ?? null, 'id'),
            'user_type' => $userType,
            'user_id' => $userId,
            'login_notification_enabled' => ModelValidation::boolean($row['login_notification_enabled'] ?? 0, 'login_notification_enabled'),
            'security_event_notification_enabled' => ModelValidation::boolean($row['security_event_notification_enabled'] ?? 0, 'security_event_notification_enabled'),
            'created_at' => ModelValidation::timestamp($row['created_at'] ?? null, 'created_at'),
            'updated_at' => ModelValidation::timestamp($row['updated_at'] ?? null, 'updated_at'),
        ];
    }

    public function toArray()
    {
        return $this->row;
    }
}
