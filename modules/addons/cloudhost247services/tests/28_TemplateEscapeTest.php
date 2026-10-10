<?php
require __DIR__ . '/bootstrap.php';

// Regression guard for user-influenced values echoed into client templates.
// Flash messages can embed request-derived text (e.g. a listing domain), and
// the Logo Studio prefill values come straight from the query string. Every
// such output must pass through |escape. Static scan: Smarty is not loaded
// in the offline test runner.

$dir = __DIR__ . '/../templates/client/';
$pattern = '/\{\$(flash(?:_ok|_error)?|prefill\.[a-z_]+)(?<rest>[^}]*)\}/';
$unsafe = [];
$outputs = 0;
foreach (glob($dir . '*.tpl') as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $n => $line) {
        if (!preg_match_all($pattern, $line, $m, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($m as $hit) {
            $outputs++;
            if (strpos($hit['rest'], '|escape') === false) {
                $unsafe[] = basename($file) . ':' . ($n + 1) . ' {$' . $hit[1] . $hit['rest'] . '}';
            }
        }
    }
}

T::ok('flash and prefill outputs found in client templates (' . $outputs . ')', $outputs >= 15);
T::ok('every flash/prefill output is escaped', $unsafe === []);
foreach ($unsafe as $line) {
    echo '  UNESCAPED: ' . $line . "\n";
}

$logo = file_get_contents($dir . 'logo_studio.tpl');
T::ok(
    'Logo Studio concept hidden input escapes the company prefill (reflected XSS guard)',
    strpos($logo, "value=\"{\$prefill.company|default:''|escape}\"") !== false
);
T::ok(
    'Logo Studio company text input still escapes the prefill',
    strpos($logo, '{$prefill.company|escape}') !== false
);

T::finish();
