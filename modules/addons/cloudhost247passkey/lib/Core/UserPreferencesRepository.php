<?php
/** Scoped repository for opt-in security notification preferences. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\IdentityScope;
use CloudHost247\Passkey\Model\ModelValidation;
use CloudHost247\Passkey\Model\UserPreferencesRecord;

class UserPreferencesRepository
{
    public function find($userType, $userId)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        $row = Db::firstQuery(
            'SELECT * FROM `' . Db::table('user_preferences') . '` WHERE `user_type` = ? AND `user_id` = ?',
            [$userType, $userId]
        );
        return $row ? new UserPreferencesRecord($row) : null;
    }

    public function values($userType, $userId)
    {
        $record = $this->find($userType, $userId);
        if ($record === null) {
            return [
                'login_notification_enabled' => 0,
                'security_event_notification_enabled' => 0,
            ];
        }
        $data = $record->toArray();
        return [
            'login_notification_enabled' => $data['login_notification_enabled'],
            'security_event_notification_enabled' => $data['security_event_notification_enabled'],
        ];
    }

    public function upsert($userType, $userId, array $preferences, $utcNow = null)
    {
        list($userType, $userId) = IdentityScope::validate($userType, $userId);
        ModelValidation::allowKeys($preferences, [
            'login_notification_enabled', 'security_event_notification_enabled',
        ], 'notification preferences');
        if (!array_key_exists('login_notification_enabled', $preferences)
            || !array_key_exists('security_event_notification_enabled', $preferences)) {
            throw new \InvalidArgumentException('Both Passkey notification preferences are required.');
        }
        $loginEnabled = ModelValidation::boolean(
            $preferences['login_notification_enabled'],
            'login_notification_enabled'
        );
        $securityEnabled = ModelValidation::boolean(
            $preferences['security_event_notification_enabled'],
            'security_event_notification_enabled'
        );
        $utcNow = $utcNow === null ? gmdate('Y-m-d H:i:s') : ModelValidation::timestamp($utcNow, 'utcNow');
        $existing = $this->find($userType, $userId);
        if ($existing === null) {
            try {
                Db::insert('user_preferences', [
                    'user_type' => $userType,
                    'user_id' => $userId,
                    'login_notification_enabled' => $loginEnabled,
                    'security_event_notification_enabled' => $securityEnabled,
                    'created_at' => $utcNow,
                    'updated_at' => $utcNow,
                ]);
            } catch (\PDOException $error) {
                // A concurrent writer may have created this identity's row;
                // continue with the scoped update below.
            }
        }
        Db::update(
            'user_preferences',
            ['user_type' => $userType, 'user_id' => $userId],
            [
                'login_notification_enabled' => $loginEnabled,
                'security_event_notification_enabled' => $securityEnabled,
                'updated_at' => $utcNow,
            ]
        );
        $record = $this->find($userType, $userId);
        if (!$record) {
            throw new \RuntimeException('Passkey notification preferences were not persisted.');
        }
        return $record;
    }
}
