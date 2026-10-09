<?php
/**
 * Merged job-type registry.
 *
 * The queue is generic; the domain platform and the infrastructure platform
 * each declare their own type sets. JobTypes is the single source of truth
 * for "is this a known job type" and the admin Operations type filter.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

final class JobTypes
{
    /** @return array<string,string> type => human label (domain + infra) */
    public static function labels()
    {
        return DomainJobTypes::labels() + InfraJobTypes::labels();
    }

    /** @param string $type @return bool */
    public static function isKnown($type)
    {
        return DomainJobTypes::isKnown($type) || InfraJobTypes::isKnown($type);
    }
}
