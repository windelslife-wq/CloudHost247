<?php

/**
 * Test bootstrap for the Smtphosting module.
 *
 * Loads the module's own classes via a PSR-4 style autoloader for the
 * ModulesGarden\ProductsReseller\Server\Smtphosting\ namespace, without
 * requiring a WHMCS installation. Compatible with PHP 7.2+.
 */

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

spl_autoload_register(function ($class) {
    $prefix = 'ModulesGarden\\ProductsReseller\\Server\\Smtphosting\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', DS, substr($class, strlen($prefix)));
    $file     = dirname(__DIR__) . DS . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
