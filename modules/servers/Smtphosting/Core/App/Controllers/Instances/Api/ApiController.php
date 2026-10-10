<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Instances\Api;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Interfaces\DefaultController;

/**
 * Placeholder for the module API action controller.
 *
 * The repository has no API specification, no api/routes.php or api/config.php
 * under App/Config, and no App\Http\Api namespace, so this controller is not
 * instantiated anywhere. It loads correctly and fails closed if called.
 * Core/Api/Http.php is an orphaned, unconfigured framework and is not used.
 */
class ApiController implements DefaultController
{
    public function execute($params = null)
    {
        throw new \RuntimeException(
            'Smtphosting API actions are not implemented in this build: no API specification is configured.'
        );
    }

    public function runExecuteProcess($params = null)
    {
        return $this->execute($params);
    }
}
