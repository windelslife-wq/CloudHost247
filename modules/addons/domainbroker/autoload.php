<?php
/**
 * Domain Broker — PSR-4 autoloader.
 *
 * The module has no Composer dependencies. When a vendor/ directory exists
 * (optional escrow/registrar SDKs installed by the operator) its autoloader is
 * loaded first; the PSR-4 fallback below always resolves the module's own
 * `DomainBroker\` namespace to lib/.
 *
 * @package DomainBroker
 */

if (defined('DOMAINBROKER_AUTOLOADED')) {
    return;
}
define('DOMAINBROKER_AUTOLOADED', true);

if (!defined('DOMAINBROKER_ROOT')) {
    define('DOMAINBROKER_ROOT', __DIR__);
}
if (!defined('DOMAINBROKER_VERSION')) {
    define('DOMAINBROKER_VERSION', '1.0.0');
}

$domainBrokerComposer = __DIR__ . '/vendor/autoload.php';
if (file_exists($domainBrokerComposer)) {
    require_once $domainBrokerComposer;
}

spl_autoload_register(function ($class) {
    $prefix = 'DomainBroker\\';
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

// The exception hierarchy lives in a single file (one class per file would be
// nine near-empty files), so PSR-4 cannot resolve it lazily. Load it eagerly:
// it is tiny, has no dependencies, and every other class may need to throw.
require_once __DIR__ . '/lib/Core/Exceptions.php';
