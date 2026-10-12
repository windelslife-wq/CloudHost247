<?php
/**
 * Idempotent override merger and full English-reference coverage gate.
 * php ci/apply-override-translations.php          fills only known missing keys
 * php ci/apply-override-translations.php --check  verifies ALL English keys
 *
 * The --check gate must not depend on the historical list of 88 missing keys:
 * adding a new English key or deleting any older translation must also fail CI.
 */
declare(strict_types=1);
if (!defined('WHMCS')) define('WHMCS', true);
$root = dirname(__DIR__);
$directory = $root . '/lang/overrides';
$check = in_array('--check', $argv, true);
$languages = array('french', 'spanish', 'german', 'portuguese-br');

function ch247_read_override(string $file): array
{
    if (!is_file($file)) return array();
    // These are trusted repository language files, not uploaded content.
    // A local scope avoids leaking one language's values into another.
    $_LANG = array();
    ob_start();
    try { require $file; }
    finally { ob_end_clean(); }
    return $_LANG;
}
function ch247_override_quote(string $value): string
{
    return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $value) . "'";
}

$reference = ch247_read_override($directory . '/english.php');
if (!$reference) {
    echo "ERROR: English reference could not be loaded\n";
    exit(2);
}
$pending = $check ? array() : require $root . '/ci/pending-translations.php';
$failed = 0;
foreach ($languages as $language) {
    $file = $directory . '/' . $language . '.php';
    $existing = ch247_read_override($file);
    if (!$check && is_file($file)) {
        $block = '';
        foreach (isset($pending[$language]) ? $pending[$language] : array() as $key => $value) {
            if (array_key_exists($key, $reference) && (!isset($existing[$key]) || trim((string) $existing[$key]) === '')) {
                $block .= '$_LANG[' . ch247_override_quote($key) . '] = ' . ch247_override_quote($value) . ";\n";
            }
        }
        if ($block !== '') {
            if (file_put_contents($file, "\n/* CloudHost247: checkout + domain broker translations */\n" . $block, FILE_APPEND) === false) {
                echo "ERROR: could not append to {$language}.php\n";
                $failed++;
                continue;
            }
            $existing = ch247_read_override($file);
        }
    }
    $missing = array();
    foreach ($reference as $key => $value) {
        if (!isset($existing[$key]) || !is_string($existing[$key]) || trim($existing[$key]) === '') {
            $missing[] = $key;
        }
    }
    $covered = count($reference) - count($missing);
    echo ($missing ? 'MISS  ' : 'OK    ') . $language . '.php — ' . $covered . '/' . count($reference) . ' English keys';
    if ($missing) {
        $failed++;
        echo '; missing: ' . implode(', ', $missing);
    }
    echo "\n";
}
echo $failed ? "RESULT: override translations are incomplete\n" : "RESULT: override translations are complete\n";
exit($failed ? 1 : 0);
