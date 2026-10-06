<?php
/**
 * Loads every shipped PHP file through the real runtime: proves each parses,
 * every extended class/interface resolves through the autoloader, and every
 * class body is well formed. This is the module's "production build" gate.
 */

require_once dirname(__DIR__) . '/autoload.php';

$moduleRoot = dirname(__DIR__);

$files = [];
$roots = [$moduleRoot . '/lib', $moduleRoot . '/install'];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (substr($path, -4) === '.php') {
            $files[] = $path;
        }
    }
}
foreach (glob($moduleRoot . '/*.php') as $path) {
    $files[] = $path;
}
foreach (glob($moduleRoot . '/cron/*.php') as $path) {
    $files[] = $path;
}
$files = array_values(array_unique($files));
sort($files);
echo 'FILES=' . count($files) . "\n";

$bad = [];
foreach ($files as $file) {
    $short = substr($file, strlen($moduleRoot) + 1);
    try {
        // Entry points and cron die without WHMCS init — they are static
        // entry shims, excluded from the load gate and parsed instead.
        if (strpos($short, 'cloudhost247marketing.php') === 0
            || strpos($short, 'hooks.php') === 0
            || strpos($short, 'track.php') === 0
            || strpos($short, 'cron/') === 0) {
            $tokens = @token_get_all(file_get_contents($file), TOKEN_PARSE);
            unset($tokens);
            continue;
        }
        require_once $file;
    } catch (\Throwable $e) {
        $bad[] = $short . ': ' . $e->getMessage();
    }
}

echo 'BAD=' . count($bad) . "\n";
foreach ($bad as $line) {
    echo $line . "\n";
}
exit(count($bad) > 0 ? 1 : 0);
