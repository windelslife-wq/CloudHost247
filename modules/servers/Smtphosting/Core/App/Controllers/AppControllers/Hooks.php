<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppController as BaseAppController;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Interfaces\AppController;

/**
 * Dispatcher placeholder for hook routing through the AppController pattern.
 *
 * Real WHMCS hooks are not routed through this class. hooks.php loads them via
 * Core/Hook/HookManager from App/Hooks/*.php (for example
 * AdminProductConfigFieldsSave.php and ClientAreaPrimarySidebar.php). Nothing
 * routes to this class, so it fails closed if reached.
 */
class Hooks extends BaseAppController implements AppController
{
    public function getControllerInstanceClass($callerName, $params)
    {
        throw new \RuntimeException(
            'Smtphosting AppController hooks are not implemented in this build: hooks are loaded from App/Hooks.'
        );
    }
}
