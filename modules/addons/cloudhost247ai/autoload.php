<?php
/**
 * PSR-4 autoloader for the Ch247Ai\ namespace + shared display helpers.
 *
 * Loaded from cloudhost247ai.php, hooks.php, cron/ and the offline tests.
 *
 * @package Ch247Ai
 */

if (!defined('CH247AI_MODULE_NAME')) {
    define('CH247AI_MODULE_NAME', 'cloudhost247ai');
}
if (!defined('CH247AI_MODULE_DIR')) {
    define('CH247AI_MODULE_DIR', __DIR__);
}
if (!defined('CH247AI_ROOT')) {
    // modules/addons/cloudhost247ai -> three levels up is the WHMCS root.
    define('CH247AI_ROOT', dirname(dirname(dirname(__DIR__))));
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'Ch247Ai\\') !== 0) {
        return;
    }
    $relative = substr($class, 8);
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }
    // All exception classes live together in Core/Exceptions.php.
    if (preg_match('/(Exception|Ch247AiRefused)$/', $class) === 1) {
        $alt = __DIR__ . '/lib/Core/Exceptions.php';
        if (is_file($alt)) {
            require $alt;
        }
    }
});

require_once __DIR__ . '/lib/Http/functions.php';
