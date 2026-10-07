<?php
/** Small PSR-4 loader for the native WHMCS Cloudflare integration. */
if (!defined('CH247CF_MODULE_DIR')) {
    define('CH247CF_MODULE_DIR', __DIR__);
}
if (!defined('CH247CF_ROOT')) {
    // modules/addons/cloudhost247cloudflare -> WHMCS root.
    define('CH247CF_ROOT', dirname(dirname(dirname(__DIR__))));
}

spl_autoload_register(function ($class) {
    $prefix = 'CloudHost247\\Cloudflare\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
        return;
    }
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
        return;
    }
    if (preg_match('/Exception$/', $class) === 1) {
        require_once __DIR__ . '/lib/Core/Exceptions.php';
    }
});

require_once __DIR__ . '/lib/Core/Exceptions.php';
