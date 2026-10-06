<?php
/**
 * Domain Broker — migration runner.
 *
 * Migrations are plain PHP files in install/migrations that return
 * ['id' => '0001_...', 'description' => '...', 'up' => function (Migrator $m) {}].
 * Applied ids are recorded in domain_broker_migrations so re-running is a no-op
 * and upgrades only apply what is missing. There is deliberately no automatic
 * "down" for tables that hold financial or negotiation history.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Migrator
{
    /** @var string */
    protected $path;

    /** @var string[] */
    protected $log = [];

    public function __construct($path = null)
    {
        $this->path = $path ?: dirname(dirname(__DIR__)) . '/install/migrations';
    }

    /**
     * Apply every pending migration.
     *
     * @return array{applied: string[], skipped: string[]}
     */
    public function migrate()
    {
        $this->ensureLedger();
        $applied = [];
        $skipped = [];

        foreach ($this->discover() as $migration) {
            if ($this->hasRun($migration['id'])) {
                $skipped[] = $migration['id'];
                continue;
            }
            $started = microtime(true);
            call_user_func($migration['up'], $this);
            Db::insert('migrations', [
                'migration'   => $migration['id'],
                'description' => isset($migration['description']) ? $migration['description'] : '',
                'batch'       => $this->nextBatch(),
                'runtime_ms'  => (int) round((microtime(true) - $started) * 1000),
                'applied_at'  => Clock::now(),
            ]);
            $applied[] = $migration['id'];
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /** @return array[] sorted migration definitions */
    public function discover()
    {
        $files = glob($this->path . '/*.php');
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $def = require $file;
            if (!is_array($def) || empty($def['id']) || empty($def['up'])) {
                throw new DomainBrokerException('Malformed migration: ' . basename($file));
            }
            $out[] = $def;
        }
        return $out;
    }

    public function hasRun($id)
    {
        if (!Db::tableExists('migrations')) {
            return false;
        }
        return Db::count('migrations', ['migration' => $id]) > 0;
    }

    public function appliedIds()
    {
        if (!Db::tableExists('migrations')) {
            return [];
        }
        return array_column(Db::fetch('migrations', [], ['order' => 'id']), 'migration');
    }

    protected function nextBatch()
    {
        $max = Db::scalar('SELECT COALESCE(MAX(batch), 0) FROM ' . Db::quoteIdentifier(Db::table('migrations')));
        return ((int) $max) + 1;
    }

    protected function ensureLedger()
    {
        if (Db::tableExists('migrations')) {
            return;
        }
        $t = new Blueprint(Db::table('migrations'));
        $t->id();
        $t->string('migration', 191);
        $t->string('description', 255, true);
        $t->integer('batch', false, 0);
        $t->integer('runtime_ms', false, 0);
        $t->datetime('applied_at', false);
        $t->unique(['migration']);
        $this->runBlueprint($t);
    }

    /* --------------------------------------------------- schema helpers -- */

    /**
     * Create a table from a blueprint built by the callback.
     */
    public function create($logicalName, callable $definition)
    {
        $table = new Blueprint(Db::table($logicalName));
        $definition($table);
        $this->runBlueprint($table);
        $this->log[] = 'create ' . $table->name();
        return $this;
    }

    public function runBlueprint(Blueprint $table)
    {
        foreach ($table->toSql(Db::driver()) as $sql) {
            Db::exec($sql);
        }
    }

    /**
     * Add a column if it is not already present (idempotent upgrades).
     */
    public function addColumn($logicalName, $column, $definitionSql)
    {
        $real = Db::table($logicalName);
        if ($this->hasColumn($logicalName, $column)) {
            return $this;
        }
        Db::exec('ALTER TABLE ' . Db::quoteIdentifier($real) . ' ADD COLUMN '
            . Db::quoteIdentifier($column) . ' ' . $definitionSql);
        $this->log[] = 'add column ' . $real . '.' . $column;
        return $this;
    }

    public function hasColumn($logicalName, $column)
    {
        $real = Db::table($logicalName);
        if (Db::isSqlite()) {
            foreach (Db::select('PRAGMA table_info(' . Db::quoteIdentifier($real) . ')') as $row) {
                if (isset($row['name']) && $row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
        $rows = Db::select('SHOW COLUMNS FROM ' . Db::quoteIdentifier($real) . ' LIKE ?', [$column]);
        return count($rows) > 0;
    }

    /** Insert a row only when the where-map matches nothing (idempotent seeds). */
    public function seed($logicalName, array $where, array $values)
    {
        if (Db::count($logicalName, $where) > 0) {
            return $this;
        }
        Db::insert($logicalName, array_merge($where, $values));
        return $this;
    }

    public function logLines()
    {
        return $this->log;
    }
}
