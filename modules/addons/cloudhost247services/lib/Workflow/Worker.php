<?php
/**
 * Combined worker: one drain pass over every known job type (domain platform
 * + infrastructure platform). The cron calls Worker::run(); DomainWorker and
 * InfraWorker stay available individually for tests and narrow drains.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

use Chs\Core\Settings;

class Worker
{
    /**
     * @return callable[] job type => handler(array $job): void
     */
    public static function handlers()
    {
        return array_merge(DomainWorker::handlers(), InfraWorker::handlers());
    }

    /**
     * Drain due jobs. @return array{ran:int, completed:int, failed:int}
     */
    public static function run($maxJobs = null, $leaseSeconds = null)
    {
        $maxJobs = $maxJobs === null ? max(1, Settings::int('jobs_per_run', 25)) : (int) $maxJobs;
        $leaseSeconds = $leaseSeconds === null ? max(30, Settings::int('jobs_lease_seconds', 300)) : (int) $leaseSeconds;
        return (new JobQueue())->run(self::handlers(), $maxJobs, $leaseSeconds);
    }
}
