<?php
/**
 * Forward-only, retryable addon migrations. Security credentials and audit
 * history are never dropped automatically during deactivation or rollback.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Core;

class Migrator
{
    private $path;

    public function __construct($path = null)
    {
        $this->path = $path ?: dirname(dirname(__DIR__)) . '/install/migrations';
    }

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
            call_user_func($migration['up']);
            Db::insert('migrations', [
                'migration' => $migration['id'],
                'description' => isset($migration['description']) ? $migration['description'] : '',
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $applied[] = $migration['id'];
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    public function discover()
    {
        $files = glob($this->path . '/*.php');
        sort($files, SORT_STRING);
        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || empty($migration['id']) || empty($migration['up']) || !is_callable($migration['up'])) {
                throw new \RuntimeException('Malformed Passkey migration: ' . basename($file));
            }
            $migrations[] = $migration;
        }
        return $migrations;
    }

    public function hasRun($migrationId)
    {
        if (!Db::tableExists('migrations')) {
            return false;
        }
        return Db::count('migrations', ['migration' => (string) $migrationId]) > 0;
    }

    private function ensureLedger()
    {
        if (!Db::tableExists('migrations')) {
            Db::execute(Schema::ledgerStatement(Db::driver()));
        }
    }
}
