<?php
/** Explicit queue boundary between App Cloud deployments and customer VMs. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;

class JobDispatcher
{
    private $orchestrator;
    private $providerWorker;
    private $accountWorker;
    private $queue;

    public function __construct(Orchestrator $orchestrator, ServerProvisioningWorker $providerWorker,
        ProviderAccountVerifyWorker $accountWorker, JobQueue $queue = null)
    {
        $this->orchestrator = $orchestrator;
        $this->providerWorker = $providerWorker;
        $this->accountWorker = $accountWorker;
        $this->queue = $queue ?: new JobQueue();
    }

    public function runJob(array $job)
    {
        $type = isset($job['job_type']) ? (string) $job['job_type'] : '';
        $isInfrastructureJob = $type === JobQueue::TYPE_PROVIDER_ACCOUNT_VERIFY
            || ServerProvisioningWorker::handles($type);
        if ($isInfrastructureJob && (string) (isset($job['queue']) ? $job['queue'] : '') !== JobQueue::QUEUE_PROVISIONING) {
            $this->queue->fail((int) $job['id'], 'Infrastructure jobs must be leased from the provisioning queue.',
                'INFRASTRUCTURE_QUEUE_MISMATCH', false);
            return ['status' => 'failed', 'error' => ['code' => 'INFRASTRUCTURE_QUEUE_MISMATCH']];
        }
        if ($type === JobQueue::TYPE_PROVIDER_ACCOUNT_VERIFY) {
            return $this->accountWorker->runJob($job);
        }
        if (ServerProvisioningWorker::handles($type)) {
            return $this->providerWorker->runJob($job);
        }
        // customer_server_id is never treated as a deployment-target server_id.
        if (!empty($job['customer_server_id'])) {
            $jobId = (int) $job['id'];
            $this->queue->fail($jobId, 'A customer-server job has an unrecognized lifecycle type.',
                'UNKNOWN_CUSTOMER_SERVER_JOB', false);
            return ['status' => 'failed', 'error' => ['code' => 'UNKNOWN_CUSTOMER_SERVER_JOB']];
        }
        return $this->orchestrator->runJob($job);
    }
}
