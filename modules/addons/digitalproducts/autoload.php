<?php
/**
 * CloudHost247 Digital Products — small PSR-4 loader.
 *
 * The addon is deliberately self contained. WHMCS may load an optional
 * Composer autoloader before this file; this loader only owns the
 * DigitalProducts namespace.
 */
if (defined('DIGITALPRODUCTS_AUTOLOADED')) {
    return;
}
define('DIGITALPRODUCTS_AUTOLOADED', true);

if (!defined('DIGITALPRODUCTS_ROOT')) {
    define('DIGITALPRODUCTS_ROOT', __DIR__);
}
if (!defined('DIGITALPRODUCTS_VERSION')) {
    define('DIGITALPRODUCTS_VERSION', '2.0.0');
}

$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;
}

spl_autoload_register(function ($class) {
    $prefix = 'DigitalProducts\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
        return;
    }
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Exceptions are shared by multiple lazily-loaded primitives.
$exceptions = __DIR__ . '/lib/Core/Exceptions.php';
if (is_file($exceptions)) {
    require_once $exceptions;
}
