<?php
/**
 * Repair legacy signed BIGINT child columns that reference Blueprint::id().
 *
 * Fresh installs get unsigned foreign-key columns from migrations 0002-0007.
 * This additive migration also fixes databases on which those historical
 * migrations were applied before their DDL was corrected. MySQL requires
 * compatible integer signedness for InnoDB foreign keys.
 */

use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Blueprint;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Migrator;

return [
    'id' => '0009_align_unsigned_foreign_key_types',
    'description' => 'Align legacy App Cloud BIGINT foreign-key columns with unsigned primary keys.',
    'up' => function (Migrator $m) {
        // SQLite does not enforce the MySQL signedness rule. Its historical
        // migrations already use the same integer affinity, so no table rebuild
        // is needed or desirable here.
        if (Db::isSqlite()) {
            return;
        }

        $references = [
            ['application_versions', 'application_id', 'applications', 'CASCADE'],
            ['application_compatibility', 'application_id', 'applications', 'CASCADE'],
            ['application_dependencies', 'application_id', 'applications', 'CASCADE'],
            ['server_credentials', 'server_id', 'servers', 'CASCADE'],
            ['installations', 'application_id', 'applications', 'RESTRICT'],
            ['installations', 'application_version_id', 'application_versions', 'RESTRICT'],
            ['installation_domains', 'installation_id', 'installations', 'CASCADE'],
            ['installation_domains', 'domain_id', 'domains', 'CASCADE'],
            ['environment', 'installation_id', 'installations', 'CASCADE'],
            ['volumes', 'installation_id', 'installations', 'CASCADE'],
            ['deployments', 'installation_id', 'installations', 'CASCADE'],
            ['deployment_steps', 'deployment_id', 'deployments', 'CASCADE'],
            ['deployment_logs', 'deployment_id', 'deployments', 'CASCADE'],
            ['created_resources', 'deployment_id', 'deployments', 'CASCADE'],
            ['backups', 'installation_id', 'installations', 'CASCADE'],
        ];

        foreach ($references as $reference) {
            list($logicalTable, $column, $logicalParent, $onDelete) = $reference;
            $table = Db::t($logicalTable);
            $parent = Db::t($logicalParent);

            $columnInfo = Db::selectOne(
                'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            $parentInfo = Db::selectOne(
                'SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$parent, 'id']
            );
            if (!$columnInfo || !$parentInfo) {
                throw new AppsException(
                    'Cannot align App Cloud foreign key ' . $logicalTable . '.' . $column
                    . ': child or referenced column is missing.'
                );
            }
            if (strtolower((string) $parentInfo['DATA_TYPE']) !== 'bigint'
                || strpos(strtolower((string) $parentInfo['COLUMN_TYPE']), 'unsigned') === false) {
                throw new AppsException(
                    'Cannot align App Cloud foreign key ' . $logicalTable . '.' . $column
                    . ': referenced primary key is not an unsigned BIGINT.'
                );
            }

            $foreignKey = Db::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                . 'AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ? LIMIT 1',
                [$table, $column, $parent, 'id']
            );
            $compatibleType = strtolower((string) $columnInfo['DATA_TYPE']) === 'bigint'
                && strpos(strtolower((string) $columnInfo['COLUMN_TYPE']), 'unsigned') !== false;

            // Never coerce negative/orphaned legacy references into an unsigned
            // column or attach a constraint to inconsistent data. Report the
            // affected relationship before making any non-transactional MySQL DDL changes.
            if (!$compatibleType || !$foreignKey) {
                $childTableSql = Db::quoteIdentifier($table);
                $parentTableSql = Db::quoteIdentifier($parent);
                $childColumnSql = Db::quoteIdentifier($column);
                $parentIdSql = Db::quoteIdentifier('id');
                $invalidReference = Db::selectOne(
                    'SELECT 1 FROM ' . $childTableSql . ' AS child LEFT JOIN ' . $parentTableSql
                    . ' AS parent_row ON parent_row.' . $parentIdSql . ' = child.' . $childColumnSql
                    . ' WHERE child.' . $childColumnSql . ' < 0 OR (child.' . $childColumnSql
                    . ' IS NOT NULL AND parent_row.' . $parentIdSql . ' IS NULL) LIMIT 1'
                );
                if ($invalidReference) {
                    throw new AppsException(
                        'Cannot align App Cloud foreign key ' . $logicalTable . '.' . $column
                        . ': negative or orphaned values require operator review.'
                    );
                }
            }

            // DDL is intentionally restartable: if a previous attempt stopped
            // after dropping the key or changing the type, the next run resumes.
            if (!$compatibleType && $foreignKey) {
                Db::exec(
                    'ALTER TABLE ' . Db::quoteIdentifier($table)
                    . ' DROP FOREIGN KEY ' . Db::quoteIdentifier($foreignKey['CONSTRAINT_NAME'])
                );
                $foreignKey = null;
            }

            if (!$compatibleType) {
                $nullable = strtoupper((string) $columnInfo['IS_NULLABLE']) === 'YES';
                $default = $columnInfo['COLUMN_DEFAULT'];
                $definition = 'BIGINT UNSIGNED ' . ($nullable ? 'NULL' : 'NOT NULL');
                if ($default !== null) {
                    $definition .= ' DEFAULT ' . (is_numeric($default) ? (string) $default : "'"
                        . str_replace("'", "''", (string) $default) . "'");
                }
                Db::exec(
                    'ALTER TABLE ' . Db::quoteIdentifier($table)
                    . ' MODIFY COLUMN ' . Db::quoteIdentifier($column) . ' ' . $definition
                );
            }

            if (!$foreignKey) {
                $blueprint = new Blueprint($table);
                $constraint = Db::quoteIdentifier($blueprint->foreignKeyName($column));
                Db::exec(
                    'ALTER TABLE ' . Db::quoteIdentifier($table)
                    . ' ADD CONSTRAINT ' . $constraint
                    . ' FOREIGN KEY (' . Db::quoteIdentifier($column) . ')'
                    . ' REFERENCES ' . Db::quoteIdentifier($parent)
                    . ' (' . Db::quoteIdentifier('id') . ') ON DELETE ' . $onDelete
                );
            }
        }
    },
];
