<?php
/** Migration runner (ported from Chs\Core\Migrator): additive, re-runnable, no "down". */

namespace Ch247Mkt\Core;

class Migrator
{
    protected $path;

    public function __construct($path = null)
    {
        $this->path = $path ?: dirname(dirname(__DIR__)) . '/install/migrations';
    }

    /** @return array{applied: string[], skipped: string[]} */
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
            call_user_func($migration['up']);
            Db::insert('migrations', [
                'migration' => $migration['id'],
                'description' => isset($migration['description']) ? $migration['description'] : '',
                'runtime_ms' => (int) round((microtime(true) - $started) * 1000),
                'applied_at' => Clock::now(),
            ]);
            $applied[] = $migration['id'];
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /** @return array[] */
    public function discover()
    {
        $files = glob($this->path . '/*.php');
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $def = require $file;
            if (!is_array($def) || empty($def['id']) || empty($def['up']) || !is_callable($def['up'])) {
                throw new Ch247MktException('Malformed migration: ' . basename($file));
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

    public function createTable($logical, callable $fn)
    {
        $blueprint = new Blueprint(Db::t($logical));
        $fn($blueprint);
        foreach ($blueprint->createSql(Db::driver()) as $sql) {
            Db::exec($sql);
        }
    }

    protected function ensureLedger()
    {
        if (Db::tableExists('migrations')) {
            return;
        }
        $blueprint = new Blueprint(Db::t('migrations'));
        $blueprint->id()->string('migration', 120)->unique('migration')->string('description', 255, true)->integer('runtime_ms', false, 0)->dateTime('applied_at', true);
        foreach ($blueprint->createSql(Db::driver()) as $sql) {
            Db::exec($sql);
        }
    }
}
