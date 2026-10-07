<?php
/**
 * CloudHost247 App Cloud — database access layer.
 *
 * Inside WHMCS the PDO handle is borrowed from WHMCS' own Capsule connection, so
 * the module participates in the host application's connection, credentials and
 * transaction scope: it never opens a second database system and never reads
 * credentials from source. Outside WHMCS (CLI worker, agent tooling, the test
 * suite) a PDO handle is injected directly.
 *
 * Every statement goes through here and is parameter-bound. Identifiers are
 * regex-validated, WHERE-less UPDATE/DELETE throws, and module tables are
 * addressed by logical name and resolved through one prefix — WHMCS core tables
 * are never touched from this class (that is the Integration layer's job).
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Db
{
    const PREFIX = 'mod_ch247apps_';
    const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var \PDO|null */
    private static $pdo;

    /** @var string mysql|sqlite */
    private static $driver = 'mysql';

    /** @var array<string,bool> tableExists memo */
    private static $exists = [];

    /** @var int nested transaction depth */
    private static $depth = 0;

    /** Bind an explicit handle (tests, CLI, agent tooling). */
    public static function setPdo(\PDO $pdo, $driver = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
        self::$driver = $driver !== null
            ? strtolower((string) $driver)
            : strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::$exists = [];
        self::$depth = 0;
    }

    public static function reset()
    {
        self::$pdo = null;
        self::$driver = 'mysql';
        self::$exists = [];
        self::$depth = 0;
    }

    public static function isBound()
    {
        return self::$pdo instanceof \PDO;
    }

    /** @return \PDO */
    public static function pdo()
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            self::setPdo(\WHMCS\Database\Capsule::connection()->getPdo());
            return self::$pdo;
        }
        if (class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
            self::setPdo(\Illuminate\Database\Capsule\Manager::connection()->getPdo());
            return self::$pdo;
        }
        throw new ConfigurationException('App Cloud: no database connection is available.');
    }

    public static function driver()
    {
        self::pdo();
        return self::$driver;
    }

    public static function isSqlite()
    {
        return self::driver() === 'sqlite';
    }

    /** Logical name → real (prefixed) table name. */
    public static function t($logical)
    {
        $logical = (string) $logical;
        if (!preg_match(self::IDENT, $logical)) {
            throw new AppsException('Illegal table identifier: ' . substr($logical, 0, 40));
        }
        return self::PREFIX . $logical;
    }

    public static function quoteIdentifier($identifier)
    {
        $identifier = (string) $identifier;
        if (!preg_match(self::IDENT, $identifier)) {
            throw new AppsException('Illegal SQL identifier: ' . substr($identifier, 0, 40));
        }
        return self::isSqlite() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    /* ------------------------------------------------------------ queries -- */

    /** @return \PDOStatement */
    public static function run($sql, array $bindings = [])
    {
        $stmt = self::pdo()->prepare($sql);
        foreach (array_values($bindings) as $i => $value) {
            $param = $i + 1;
            if (is_int($value)) {
                $stmt->bindValue($param, $value, \PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value ? 1 : 0, \PDO::PARAM_INT);
            } elseif (is_null($value)) {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($param, (string) $value, \PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    /** Raw DDL — migrations only, never user input. */
    public static function exec($sql)
    {
        return self::pdo()->exec($sql);
    }

    public static function select($sql, array $bindings = [])
    {
        return self::run($sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function selectOne($sql, array $bindings = [])
    {
        $row = self::run($sql, $bindings)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function scalar($sql, array $bindings = [])
    {
        $row = self::run($sql, $bindings)->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** Insert a row, return the new primary key. */
    public static function insert($table, array $data)
    {
        $real = self::quoteIdentifier(self::t($table));
        $cols = [];
        $place = [];
        $vals = [];
        foreach ($data as $col => $value) {
            $cols[] = self::quoteIdentifier($col);
            $place[] = '?';
            $vals[] = $value;
        }
        self::run('INSERT INTO ' . $real . ' (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', $place) . ')', $vals);
        return (int) self::pdo()->lastInsertId();
    }

    /** Update rows matching an equality where-map. Returns affected rows. */
    public static function update($table, array $data, array $where)
    {
        if (!$data) {
            return 0;
        }
        $set = [];
        $vals = [];
        foreach ($data as $col => $value) {
            $set[] = self::quoteIdentifier($col) . ' = ?';
            $vals[] = $value;
        }
        list($clause, $whereVals) = self::buildWhere($where);
        if ($clause === '') {
            throw new AppsException('Refusing to UPDATE without a WHERE clause.');
        }
        $sql = 'UPDATE ' . self::quoteIdentifier(self::t($table)) . ' SET ' . implode(', ', $set) . $clause;
        return self::run($sql, array_merge($vals, $whereVals))->rowCount();
    }

    /**
     * Conditional update: only applies when the row still matches $where.
     * Returns true when this caller won the race — the primitive every state
     * transition and queue lease in the platform is built on.
     */
    public static function compareAndSet($table, array $data, array $where)
    {
        return self::update($table, $data, $where) > 0;
    }

    public static function delete($table, array $where)
    {
        list($clause, $vals) = self::buildWhere($where);
        if ($clause === '') {
            throw new AppsException('Refusing to DELETE without a WHERE clause.');
        }
        return self::run('DELETE FROM ' . self::quoteIdentifier(self::t($table)) . $clause, $vals)->rowCount();
    }

    /**
     * @param array $where column => value | [operator, value] | null
     * @param array $opts  order, dir, order2, dir2, limit, offset, columns
     */
    public static function fetch($table, array $where = [], array $opts = [])
    {
        $columns = '*';
        if (!empty($opts['columns']) && is_array($opts['columns'])) {
            $columns = implode(', ', array_map([__CLASS__, 'quoteIdentifier'], $opts['columns']));
        }
        list($clause, $vals) = self::buildWhere($where);
        $sql = 'SELECT ' . $columns . ' FROM ' . self::quoteIdentifier(self::t($table)) . $clause
            . self::buildOrderLimit($opts);
        return self::select($sql, $vals);
    }

    public static function first($table, array $where = [], array $opts = [])
    {
        $opts['limit'] = 1;
        $rows = self::fetch($table, $where, $opts);
        return $rows ? $rows[0] : null;
    }

    public static function count($table, array $where = [])
    {
        list($clause, $vals) = self::buildWhere($where);
        return (int) self::scalar(
            'SELECT COUNT(*) FROM ' . self::quoteIdentifier(self::t($table)) . $clause,
            $vals
        );
    }

    public static function sum($table, $column, array $where = [])
    {
        list($clause, $vals) = self::buildWhere($where);
        return (int) self::scalar(
            'SELECT COALESCE(SUM(' . self::quoteIdentifier($column) . '), 0) FROM '
            . self::quoteIdentifier(self::t($table)) . $clause,
            $vals
        );
    }

    public static function max($table, $column, array $where = [])
    {
        list($clause, $vals) = self::buildWhere($where);
        return self::scalar(
            'SELECT MAX(' . self::quoteIdentifier($column) . ') FROM '
            . self::quoteIdentifier(self::t($table)) . $clause,
            $vals
        );
    }

    /**
     * WHERE clause from a map. Supported value forms:
     *   'col' => 'v'            → col = ?
     *   'col' => null           → col IS NULL
     *   'col' => ['in', [...]]  → col IN (…)      (empty set matches nothing)
     *   'col' => ['notin',[…]]  → col NOT IN (…)
     *   'col' => ['like', 'x%']
     *   'col' => ['>=', 5] / '<=' / '>' / '<' / '=' / '!='
     *   'col' => ['null'] / ['notnull']
     *
     * @return array [clause, bindings]
     */
    public static function buildWhere(array $where)
    {
        if (!$where) {
            return ['', []];
        }
        $parts = [];
        $vals = [];
        foreach ($where as $col => $value) {
            $q = self::quoteIdentifier($col);
            if (is_null($value)) {
                $parts[] = $q . ' IS NULL';
                continue;
            }
            if (is_array($value)) {
                $op = strtolower((string) (isset($value[0]) ? $value[0] : '='));
                switch ($op) {
                    case 'in':
                    case 'notin':
                        $list = isset($value[1]) && is_array($value[1]) ? array_values($value[1]) : [];
                        if (!$list) {
                            $parts[] = ($op === 'in') ? '1 = 0' : '1 = 1';
                            break;
                        }
                        $parts[] = $q . ($op === 'in' ? ' IN (' : ' NOT IN (')
                            . implode(', ', array_fill(0, count($list), '?')) . ')';
                        foreach ($list as $v) {
                            $vals[] = $v;
                        }
                        break;
                    case 'null':
                        $parts[] = $q . ' IS NULL';
                        break;
                    case 'notnull':
                        $parts[] = $q . ' IS NOT NULL';
                        break;
                    case 'range':
                        $parts[] = $q . ' BETWEEN ? AND ?';
                        $vals[] = isset($value[1]) ? $value[1] : null;
                        $vals[] = isset($value[2]) ? $value[2] : null;
                        break;
                    case 'like':
                        $parts[] = $q . ' LIKE ?';
                        $vals[] = isset($value[1]) ? $value[1] : '';
                        break;
                    case '=':
                    case '!=':
                    case '<>':
                    case '>':
                    case '>=':
                    case '<':
                    case '<=':
                        $parts[] = $q . ' ' . $op . ' ?';
                        $vals[] = isset($value[1]) ? $value[1] : null;
                        break;
                    default:
                        throw new AppsException('Unsupported SQL operator: ' . $op);
                }
                continue;
            }
            $parts[] = $q . ' = ?';
            $vals[] = $value;
        }
        return [' WHERE ' . implode(' AND ', $parts), $vals];
    }

    public static function buildOrderLimit(array $opts)
    {
        $sql = '';
        if (!empty($opts['order'])) {
            $dir = (isset($opts['dir']) && strtolower($opts['dir']) === 'desc') ? 'DESC' : 'ASC';
            $sql .= ' ORDER BY ' . self::quoteIdentifier($opts['order']) . ' ' . $dir;
            if (!empty($opts['order2'])) {
                $dir2 = (isset($opts['dir2']) && strtolower($opts['dir2']) === 'desc') ? 'DESC' : 'ASC';
                $sql .= ', ' . self::quoteIdentifier($opts['order2']) . ' ' . $dir2;
            }
        }
        if (isset($opts['limit'])) {
            $sql .= ' LIMIT ' . max(0, (int) $opts['limit']);
            if (isset($opts['offset'])) {
                $sql .= ' OFFSET ' . max(0, (int) $opts['offset']);
            }
        }
        return $sql;
    }

    /** Nested-safe transaction. Joins a transaction the host already owns. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $owns = (self::$depth === 0) && !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        self::$depth++;
        try {
            $result = $fn();
            self::$depth--;
            if ($owns && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            self::$depth--;
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function tableExists($table)
    {
        $real = self::t($table);
        if (isset(self::$exists[$real])) {
            return self::$exists[$real];
        }
        try {
            if (self::isSqlite()) {
                $row = self::selectOne(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                    [$real]
                );
                return self::$exists[$real] = ($row !== null);
            }
            self::run('SELECT 1 FROM ' . self::quoteIdentifier($real) . ' LIMIT 1');
            return self::$exists[$real] = true;
        } catch (\Throwable $e) {
            return self::$exists[$real] = false;
        }
    }

    /** Forget memoised existence checks (after a migration runs). */
    public static function flushExistsCache()
    {
        self::$exists = [];
    }
}
