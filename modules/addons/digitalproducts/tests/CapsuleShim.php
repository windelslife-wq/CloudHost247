<?php
/**
 * Offline stand-in for WHMCS\Database\Capsule, backed by in-memory SQLite.
 *
 * The module's production code addresses WHMCS through Capsule's query builder
 * only. This shim implements that builder subset by compiling it to real SQL
 * and running it against SQLite, so the suites exercise the genuine schema, the
 * genuine joins and the genuine WHERE semantics instead of a hand-written
 * approximation of them.
 *
 * Deliberate limits, so a test fails loudly rather than passing by accident:
 *  - Unsupported builder methods throw instead of silently ignoring the call.
 *  - MySQL-only DDL reaches SQLite unchanged and fails, which is what the
 *    migrations already expect (they wrap those statements in try/catch).
 *
 * Test-only. Never shipped to a WHMCS host.
 */
namespace WHMCS\Database;

class CapsuleRaw
{
    public $value;
    public function __construct($value) { $this->value = (string) $value; }
    public function __toString() { return $this->value; }
}

class Capsule
{
    /** @var \PDO|null */
    public static $pdo;

    public static function connect()
    {
        self::$pdo = new \PDO('sqlite::memory:');
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ);
        return self::$pdo;
    }

    public static function table($name) { return new CapsuleQuery((string) $name); }
    public static function raw($value) { return new CapsuleRaw($value); }
    public static function schema() { return new CapsuleSchema(); }

    public static function statement($sql, array $bindings = [])
    {
        return self::$pdo->prepare($sql)->execute($bindings);
    }

    public static function connection()
    {
        return new CapsuleConnection();
    }
}

class CapsuleConnection
{
    public function getPdo() { return Capsule::$pdo; }
}

/* ------------------------------------------------------------------ schema */

class CapsuleColumn
{
    public $name;
    public $type;
    public $length;
    public $nullable = false;
    public $default;
    public $unsigned = false;
    public $autoIncrement = false;
    public $isUnique = false;

    public function __construct($name, $type, $length = null) { $this->name = $name; $this->type = $type; $this->length = $length; }
    public function nullable() { $this->nullable = true; return $this; }
    public function unsigned() { $this->unsigned = true; return $this; }
    public function default($value) { $this->default = $value; return $this; }
    /** Column-level ->unique(), as used by the shipped migrations. */
    public function unique($name = null) { $this->isUnique = true; return $this; }
}

class CapsuleBlueprint
{
    public $table;
    public $columns = [];
    public $uniques = [];
    public $indexes = [];

    public function __construct($table) { $this->table = $table; }

    public function increments($name) { $c = new CapsuleColumn($name, 'integer'); $c->autoIncrement = true; $this->columns[] = $c; return $c; }
    public function integer($name) { $c = new CapsuleColumn($name, 'integer'); $this->columns[] = $c; return $c; }
    public function bigInteger($name) { return $this->integer($name); }
    public function boolean($name) { $c = new CapsuleColumn($name, 'boolean'); $this->columns[] = $c; return $c; }
    public function string($name, $length = 255) { $c = new CapsuleColumn($name, 'varchar', $length); $this->columns[] = $c; return $c; }
    public function text($name) { $c = new CapsuleColumn($name, 'text'); $this->columns[] = $c; return $c; }
    public function dateTime($name) { $c = new CapsuleColumn($name, 'datetime'); $this->columns[] = $c; return $c; }
    public function date($name) { $c = new CapsuleColumn($name, 'date'); $this->columns[] = $c; return $c; }
    public function timestamp($name) { return $this->dateTime($name); }

    public function unique($columns, $name = null) { $this->uniques[] = (array) $columns; return $this; }
    public function index($columns, $name = null) { $this->indexes[] = (array) $columns; return $this; }

    public function hasColumn($name)
    {
        foreach ($this->columns as $c) if ($c->name === $name) return true;
        return false;
    }
}

class CapsuleSchema
{
    public function hasTable($table)
    {
        $stmt = Capsule::$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    public function hasColumn($table, $column)
    {
        if (!$this->hasTable($table)) return false;
        $stmt = Capsule::$pdo->prepare("PRAGMA table_info(" . CapsuleQuery::quoteIdentifier($table) . ")");
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (strtolower($row['name']) === strtolower($column)) return true;
        }
        return false;
    }

    public function create($table, callable $definition)
    {
        if ($this->hasTable($table)) return;
        $blueprint = new CapsuleBlueprint($table);
        $definition($blueprint);
        $parts = [];
        foreach ($blueprint->columns as $col) {
            $parts[] = CapsuleQuery::quoteIdentifier($col->name) . ' ' . $this->columnType($col);
        }
        foreach ($blueprint->uniques as $cols) {
            $parts[] = 'UNIQUE (' . implode(', ', array_map(['WHMCS\Database\CapsuleQuery', 'quoteIdentifier'], $cols)) . ')';
        }
        $sql = 'CREATE TABLE ' . CapsuleQuery::quoteIdentifier($table) . ' (' . implode(', ', $parts) . ')';
        Capsule::$pdo->exec($sql);
        foreach ($blueprint->indexes as $cols) {
            $this->index($table, $cols);
        }
    }

    public function table($table, callable $definition)
    {
        $blueprint = new CapsuleBlueprint($table);
        $definition($blueprint);
        foreach ($blueprint->columns as $col) {
            if ($this->hasColumn($table, $col->name)) continue;
            Capsule::$pdo->exec('ALTER TABLE ' . CapsuleQuery::quoteIdentifier($table)
                . ' ADD COLUMN ' . CapsuleQuery::quoteIdentifier($col->name) . ' ' . $this->columnType($col));
        }
        foreach ($blueprint->uniques as $cols) {
            try { $this->unique($table, $cols); } catch (\Throwable $e) {}
        }
        foreach ($blueprint->indexes as $cols) {
            try { $this->index($table, $cols); } catch (\Throwable $e) {}
        }
    }

    public function drop($table)
    {
        Capsule::$pdo->exec('DROP TABLE IF EXISTS ' . CapsuleQuery::quoteIdentifier($table));
    }

    protected function index($table, $cols)
    {
        $name = 'idx_' . $table . '_' . implode('_', $cols);
        Capsule::$pdo->exec('CREATE INDEX ' . CapsuleQuery::quoteIdentifier($name)
            . ' ON ' . CapsuleQuery::quoteIdentifier($table)
            . ' (' . implode(', ', array_map(['WHMCS\Database\CapsuleQuery', 'quoteIdentifier'], $cols)) . ')');
    }

    protected function unique($table, $cols)
    {
        $name = 'uq_' . $table . '_' . implode('_', $cols);
        Capsule::$pdo->exec('CREATE UNIQUE INDEX ' . CapsuleQuery::quoteIdentifier($name)
            . ' ON ' . CapsuleQuery::quoteIdentifier($table)
            . ' (' . implode(', ', array_map(['WHMCS\Database\CapsuleQuery', 'quoteIdentifier'], $cols)) . ')');
    }

    protected function columnType(CapsuleColumn $col)
    {
        $sql = '';
        if ($col->autoIncrement) {
            $sql = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        } elseif ($col->type === 'integer') {
            $sql = 'INTEGER';
        } elseif ($col->type === 'boolean') {
            $sql = 'INTEGER';
        } elseif ($col->type === 'text') {
            $sql = 'TEXT';
        } elseif ($col->type === 'datetime' || $col->type === 'date') {
            $sql = 'TEXT';
        } else {
            $sql = 'VARCHAR(' . (int) ($col->length ?: 255) . ')';
        }
        if (!$col->nullable && !$col->autoIncrement) {
            if ($col->default === null) {
                // Match MySQL/SQLite permissiveness for additive migrations; the
                // module always supplies defaults through PHP for new rows.
                $sql .= ' NULL';
            }
        }
        if ($col->default !== null && !$col->autoIncrement) {
            $value = $col->default;
            if (is_bool($value)) $value = $value ? 1 : 0;
            if (is_string($value)) $sql .= " DEFAULT '" . str_replace("'", "''", $value) . "'";
            else $sql .= ' DEFAULT ' . $value;
        }
        if ($col->isUnique && !$col->autoIncrement) $sql .= ' UNIQUE';
        return $sql;
    }
}

/* ------------------------------------------------------------ query builder */

class CapsuleQuery
{
    protected $table;
    protected $alias;
    protected $selects = [];
    protected $joins = [];
    protected $wheres = [];
    protected $bindings = [];
    protected $orders = [];
    protected $limitValue;
    protected $offsetValue;

    public function __construct($table)
    {
        $table = (string) $table;
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $table, $m)) {
            $this->table = trim($m[1]);
            $this->alias = trim($m[2]);
        } else {
            $this->table = $table;
            $this->alias = null;
        }
    }

    /** FROM / JOIN clause for a table that may carry an alias. */
    protected static function tableRef($table, $alias = null)
    {
        $sql = self::quoteIdentifier($table);
        if ($alias !== null && $alias !== '' && $alias !== $table) $sql .= ' AS ' . self::quoteIdentifier($alias);
        return $sql;
    }

    public static function quoteIdentifier($name)
    {
        if ($name === '*') return $name;
        return '"' . str_replace('"', '""', (string) $name) . '"';
    }

    /* ---- projection ---- */

    public function select($columns = null)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        foreach ($columns as $column) {
            if ($column instanceof CapsuleRaw) { $this->selects[] = $column->value; continue; }
            $this->selects[] = $this->qualifySelect($column);
        }
        return $this;
    }

    protected function qualifySelect($column)
    {
        $column = (string) $column;
        if (preg_match('/\s+as\s+/i', $column)) {
            [$left, $right] = preg_split('/\s+as\s+/i', $column, 2);
            return $this->qualifyColumn($left) . ' AS ' . $this->quoteAlias(trim($right));
        }
        return $this->qualifyColumn($column);
    }

    protected function qualifyColumn($column)
    {
        $column = trim((string) $column);
        if ($column === '*') return '*';
        if (strpos($column, '.') !== false) {
            [$t, $c] = explode('.', $column, 2);
            return self::quoteIdentifier($t) . '.' . ($c === '*' ? '*' : self::quoteIdentifier($c));
        }
        return self::quoteIdentifier($column);
    }

    protected function quoteAlias($alias)
    {
        return self::quoteIdentifier($alias);
    }

    /* ---- joins ---- */

    public function join($table, $first, $operator = null, $second = null, $type = 'INNER')
    {
        if ($first instanceof \Closure) {
            $nested = new CapsuleQuery($table);
            $first($nested);
            $this->joins[] = ['type' => $type, 'ref' => self::tableRef($nested->table, $nested->alias), 'sql' => '(' . implode(' AND ', $nested->wheres) . ')'];
            $this->bindings = array_merge($this->bindings, $nested->bindings);
            return $this;
        }
        if ($second === null) { $second = $operator; $operator = '='; }
        $join = new CapsuleQuery($table);
        $this->joins[] = [
            'type' => $type,
            'ref' => self::tableRef($join->table, $join->alias),
            'sql' => $this->qualifyColumn($first) . ' ' . $operator . ' ' . $this->qualifyColumn($second),
        ];
        return $this;
    }

    public function leftJoin($table, $first, $operator = null, $second = null) { return $this->join($table, $first, $operator, $second, 'LEFT'); }
    public function rightJoin($table, $first, $operator = null, $second = null) { return $this->join($table, $first, $operator, $second, 'RIGHT'); }

    /* ---- wheres ---- */

    public function where($column, $operator = null, $value = null, $boolean = 'AND')
    {
        if ($column instanceof \Closure) {
            $nested = new CapsuleQuery($this->table);
            $column($nested);
            if (!$nested->wheres) return $this;
            $group = '(' . implode(' AND ', $nested->wheres) . ')';
            if ($boolean === 'OR') $this->appendOr($group);
            else $this->wheres[] = $group;
            $this->bindings = array_merge($this->bindings, $nested->bindings);
            return $this;
        }
        $args = func_num_args();
        if ($args === 2) { $value = $operator; $operator = '='; }
        $operator = strtoupper((string) $operator);
        if ($value === null && $operator === '=') $operator = 'IS';
        if ($operator === 'IS' || $operator === 'IS NOT') {
            $clause = $this->qualifyColumn($column) . ' ' . $operator . ' NULL';
            if ($boolean === 'OR') $this->appendOr($clause);
            else $this->wheres[] = $clause;
            return $this;
        }
        if (is_array($value)) {
            return $this->whereIn($column, $value, $boolean, $operator === '!=' || $operator === '<>' || $operator === 'NOT IN');
        }
        $clause = $this->qualifyColumn($column) . ' ' . $operator . ' ?';
        if ($boolean === 'OR') $this->appendOr($clause);
        else $this->wheres[] = $clause;
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) { $value = $operator; $operator = '='; }
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn($column, array $values, $boolean = 'AND', $not = false)
    {
        if (!$values) {
            $this->wheres[] = $not ? '1 = 1' : '1 = 0';
            return $this;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = $this->qualifyColumn($column) . ($not ? ' NOT IN ' : ' IN ') . '(' . $placeholders . ')';
        foreach ($values as $v) $this->bindings[] = $v;
        return $this;
    }

    public function whereNotIn($column, array $values) { return $this->whereIn($column, $values, 'AND', true); }

    public function whereNull($column) { $this->wheres[] = $this->qualifyColumn($column) . ' IS NULL'; return $this; }
    public function whereNotNull($column) { $this->wheres[] = $this->qualifyColumn($column) . ' IS NOT NULL'; return $this; }

    public function orWhereNull($column) { return $this->appendOr($this->qualifyColumn($column) . ' IS NULL'); }
    public function orWhereNotNull($column) { return $this->appendOr($this->qualifyColumn($column) . ' IS NOT NULL'); }

    /** Join the next condition onto the previous one with OR, as a group. */
    protected function appendOr($sql)
    {
        if (!$this->wheres) { $this->wheres[] = $sql; return $this; }
        $previous = array_pop($this->wheres);
        $this->wheres[] = '(' . $previous . ' OR ' . $sql . ')';
        return $this;
    }

    public function whereDate($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) { $value = $operator; $operator = '='; }
        $this->wheres[] = 'date(' . $this->qualifyColumn($column) . ') ' . $operator . ' ?';
        $this->bindings[] = $value;
        return $this;
    }

    /* ---- ordering / paging ---- */

    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $this->orders[] = $this->qualifyColumn($column) . ' ' . $direction;
        return $this;
    }

    public function limit($value) { $this->limitValue = max(0, (int) $value); return $this; }
    public function offset($value) { $this->offsetValue = max(0, (int) $value); return $this; }
    public function take($value) { return $this->limit($value); }
    public function skip($value) { return $this->offset($value); }

    public function forPage($page, $perPage)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    /* ---- execution ---- */

    protected function compileSelect()
    {
        $select = $this->selects ? implode(', ', $this->selects) : '*';
        $sql = 'SELECT ' . $select . ' FROM ' . self::tableRef($this->table, $this->alias);
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $join['ref'] . ' ON ' . $join['sql'];
        }
        if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        if ($this->orders) $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . (int) $this->limitValue;
            if ($this->offsetValue) $sql .= ' OFFSET ' . (int) $this->offsetValue;
        }
        return $sql;
    }

    public function get()
    {
        $stmt = Capsule::$pdo->prepare($this->compileSelect());
        $stmt->execute($this->bindings);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function first()
    {
        $sql = $this->compileSelect() . ($this->limitValue === null ? ' LIMIT 1' : '');
        $stmt = Capsule::$pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    public function value($column)
    {
        $this->selects = [$this->qualifySelect($column)];
        $stmt = Capsule::$pdo->prepare($this->compileSelect() . ' LIMIT 1');
        $stmt->execute($this->bindings);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public function pluck($column, $key = null)
    {
        $rows = $this->get();
        $out = [];
        foreach ($rows as $row) {
            $value = $row->$column ?? null;
            if ($key === null) $out[] = $value;
            else $out[$row->$key ?? ''] = $value;
        }
        return new CapsuleCollection($out);
    }

    public function count()
    {
        $this->selects = ['COUNT(*) AS aggregate'];
        $stmt = Capsule::$pdo->prepare($this->compileSelect());
        $stmt->execute($this->bindings);
        return (int) $stmt->fetch(\PDO::FETCH_OBJ)->aggregate;
    }

    public function exists()
    {
        return $this->count() > 0;
    }

    public function insert(array $values)
    {
        $columns = array_keys($values);
        $placeholders = [];
        $bindings = [];
        foreach ($values as $value) {
            if ($value instanceof CapsuleRaw) { $placeholders[] = $value->value; continue; }
            $placeholders[] = '?';
            $bindings[] = is_bool($value) ? (int) $value : $value;
        }
        $sql = 'INSERT INTO ' . self::quoteIdentifier($this->table)
            . ' (' . implode(', ', array_map([__CLASS__, 'quoteIdentifier'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
        Capsule::$pdo->prepare($sql)->execute($bindings);
        return true;
    }

    public function insertGetId(array $values)
    {
        $this->insert($values);
        return (int) Capsule::$pdo->lastInsertId();
    }

    public function update(array $values)
    {
        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            if ($value instanceof CapsuleRaw) { $sets[] = self::quoteIdentifier($column) . ' = ' . $value->value; continue; }
            $sets[] = self::quoteIdentifier($column) . ' = ?';
            $bindings[] = is_bool($value) ? (int) $value : $value;
        }
        $sql = 'UPDATE ' . self::quoteIdentifier($this->table) . ' SET ' . implode(', ', $sets);
        if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        $stmt = Capsule::$pdo->prepare($sql);
        $stmt->execute(array_merge($bindings, $this->bindings));
        return $stmt->rowCount();
    }

    public function increment($column, $amount = 1)
    {
        $sql = 'UPDATE ' . self::quoteIdentifier($this->table)
            . ' SET ' . self::quoteIdentifier($column) . ' = COALESCE(' . self::quoteIdentifier($column) . ', 0) + ' . (int) $amount;
        if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        return Capsule::$pdo->prepare($sql)->execute($this->bindings);
    }

    public function decrement($column, $amount = 1) { return $this->increment($column, -1 * (int) $amount); }

    public function delete()
    {
        $sql = 'DELETE FROM ' . self::quoteIdentifier($this->table);
        if ($this->wheres) $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        $stmt = Capsule::$pdo->prepare($sql);
        $stmt->execute($this->bindings);
        return $stmt->rowCount();
    }

    public function updateOrInsert(array $attributes, array $values = [])
    {
        $probe = new CapsuleQuery($this->table);
        foreach ($attributes as $column => $value) $probe->where($column, '=', $value);
        if ($probe->exists()) {
            return $probe->update($values);
        }
        return $this->insert(array_merge($attributes, $values));
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $total = (int) (new CapsuleQuery($this->table))->count();
        $perPage = max(1, (int) $perPage);
        $page = $page === null ? (int) ($_GET[$pageName] ?? 1) : (int) $page;
        $page = max(1, $page);
        $items = $this->forPage($page, $perPage)->get();
        return new CapsulePaginator($items, $total, $perPage, $page);
    }

    /** Unsupported methods must be loud, never silently ignored. */
    public function __call($method, $arguments)
    {
        throw new \RuntimeException('Capsule test shim does not implement ' . get_class($this) . '::' . $method . '()');
    }
}

class CapsulePaginator implements \IteratorAggregate, \Countable, \JsonSerializable
{
    public $data;
    public $total;
    public $per_page;
    public $current_page;
    public $last_page;

    public function __construct(array $items, $total, $perPage, $page)
    {
        $this->data = $items;
        $this->total = $total;
        $this->per_page = $perPage;
        $this->current_page = $page;
        $this->last_page = max(1, (int) ceil($total / $perPage));
    }

    public function items() { return $this->data; }
    public function total() { return $this->total; }
    public function perPage() { return $this->per_page; }
    public function currentPage() { return $this->current_page; }
    public function lastPage() { return $this->last_page; }
    public function count(): int { return count($this->data); }
    public function getIterator(): \Iterator { return new \ArrayIterator($this->data); }
    public function jsonSerialize() { return ['data' => $this->data, 'total' => $this->total, 'per_page' => $this->per_page, 'current_page' => $this->current_page, 'last_page' => $this->last_page]; }
}

class CapsuleCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    private $items;

    public function __construct(array $items) { $this->items = $items; }
    public function all() { return $this->items; }
    public function toArray() { return $this->items; }
    public function count(): int { return count($this->items); }
    public function isEmpty() { return count($this->items) === 0; }
    public function getIterator(): \Iterator { return new \ArrayIterator($this->items); }
    public function offsetExists($offset): bool { return isset($this->items[$offset]); }
    public function offsetGet($offset) { return $this->items[$offset] ?? null; }
    public function offsetSet($offset, $value): void { if ($offset === null) $this->items[] = $value; else $this->items[$offset] = $value; }
    public function offsetUnset($offset): void { unset($this->items[$offset]); }
}
