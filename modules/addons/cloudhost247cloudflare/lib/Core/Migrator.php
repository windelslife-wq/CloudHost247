<?php
namespace CloudHost247\Cloudflare\Core;

class Migrator
{
    private $path;
    public function __construct($path = null) { $this->path = $path ?: CH247CF_MODULE_DIR . '/install/migrations'; }
    public function migrate()
    {
        $this->create('migrations', [
            'id' => 'id', 'migration' => 'VARCHAR(120) NOT NULL UNIQUE', 'description' => 'VARCHAR(255) NULL',
            'runtime_ms' => 'INT NOT NULL DEFAULT 0', 'applied_at' => 'DATETIME NULL',
        ]);
        $applied = []; $skipped = [];
        $files = glob($this->path . '/*.php'); sort($files, SORT_STRING);
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || empty($migration['id']) || !isset($migration['up']) || !is_callable($migration['up'])) throw new \RuntimeException('Malformed Cloudflare migration: ' . basename($file));
            if (Db::count('migrations', ['migration' => $migration['id']]) > 0) { $skipped[] = $migration['id']; continue; }
            $started = microtime(true);
            call_user_func($migration['up']);
            Db::insert('migrations', ['migration' => $migration['id'], 'description' => (string) ($migration['description'] ?? ''), 'runtime_ms' => (int) round((microtime(true) - $started) * 1000), 'applied_at' => gmdate('Y-m-d H:i:s')]);
            $applied[] = $migration['id'];
        }
        return ['applied' => $applied, 'skipped' => $skipped];
    }
    public static function create($logical, array $columns, array $indexes = [])
    {
        $table = Db::table($logical); $driver = Db::driver();
        $defs = [];
        foreach ($columns as $name => $definition) {
            if ($name === 'id') {
                $defs[] = $driver === 'sqlite' ? '`id` INTEGER PRIMARY KEY AUTOINCREMENT' : '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            } else {
                $defs[] = '`' . $name . '` ' . $definition;
            }
        }
        if ($driver === 'sqlite') {
            foreach ($indexes as $index) {
                if (preg_match('/^UNIQUE KEY `[^`]+` (\\(.+\\))$/i', $index, $m)) {
                    $defs[] = 'UNIQUE ' . $m[1];
                }
            }
        } else {
            foreach ($indexes as $index) $defs[] = $index;
        }
        $engine = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        Db::exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $defs) . ')' . $engine);
        if ($driver === 'sqlite') {
            foreach ($indexes as $index) {
                if (strpos(strtoupper($index), 'UNIQUE ') === 0) continue;
                if (preg_match('/^KEY `([A-Za-z0-9_]+)` \((.+)\)$/', $index, $m)) {
                    Db::exec('CREATE INDEX IF NOT EXISTS `' . $m[1] . '_' . $table . '` ON `' . $table . '` (' . $m[2] . ')');
                }
            }
        }
    }
}
