<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppController as BaseAppController;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Interfaces\AppController;

/**
 * Dispatcher for the "api" caller (see Application::getControllerClass()).
 *
 * No WHMCS entry point routes to "<module>_api" and the repository contains no
 * API specification or route/config files, so this controller deliberately
 * fails closed with an explicit error instead of returning null or fataling.
 * Implement a real API only against a written specification.
 */
class Api extends BaseAppController implements AppController
{
    public function getControllerInstanceClass($callerName, $params)
    {
        throw new \RuntimeException(
            'Smtphosting API is not implemented in this build: no API specification or route is configured.'
        );
    }
}
