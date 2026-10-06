<?php
/**
 * PSR-4 autoloader for the Chs\ root namespace + module helper functions.
 *
 * Loaded from cloudhost247services.php (addon entry), hooks.php,
 * cron/cloudhost247services.php and the offline test harness (tests/*).
 *
 * @package Chs
 */

if (!defined('CHS_MODULE_NAME')) {
    define('CHS_MODULE_NAME', 'cloudhost247services');
}
if (!defined('CHS_MODULE_DIR')) {
    define('CHS_MODULE_DIR', __DIR__);
}
if (!defined('CHS_ROOT')) {
    // modules/addons/cloudhost247services → three levels up is the install root.
    define('CHS_ROOT', dirname(dirname(dirname(__DIR__))));
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'Chs\\') !== 0) {
        return;
    }
    $relative = substr($class, 4);
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }
    // Every Chs\* exception class lives together in Core/Exceptions.php.
    if (preg_match('/Exception$/', $class) === 1) {
        $alt = __DIR__ . '/lib/Core/Exceptions.php';
        if (is_file($alt)) {
            require $alt;
        }
    }
});

require_once __DIR__ . '/lib/Http/functions.php';
