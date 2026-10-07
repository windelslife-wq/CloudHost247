<?php
/**
 * CloudHost247 App Cloud — portable table blueprint.
 *
 * Emits MySQL (InnoDB / utf8mb4) DDL for a production WHMCS install and SQLite
 * DDL for the offline test harness from one declaration, so the schema the tests
 * exercise is the schema that ships. Foreign keys are declared between module
 * tables only: constraining WHMCS core tables would make the module undeployable
 * on hosted WHMCS and would block WHMCS' own maintenance routines.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

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

    /* ----------------------------------------------------------- columns -- */

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

    /** Universally unique reference (installations, deployments, jobs). */
    public function uuid($name = 'uuid')
    {
        return $this->string($name, 36);
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
        $this->columns[] = ['name' => $name, 'type' => 'text', 'null' => $null, 'default' => null];
        return $this;
    }

    public function longText($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'longtext', 'null' => $null, 'default' => null];
        return $this;
    }

    /** Money is always an integer count of minor units — never a float. */
    public function money($name, $null = true)
    {
        return $this->bigInteger($name, $null, $null ? null : 0);
    }

    public function decimal($name, $precision = 12, $scale = 4, $null = true, $default = null)
    {
        $this->columns[] = [
            'name' => $name, 'type' => 'decimal', 'precision' => (int) $precision,
            'scale' => (int) $scale, 'null' => $null, 'default' => $default,
        ];
        return $this;
    }

    public function datetime($name, $null = true)
    {
        $this->columns[] = ['name' => $name, 'type' => 'datetime', 'null' => $null, 'default' => null];
        return $this;
    }

    /** created_at / updated_at are written by the application, never the DB. */
    public function timestamps()
    {
        $this->datetime('created_at', false);
        $this->datetime('updated_at', false);
        return $this;
    }

    public function softDeletes()
    {
        $this->datetime('deleted_at', true);
        $this->index(['deleted_at']);
        return $this;
    }

    /* ----------------------------------------------------------- indexes -- */

    public function index(array $columns, $name = null)
    {
        $this->indexes[] = ['columns' => $columns, 'name' => $name ?: $this->indexName('idx', $columns)];
        return $this;
    }

    public function unique(array $columns, $name = null)
    {
        $this->uniques[] = ['columns' => $columns, 'name' => $name ?: $this->indexName('uniq', $columns)];
        return $this;
    }

    public function foreign($column, $logicalRefTable, $refColumn = 'id', $onDelete = 'CASCADE')
    {
        $this->foreign[] = [
            'column' => $column,
            'table' => Db::t($logicalRefTable),
            'ref' => $refColumn,
            'onDelete' => strtoupper($onDelete),
        ];
        return $this;
    }

    protected function indexName($kind, array $columns)
    {
        $base = $kind . '_' . $this->table . '_' . implode('_', $columns);
        $base = preg_replace('/[^a-z0-9_]/i', '', $base);
        if (strlen($base) > 60) {
            $base = substr($base, 0, 50) . '_' . substr(md5($base), 0, 8);
        }
        return $base;
    }

    /* --------------------------------------------------------------- DDL -- */

    /** @return string[] statements to execute in order */
    public function createSql($driver)
    {
        return $driver === 'sqlite' ? $this->toSqlite() : $this->toMysql();
    }

    protected function col($driver, array $c)
    {
        $q = $driver === 'sqlite' ? '"' : '`';
        $sql = $q . $c['name'] . $q . ' ';

        if (!empty($c['auto'])) {
            return $driver === 'sqlite'
                ? $sql . 'INTEGER PRIMARY KEY AUTOINCREMENT'
                : $sql . 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
        }

        switch ($c['type']) {
            case 'bigint':
                $sql .= $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
                break;
            case 'int':
                $sql .= $driver === 'sqlite' ? 'INTEGER' : 'INT';
                break;
            case 'tinyint':
                $sql .= $driver === 'sqlite' ? 'INTEGER' : 'TINYINT';
                break;
            case 'varchar':
                $sql .= 'VARCHAR(' . $c['length'] . ')';
                break;
            case 'char':
                $sql .= 'CHAR(' . $c['length'] . ')';
                break;
            case 'text':
                $sql .= 'TEXT';
                break;
            case 'longtext':
                $sql .= $driver === 'sqlite' ? 'TEXT' : 'LONGTEXT';
                break;
            case 'decimal':
                $sql .= 'DECIMAL(' . $c['precision'] . ',' . $c['scale'] . ')';
                break;
            case 'datetime':
                $sql .= 'DATETIME';
                break;
            default:
                throw new AppsException('Unknown column type ' . $c['type']);
        }

        $sql .= empty($c['null']) ? ' NOT NULL' : ' NULL';

        if (array_key_exists('default', $c) && $c['default'] !== null) {
            $d = $c['default'];
            $sql .= ' DEFAULT ' . (is_int($d) || is_float($d)
                ? $d
                : "'" . str_replace("'", "''", (string) $d) . "'");
        }
        return $sql;
    }

    protected function toMysql()
    {
        $lines = [];
        foreach ($this->columns as $c) {
            $lines[] = '  ' . $this->col('mysql', $c);
        }
        if ($this->primary) {
            $lines[] = '  PRIMARY KEY (`' . $this->primary . '`)';
        }
        foreach ($this->uniques as $u) {
            $lines[] = '  UNIQUE KEY `' . $u['name'] . '` (`' . implode('`, `', $u['columns']) . '`)';
        }
        foreach ($this->indexes as $i) {
            $lines[] = '  KEY `' . $i['name'] . '` (`' . implode('`, `', $i['columns']) . '`)';
        }
        foreach ($this->foreign as $f) {
            $lines[] = '  CONSTRAINT `' . $this->indexName('fk', [$f['column']]) . '` FOREIGN KEY (`'
                . $f['column'] . '`) REFERENCES `' . $f['table'] . '` (`' . $f['ref'] . '`) ON DELETE '
                . $f['onDelete'];
        }
        return [
            "CREATE TABLE IF NOT EXISTS `{$this->table}` (\n" . implode(",\n", $lines)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    protected function toSqlite()
    {
        $lines = [];
        foreach ($this->columns as $c) {
            $lines[] = '  ' . $this->col('sqlite', $c);
        }
        foreach ($this->uniques as $u) {
            $lines[] = '  CONSTRAINT "' . $u['name'] . '" UNIQUE ("' . implode('", "', $u['columns']) . '")';
        }
        foreach ($this->foreign as $f) {
            $lines[] = '  FOREIGN KEY ("' . $f['column'] . '") REFERENCES "' . $f['table']
                . '" ("' . $f['ref'] . '") ON DELETE ' . $f['onDelete'];
        }
        $stmts = ["CREATE TABLE IF NOT EXISTS \"{$this->table}\" (\n" . implode(",\n", $lines) . "\n)"];
        foreach ($this->indexes as $i) {
            $stmts[] = 'CREATE INDEX IF NOT EXISTS "' . $i['name'] . '" ON "' . $this->table
                . '" ("' . implode('", "', $i['columns']) . '")';
        }
        return $stmts;
    }
}
