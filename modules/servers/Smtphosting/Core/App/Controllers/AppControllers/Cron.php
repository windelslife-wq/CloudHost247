<?php

namespace ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppControllers;

use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\AppController as BaseAppController;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Interfaces\AppController;

/**
 * Dispatcher for scheduled work routed through the AppController pattern.
 *
 * This build defines no scheduled jobs: nothing routes to this class, the
 * Core/CommandLine queue framework has no registered job classes, and no
 * cron/cron.php entry point exists. Fail closed rather than silently doing
 * nothing, so an accidental caller gets an explicit error.
 */
class Cron extends BaseAppController implements AppController
{
    public function getControllerInstanceClass($callerName, $params)
    {
        throw new \RuntimeException(
            'Smtphosting cron jobs are not implemented in this build: no scheduled jobs are registered.'
        );
    }
}
