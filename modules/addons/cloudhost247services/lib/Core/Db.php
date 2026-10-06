<?php
/**
 * Module table access layer.
 *
 * - Every module table lives under the mod_chs_ prefix and is addressed by its
 *   logical name: Db::t('auctions') === 'mod_chs_auctions'.
 * - All values are bound; identifiers are regex-validated before touching SQL,
 *   and obvious abuse (unknown module table, WHERE-less DELETE) throws.
 * - Production driver comes from WHMCS Capsule; tests inject SQLite.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

use PDO;

class Db
{
    private const PREFIX = 'mod_chs_';
    private const IDENT  = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var PDO|null */
    private static $pdo;
    /** @var string 'mysql'|'sqlite' */
    private static $driver = 'mysql';
    /** @var array<string,bool> table existence, memoised per request */
    private static $exists = [];

    /** Test seam: inject a PDO + driver name. */
    public static function setPdo(PDO $pdo, $driver = null)
    {
        self::$pdo = $pdo;
        self::$driver = $driver !== null
            ? strtolower($driver)
            : strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$exists = [];
    }

    /** Drop the injected connection (test reset). */
    public static function reset()
    {
        self::$pdo = null;
        self::$driver = 'mysql';
        self::$exists = [];
    }

    public static function pdo()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (class_exists('WHMCS\\Database\\Capsule')) {
            self::setPdo(\WHMCS\Database\Capsule::connection()->getPdo());
            return self::$pdo;
        }
        throw new ChsException('No database connection available (not in WHMCS context and no PDO injected).');
    }

    public static function driver()
    {
        self::pdo();
        return self::$driver;
    }

    /** Physical table name for a logical module table. */
    public static function t($logical)
    {
        self::assertIdentifier($logical, 'table');
        return self::PREFIX . $logical;
    }

    public static function tableExists($logical)
    {
        $logical = (string) $logical;
        if (isset(self::$exists[$logical])) {
            return self::$exists[$logical];
        }
        $table = self::t($logical);
        if (self::driver() === 'sqlite') {
            $rows = self::unsafeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        } else {
            $rows = self::unsafeQuery(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        }
        // Only memoise the affirmative: a table may be created later in the
        // same request (the Migrator does exactly that for its own ledger).
        if ($rows !== []) {
            self::$exists[$logical] = true;
            return true;
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all($logical, array $where = [], $orderBy = '', $limit = 0, $offset = 0)
    {
        list($sql, $bind) = self::compile($logical, $where);
        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . self::safeOrder($orderBy);
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }
        return self::unsafeQuery($sql, $bind);
    }

    /** @return array<string,mixed>|null */
    public static function first($logical, array $where = [], $orderBy = '')
    {
        $rows = self::all($logical, $where, $orderBy, 1);
        return $rows === [] ? null : $rows[0];
    }

    public static function value($logical, $column, array $where)
    {
        self::assertIdentifier($column, 'column');
        $row = self::first($logical, $where);
        return $row === null || !array_key_exists($column, $row) ? null : $row[$column];
    }

    public static function count($logical, array $where = [])
    {
        list($whereSql, $bind) = self::where($where);
        $rows = self::unsafeQuery(
            'SELECT COUNT(*) AS c FROM ' . self::table($logical) . ($whereSql !== '' ? ' WHERE ' . $whereSql : ''),
            $bind
        );
        return (int) $rows[0]['c'];
    }

    /** @return int last insert id (0 when the table has no autoincrement) */
    public static function insert($logical, array $data)
    {
        self::table($logical);
        $columns = [];
        foreach ($data as $column => $value) {
            self::assertIdentifier($column, 'column');
            $columns[] = $column;
        }
        $sql = 'INSERT INTO ' . self::t($logical)
            . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::unsafeExec($sql, array_values($data));
        try {
            return (int) self::pdo()->lastInsertId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** INSERT IGNORE semantics on both drivers. */
    public static function insertOrIgnore($logical, array $data)
    {
        self::table($logical);
        $columns = [];
        foreach ($data as $column => $value) {
            self::assertIdentifier($column, 'column');
            $columns[] = $column;
        }
        $verb = self::driver() === 'sqlite' ? 'INSERT OR IGNORE INTO ' : 'INSERT IGNORE INTO ';
        $sql = $verb . self::t($logical)
            . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        return self::unsafeExec($sql, array_values($data)) > 0;
    }

    /** @return int affected */
    public static function update($logical, array $where, array $set)
    {
        self::table($logical);
        if ($set === []) {
            return 0;
        }
        $setSql = [];
        $bind = [];
        foreach ($set as $column => $value) {
            self::assertIdentifier($column, 'column');
            $setSql[] = $column . ' = ?';
            $bind[] = $value;
        }
        list($whereSql, $whereBind) = self::where($where);
        if ($whereSql === '') {
            throw new ChsException('Refusing WHERE-less UPDATE on ' . $logical);
        }
        return self::unsafeExec(
            'UPDATE ' . self::t($logical) . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $whereSql,
            array_merge($bind, $whereBind)
        );
    }

    /** @return int affected */
    public static function delete($logical, array $where)
    {
        self::table($logical);
        list($whereSql, $bind) = self::where($where);
        if ($whereSql === '') {
            throw new ChsException('Refusing WHERE-less DELETE on ' . $logical);
        }
        return self::unsafeExec('DELETE FROM ' . self::t($logical) . ' WHERE ' . $whereSql, $bind);
    }

    /** Raw statement with bound values; returns affected row count. */
    public static function exec($sql, array $bind = [])
    {
        return self::unsafeExec($sql, $bind);
    }

    /** Raw SELECT with bound values. */
    public static function query($sql, array $bind = [])
    {
        return self::unsafeQuery($sql, $bind);
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            // Nested call: the outermost transaction owns commit/rollback;
            // an exception here still bubbles up and aborts the whole unit.
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------ internals - */

    /** Validate the table is a known module table; throws for anything else. */
    private static function table($logical)
    {
        $logical = (string) $logical;
        self::assertIdentifier($logical, 'table');
        if (!self::tableExists($logical)) {
            throw new ChsException('Unknown module table: ' . $logical);
        }
        return self::t($logical);
    }

    private static function assertIdentifier($identifier, $what)
    {
        if (!is_string($identifier) || !preg_match(self::IDENT, $identifier)) {
            throw new ChsException('Unsafe ' . $what . ' identifier: "' . (string) $identifier . '"');
        }
    }

    /** ORDER BY whitelist: `col [ASC|DESC], col2 [ASC|DESC]…` (optionally dot-prefixed). */
    private static function safeOrder($orderBy)
    {
        $orderBy = trim((string) $orderBy);
        if (!preg_match('/^[A-Za-z0-9_.]+( (ASC|DESC))?(, *[A-Za-z0-9_.]+( (ASC|DESC))?)*$/i', $orderBy)) {
            throw new ChsException('Unsafe ORDER BY: "' . $orderBy . '"');
        }
        return $orderBy;
    }

    /** @return array{0:string,1:array} */
    private static function compile($logical, array $where)
    {
        self::table($logical);
        list($whereSql, $bind) = self::where($where);
        return ['SELECT * FROM ' . self::t($logical) . ($whereSql !== '' ? ' WHERE ' . $whereSql : ''), $bind];
    }

    /** @return array{0:string,1:array} */
    private static function where(array $where)
    {
        $parts = [];
        $bind = [];
        foreach ($where as $column => $value) {
            self::assertIdentifier($column, 'column');
            if (is_array($value)) {
                if ($value === []) {
                    $parts[] = '1 = 0';
                    continue;
                }
                $parts[] = $column . ' IN (' . implode(',', array_fill(0, count($value), '?')) . ')';
                foreach ($value as $v) {
                    $bind[] = $v;
                }
            } elseif ($value === null) {
                $parts[] = $column . ' IS NULL';
            } else {
                $parts[] = $column . ' = ?';
                $bind[] = $value;
            }
        }
        return [implode(' AND ', $parts), $bind];
    }

    private static function unsafeQuery($sql, array $bind)
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function unsafeExec($sql, array $bind)
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->rowCount();
    }
}
