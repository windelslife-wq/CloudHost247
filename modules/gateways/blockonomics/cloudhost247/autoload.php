<?php
/**
 * CloudHost247 Blockonomics governance layer autoloader.
 *
 * Safe to require from the gateway entry, the payment page, the callback
 * and the CloudHost247 addon admin — all paths are self-contained; WHMCS
 * Capsule is only touched by CapsuleStore at runtime.
 */

spl_autoload_register(function ($class) {
    $prefix = 'CloudHost247\\Blockonomics\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
