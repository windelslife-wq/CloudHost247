<?php
/**
 * Database Helper
 * Provides safe database operations with escaping
 */

namespace PhoneServices\Core;

class Database
{
    /**
     * Execute a safe query with parameters
     */
    public static function query($sql, $params = [])
    {
        foreach ($params as $key => $value) {
            $sql = str_replace(':' . $key, "'" . db_escape_string($value) . "'", $sql);
        }

        try {
            $result = full_query($sql);
        } catch (\Throwable $e) {
            Logger::error('Database query failed: ' . $sql . ' | Error: ' . $e->getMessage());
            return false;
        }

        return $result;
    }

    /**
     * Build a Capsule query with equality constraints
     */
    private static function tableQuery($table, $where = [])
    {
        $query = \WHMCS\Database\Capsule::table($table);
        foreach ((array) $where as $column => $value) {
            $query->where($column, $value);
        }
        return $query;
    }
    
    /**
     * Insert and return ID
     */
    public static function insert($table, $data)
    {
        $id = insert_query($table, $data);
        return $id;
    }
    
    /**
     * Update records
     */
    public static function update($table, $data, $where)
    {
        update_query($table, $data, $where);
    }
    
    /**
     * Select records
     */
    public static function select($table, $fields = '*', $where = [], $orderBy = 'id', $orderDir = 'ASC', $limit = null)
    {
        $query = self::tableQuery($table, $where)->orderBy($orderBy, strtoupper($orderDir) === 'DESC' ? 'desc' : 'asc');
        if ($limit !== null) {
            $query->limit((int) $limit);
        }
        $columns = is_array($fields) ? $fields : [$fields];
        return $query->get($columns)->map(function ($row) {
            return (array) $row;
        })->all();
    }
    
    /**
     * Get single row
     */
    public static function row($table, $fields = '*', $where = [])
    {
        $rows = self::select($table, $fields, $where, 'id', 'ASC', 1);
        return $rows ? $rows[0] : null;
    }
    
    /**
     * Count records
     */
    public static function count($table, $where = [])
    {
        return (int) self::tableQuery($table, $where)->count();
    }
    
    /**
     * Delete records
     */
    public static function delete($table, $where)
    {
        delete_query($table, $where);
    }
    
    /**
     * Begin transaction (if supported)
     */
    public static function beginTransaction()
    {
        full_query('START TRANSACTION');
    }
    
    /**
     * Commit transaction
     */
    public static function commit()
    {
        full_query('COMMIT');
    }
    
    /**
     * Rollback transaction
     */
    public static function rollback()
    {
        full_query('ROLLBACK');
    }
}
