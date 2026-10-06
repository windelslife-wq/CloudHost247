<?php
/**
 * Domain Broker — database access layer.
 *
 * Inside WHMCS the PDO handle is borrowed from WHMCS' own Capsule connection
 * so the module participates in the host application's connection, credentials
 * and transaction scope — it never opens its own socket and never reads
 * credentials from source. Outside WHMCS (CLI tooling, the test suite) a PDO
 * handle can be injected directly.
 *
 * Every statement in the module goes through here and is parameter-bound;
 * identifiers (table / column names) are whitelisted through quoteIdentifier()
 * which rejects anything that is not a bare identifier, so there is no path
 * from user input into SQL text.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Db
{
    /** @var \PDO|null */
    protected static $pdo;

    /** @var string Optional table-name prefix (configurable, default none). */
    protected static $prefix = '';

    /** @var string mysql|sqlite */
    protected static $driver = 'mysql';

    /** @var int */
    protected static $transactionDepth = 0;

    /**
     * Logical table names. The module owns these tables exclusively; WHMCS core
     * tables are always addressed through the Integration layer, never here.
     */
    const TABLES = [
        'requests'     => 'domain_broker_requests',
        'offers'       => 'domain_broker_offers',
        'negotiations' => 'domain_broker_negotiations',
        'messages'     => 'domain_broker_messages',
        'assignments'  => 'domain_broker_assignments',
        'payments'     => 'domain_broker_payments',
        'transfers'    => 'domain_broker_transfers',
        'documents'    => 'domain_broker_documents',
        'activity'     => 'domain_broker_activity_logs',
        'disputes'     => 'domain_broker_disputes',
        'fees'         => 'domain_broker_fees',
        'settings'     => 'domain_broker_settings',
        'brokers'      => 'domain_broker_brokers',
        'roles'        => 'domain_broker_role_permissions',
        'contacts'     => 'domain_broker_owner_contacts',
        'verifications' => 'domain_broker_verifications',
        'notifications' => 'domain_broker_notifications',
        'idempotency'  => 'domain_broker_idempotency_keys',
        'ratelimits'   => 'domain_broker_rate_limits',
        'risk'         => 'domain_broker_risk_flags',
        'webhooks'     => 'domain_broker_webhook_events',
        'tokens'       => 'domain_broker_api_tokens',
        'migrations'   => 'domain_broker_migrations',
    ];

    /**
     * Bind an explicit PDO handle (tests, CLI, or a host app that already has one).
     */
    public static function setPdo(\PDO $pdo, $prefix = '')
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
        self::$prefix = (string) $prefix;
        self::$driver = strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::$transactionDepth = 0;
    }

    /** Release the handle (used between test cases). */
    public static function reset()
    {
        self::$pdo = null;
        self::$prefix = '';
        self::$driver = 'mysql';
        self::$transactionDepth = 0;
    }

    public static function isBound()
    {
        return self::$pdo instanceof \PDO;
    }

    /**
     * @return \PDO
     * @throws ConfigurationException
     */
    public static function pdo()
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        if (class_exists('\WHMCS\Database\Capsule')) {
            /** @var \PDO $pdo */
            $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
            self::setPdo($pdo, '');
            return self::$pdo;
        }
        if (class_exists('\Illuminate\Database\Capsule\Manager')) {
            $pdo = \Illuminate\Database\Capsule\Manager::connection()->getPdo();
            self::setPdo($pdo, '');
            return self::$pdo;
        }

        throw new ConfigurationException('Domain Broker: no database connection is available.');
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

    /**
     * Resolve a logical table key (or a literal table name) to its real name.
     */
    public static function table($key)
    {
        $name = isset(self::TABLES[$key]) ? self::TABLES[$key] : $key;
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $name)) {
            throw new DomainBrokerException('Illegal table identifier.');
        }
        return self::$prefix . $name;
    }

    /**
     * Quote an identifier after validating it is a bare identifier.
     */
    public static function quoteIdentifier($identifier)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $identifier)) {
            throw new DomainBrokerException('Illegal SQL identifier: ' . substr((string) $identifier, 0, 40));
        }
        return self::isSqlite() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    /**
     * Run an arbitrary parameterised statement.
     *
     * @return \PDOStatement
     */
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

    /** Execute raw DDL (migrations only — never receives user input). */
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

    /**
     * Insert a row and return the new primary key.
     */
    public static function insert($table, array $data)
    {
        $real = self::table($table);
        $cols = [];
        $place = [];
        $vals = [];
        foreach ($data as $col => $value) {
            $cols[] = self::quoteIdentifier($col);
            $place[] = '?';
            $vals[] = $value;
        }
        $sql = 'INSERT INTO ' . self::quoteIdentifier($real)
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $place) . ')';
        self::run($sql, $vals);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Update rows matching an equality where-map. Returns affected row count.
     */
    public static function update($table, array $data, array $where)
    {
        if (!$data) {
            return 0;
        }
        $real = self::table($table);
        $set = [];
        $vals = [];
        foreach ($data as $col => $value) {
            $set[] = self::quoteIdentifier($col) . ' = ?';
            $vals[] = $value;
        }
        list($clause, $whereVals) = self::buildWhere($where);
        $sql = 'UPDATE ' . self::quoteIdentifier($real) . ' SET ' . implode(', ', $set) . $clause;
        return self::run($sql, array_merge($vals, $whereVals))->rowCount();
    }

    /**
     * Hard delete. Deliberately NOT used for financial / negotiation history —
     * those tables are soft-deleted or append-only (see Migrator + services).
     */
    public static function delete($table, array $where)
    {
        $real = self::table($table);
        list($clause, $vals) = self::buildWhere($where);
        if ($clause === '') {
            throw new DomainBrokerException('Refusing to delete without a where clause.');
        }
        return self::run('DELETE FROM ' . self::quoteIdentifier($real) . $clause, $vals)->rowCount();
    }

    /**
     * Fetch rows by an equality where-map.
     *
     * @param array  $where  column => value | [column, operator, value]
     * @param array  $opts   order (col), dir (asc|desc), limit, offset
     */
    public static function fetch($table, array $where = [], array $opts = [])
    {
        $real = self::table($table);
        list($clause, $vals) = self::buildWhere($where);
        $sql = 'SELECT * FROM ' . self::quoteIdentifier($real) . $clause;
        $sql .= self::buildOrderLimit($opts);
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
        $real = self::table($table);
        list($clause, $vals) = self::buildWhere($where);
        return (int) self::scalar('SELECT COUNT(*) FROM ' . self::quoteIdentifier($real) . $clause, $vals);
    }

    public static function sum($table, $column, array $where = [])
    {
        $real = self::table($table);
        list($clause, $vals) = self::buildWhere($where);
        $sql = 'SELECT COALESCE(SUM(' . self::quoteIdentifier($column) . '), 0) FROM '
            . self::quoteIdentifier($real) . $clause;
        return (int) self::scalar($sql, $vals);
    }

    /**
     * Build a WHERE clause from a map.
     *
     * Supported value forms:
     *   'col' => 'value'                       → col = ?
     *   'col' => null                          → col IS NULL
     *   'col' => ['in', [a, b]]                → col IN (?, ?)
     *   'col' => ['notin', [a, b]]
     *   'col' => ['>=', $v] / ['<=',...] etc.
     *   'col' => ['notnull']                   → col IS NOT NULL
     *   'col' => ['null']                      → col IS NULL
     *   'col' => ['like', 'abc%']
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
                            // IN () is invalid SQL; an empty set matches nothing.
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
                        throw new DomainBrokerException('Unsupported SQL operator.');
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
            $limit = max(0, (int) $opts['limit']);
            $sql .= ' LIMIT ' . $limit;
            if (isset($opts['offset'])) {
                $sql .= ' OFFSET ' . max(0, (int) $opts['offset']);
            }
        }
        return $sql;
    }

    /** Nested-safe transaction helper. */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $top = (self::$transactionDepth === 0);
        if ($top) {
            if ($pdo->inTransaction()) {
                // Host application already owns a transaction; join it.
                $top = false;
            } else {
                $pdo->beginTransaction();
            }
        }
        self::$transactionDepth++;
        try {
            $result = $fn();
            self::$transactionDepth--;
            if ($top && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            self::$transactionDepth--;
            if ($top && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True when the named table exists. */
    public static function tableExists($table)
    {
        $real = self::table($table);
        try {
            if (self::isSqlite()) {
                $row = self::selectOne(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                    [$real]
                );
                return $row !== null;
            }
            self::run('SELECT 1 FROM ' . self::quoteIdentifier($real) . ' LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
