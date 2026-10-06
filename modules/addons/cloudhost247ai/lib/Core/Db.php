<?php
/**
 * Module table access layer (ported from the proven Chs\Core\Db shape).
 *
 * - Module tables live under mod_ch247ai_ and are addressed by logical name.
 * - Values are always bound; identifiers are regex-validated; WHERE-less
 *   UPDATE/DELETE throws.
 * - Production driver comes from WHMCS Capsule; tests inject SQLite.
 */

namespace Ch247Ai\Core;

use PDO;

class Db
{
    private const PREFIX = 'mod_ch247ai_';
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var PDO|null */
    private static $pdo;
    /** @var string */
    private static $driver = 'mysql';
    /** @var array<string,bool> */
    private static $exists = [];

    public static function setPdo(PDO $pdo, $driver = null)
    {
        self::$pdo = $pdo;
        self::$driver = $driver !== null ? strtolower($driver) : strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$exists = [];
    }
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
        throw new Ch247AiException('No database connection available (not in WHMCS context and no PDO injected).');
    }
    public static function driver()
    {
        self::pdo();
        return self::$driver;
    }
    public static function t($logical)
    {
        self::assertIdentifier($logical, 'table');
        return self::PREFIX . $logical;
    }
    /** Physical name of a WHMCS core table (never prefixed). */
    public static function whmcs($table)
    {
        self::assertIdentifier($table, 'table');
        return $table;
    }
    public static function tableExists($logical)
    {
        $logical = (string) $logical;
        if (isset(self::$exists[$logical])) {
            return self::$exists[$logical];
        }
        $table = self::PREFIX . $logical;
        if (self::driver() === 'sqlite') {
            $rows = self::unsafeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        } else {
            $rows = self::unsafeQuery('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
        }
        if ($rows !== []) {
            self::$exists[$logical] = true;
            return true;
        }
        return false;
    }
    /** Does a WHMCS core table exist (fail-closed data-source checks)? */
    public static function whmcsTableExists($table)
    {
        $table = self::whmcs($table);
        if (self::driver() === 'sqlite') {
            $rows = self::unsafeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        } else {
            $rows = self::unsafeQuery('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]);
        }
        return $rows !== [];
    }

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
    public static function first($logical, array $where = [], $orderBy = 'id DESC')
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
        $rows = self::unsafeQuery('SELECT COUNT(*) AS c FROM ' . self::t($logical) . ($whereSql !== '' ? ' WHERE ' . $whereSql : ''), $bind);
        return (int) $rows[0]['c'];
    }
    public static function insert($logical, array $data)
    {
        $columns = [];
        foreach ($data as $column => $value) {
            self::assertIdentifier($column, 'column');
            $columns[] = $column;
        }
        $sql = 'INSERT INTO ' . self::t($logical) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::unsafeExec($sql, array_values($data));
        try {
            return (int) self::pdo()->lastInsertId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
    public static function update($logical, array $where, array $set)
    {
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
            throw new Ch247AiException('Refusing WHERE-less UPDATE on ' . $logical);
        }
        return self::unsafeExec('UPDATE ' . self::t($logical) . ' SET ' . implode(', ', $setSql) . ' WHERE ' . $whereSql, array_merge($bind, $whereBind));
    }
    public static function delete($logical, array $where)
    {
        list($whereSql, $bind) = self::where($where);
        if ($whereSql === '') {
            throw new Ch247AiException('Refusing WHERE-less DELETE on ' . $logical);
        }
        return self::unsafeExec('DELETE FROM ' . self::t($logical) . ' WHERE ' . $whereSql, $bind);
    }
    public static function exec($sql, array $bind = [])
    {
        return self::unsafeExec($sql, $bind);
    }
    public static function query($sql, array $bind = [])
    {
        return self::unsafeQuery($sql, $bind);
    }

    /** Compile a logical-table SELECT. */
    protected static function compile($logical, array $where)
    {
        list($whereSql, $bind) = self::where($where);
        return ['SELECT * FROM ' . self::t($logical) . ($whereSql !== '' ? ' WHERE ' . $whereSql : ''), $bind];
    }
    protected static function where(array $where)
    {
        $parts = [];
        $bind = [];
        foreach ($where as $column => $constraint) {
            if ($column === 'or') {
                continue; // not used by this module
            }
            self::assertIdentifier($column, 'column');
            if (is_array($constraint) && count($constraint) === 2 && $constraint[0] === 'in') {
                $values = array_values($constraint[1]);
                if ($values === []) {
                    $parts[] = '0 = 1';
                    continue;
                }
                $parts[] = $column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
                foreach ($values as $value) {
                    $bind[] = $value;
                }
                continue;
            }
            if (is_array($constraint) && count($constraint) === 2 && in_array(strtoupper($constraint[0]), ['>', '<', '>=', '<=', '!=', '='], true)) {
                $parts[] = $column . ' ' . strtoupper($constraint[0]) . ' ?';
                $bind[] = $constraint[1];
                continue;
            }
            if ($constraint === null) {
                $parts[] = $column . ' IS NULL';
                continue;
            }
            $parts[] = $column . ' = ?';
            $bind[] = $constraint;
        }
        return [implode(' AND ', $parts), $bind];
    }
    protected static function safeOrder($orderBy)
    {
        if (!preg_match('/^[A-Za-z0-9_,\s\(\)]+( ASC| DESC)?$/i', $orderBy) || stripos($orderBy, ';') !== false) {
            throw new Ch247AiException('Unsafe ORDER BY: ' . $orderBy);
        }
        return $orderBy;
    }
    protected static function assertIdentifier($name, $kind)
    {
        if (!is_string($name) || preg_match(self::IDENT, (string) $name) !== 1) {
            throw new Ch247AiException('Invalid ' . $kind . ' identifier.');
        }
    }
    protected static function unsafeQuery($sql, array $bind)
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }
    protected static function unsafeExec($sql, array $bind)
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }
}
