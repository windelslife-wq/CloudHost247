<?php
/**
 * Domain Broker — static load check (driven by tests/lint.mjs, or run
 * directly with a native binary: `php tests/lint.php`).
 *
 * Includes every shipped PHP file and reports parse/definition errors
 * per file instead of dying on the first one.
 *
 * @package DomainBroker
 */

define('DOMAINBROKER_TESTING', true);

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

/** Directories that are entry points rather than library code. */
$skipDirs = ['/tests/'];
/**
 * Files that require a WHMCS runtime and cannot be *loaded* standalone. They
 * are still syntax checked below with token_get_all(..., TOKEN_PARSE), which
 * raises a ParseError on malformed source without executing anything.
 */
$skipFiles = ['hooks.php', 'domainbroker.php', 'index.php', 'webhook.php', 'download.php', 'domainbroker-cron.php'];
$entryFiles = [];

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') {
        continue;
    }
    foreach ($skipDirs as $skip) {
        if (strpos($path, $skip) !== false) {
            continue 2;
        }
    }
    if (in_array(basename($path), $skipFiles, true)) {
        $entryFiles[] = $path;
        continue;
    }
    $files[] = $path;
}
sort($files);

$bad = 0;
$verbose = in_array('-v', $argv === null ? [] : (array) $argv, true);

foreach ($files as $file) {
    $label = str_replace($root . '/', '', $file);
    if (basename($file) === 'autoload.php') {
        echo "OK    {$label}\n";
        continue;
    }
    try {
        include_once $file;
        echo "OK    {$label}\n";
    } catch (\ParseError $e) {
        $bad++;
        echo "PARSE {$label}: {$e->getMessage()} (line {$e->getLine()})\n";
    } catch (\Throwable $e) {
        $bad++;
        echo 'ERROR ' . $label . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
}

sort($entryFiles);
foreach ($entryFiles as $file) {
    $label = str_replace($root . '/', '', $file);
    try {
        token_get_all((string) file_get_contents($file), TOKEN_PARSE);
        echo "SYNTAX {$label}\n";
    } catch (\ParseError $e) {
        $bad++;
        echo "PARSE {$label}: {$e->getMessage()} (line {$e->getLine()})\n";
    }
}

echo "\nFILES=" . (count($files) + count($entryFiles)) . " BAD={$bad}\n";
exit($bad > 0 ? 1 : 0);
