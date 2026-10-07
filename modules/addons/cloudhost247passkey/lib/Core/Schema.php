<?php
/**
 * Portable MySQL/MariaDB and SQLite schema definition for Passkey-owned data.
 * Only this module's tables are created; WHMCS identity tables are referenced by
 * scalar IDs and never receive foreign keys or writes.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

class Schema
{
    /** Logical data tables installed across the forward-only addon migrations. */
    public static function tableNames()
    {
        return [
            'credentials', 'challenges', 'events', 'settings', 'user_policies',
            'user_preferences', 'reset_grants', 'rate_limits', 'external_identities', 'user_handles',
        ];
    }

    /** DDL for the additive Phase 3 migration; no raw challenge is added. */
    public static function credentialSourceColumnStatement($driver)
    {
        $driver = strtolower((string) $driver);
        if ($driver === 'sqlite') {
            return 'ALTER TABLE `' . Db::table('credentials') . '` ADD COLUMN `credential_source_json` TEXT NULL';
        }
        if ($driver === 'mysql') {
            return 'ALTER TABLE `' . Db::table('credentials') . '` ADD COLUMN `credential_source_json` LONGTEXT NULL';
        }
        throw new \InvalidArgumentException('Unsupported Passkey migration driver.');
    }

    /** Create the opaque WebAuthn user-handle mapping without copying WHMCS users. */
    public static function userHandleStatements($driver)
    {
        $driver = strtolower((string) $driver);
        if ($driver === 'sqlite') {
            $table = Db::table('user_handles');
            return [
                'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
                    . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_type` VARCHAR(16) NOT NULL, '
                    . '`user_id` BIGINT NOT NULL, `user_handle` VARCHAR(64) NOT NULL, '
                    . '`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL)',
                'CREATE UNIQUE INDEX IF NOT EXISTS `uq_pk_user_handle_owner` ON `' . $table . '` (`user_type`, `user_id`)',
                'CREATE UNIQUE INDEX IF NOT EXISTS `uq_pk_user_handle_value` ON `' . $table . '` (`user_handle`)',
            ];
        }
        if ($driver === 'mysql') {
            return [
                'CREATE TABLE IF NOT EXISTS `' . Db::table('user_handles') . '` ('
                    . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_type` VARCHAR(16) NOT NULL, '
                    . '`user_id` BIGINT UNSIGNED NOT NULL, `user_handle` VARCHAR(64) NOT NULL, '
                    . '`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, PRIMARY KEY (`id`), '
                    . 'UNIQUE KEY `uq_pk_user_handle_owner` (`user_type`, `user_id`), '
                    . 'UNIQUE KEY `uq_pk_user_handle_value` (`user_handle`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }
        throw new \InvalidArgumentException('Unsupported Passkey migration driver.');
    }

    /** SQL DDL suitable for inspecting/test-running on the named driver. */
    public static function statements($driver)
    {
        $driver = strtolower((string) $driver);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \InvalidArgumentException('Unsupported Passkey migration driver.');
        }

        $statements = [];
        foreach (self::definitions() as $logicalName => $definition) {
            $table = Db::table($logicalName);
            $parts = [];
            foreach ($definition['columns'] as $column => $spec) {
                $parts[] = self::columnSql($column, $spec, $driver);
            }
            foreach ($definition['uniques'] as $unique) {
                if ($driver === 'mysql') {
                    $parts[] = 'UNIQUE KEY `' . $unique['name'] . '` (' . self::columnList($unique['columns']) . ')';
                }
            }
            if ($driver === 'mysql') {
                foreach ($definition['indexes'] as $index) {
                    $parts[] = 'KEY `' . $index['name'] . '` (' . self::columnList($index['columns']) . ')';
                }
            }
            foreach ($definition['foreign'] as $foreign) {
                $parts[] = 'CONSTRAINT `' . $foreign['name'] . '` FOREIGN KEY (`' . $foreign['column'] . '`)'
                    . ' REFERENCES `' . Db::table($foreign['table']) . '` (`id`) ON DELETE ' . $foreign['on_delete'];
            }
            $sql = 'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $parts) . ')';
            if ($driver === 'mysql') {
                $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            }
            $statements[] = $sql;

            if ($driver === 'sqlite') {
                foreach ($definition['uniques'] as $unique) {
                    $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS `' . $unique['name'] . '` ON `' . $table
                        . '` (' . self::columnList($unique['columns']) . ')';
                }
                foreach ($definition['indexes'] as $index) {
                    $statements[] = 'CREATE INDEX IF NOT EXISTS `' . $index['name'] . '` ON `' . $table
                        . '` (' . self::columnList($index['columns']) . ')';
                }
            }
        }
        return $statements;
    }

    public static function ledgerStatement($driver)
    {
        $driver = strtolower((string) $driver);
        if ($driver === 'sqlite') {
            return 'CREATE TABLE IF NOT EXISTS `' . Db::table('migrations') . '` ('
                . '`id` INTEGER PRIMARY KEY AUTOINCREMENT, `migration` VARCHAR(120) NOT NULL UNIQUE, '
                . '`description` VARCHAR(255) NOT NULL, `applied_at` DATETIME NOT NULL)';
        }
        if ($driver === 'mysql') {
            return 'CREATE TABLE IF NOT EXISTS `' . Db::table('migrations') . '` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `migration` VARCHAR(120) NOT NULL, '
                . '`description` VARCHAR(255) NOT NULL, `applied_at` DATETIME NOT NULL, PRIMARY KEY (`id`), '
                . 'UNIQUE KEY `uq_pk_migration_name` (`migration`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        throw new \InvalidArgumentException('Unsupported Passkey migration driver.');
    }

    private static function definitions()
    {
        return [
            'credentials' => [
                'columns' => [
                    'id' => self::c('id'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint'),
                    'credential_id' => self::c('longtext'),
                    'credential_id_hash' => self::c('char:64'),
                    'public_key' => self::c('longtext'),
                    'credential_type' => self::c('varchar:32', false, 'public-key'),
                    'sign_count' => self::c('bigint', false, 0),
                    'transports_json' => self::c('text', true),
                    'authenticator_metadata_json' => self::c('text', true),
                    'device_name' => self::c('varchar:120', false, 'Passkey'),
                    'created_at' => self::c('datetime'),
                    'last_used_at' => self::c('datetime', true),
                    'revoked_at' => self::c('datetime', true),
                    'disabled_at' => self::c('datetime', true),
                    'registration_ip' => self::c('varchar:45', true),
                    'registration_user_agent' => self::c('varchar:512', true),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_credential_hash', 'columns' => ['credential_id_hash']]],
                'indexes' => [
                    ['name' => 'ix_pk_cred_owner_created', 'columns' => ['user_type', 'user_id', 'created_at']],
                    ['name' => 'ix_pk_cred_owner_state', 'columns' => ['user_type', 'user_id', 'revoked_at', 'disabled_at']],
                    ['name' => 'ix_pk_cred_last_used', 'columns' => ['last_used_at']],
                ],
                'foreign' => [],
            ],
            'challenges' => [
                'columns' => [
                    'id' => self::c('id'),
                    'challenge_hash' => self::c('char:64'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint', true),
                    'challenge_type' => self::c('varchar:32'),
                    'action_code' => self::c('varchar:128', true),
                    'session_binding_hash' => self::c('char:64'),
                    'rp_id' => self::c('varchar:253'),
                    'origin' => self::c('varchar:512'),
                    'expires_at' => self::c('datetime'),
                    'consumed_at' => self::c('datetime', true),
                    'created_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_challenge_hash', 'columns' => ['challenge_hash']]],
                'indexes' => [
                    ['name' => 'ix_pk_ch_owner_expiry', 'columns' => ['user_type', 'user_id', 'challenge_type', 'expires_at']],
                    ['name' => 'ix_pk_ch_exp_consumed', 'columns' => ['expires_at', 'consumed_at']],
                ],
                'foreign' => [],
            ],
            'events' => [
                'columns' => [
                    'id' => self::c('id'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint', true),
                    'passkey_id' => self::c('bigint', true),
                    'event_type' => self::c('varchar:64'),
                    'success' => self::c('tinyint', false, 0),
                    'reason_code' => self::c('varchar:64', true),
                    'ip_address' => self::c('varchar:45', true),
                    'user_agent' => self::c('varchar:512', true),
                    'metadata_json' => self::c('text', true),
                    'created_at' => self::c('datetime'),
                ],
                'uniques' => [],
                'indexes' => [
                    ['name' => 'ix_pk_evt_owner_time', 'columns' => ['user_type', 'user_id', 'created_at']],
                    ['name' => 'ix_pk_evt_type_time', 'columns' => ['event_type', 'created_at']],
                    ['name' => 'ix_pk_evt_result_time', 'columns' => ['success', 'created_at']],
                    ['name' => 'ix_pk_evt_credential', 'columns' => ['passkey_id', 'created_at']],
                ],
                'foreign' => [['name' => 'fk_pk_evt_credential', 'column' => 'passkey_id', 'table' => 'credentials', 'on_delete' => 'SET NULL']],
            ],
            'settings' => [
                'columns' => [
                    'id' => self::c('id'),
                    'setting_key' => self::c('varchar:96'),
                    'setting_value' => self::c('text', true),
                    'encrypted_value' => self::c('longtext', true),
                    'is_secret' => self::c('tinyint', false, 0),
                    'updated_by_admin_id' => self::c('bigint', true),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_setting_key', 'columns' => ['setting_key']]],
                'indexes' => [],
                'foreign' => [],
            ],
            'user_policies' => [
                'columns' => [
                    'id' => self::c('id'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint'),
                    'policy' => self::c('varchar:32', false, 'default'),
                    'temporary_disabled_until' => self::c('datetime', true),
                    'reason_code' => self::c('varchar:96', true),
                    'updated_by_admin_id' => self::c('bigint', true),
                    'created_at' => self::c('datetime'),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_policy_owner', 'columns' => ['user_type', 'user_id']]],
                'indexes' => [['name' => 'ix_pk_policy_until', 'columns' => ['policy', 'temporary_disabled_until']]],
                'foreign' => [],
            ],
            'user_preferences' => [
                'columns' => [
                    'id' => self::c('id'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint'),
                    'login_notification_enabled' => self::c('tinyint', false, 0),
                    'security_event_notification_enabled' => self::c('tinyint', false, 0),
                    'created_at' => self::c('datetime'),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_pref_owner', 'columns' => ['user_type', 'user_id']]],
                'indexes' => [],
                'foreign' => [],
            ],
            'reset_grants' => [
                'columns' => [
                    'id' => self::c('id'),
                    'token_hash' => self::c('char:64'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint'),
                    'session_binding_hash' => self::c('char:64'),
                    'challenge_id' => self::c('bigint', true),
                    'expires_at' => self::c('datetime'),
                    'consumed_at' => self::c('datetime', true),
                    'created_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_reset_token_hash', 'columns' => ['token_hash']]],
                'indexes' => [
                    ['name' => 'ix_pk_reset_owner_expiry', 'columns' => ['user_type', 'user_id', 'expires_at']],
                    ['name' => 'ix_pk_reset_exp_consumed', 'columns' => ['expires_at', 'consumed_at']],
                ],
                'foreign' => [['name' => 'fk_pk_reset_challenge', 'column' => 'challenge_id', 'table' => 'challenges', 'on_delete' => 'SET NULL']],
            ],
            'rate_limits' => [
                'columns' => [
                    'id' => self::c('id'),
                    'action' => self::c('varchar:40'),
                    'principal_hash' => self::c('char:64'),
                    'window_started_at' => self::c('datetime'),
                    'hit_count' => self::c('int', false, 0),
                    'expires_at' => self::c('datetime'),
                    'created_at' => self::c('datetime'),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_rate_window', 'columns' => ['action', 'principal_hash', 'window_started_at']]],
                'indexes' => [['name' => 'ix_pk_rate_expiry', 'columns' => ['expires_at']]],
                'foreign' => [],
            ],
            'external_identities' => [
                'columns' => [
                    'id' => self::c('id'),
                    'provider' => self::c('varchar:40'),
                    'tenant_id' => self::c('varchar:191'),
                    'subject_id' => self::c('varchar:255'),
                    'identity_hash' => self::c('char:64'),
                    'user_type' => self::c('varchar:16'),
                    'user_id' => self::c('bigint'),
                    'linked_at' => self::c('datetime'),
                    'last_authenticated_at' => self::c('datetime', true),
                    'revoked_at' => self::c('datetime', true),
                    'created_at' => self::c('datetime'),
                    'updated_at' => self::c('datetime'),
                ],
                'uniques' => [['name' => 'uq_pk_external_identity_hash', 'columns' => ['identity_hash']]],
                'indexes' => [
                    ['name' => 'ix_pk_external_owner', 'columns' => ['user_type', 'user_id', 'provider']],
                    ['name' => 'ix_pk_external_revoked', 'columns' => ['revoked_at']],
                ],
                'foreign' => [],
            ],
        ];
    }

    private static function c($type, $nullable = false, $default = null)
    {
        return ['type' => $type, 'nullable' => (bool) $nullable, 'default' => $default];
    }

    private static function columnSql($name, array $spec, $driver)
    {
        $name = self::identifier($name);
        $type = $spec['type'];
        if ($type === 'id') {
            return '`' . $name . '` ' . ($driver === 'sqlite'
                ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
                : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        }
        if (preg_match('/^(varchar|char):(\d+)$/', $type, $match)) {
            $typeSql = strtoupper($match[1]) . '(' . (int) $match[2] . ')';
        } else {
            switch ($type) {
                case 'bigint': $typeSql = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED'; break;
                case 'int': $typeSql = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED'; break;
                case 'tinyint': $typeSql = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT UNSIGNED'; break;
                case 'text': $typeSql = 'TEXT'; break;
                case 'longtext': $typeSql = $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT'; break;
                case 'datetime': $typeSql = 'DATETIME'; break;
                default: throw new \InvalidArgumentException('Unknown Passkey schema type.');
            }
        }
        $sql = '`' . $name . '` ' . $typeSql . ($spec['nullable'] ? ' NULL' : ' NOT NULL');
        if ($spec['default'] !== null) {
            $sql .= ' DEFAULT ' . self::literal($spec['default']);
        }
        return $sql;
    }

    private static function columnList(array $columns)
    {
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = '`' . self::identifier($column) . '`';
        }
        return implode(', ', $quoted);
    }

    private static function identifier($value)
    {
        $value = (string) $value;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException('Invalid Passkey schema identifier.');
        }
        return $value;
    }

    private static function literal($value)
    {
        if (is_int($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
