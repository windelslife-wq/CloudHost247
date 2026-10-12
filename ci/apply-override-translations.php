<?php
/**
 * WHMCS language overrides — merge the pending translations.
 *
 * lang/overrides/english.php is the reference. This script walks every other
 * override file, finds the keys that the reference defines but the translation
 * is missing or has left empty, and appends the translations from
 * ci/pending-translations.php.
 *
 * It is idempotent: running it twice changes nothing the second time. It never
 * edits a key that already has a value, so a human translator's work is never
 * overwritten.
 *
 * Usage:  php ci/apply-override-translations.php [--check]
 *
 *   (no flag)  write the missing keys into the language files
 *   --check    report only; exit 1 if anything is missing. This is the CI gate.
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$overridesDir = $root . '/lang/overrides';
$pendingFile = $root . '/ci/pending-translations.php';
$checkOnly = in_array('--check', $argv, true);

if (!is_file($pendingFile)) {
    echo "Pending translation file not found: {$pendingFile}\n";
    exit(2);
}

/** @var array<string,array<string,string>> $pending */
$pending = require $pendingFile;

/**
 * Parse an override file without executing it (it calls die() outside WHMCS).
 *
 * Handles both shapes used across the repo:
 *   $_LANG['key'] = 'value';
 *   $_LANG['key'] = "value";
 *   json_decode('{ "key": "value" }')
 *
 * @param string $path
 *
 * @return array<string,string>
 */
function ch247_parse_overrides(string $path): array
{
    $source = file_get_contents($path);
    $out = array();

    if (preg_match("/json_decode\('(\{.*\})'\s*,?\s*\w*\s*\)/s", $source, $m)) {
        $json = str_replace("\\'", "'", $m[1]);
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($value)) {
                    $out[(string) $key] = $value;
                }
            }
        }
    }

    if (preg_match_all(
        '/\$_LANG\[\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\]\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')\s*;/',
        $source,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $value = $m[2] !== '' ? $m[2] : $m[3];
            $out[$m[1]] = str_replace(array("\\'", '\\"'), array("'", '"'), $value);
        }
    }

    return $out;
}

/**
 * @param string $value
 *
 * @return string
 */
function ch247_php_quote(string $value): string
{
    // Single-quoted unless the string needs an escape that is clearer double-quoted.
    if (strpos($value, "'") === false && strpos($value, '\\') === false) {
        return "'" . $value . "'";
    }

    return '"' . str_replace(array('\\', '"', '$'), array('\\\\', '\\"', '\\$'), $value) . '"';
}

$reference = ch247_parse_overrides($overridesDir . '/english.php');
if (!$reference) {
    echo "Could not parse lang/overrides/english.php\n";
    exit(2);
}

$exitCode = 0;
$totalApplied = 0;

foreach ($pending as $language => $translations) {
    $file = $overridesDir . '/' . $language . '.php';
    if (!is_file($file)) {
        echo "SKIP  {$language}.php (file not found)\n";
        continue;
    }

    $existing = ch247_parse_overrides($file);

    $toAdd = array();
    foreach ($translations as $key => $value) {
        // Only fill holes: never overwrite a value a translator already set.
        if (!array_key_exists($key, $reference)) {
            continue;
        }
        if (array_key_exists($key, $existing) && trim($existing[$key]) !== '') {
            continue;
        }
        $toAdd[$key] = $value;
    }

    if (!$toAdd) {
        echo "OK    {$language}.php — nothing missing\n";
        continue;
    }

    if ($checkOnly) {
        echo "MISS  {$language}.php — " . count($toAdd) . " key(s): " . implode(', ', array_slice(array_keys($toAdd), 0, 8)) . "\n";
        $exitCode = 1;
        continue;
    }

    $block = "\n/* --- CloudHost247: checkout + domain broker translations --- */\n";
    foreach ($toAdd as $key => $value) {
        $block .= '$_LANG[' . ch247_php_quote($key) . '] = ' . ch247_php_quote($value) . ";\n";
    }

    $handle = fopen($file, 'a');
    if ($handle === false) {
        echo "Could not open {$file} for writing\n";
        $exitCode = 1;
        continue;
    }
    fwrite($handle, $block);
    fclose($handle);

    $totalApplied += count($toAdd);
    echo "WRITE {$language}.php — added " . count($toAdd) . " key(s)\n";
}

if ($checkOnly) {
    echo $exitCode === 0
        ? "RESULT: override translations are complete\n"
        : "RESULT: override translations are incomplete\n";
    exit($exitCode);
}

echo "RESULT: applied {$totalApplied} translation(s)\n";
exit(0);
