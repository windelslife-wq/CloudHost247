<?php
/**
 * CloudHost247 Services Suite — portable table blueprint.
 *
 * Emits MySQL (InnoDB/utf8mb4) DDL for production and SQLite DDL for the
 * offline test harness from a single declaration, so the schema the tests
 * exercise is the schema that ships.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Blueprint
{
    /** @var string */
    protected $table;

    /** @var array[] */
    protected $columns = [];

    /** @var array[] */
    protected $indexes = [];

    /** @var array[] */
    protected $uniques = [];

    /** @var array[] */
    protected $foreign = [];

    /** @var string|null */
    protected $primary;

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function name()
    {
        return $this->table;
    }

    public function id($name = 'id')
    {
        $this->columns[] = ['name' => $name, 'type' => 'bigint', 'auto' => true, 'null' => false];
        $this->primary = $name;
        return $this;
    }

    public function bigInteger($name, $null = false, $default = null)
    {
        $this->columns[] = ['name' => $name, 'type' => 'bigint', 'null' => $null, 'default' => $default];
        return $this;
    }

    public function integer($name, $null = false, $default = null)
    {
        $this->columns[] = ['name' => $name, 'type' => 'int', 'null' => $null, 'default' => $default];
        return $this;
    }

    public function tinyInteger($name, $null = false, $default = null)
    {
        $this->columns[] = ['name' => $name, 'type' => 'tinyint', 'null' => $null, 'default' => $default];
        return $this;
    }

    public function boolean($name, $default = 0)
    {
        return $this->tinyInteger($name, false, (int) $default);
    }

    public function string($name, $length = 191, $null = false, $default = null)
    {
        $this->columns[] = [
            'name' => $name, 'type' => 'varchar', 'length' => (int) $length,
            'null' => $null, 'default' => $default,
        ];
        return $this;
    }

    public function char($name, $length, $null = false, $default = null)
    {
        $this->columns[] = [
            'name' => $name, 'type' => 'char', 'length' => (int) $length,
            'null' => $null, 'default' => $default,
        ];
        return $this;
    }

    public function text($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'text', 'null' => $null];
        return $this;
    }

    public function longText($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'longtext', 'null' => $null];
        return $this;
    }

    public function decimalColumn($name, $precision = 12, $scale = 2, $null = false, $default = null)
    {
        $this->columns[] = [
            'name' => $name, 'type' => 'decimal', 'precision' => (int) $precision,
            'scale' => (int) $scale, 'null' => $null, 'default' => $default,
        ];
        return $this;
    }

    public function dateTime($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'datetime', 'null' => $null];
        return $this;
    }

    public function dateColumn($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'date', 'null' => $null];
        return $this;
    }

    /** created_at + updated_at, both nullable (legacy-friendly). */
    public function timestamps()
    {
        $this->dateTime('created_at', true);
        $this->dateTime('updated_at', true);
        return $this;
    }

    public function index($columns, $name = '')
    {
        $columns = (array) $columns;
        $this->indexes[] = ['name' => $name ?: 'ix_' . implode('_', $columns), 'columns' => $columns];
        return $this;
    }

    public function unique($columns, $name = '')
    {
        $columns = (array) $columns;
        $this->uniques[] = ['name' => $name ?: 'uq_' . implode('_', $columns), 'columns' => $columns];
        return $this;
    }

    public function foreign($column, $refLogicalTable, $refColumn = 'id', $onDelete = 'restrict')
    {
        $this->foreign[] = [
            'column' => $column,
            'ref' => Db::t($refLogicalTable),
            'refColumn' => $refColumn,
            'onDelete' => strtolower($onDelete),
            'name' => 'fk_' . $this->table . '_' . $column,
        ];
        return $this;
    }

    /**
     * @return string[] DDL statements for the given driver (mysql|sqlite)
     */
    public function createSql($driver)
    {
        $defs = [];
        foreach ($this->columns as $col) {
            $defs[] = $this->columnSql($col, $driver);
        }
        if ($this->primary !== null && $driver !== 'sqlite') {
            $defs[] = 'PRIMARY KEY (`' . $this->primary . '`)';
        }
        foreach ($this->uniques as $uq) {
            $cols = '`' . implode('`, `', $uq['columns']) . '`';
            if ($driver === 'sqlite') {
                $defs[] = 'UNIQUE (' . $cols . ')';
            } else {
                $defs[] = 'UNIQUE KEY `' . $uq['name'] . '` (' . $cols . ')';
            }
        }
        if ($driver !== 'sqlite') {
            foreach ($this->indexes as $ix) {
                $defs[] = 'KEY `' . $ix['name'] . '` (`' . implode('`, `', $ix['columns']) . '`)';
            }
            foreach ($this->foreign as $fk) {
                $defs[] = 'CONSTRAINT `' . $fk['name'] . '` FOREIGN KEY (`' . $fk['column'] . '`)'
                    . ' REFERENCES `' . $fk['ref'] . '` (`' . $fk['refColumn'] . '`)'
                    . ' ON DELETE ' . strtoupper($fk['onDelete']);
            }
        }

        $statements = [];
        if ($driver === 'sqlite') {
            $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ('
                . implode(', ', $defs) . ')';
            foreach ($this->indexes as $ix) {
                $statements[] = 'CREATE INDEX IF NOT EXISTS `' . $ix['name'] . '_' . $this->table
                    . '` ON `' . $this->table . '` (`' . implode('`, `', $ix['columns']) . '`)';
            }
            return $statements;
        }

        $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` ('
            . implode(', ', $defs)
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return $statements;
    }

    protected function columnSql(array $col, $driver)
    {
        $name = '`' . $col['name'] . '`';

        if (!empty($col['auto'])) {
            if ($driver === 'sqlite') {
                // SQLite auto-increment implies INTEGER PRIMARY KEY.
                return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            }
            return $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
        }

        switch ($col['type']) {
            case 'bigint':
                $sql = $name . ' BIGINT';
                break;
            case 'int':
                $sql = $name . ' INT';
                break;
            case 'tinyint':
                $sql = $name . ' TINYINT';
                break;
            case 'varchar':
                $sql = $name . ' VARCHAR(' . $col['length'] . ')';
                break;
            case 'char':
                $sql = $name . ' CHAR(' . $col['length'] . ')';
                break;
            case 'text':
                $sql = $name . ($driver === 'sqlite' ? ' TEXT' : ' TEXT');
                break;
            case 'longtext':
                $sql = $name . ($driver === 'sqlite' ? ' TEXT' : ' LONGTEXT');
                break;
            case 'decimal':
                $sql = $name . ' DECIMAL(' . $col['precision'] . ',' . $col['scale'] . ')';
                break;
            case 'datetime':
                $sql = $name . ($driver === 'sqlite' ? ' TEXT' : ' DATETIME');
                break;
            case 'date':
                $sql = $name . ($driver === 'sqlite' ? ' TEXT' : ' DATE');
                break;
            default:
                throw new ChsException('Unsupported blueprint type: ' . $col['type']);
        }

        $sql .= empty($col['null']) ? ' NOT NULL' : ' NULL';
        if (array_key_exists('default', $col) && $col['default'] !== null) {
            $default = $col['default'];
            if (is_int($default) || is_float($default)) {
                $sql .= ' DEFAULT ' . $default;
            } else {
                $sql .= ' DEFAULT \'' . str_replace("'", "''", (string) $default) . '\'';
            }
        }
        return $sql;
    }
}
