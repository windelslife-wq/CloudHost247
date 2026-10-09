<?php
/**
 * Infrastructure / server-provisioning job types.
 *
 * Every long-running or provider-touching server operation runs as a job on
 * the mod_chs_jobs queue — never inline in an HTTP request. Provisioning and
 * reinstall jobs are idempotent: their idempotency keys make a retried
 * delivery a no-op instead of a double-created server.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

final class InfraJobTypes
{
    public const PROVISION         = 'SERVER_PROVISION';
    public const REINSTALL         = 'SERVER_REINSTALL';
    public const ACTION            = 'SERVER_ACTION';
    public const HEALTH_CHECK      = 'SERVER_HEALTH_CHECK';
    public const PROVIDER_SYNC     = 'INFRA_PROVIDER_SYNC';

    /** @return array<string,string> type => human label */
    public static function labels()
    {
        return [
            self::PROVISION     => 'Server provisioning',
            self::REINSTALL     => 'OS reinstall',
            self::ACTION        => 'Server action',
            self::HEALTH_CHECK  => 'Server health check',
            self::PROVIDER_SYNC => 'Provider state sync',
        ];
    }

    /** @param string $type @return bool */
    public static function isKnown($type)
    {
        return array_key_exists((string) $type, self::labels());
    }
}
