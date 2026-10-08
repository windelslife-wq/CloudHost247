<?php
/** Loads reviewed provider adapters consistently in WHMCS requests and workers. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\ConfigurationException;

class ProviderBootstrap
{
    private static $booted = false;

    /** Load the optional, operator-maintained providers/bootstrap.php file once. */
    public static function boot()
    {
        if (self::$booted) {
            return ProviderRegistry::catalog();
        }
        self::$booted = true;
        $root = defined('CH247APPS_ROOT') ? CH247APPS_ROOT : dirname(__DIR__, 2);
        $file = $root . '/providers/bootstrap.php';
        if (!is_file($file)) {
            return ProviderRegistry::catalog();
        }
        if (!is_readable($file)) {
            self::$booted = false;
            throw new ConfigurationException('The configured provider bootstrap file is not readable.');
        }

        try {
            $adapters = require $file;
            // Bootstrap files may return adapter instances for an explicit
            // registration loop, or register them directly as side effects.
            if (is_array($adapters)) {
                foreach ($adapters as $adapter) {
                    if (!($adapter instanceof InfrastructureProviderInterface)) {
                        throw new ConfigurationException('The provider bootstrap returned an invalid adapter.');
                    }
                    if (!ProviderRegistry::hasAdapter($adapter->key())) {
                        ProviderRegistry::register($adapter);
                    }
                }
            }
        } catch (\Throwable $e) {
            self::$booted = false;
            throw $e;
        }
        return ProviderRegistry::catalog();
    }

    /** Test seam; normal application requests never reset the bootstrap. */
    public static function reset()
    {
        self::$booted = false;
    }
}
