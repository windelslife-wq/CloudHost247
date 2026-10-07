<?php
/** Test-only DDL failure used to prove the ledger records only complete migrations. */

use CloudHost247\Passkey\Core\Db;

return [
    'id' => 'probe_retryable_migration',
    'description' => 'Test-only partial DDL/retry fixture',
    'up' => function () {
        Db::execute('CREATE TABLE IF NOT EXISTS `mod_ch247pk_retry_probe` (`id` INTEGER PRIMARY KEY)');
        if (!empty($GLOBALS['CH247PK_FAIL_FIXTURE_MIGRATION'])) {
            throw new RuntimeException('intentional test migration failure');
        }
    },
];
