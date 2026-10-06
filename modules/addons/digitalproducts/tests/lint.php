<?php
// Syntax/load gate for the addon. Entry points are token-parsed because they
// require a real WHMCS bootstrap; library classes are loaded through autoload.
define('DIGITALPRODUCTS_TESTING', true);
$root = dirname(__DIR__); require_once $root . '/autoload.php';
$skip = ['api.php', 'download.php', 'hooks.php', 'digitalproducts.php', 'digitalproducts_clientarea.php'];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) { if (substr($file->getPathname(), -4) !== '.php' || strpos($file->getPathname(), '/tests/') !== false) continue; $files[] = $file->getPathname(); }
sort($files); $entries = []; $bad = 0;
foreach ($files as $file) {
    if (in_array(basename($file), $skip, true)) { $entries[] = $file; continue; }
    try { include_once $file; echo 'OK ' . str_replace($root . '/', '', $file) . "\n"; } catch (\Throwable $e) { $bad++; echo 'BAD ' . $file . ': ' . $e->getMessage() . "\n"; }
}
foreach ($entries as $file) { try { token_get_all(file_get_contents($file), TOKEN_PARSE); echo 'SYNTAX ' . str_replace($root . '/', '', $file) . "\n"; } catch (\Throwable $e) { $bad++; echo 'BAD ' . $file . ': ' . $e->getMessage() . "\n"; } }
echo 'FILES=' . (count($files)) . ' BAD=' . $bad . "\n"; exit($bad ? 1 : 0);
