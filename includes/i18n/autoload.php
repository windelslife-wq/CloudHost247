<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * Minimal PSR-4 autoloader for the `CloudHost247\I18n\` namespace.
 *
 * The repository deliberately ships no Composer autoloader at this level: it
 * is an overlay copied on top of a WHMCS install, so a self-contained
 * spl_autoload_register keeps the module installable with a plain rsync.
 *
 * Safe to require more than once.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

if (!defined('CLOUDHOST247_I18N_AUTOLOAD_REGISTERED')) {
    define('CLOUDHOST247_I18N_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(function ($class) {
        $prefix = 'CloudHost247\\I18n\\';
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($file)) {
            /** @noinspection PhpIncludeInspection */
            require $file;
        }
    });
}
