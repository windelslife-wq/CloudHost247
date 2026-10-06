<?php
/**
 * PSR-4 autoloader for the Ch247Mkt\ namespace + shared display helpers.
 *
 * Loaded from cloudhost247marketing.php, hooks.php, track.php, cron/ and the
 * offline tests.
 *
 * @package Ch247Mkt
 */

if (!defined('CH247M_MODULE_NAME')) {
    define('CH247M_MODULE_NAME', 'cloudhost247marketing');
}
if (!defined('CH247M_MODULE_DIR')) {
    define('CH247M_MODULE_DIR', __DIR__);
}
if (!defined('CH247M_ROOT')) {
    // modules/addons/cloudhost247marketing -> three levels up is the WHMCS root.
    define('CH247M_ROOT', dirname(dirname(dirname(__DIR__))));
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'Ch247Mkt\\') !== 0) {
        return;
    }
    $relative = substr($class, 9);
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }
    // All exception classes live together in Core/Exceptions.php.
    if (preg_match('/Exception$/', $class) === 1) {
        $alt = __DIR__ . '/lib/Core/Exceptions.php';
        if (is_file($alt)) {
            require $alt;
        }
    }
});

require_once __DIR__ . '/lib/Http/functions.php';
