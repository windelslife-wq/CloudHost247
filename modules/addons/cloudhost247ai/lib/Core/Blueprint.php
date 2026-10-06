<?php
/**
 * Portable table blueprint (ported from Chs\Core\Blueprint): one declaration
 * emits MySQL InnoDB/utf8mb4 DDL for production and SQLite DDL for tests.
 */

namespace Ch247Ai\Core;

class Blueprint
{
    protected $table;
    protected $columns = [];
    protected $indexes = [];
    protected $uniques = [];
    protected $foreign = [];
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
    public function string($name, $length = 191, $null = false, $default = null)
    {
        $this->columns[] = ['name' => $name, 'type' => 'varchar', 'length' => (int) $length, 'null' => $null, 'default' => $default];
        return $this;
    }
    public function char($name, $length, $null = false, $default = null)
    {
        $this->columns[] = ['name' => $name, 'type' => 'char', 'length' => (int) $length, 'null' => $null, 'default' => $default];
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
    public function dateTime($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'datetime', 'null' => $null];
        return $this;
    }
    public function date($name, $null = false)
    {
        $this->columns[] = ['name' => $name, 'type' => 'date', 'null' => $null];
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
    public function foreign($column, $refPhysicalTable, $refColumn = 'id', $onDelete = 'restrict')
    {
        $this->foreign[] = ['column' => $column, 'ref' => $refPhysicalTable, 'refColumn' => $refColumn, 'onDelete' => strtolower($onDelete), 'name' => 'fk_' . $this->table . '_' . $column];
        return $this;
    }

    /** @return string[] DDL statements for the given driver (mysql|sqlite) */
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
            $defs[] = $driver === 'sqlite' ? 'UNIQUE (' . $cols . ')' : 'UNIQUE KEY `' . $uq['name'] . '` (' . $cols . ')';
        }
        if ($driver !== 'sqlite') {
            foreach ($this->indexes as $ix) {
                $defs[] = 'KEY `' . $ix['name'] . '` (`' . implode('`, `', $ix['columns']) . '`)';
            }
            foreach ($this->foreign as $fk) {
                $defs[] = 'CONSTRAINT `' . $fk['name'] . '` FOREIGN KEY (`' . $fk['column'] . '`) REFERENCES `' . $fk['ref'] . '` (`' . $fk['refColumn'] . '`) ON DELETE ' . strtoupper($fk['onDelete']);
            }
        }
        $statements = [];
        if ($driver === 'sqlite') {
            $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` (' . implode(', ', $defs) . ')';
            foreach ($this->indexes as $ix) {
                $statements[] = 'CREATE INDEX IF NOT EXISTS `' . $ix['name'] . '_' . $this->table . '` ON `' . $this->table . '` (`' . implode('`, `', $ix['columns']) . '`)';
            }
            return $statements;
        }
        $statements[] = 'CREATE TABLE IF NOT EXISTS `' . $this->table . '` (' . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return $statements;
    }

    protected function columnSql(array $col, $driver)
    {
        $name = '`' . $col['name'] . '`';
        if (!empty($col['auto'])) {
            return $driver === 'sqlite' ? $name . ' INTEGER PRIMARY KEY AUTOINCREMENT' : $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
        }
        switch ($col['type']) {
            case 'varchar':
                $typeSql = ' VARCHAR(' . (int) $col['length'] . ')';
                break;
            case 'char':
                $typeSql = ' CHAR(' . (int) $col['length'] . ')';
                break;
            case 'longtext':
                $typeSql = $driver === 'sqlite' ? ' TEXT' : ' LONGTEXT';
                break;
            default:
                $known = ['bigint' => ' BIGINT', 'int' => ' INT', 'text' => ' TEXT', 'datetime' => ' DATETIME', 'date' => ' DATE'];
                $typeSql = isset($known[$col['type']]) ? $known[$col['type']] : ' TEXT';
        }
        $sql = $name . $typeSql;
        if (!empty($col['null'])) {
            $sql .= ' NULL';
        } else {
            $sql .= ' NOT NULL';
        }
        if (array_key_exists('default', $col) && $col['default'] !== null) {
            $sql .= " DEFAULT '" . addslashes((string) $col['default']) . "'";
        }
        return $sql;
    }
}
