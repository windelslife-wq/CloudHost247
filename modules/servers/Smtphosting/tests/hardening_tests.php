<?php

/**
 * Low-severity hardening regression checks (S-4, S-7, S-8, S-9).
 *
 * These are static checks on the source. The hooks and the Synchronize action
 * depend on WHMCS classes and cannot run in this harness, so the checks
 * confirm the shipped code shape. They do not execute the code paths.
 */

$hardeningModuleDir = dirname(__DIR__);

function smtp_module_file($relative)
{
    global $hardeningModuleDir;
    $path = $hardeningModuleDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $src  = @file_get_contents($path);
    smtp_assert($src !== false, 'expected file to exist: ' . $relative);
    return $src;
}

smtp_test('S-7: Synchronize logs the failure cause and still returns the generic error', function () {
    $src = smtp_module_file('App/Http/Actions/Synchronize.php');
    smtp_assert(strpos($src, 'Logger::get()') !== false, 'Synchronize catch block must log the failure');
    smtp_assert(strpos($src, "['error' => 'An error ocurred during synchronization']") !== false,
        'Synchronize must keep the generic error return value');
    smtp_assert(strpos($src, 'catch (\\Throwable $logError)') !== false,
        'logging failures must not escape the catch block');
});

smtp_test('S-8: client-area sidebar hook checks for a missing hosting record before using it', function () {
    $src = smtp_module_file('App/Hooks/ClientAreaPrimarySidebar.php');
    smtp_assert(strpos($src, 'Hosting::find($request->get(\'id\'))->packageid') === false,
        'the unchecked find()->packageid dereference must be gone');
    smtp_assert(strpos($src, 'if(!$hosting)') !== false, 'a null check on the hosting record must exist');
});

smtp_test('S-9: product config save hook logs failures instead of swallowing them', function () {
    $src = smtp_module_file('App/Hooks/AdminProductConfigFieldsSave.php');
    smtp_assert(strpos($src, 'do nothing on save') === false, 'the silent catch must be removed');
    smtp_assert(strpos($src, 'Logger::get()') !== false, 'the save hook catch must log the failure');
    smtp_assert(strpos($src, 'catch (\\Throwable $logError)') !== false,
        'logging failures must not break the admin save');
});

smtp_test('S-4: the dead CronInfo page that points at a missing cron script is removed', function () {
    global $hardeningModuleDir;
    $path = $hardeningModuleDir . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'UI' . DIRECTORY_SEPARATOR
        . 'Admin' . DIRECTORY_SEPARATOR . 'ProductConfig' . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . 'CronInfo.php';
    smtp_assert(!file_exists($path), 'CronInfo.php must be removed');

    $refs = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hardeningModuleDir,
        \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
            || strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if (strpos((string) file_get_contents($file->getPathname()), 'CronInfo') !== false) {
            $refs[] = $file->getPathname();
        }
    }
    smtp_assert($refs === [], 'nothing may reference CronInfo: ' . implode(', ', $refs));
});
