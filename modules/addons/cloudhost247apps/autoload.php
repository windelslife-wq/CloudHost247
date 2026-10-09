<?php
/**
 * CloudHost247 App Cloud — PSR-4 autoloader.
 *
 * The module has no Composer dependencies: the manifest format is a documented
 * YAML subset parsed by Ch247Apps\Core\Yaml, HTTP is cURL, crypto is OpenSSL.
 * When a vendor/ directory exists (an operator-installed SDK for a DNS or object
 * storage provider) its autoloader is loaded first, then the PSR-4 fallback
 * below always resolves `Ch247Apps\` to lib/.
 *
 * @package Ch247Apps
 */

if (defined('CH247APPS_AUTOLOADED')) {
    return;
}
define('CH247APPS_AUTOLOADED', true);

if (!defined('CH247APPS_ROOT')) {
    define('CH247APPS_ROOT', __DIR__);
}
if (!defined('CH247APPS_VERSION')) {
    define('CH247APPS_VERSION', '1.11.1');
}
if (!defined('CH247APPS_MANIFEST_PATH')) {
    define('CH247APPS_MANIFEST_PATH', __DIR__ . '/manifests');
}

$ch247AppsComposer = __DIR__ . '/vendor/autoload.php';
if (file_exists($ch247AppsComposer)) {
    require_once $ch247AppsComposer;
}

spl_autoload_register(function ($class) {
    $prefix = 'Ch247Apps\\';
    $length = strlen($prefix);
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, $length));
    // Defensive: never allow traversal out of lib/.
    if (strpos($relative, '..') !== false) {
        return;
    }
    $file = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// The exception hierarchy lives in one file (one class per file would be ten
// near-empty files), so PSR-4 cannot resolve it lazily. Load it eagerly: it is
// tiny, dependency free, and every other class may need to throw it.
require_once __DIR__ . '/lib/Core/Exceptions.php';
