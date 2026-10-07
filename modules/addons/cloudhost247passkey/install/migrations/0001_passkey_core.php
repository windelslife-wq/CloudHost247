<?php
/** Additive Phase 2 storage for WebAuthn credentials, ceremonies and policy. */

use CloudHost247\Passkey\Core\Db;
use CloudHost247\Passkey\Core\Schema;
use CloudHost247\Passkey\Model\SettingsRecord;

return [
    'id' => '0001_passkey_core',
    'description' => 'Passkey credentials, one-use challenges, security events, policies and privacy controls',
    'up' => function () {
        foreach (Schema::statements(Db::driver()) as $sql) {
            Db::execute($sql);
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach (SettingsRecord::defaults() as $key => $value) {
            Db::insertIgnore('settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'encrypted_value' => null,
                'is_secret' => 0,
                'updated_by_admin_id' => null,
                'updated_at' => $now,
            ]);
        }
    },
];
