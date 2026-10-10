<?php
$root = dirname(__DIR__);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '/node_modules/') !== false) continue;
    $files[] = $p;
}
sort($files);
$bad = 0;
foreach ($files as $p) {
    $src = @file_get_contents($p);
    try { token_get_all($src, TOKEN_PARSE); }
    catch (\Throwable $e) { $bad++; echo "PARSE FAIL: " . str_replace($root . '/', '', $p) . ': ' . $e->getMessage() . "\n"; }
}
echo "FILES=" . count($files) . "\nBAD=$bad\n";
