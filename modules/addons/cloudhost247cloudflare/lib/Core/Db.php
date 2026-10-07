<?php
namespace CloudHost247\Cloudflare\Core;

use PDO;

/** Parameterized access to this addon's tables and read-only WHMCS tables. */
class Db
{
    const PREFIX = 'mod_ch247cf_';
    private static $pdo;
    private static $driver = 'mysql';

    public static function setPdo(PDO $pdo, $driver = null)
    {
        self::$pdo = $pdo;
        self::$driver = $driver ? strtolower((string) $driver) : strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    public static function reset() { self::$pdo = null; self::$driver = 'mysql'; }
    public static function pdo()
    {
        if (self::$pdo !== null) return self::$pdo;
        if (class_exists('WHMCS\\Database\\Capsule')) {
            self::setPdo(\WHMCS\Database\Capsule::connection()->getPdo());
            return self::$pdo;
        }
        throw new ConfigurationException('The Cloudflare integration database is unavailable.');
    }
    public static function driver() { self::pdo(); return self::$driver; }
    public static function table($logical)
    {
        self::assertIdentifier($logical);
        return self::PREFIX . $logical;
    }
    public static function whmcsTable($name)
    {
        self::assertIdentifier($name);
        return $name;
    }
    public static function query($sql, array $bind = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($bind));
        return $stmt->fetchAll();
    }
    public static function firstQuery($sql, array $bind = [])
    {
        $rows = self::query($sql, $bind);
        return $rows ? $rows[0] : null;
    }
    public static function exec($sql, array $bind = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($bind));
        return $stmt->rowCount();
    }
    public static function insert($logical, array $data)
    {
        if (!$data) throw new \InvalidArgumentException('Cannot insert an empty row.');
        $table = self::table($logical);
        $cols = [];
        foreach ($data as $col => $unused) { self::assertIdentifier($col); $cols[] = '`' . $col . '`'; }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }
    public static function update($logical, array $where, array $data)
    {
        if (!$where || !$data) throw new \InvalidArgumentException('A scoped update is required.');
        $table = self::table($logical);
        $sets = [];
        foreach ($data as $col => $unused) { self::assertIdentifier($col); $sets[] = '`' . $col . '` = ?'; }
        $conditions = [];
        foreach ($where as $col => $value) {
            self::assertIdentifier($col);
            $conditions[] = $value === null ? '`' . $col . '` IS NULL' : '`' . $col . '` = ?';
        }
        $bind = array_values($data);
        foreach ($where as $value) { if ($value !== null) $bind[] = $value; }
        return self::exec('UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conditions), $bind);
    }
    public static function delete($logical, array $where)
    {
        if (!$where) throw new \InvalidArgumentException('A scoped delete is required.');
        $table = self::table($logical);
        $conditions = [];
        $bind = [];
        foreach ($where as $col => $value) {
            self::assertIdentifier($col);
            if ($value === null) $conditions[] = '`' . $col . '` IS NULL';
            else { $conditions[] = '`' . $col . '` = ?'; $bind[] = $value; }
        }
        return self::exec('DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $conditions), $bind);
    }
    public static function first($logical, array $where = [], $orderBy = 'id DESC')
    {
        $rows = self::all($logical, $where, $orderBy, 1);
        return $rows ? $rows[0] : null;
    }
    public static function all($logical, array $where = [], $orderBy = 'id DESC', $limit = 0)
    {
        $table = self::table($logical);
        $sql = 'SELECT * FROM `' . $table . '`';
        $bind = [];
        if ($where) {
            $conditions = [];
            foreach ($where as $col => $value) {
                self::assertIdentifier($col);
                if ($value === null) $conditions[] = '`' . $col . '` IS NULL';
                else { $conditions[] = '`' . $col . '` = ?'; $bind[] = $value; }
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        if ($orderBy !== '') {
            if (!preg_match('/^[A-Za-z0-9_`,. ]+( ASC| DESC)?$/i', $orderBy) || strpos($orderBy, ';') !== false) throw new \InvalidArgumentException('Unsafe ordering.');
            $sql .= ' ORDER BY ' . $orderBy;
        }
        if ((int) $limit > 0) $sql .= ' LIMIT ' . (int) $limit;
        return self::query($sql, $bind);
    }
    public static function count($logical, array $where = [])
    {
        $table = self::table($logical);
        $sql = 'SELECT COUNT(*) AS c FROM `' . $table . '`';
        $bind = [];
        if ($where) {
            $parts = [];
            foreach ($where as $col => $value) {
                self::assertIdentifier($col);
                if ($value === null) $parts[] = '`' . $col . '` IS NULL';
                else { $parts[] = '`' . $col . '` = ?'; $bind[] = $value; }
            }
            $sql .= ' WHERE ' . implode(' AND ', $parts);
        }
        $row = self::firstQuery($sql, $bind);
        return (int) ($row['c'] ?? 0);
    }
    public static function tableExists($logical)
    {
        $table = self::table($logical);
        if (self::driver() === 'sqlite') return self::firstQuery("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", [$table]) !== null;
        return self::firstQuery('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) !== null;
    }
    private static function assertIdentifier($identifier)
    {
        if (!is_string($identifier) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) throw new \InvalidArgumentException('Invalid database identifier.');
    }
}
