<?php
/**
 * Phase 2 — execution columns on `approvals`.
 *
 * Additive only: no table is created, dropped or rewritten (brief §34).
 * The approval row becomes the execution record as well, so an operator can
 * read request → decision → execution → verification in one place.
 *
 *   args_digest        SHA-256 of the canonical arguments at request time.
 *                      Execution refuses if the arguments drifted, so an
 *                      approval for "reply to ticket 401" can never be spent
 *                      on "reply to ticket 999".
 *   execution_status   none | running | succeeded | failed
 *   execution_result   redacted JSON returned by the tool
 *   verified           1 only when a post-write re-read confirmed the change
 *   verification_note  what was checked, or why it could not be confirmed
 *   attempts           execution attempts (single-use claim keeps this <= 1)
 */

use Ch247Ai\Core\Db;

return [
    'id' => '0005_execution',
    'description' => 'Execution + verification columns on approvals',
    'up' => function () {
        $table = Db::t('approvals');

        // Column names for a physical table, on either driver. Defined inline
        // because migration files are re-included on every migrate() pass.
        $columnsOf = function ($physicalTable) {
            $names = [];
            try {
                if (Db::driver() === 'mysql') {
                    $rows = Db::query(
                        'SELECT COLUMN_NAME AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
                        [$physicalTable]
                    );
                } else {
                    $rows = Db::query('PRAGMA table_info(' . $physicalTable . ')');
                }
                foreach ($rows as $row) {
                    if (isset($row['name'])) {
                        $names[] = (string) $row['name'];
                    }
                }
            } catch (\Throwable $e) {
                // Unreadable schema -> report nothing present; ADD COLUMN then
                // fails loudly rather than silently skipping the migration.
            }
            return $names;
        };
        $existing = $columnsOf($table);

        $columns = [
            'args_digest' => "VARCHAR(64) NULL",
            'execution_status' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'execution_result' => 'TEXT NULL',
            'verified' => 'INT NOT NULL DEFAULT 0',
            'verification_note' => 'VARCHAR(500) NULL',
            'attempts' => 'INT NOT NULL DEFAULT 0',
        ];
        foreach ($columns as $name => $ddl) {
            if (!in_array($name, $existing, true)) {
                Db::exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $ddl);
            }
        }
    },
];
