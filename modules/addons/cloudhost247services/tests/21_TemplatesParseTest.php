<?php
require __DIR__ . '/bootstrap.php';
// Parse-checks every admin view + root landing page (not covered by lint.mjs).
// Repo root: absolute php-wasm mount first, native-checkout relative path fallback (CI runs native PHP).
$ch247RepoRoot = is_dir('/repo') ? '/repo' : dirname(__DIR__, 4);
$files = array_merge(
    glob(__DIR__ . '/../templates/admin/*.phtml'),
    glob($ch247RepoRoot . '/*.php')
);
$bad = [];
foreach ($files as $f) {
    $src = @file_get_contents($f);
    if ($src === false) {
        $bad[] = 'MISSING: ' . $f;
        continue;
    }
    try {
        @token_get_all($src, TOKEN_PARSE);
    } catch (\Throwable $e) {
        $bad[] = 'PARSE FAIL: ' . basename($f) . ': ' . $e->getMessage();
    }
}
T::ok('all admin views + root landings parse (' . count($files) . ' files)', $bad === []);
foreach ($bad as $line) {
    echo '  ' . $line . "\n";
}
T::finish();
