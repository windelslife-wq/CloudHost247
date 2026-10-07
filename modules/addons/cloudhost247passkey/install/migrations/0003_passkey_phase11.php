<?php
/** Phase 11 settings: notification switches, sensitive-action policy, Entra configuration. */

use CloudHost247\Passkey\Core\Db;

return [
    'id' => '0003_passkey_phase11',
    'description' => 'Notification, sensitive-action, and Entra ID settings for the native WHMCS boundary',
    'up' => function () {
        $now = gmdate('Y-m-d H:i:s');
        $defaults = [
            'login_notifications_enabled' => '0',
            'security_event_notifications_enabled' => '0',
            'entra_client_login_enabled' => '0',
            'entra_admin_login_enabled' => '0',
            'entra_tenant_id' => '',
            'entra_client_id' => '',
            'entra_redirect_uri' => '',
            'entra_allowed_domains' => '[]',
            'sensitive_action_policy' => 'optional',
        ];
        foreach ($defaults as $key => $value) {
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
