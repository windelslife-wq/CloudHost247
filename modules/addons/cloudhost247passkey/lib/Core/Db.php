<?php
/**
 * Parameterized access to this addon's own tables.
 * WHMCS Capsule supplies production PDO; tests inject SQLite PDO.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

use PDO;

class Db
{
    const PREFIX = 'mod_ch247pk_';
    const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private static $pdo;
    private static $driver = 'mysql';
    private static $tableExists = [];

    public static function setPdo(PDO $pdo, $driver = null)
    {
        self::$pdo = $pdo;
        self::$driver = $driver !== null
            ? strtolower((string) $driver)
            : strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$tableExists = [];
    }

    public static function reset()
    {
        self::$pdo = null;
        self::$driver = 'mysql';
        self::$tableExists = [];
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
        throw new \RuntimeException('Passkey database connection is unavailable.');
    }

    public static function driver()
    {
        self::pdo();
        return self::$driver;
    }

    public static function table($logicalName)
    {
        self::assertIdentifier($logicalName);
        return self::PREFIX . $logicalName;
    }

    public static function quoteIdentifier($identifier)
    {
        self::assertIdentifier($identifier);
        return '`' . $identifier . '`';
    }

    public static function tableExists($logicalName)
    {
        $logicalName = (string) $logicalName;
        if (array_key_exists($logicalName, self::$tableExists)) {
            return self::$tableExists[$logicalName];
        }
        $table = self::table($logicalName);
        if (self::driver() === 'sqlite') {
            $rows = self::query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
        } else {
            $rows = self::query(
                'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            );
        }
        if ($rows) {
            self::$tableExists[$logicalName] = true;
            return true;
        }
        // Do not cache misses: a migration can create this table during a request.
        return false;
    }

    public static function columnExists($logicalName, $columnName)
    {
        $table = self::table($logicalName);
        self::assertIdentifier($columnName);
        if (self::driver() === 'sqlite') {
            foreach (self::query('PRAGMA table_info(' . self::quoteIdentifier($table) . ')') as $column) {
                if (isset($column['name']) && $column['name'] === $columnName) {
                    return true;
                }
            }
            return false;
        }
        return (bool) self::firstQuery(
            'SELECT column_name AS name FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $columnName]
        );
    }

    public static function query($sql, array $bindings = [])
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute(array_values($bindings));
        return $statement->fetchAll();
    }

    public static function firstQuery($sql, array $bindings = [])
    {
        $rows = self::query($sql, $bindings);
        return $rows ? $rows[0] : null;
    }

    public static function execute($sql, array $bindings = [])
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute(array_values($bindings));
        return $statement->rowCount();
    }

    public static function insert($logicalName, array $data)
    {
        if (!$data) {
            throw new \InvalidArgumentException('Cannot insert an empty Passkey row.');
        }
        $columns = [];
        foreach ($data as $column => $unused) {
            $columns[] = self::quoteIdentifier($column);
        }
        $sql = 'INSERT INTO ' . self::quoteIdentifier(self::table($logicalName))
            . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::execute($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** Insert defaults once without overwriting an administrator's settings. */
    public static function insertIgnore($logicalName, array $data)
    {
        if (!$data) {
            throw new \InvalidArgumentException('Cannot insert an empty Passkey row.');
        }
        $columns = [];
        foreach ($data as $column => $unused) {
            $columns[] = self::quoteIdentifier($column);
        }
        $verb = self::driver() === 'sqlite' ? 'INSERT OR IGNORE INTO ' : 'INSERT IGNORE INTO ';
        $sql = $verb . self::quoteIdentifier(self::table($logicalName))
            . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        return self::execute($sql, array_values($data)) > 0;
    }

    /** Updates must always have a non-empty parameterized scope. */
    public static function update($logicalName, array $where, array $values)
    {
        if (!$where) {
            throw new \InvalidArgumentException('Refusing an unscoped Passkey database update.');
        }
        if (!$values) {
            throw new \InvalidArgumentException('A Passkey update requires changed values.');
        }
        $setParts = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            $setParts[] = self::quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }
        $whereParts = [];
        foreach ($where as $column => $value) {
            $identifier = self::quoteIdentifier($column);
            if ($value === null) {
                $whereParts[] = $identifier . ' IS NULL';
            } else {
                $whereParts[] = $identifier . ' = ?';
                $bindings[] = $value;
            }
        }
        return self::execute(
            'UPDATE ' . self::quoteIdentifier(self::table($logicalName))
                . ' SET ' . implode(', ', $setParts) . ' WHERE ' . implode(' AND ', $whereParts),
            $bindings
        );
    }

    public static function count($logicalName, array $where = [])
    {
        $conditions = [];
        $bindings = [];
        foreach ($where as $column => $value) {
            $identifier = self::quoteIdentifier($column);
            if ($value === null) {
                $conditions[] = $identifier . ' IS NULL';
            } else {
                $conditions[] = $identifier . ' = ?';
                $bindings[] = $value;
            }
        }
        $sql = 'SELECT COUNT(*) AS c FROM ' . self::quoteIdentifier(self::table($logicalName))
            . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '');
        $row = self::firstQuery($sql, $bindings);
        return $row ? (int) $row['c'] : 0;
    }

    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return call_user_func($callback, $pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = call_user_func($callback, $pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertIdentifier($identifier)
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, (string) $identifier)) {
            throw new \InvalidArgumentException('Invalid Passkey SQL identifier.');
        }
    }
}
