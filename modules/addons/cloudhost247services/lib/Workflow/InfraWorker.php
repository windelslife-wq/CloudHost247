<?php
/**
 * Infrastructure worker.
 *
 * Dispatches server-provisioning queue jobs to the provisioning service.
 * Like the domain worker, it runs from the module cron (or a host queue
 * worker) — never inside an HTTP request.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

use Chs\Providers\Infrastructure\InfraProviderRegistry;
use Chs\Services\ServerProvisioningService;

class InfraWorker
{
    /** @var InfraProviderRegistry|null test seam */
    public static $registryOverride;

    /** Registry used by all handlers (override only in tests). */
    public static function registry()
    {
        return self::$registryOverride ?: new InfraProviderRegistry();
    }

    /**
     * @return callable[] job type => handler(array $job): void
     */
    public static function handlers()
    {
        return [
            InfraJobTypes::PROVISION => function (array $job) {
                (new ServerProvisioningService(self::registry()))->executeProvision($job);
            },
            InfraJobTypes::REINSTALL => function (array $job) {
                (new ServerProvisioningService(self::registry()))->executeReinstall($job);
            },
            InfraJobTypes::ACTION => function (array $job) {
                (new ServerProvisioningService(self::registry()))->executeAction($job);
            },
            InfraJobTypes::HEALTH_CHECK => function (array $job) {
                (new ServerProvisioningService(self::registry()))->executeHealthCheck($job);
            },
            InfraJobTypes::PROVIDER_SYNC => function (array $job) {
                unset($job);
                (new ServerProvisioningService(self::registry()))->syncProviderStates();
                self::registry()->healthCheckAll();
            },
        ];
    }
}
