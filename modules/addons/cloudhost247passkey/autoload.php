<?php
/**
 * PSR-4-style autoloader for the native CloudHost247 Passkey addon.
 *
 * @package CloudHost247\Passkey
 */

if (!defined('CH247PK_MODULE_DIR')) {
    define('CH247PK_MODULE_DIR', __DIR__);
}

$ch247pkComposerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($ch247pkComposerAutoload)) {
    require_once $ch247pkComposerAutoload;
}

spl_autoload_register(function ($class) {
    $prefix = 'CloudHost247\\Passkey\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
