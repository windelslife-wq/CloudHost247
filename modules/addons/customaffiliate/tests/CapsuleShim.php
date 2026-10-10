<?php
/**
 * In-memory stand-in for WHMCS\Database\Capsule, used only by the offline tests.
 * Supports the query-builder subset CommissionManager uses. Raw SQL expressions
 * are deliberately unsupported, so a test fails if production code reintroduces them.
 */
namespace WHMCS\Database;

class Capsule
{
    public static $tables = [];
    public static $seq = [];

    public static function reset()
    {
        self::$tables = [];
        self::$seq = [];
    }

    public static function table($name)
    {
        return new CapsuleQuery($name);
    }

    public static function raw($value)
    {
        throw new \RuntimeException('Raw SQL is not supported by the test shim: ' . $value);
    }
}

class CapsuleQuery
{
    private $table;
    private $conds = [];

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function where($col, $op = null, $val = null)
    {
        if (func_num_args() === 2) {
            $val = $op;
            $op = '=';
        }
        $this->conds[] = [$col, strtolower((string) $op), $val];
        return $this;
    }

    private function matches(array $row)
    {
        foreach ($this->conds as [$col, $op, $val]) {
            $rv = $row[$col] ?? null;
            if ($op === 'like') {
                $re = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string) $val, '/')) . '$/i';
                if (!preg_match($re, (string) $rv)) return false;
                continue;
            }
            if ($op === '=') {
                if ($rv === null || (string) $rv !== (string) $val) return false;
                continue;
            }
            throw new \RuntimeException('Unsupported operator in shim: ' . $op);
        }
        return true;
    }

    private function rows()
    {
        $out = [];
        foreach (Capsule::$tables[$this->table] ?? [] as $row) {
            if ($this->matches($row)) $out[] = $row;
        }
        return $out;
    }

    public function first()
    {
        $rows = $this->rows();
        return $rows ? (object) $rows[0] : null;
    }

    public function get()
    {
        return array_map(function ($r) { return (object) $r; }, $this->rows());
    }

    public function exists()
    {
        return count($this->rows()) > 0;
    }

    public function pluck($value, $key = null)
    {
        $out = [];
        foreach ($this->rows() as $r) {
            if ($key === null) $out[] = $r[$value] ?? null;
            else $out[$r[$key] ?? ''] = $r[$value] ?? null;
        }
        return new CapsuleCollection($out);
    }

    public function insert(array $row)
    {
        $id = (Capsule::$seq[$this->table] ?? 0) + 1;
        Capsule::$seq[$this->table] = $id;
        $row['id'] = $row['id'] ?? $id;
        Capsule::$tables[$this->table][] = $row;
        return true;
    }

    public function update(array $values)
    {
        $n = 0;
        foreach (Capsule::$tables[$this->table] ?? [] as $i => $row) {
            if ($this->matches($row)) {
                foreach ($values as $k => $v) Capsule::$tables[$this->table][$i][$k] = $v;
                $n++;
            }
        }
        return $n;
    }

    public function delete()
    {
        $before = count(Capsule::$tables[$this->table] ?? []);
        Capsule::$tables[$this->table] = array_values(array_filter(
            Capsule::$tables[$this->table] ?? [], function ($row) { return !$this->matches($row); }
        ));
        return $before - count(Capsule::$tables[$this->table]);
    }
}

class CapsuleCollection
{
    private $items;
    public function __construct(array $items) { $this->items = $items; }
    public function toArray() { return $this->items; }
}
