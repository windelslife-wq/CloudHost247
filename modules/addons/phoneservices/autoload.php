<?php
/**
 * PhoneServices autoloader.
 *
 * Uses the Composer autoloader when the module's dependencies have been
 * installed (`composer install` inside this directory). When vendor/ is not
 * present the module still works for everything that only relies on its own
 * classes, thanks to the PSR-4 fallback registered below.
 */

if (defined('PHONESERVICES_AUTOLOADED')) {
    return;
}
define('PHONESERVICES_AUTOLOADED', true);

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function ($class) {
    $prefix = 'PhoneServices\\';
    $length = strlen($prefix);
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, $length));
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
