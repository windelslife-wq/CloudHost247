<?php
namespace DigitalProducts\Core;

use WHMCS\Database\Capsule;

class Migrator
{
    protected $path;
    public function __construct($path = null) { $this->path = $path ?: DIGITALPRODUCTS_ROOT . '/install/migrations'; }

    public function migrate()
    {
        $this->ensureLedger();
        $applied = [];
        $files = glob($this->path . '/*.php');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || empty($migration['id']) || !isset($migration['up']) || !is_callable($migration['up'])) throw new DigitalProductsException('Malformed migration: ' . basename($file));
            if ($this->hasRun($migration['id'])) continue;
            call_user_func($migration['up'], $this);
            Capsule::table('mod_digitalproducts_migrations')->insert(['migration' => $migration['id'], 'description' => (string) ($migration['description'] ?? ''), 'applied_at' => Clock::now()]);
            $applied[] = $migration['id'];
        }
        return $applied;
    }

    public function tableExists($name) { return Capsule::schema()->hasTable($name); }
    public function hasColumn($table, $column) { return Capsule::schema()->hasColumn($table, $column); }

    public function create($table, callable $definition)
    {
        if (!$this->tableExists($table)) Capsule::schema()->create($table, $definition);
        return $this;
    }

    public function addColumn($table, $column, callable $definition)
    {
        if (!$this->tableExists($table) || $this->hasColumn($table, $column)) return $this;
        Capsule::schema()->table($table, $definition);
        return $this;
    }

    public function index($table, $columns, $name = null)
    {
        try { Capsule::schema()->table($table, function ($blueprint) use ($columns, $name) { $name ? $blueprint->index((array) $columns, $name) : $blueprint->index((array) $columns); }); } catch (\Throwable $e) { /* existing index / old MySQL is harmless */ }
        return $this;
    }

    protected function ensureLedger()
    {
        if ($this->tableExists('mod_digitalproducts_migrations')) return;
        Capsule::schema()->create('mod_digitalproducts_migrations', function ($table) {
            $table->increments('id');
            $table->string('migration', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->dateTime('applied_at');
        });
    }

    protected function hasRun($id) { return Capsule::table('mod_digitalproducts_migrations')->where('migration', $id)->exists(); }
}
