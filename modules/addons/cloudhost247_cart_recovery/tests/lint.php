<?php
/** Parse and autoload gate for the shipped Cart Recovery addon files. */

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once dirname(__DIR__) . '/bootstrap.php';

$moduleRoot = dirname(__DIR__);
$files = [];
foreach ([$moduleRoot . '/lib', $moduleRoot . '/migrations', $moduleRoot . '/tests'] as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (substr($file->getPathname(), -4) === '.php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach (glob($moduleRoot . '/*.php') as $file) {
    $files[] = $file;
}
$files = array_values(array_unique($files));
sort($files);

$bad = [];
foreach ($files as $file) {
    try {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (Throwable $e) {
        $bad[] = substr($file, strlen($moduleRoot) + 1) . ': ' . $e->getMessage();
    }
}

$classes = [
    'CloudHost247\\CartRecovery\\AdminController',
    'CloudHost247\\CartRecovery\\Analytics',
    'CloudHost247\\CartRecovery\\CartSnapshot',
    'CloudHost247\\CartRecovery\\EmailService',
    'CloudHost247\\CartRecovery\\Lock',
    'CloudHost247\\CartRecovery\\Log',
    'CloudHost247\\CartRecovery\\MigrationRunner',
    'CloudHost247\\CartRecovery\\OrderRevenue',
    'CloudHost247\\CartRecovery\\RecoveryService',
    'CloudHost247\\CartRecovery\\ReminderService',
    'CloudHost247\\CartRecovery\\Schema',
    'CloudHost247\\CartRecovery\\SettingsRepository',
    'CloudHost247\\CartRecovery\\TokenService',
    'CloudHost247\\CartRecovery\\Migrations\\V100',
    'CloudHost247\\CartRecovery\\Migrations\\V101',
];
foreach ($classes as $class) {
    try {
        if (!class_exists($class)) {
            $bad[] = $class . ': autoload failed';
        }
    } catch (Throwable $e) {
        $bad[] = $class . ': ' . $e->getMessage();
    }
}

echo 'PHP_FILES=' . count($files) . "\n";
echo 'BAD=' . count($bad) . "\n";
foreach ($bad as $line) {
    echo 'BAD_FILE ' . $line . "\n";
}
exit(count($bad) === 0 ? 0 : 1);
