<?php
/** Explicit queue boundary between App Cloud deployments and customer VMs. */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\ControlPanels\PanelAccountWorker;
use Ch247Apps\Core\Actor;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;

class JobDispatcher
{
    private $orchestrator;
    private $providerWorker;
    private $accountWorker;
    private $panelWorker;
    private $contaboWorker;
    private $queue;

    public function __construct(Orchestrator $orchestrator, ServerProvisioningWorker $providerWorker,
        ProviderAccountVerifyWorker $accountWorker, JobQueue $queue = null, PanelAccountWorker $panelWorker = null)
    {
        $this->orchestrator = $orchestrator;
        $this->providerWorker = $providerWorker;
        $this->accountWorker = $accountWorker;
        $this->queue = $queue ?: new JobQueue();
        $this->panelWorker = $panelWorker ?: new PanelAccountWorker(Actor::system('Control-panel worker'), $this->queue);
        $this->contaboWorker = new ContaboAdoptionWorker(Actor::system('Contabo adoption worker'), $this->queue);
    }

    public function runJob(array $job)
    {
        $type = isset($job['job_type']) ? (string) $job['job_type'] : '';
        $isInfrastructureJob = $type === JobQueue::TYPE_CONTABO_ADOPT
            || $type === JobQueue::TYPE_PROVIDER_ACCOUNT_VERIFY
            || ServerProvisioningWorker::handles($type);
        if ($isInfrastructureJob && (string) (isset($job['queue']) ? $job['queue'] : '') !== JobQueue::QUEUE_PROVISIONING) {
            $this->queue->fail((int) $job['id'], 'Infrastructure jobs must be leased from the provisioning queue.',
                'INFRASTRUCTURE_QUEUE_MISMATCH', false);
            return ['status' => 'failed', 'error' => ['code' => 'INFRASTRUCTURE_QUEUE_MISMATCH']];
        }
        $isPanelJob = PanelAccountWorker::handles($type);
        if ($isPanelJob) {
            // The worker owns mismatch failure cleanup so the panel-account
            // reservation is cleared together with the rejected queue job.
            return $this->panelWorker->runJob($job);
        }
        if ($type === JobQueue::TYPE_CONTABO_ADOPT) {
            return $this->contaboWorker->runJob($job);
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
        if (!empty($job['panel_account_id'])) {
            $jobId = (int) $job['id'];
            $this->queue->fail($jobId, 'A panel-account job has an unrecognized lifecycle type.',
                'UNKNOWN_PANEL_ACCOUNT_JOB', false);
            return ['status' => 'failed', 'error' => ['code' => 'UNKNOWN_PANEL_ACCOUNT_JOB']];
        }
        return $this->orchestrator->runJob($job);
    }
}
