<?php
$hookManager->register(
    function ($args) {
        try
        {
            if(!\ModulesGarden\ProductsReseller\Server\Smtphosting\App\Helpers\ResellerModuleChecker::isProperProduct($args['pid']))
            {
                return;
            }
            //todo product/module chceck
            $configController = new  \ModulesGarden\ProductsReseller\Server\Smtphosting\Core\App\Controllers\Instances\Addon\ConfigOptions();
            $configController->runExecuteProcess($args);
        }
        catch (\Exception $exc)
        {
            // Do not break the admin product save, but record the failure.
            try
            {
                \ModulesGarden\ProductsReseller\Server\Smtphosting\Core\HandlerError\Logger::get()
                    ->error('Product config save hook failed: ' . $exc->getMessage());
            }
            catch (\Throwable $logError)
            {
                // Logging must never change the result of the save.
            }
        }
    },
    100
);
