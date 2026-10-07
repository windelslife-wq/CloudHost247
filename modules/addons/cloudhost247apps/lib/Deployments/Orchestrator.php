<?php
/**
 * CloudHost247 App Cloud — deployment orchestrator.
 *
 * Runs one deployment from start to finish inside the worker: asks the adapter
 * for a step plan, executes the steps in order, records every step and every
 * resource created, watches for cancellation and timeouts, and compensates
 * (rolls back) when a step fails for good.
 *
 * Design rules this file exists to keep:
 *   • the orchestrator has no idea whether it is deploying Docker, cPanel or
 *     Kubernetes — it only speaks AdapterInterface
 *   • steps are resumed, not repeated: an attempt that already succeeded is
 *     skipped, so a retry after a transient failure does not recreate resources
 *   • a transient failure does not roll back; it is requeued with backoff. Only a
 *     permanent failure compensates, because tearing down a deployment that could
 *     have succeeded is worse than waiting
 *   • nothing created is ever left unrecorded: resources go into the ledger the
 *     moment the step reports them, and anything that cannot be removed ends up
 *     marked `leaked` instead of silently forgotten
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Deployments;

use Ch247Apps\Adapters\AdapterFactory;
use Ch247Apps\Adapters\AdapterInterface;
use Ch247Apps\Adapters\DeploymentContext;
use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Servers\ServerService;

class Orchestrator
{
    /** @var Actor */
    private $actor;

    /** @var DeploymentService */
    private $deployments;

    /** @var JobQueue */
    private $queue;

    /** @var AdapterInterface|null */
    private $adapterOverride;

    /** @var array|null the job row being run */
    private $job;

    public function __construct(Actor $actor = null, DeploymentService $deployments = null, JobQueue $queue = null)
    {
        $this->actor = $actor ?: Actor::system('Orchestrator');
        $this->deployments = $deployments ?: new DeploymentService($this->actor);
        $this->queue = $queue ?: new JobQueue();
    }

    /** Force an adapter (tests, dry runs). */
    public function useAdapter(AdapterInterface $adapter = null)
    {
        $this->adapterOverride = $adapter;
        return $this;
    }

    /* ---------------------------------------------------------------- worker */

    /**
     * Run one leased job.
     *
     * @return array{status: string, deployment_id: int|null, result?: array, error?: array}
     * @throws \Throwable re-thrown retryable failures so the queue can back off
     */
    public function runJob(array $job)
    {
        $this->job = $job;
        $jobId = (int) $job['id'];
        $payload = $this->queue->payload($job);

        $deploymentId = !empty($job['deployment_id']) ? (int) $job['deployment_id']
            : (!empty($payload['deployment_id']) ? (int) $payload['deployment_id'] : null);
        if ($deploymentId === null) {
            $this->queue->fail($jobId, 'The job does not reference a deployment.', 'JOB_MISSING_DEPLOYMENT', false);
            return ['status' => 'failed', 'deployment_id' => null,
                'error' => ['code' => 'JOB_MISSING_DEPLOYMENT', 'message' => 'No deployment reference.']];
        }

        Db::update('jobs', ['deployment_id' => $deploymentId], ['id' => $jobId]);
        Db::update('deployments', ['job_id' => $jobId], ['id' => $deploymentId, 'job_id' => null]);

        $this->queue->start($jobId, isset($job['leased_by']) ? $job['leased_by'] : 'worker');

        try {
            $result = $this->run($deploymentId);
            $this->queue->complete($jobId, $result);
            return ['status' => 'completed', 'deployment_id' => $deploymentId, 'result' => $result];
        } catch (\Throwable $e) {
            $retryable = $e instanceof AppsException ? $e->isRetryable() : false;
            $code = $e instanceof AppsException ? $e->errorCode() : 'DEPLOYMENT_FAILED';
            $this->queue->fail($jobId, $e->getMessage(), $code, $retryable, $e);
            if ($retryable) {
                // Let the queue's backoff decide when to try again.
                throw $e;
            }
            return ['status' => 'failed', 'deployment_id' => $deploymentId,
                'error' => ['code' => $code, 'message' => $e->getMessage(), 'retryable' => false]];
        }
    }

    /* ------------------------------------------------------------------- run */

    /**
     * Execute a deployment record.
     *
     * @return array{status: string, steps: array, resources: array, duration_ms: int|null}
     */
    public function run($deploymentId)
    {
        $row = $this->deployments->row($deploymentId);
        if (in_array((string) $row['status'], [DeploymentService::STATUS_SUCCEEDED,
            DeploymentService::STATUS_CANCELLED, DeploymentService::STATUS_ROLLED_BACK,
            DeploymentService::STATUS_TIMEOUT], true)) {
            // Finished, cancelled or already compensated: a duplicate dispatch must
            // never deploy (or roll back) twice. A *failed* deployment is still
            // runnable, because that is exactly what a retry is.
            return ['status' => (string) $row['status'], 'steps' => [], 'resources' => [],
                'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
                'note' => 'Deployment had already finished (' . $row['status'] . ').'];
        }

        $context = DeploymentContext::load((int) $row['installation_id'], (string) $row['action'], $this->actor, [
            'deployment_id' => (int) $row['id'],
            'version_id' => !empty($row['payload']) ? $this->payloadValue($row, 'version_id') : null,
            'remove_data' => $this->payloadValue($row, 'remove_data'),
        ]);
        $adapter = $this->adapter($context);

        $running = $this->deployments->markRunning((int) $row['id']);
        $this->deployments->log((int) $row['id'], 'Deployment started (' . $row['action'] . ', adapter: '
            . $adapter->name() . ', attempt ' . (int) $running['attempts'] . ').', 'info', 'worker');

        $plan = $adapter->plan($context);
        $steps = $this->prepareSteps((int) $row['id'], $plan);
        $this->applyLifecycleStatus($context, 'start');

        $timeoutSeconds = Settings::int('deployment_timeout_seconds', 3600);
        $startedAt = !empty($running['started_at']) ? $running['started_at'] : Clock::now();
        $completedSteps = 0;
        $failure = null;
        $failedStepId = null;

        foreach ($plan as $planned) {
            $key = (string) $planned['key'];
            $step = $steps[$key];

            if (in_array((string) $step['status'], [DeploymentService::STEP_SUCCEEDED,
                DeploymentService::STEP_SKIPPED], true)) {
                // A previous attempt already did this work.
                $completedSteps++;
                continue;
            }

            if ($this->deployments->cancelRequested((int) $row['id']) || $this->jobCancelRequested()) {
                $this->deployments->log((int) $row['id'], 'Cancellation honoured before "' . $planned['name'] . '".',
                    'warning', 'worker');
                $this->rollback((int) $row['id'], 'Cancelled before step ' . $key, $adapter, $context);
                return $this->outcome((int) $row['id'], $context, $adapter);
            }

            if (Clock::diffSeconds($startedAt, Clock::now()) > $timeoutSeconds) {
                $this->deployments->markTimeout((int) $row['id']);
                $this->deployments->log((int) $row['id'], 'Deployment exceeded ' . $timeoutSeconds . 's and was stopped.',
                    'error', 'worker');
                $this->rollback((int) $row['id'], 'Timed out at step ' . $key, $adapter, $context);
                return $this->outcome((int) $row['id'], $context, $adapter);
            }

            $this->touchLease();
            $step = $this->deployments->startStep((int) $step['id'], (int) $row['id']);
            $this->deployments->log((int) $row['id'], 'Running "' . $planned['name'] . '".', 'debug', 'worker',
                (int) $step['id']);

            try {
                $result = $adapter->executeStep($key, $context);
                $resources = isset($result['resources']) && is_array($result['resources']) ? $result['resources'] : [];
                $this->deployments->completeStep((int) $step['id'],
                    isset($result['output']) ? $result['output'] : [],
                    $resources,
                    isset($result['message']) ? (string) $result['message'] : '');
                $completedSteps++;
                $this->deployments->progress((int) $row['id'],
                    (int) floor($completedSteps / max(1, count($plan)) * 100), $planned['name']);
                if (isset($result['output']['logs']) && is_array($result['output']['logs'])) {
                    foreach ($result['output']['logs'] as $line) {
                        $this->deployments->log((int) $row['id'], (string) $line, 'info', 'agent', (int) $step['id']);
                    }
                }
            } catch (\Throwable $e) {
                $code = $e instanceof AppsException ? $e->errorCode() : 'STEP_FAILED';
                $retryable = $e instanceof AppsException ? $e->isRetryable() : false;
                $this->deployments->failStep((int) $step['id'], $e->getMessage(), $code,
                    $e instanceof AppsException ? $e->context() : []);
                $failure = $e;
                $failedStepId = (int) $step['id'];

                $attempts = (int) $running['attempts'];
                $maxAttempts = $this->job ? (int) $this->job['max_attempts'] : Settings::int('job_max_attempts', 5);
                if ($retryable && $attempts < $maxAttempts) {
                    // Transient: keep what exists, let the queue retry, resume here.
                    $this->deployments->markFailed((int) $row['id'], $e->getMessage(), $code, $failedStepId,
                    ['result' => Str::jsonEncode(['retryable' => true, 'attempt' => $attempts])]);
                    $this->applyLifecycleStatus($context, 'retry');
                    throw $e;
                }
                break;
            }
        }

        if ($failure !== null) {
            $code = $failure instanceof AppsException ? $failure->errorCode() : 'DEPLOYMENT_FAILED';
            $this->deployments->markFailed((int) $row['id'], $failure->getMessage(), $code, $failedStepId);
            $this->rollback((int) $row['id'], 'Step failed: ' . $code, $adapter, $context);
            return $this->outcome((int) $row['id'], $context, $adapter);
        }

        $summary = $this->buildSummary($context, $adapter);
        $this->deployments->markSucceeded((int) $row['id'], $summary);
        $this->applyLifecycleStatus($context, 'success', $summary);
        return $this->outcome((int) $row['id'], $context, $adapter);
    }

    /* -------------------------------------------------------------- rollback */

    /**
     * Compensate everything a deployment created, newest first.
     *
     * @return array{removed: int, leaked: int, steps: array}
     */
    public function rollback($deploymentId, $reason = '', AdapterInterface $adapter = null,
        DeploymentContext $context = null)
    {
        $row = $this->deployments->row($deploymentId);
        if ($context === null) {
            try {
                $context = DeploymentContext::load((int) $row['installation_id'], (string) $row['action'],
                    $this->actor, ['deployment_id' => (int) $row['id'], 'remove_data' => false]);
            } catch (\Throwable $e) {
                // Without a context there is nothing an adapter can compensate;
                // the ledger still shows what needs manual cleanup.
                $this->deployments->log((int) $row['id'], 'Rollback could not load the deployment context: '
                    . $e->getMessage(), 'error', 'worker');
                $this->markEverythingLeaked((int) $row['id'], $e->getMessage());
                return $this->finishRollback((int) $row['id'], 0,
                    Db::count('created_resources', ['deployment_id' => (int) $row['id'],
                        'status' => DeploymentService::RESOURCE_LEAKED]), []);
            }
        }
        if ($adapter === null) {
            $adapter = $this->adapter($context);
        }

        $this->deployments->markRollingBack((int) $row['id'], $reason);

        $steps = $this->deployments->steps((int) $row['id']);
        $reversed = array_reverse($steps);
        $rolledBack = [];
        $removed = 0;
        $leaked = 0;

        foreach ($reversed as $step) {
            $status = (string) $step['status'];
            if (!in_array($status, [DeploymentService::STEP_SUCCEEDED, DeploymentService::STEP_RUNNING,
                DeploymentService::STEP_FAILED], true)) {
                continue;
            }
            if (!(int) $step['reversible']) {
                $this->deployments->log((int) $row['id'], 'Step "' . $step['name'] . '" is not reversible; skipped.',
                    'debug', 'worker', (int) $step['id']);
                continue;
            }
            try {
                $result = $adapter->rollbackStep((string) $step['key'], $context);
                $this->deployments->markStepRolledBack((int) $step['id']);
                $message = isset($result['message']) ? (string) $result['message'] : 'Rolled back.';
                $this->deployments->log((int) $row['id'], $message, 'info', 'worker', (int) $step['id']);
                if (!empty($result['leaked'])) {
                    $leaked += $this->markStepResourcesLeaked((int) $step['id'], $message);
                } else {
                    $removed += $this->markStepResourcesRemoved((int) $row['id'], (int) $step['id']);
                }
                $rolledBack[] = $step['key'];
            } catch (\Throwable $e) {
                $message = 'Compensation failed for "' . $step['name'] . '": ' . $e->getMessage();
                $this->deployments->log((int) $row['id'], $message, 'error', 'worker', (int) $step['id']);
                $leaked += $this->markStepResourcesLeaked((int) $step['id'], $e->getMessage());
            }
        }

        // Anything still open on the ledger is leaked: better an operator sees it
        // than a customer's server quietly filling up with orphans.
        foreach ($this->deployments->resourcesFor((int) $row['id'],
            [DeploymentService::RESOURCE_CREATED, DeploymentService::RESOURCE_ROLLBACK_PENDING]) as $resource) {
            $this->deployments->markResourceLeaked((int) $resource['id'], 'Rollback did not remove it.');
            $leaked++;
        }

        return $this->finishRollback((int) $row['id'], $removed, $leaked, $rolledBack);
    }

    private function finishRollback($deploymentId, $removed, $leaked, array $rolledBack)
    {
        $this->deployments->markRolledBack($deploymentId, [
            'removed_resources' => $removed, 'leaked_resources' => $leaked, 'steps_rolled_back' => $rolledBack,
        ]);
        $row = $this->deployments->row($deploymentId);
        $installation = Db::first('installations', ['id' => (int) $row['installation_id']]);
        if ($installation) {
            $this->releaseCapacity($installation);
            try {
                InstallationState::apply((int) $installation['id'], InstallationState::FAILED, [
                    'health_status' => 'unknown',
                    'health_message' => 'Deployment was rolled back.',
                ], $this->actor, 'Deployment rolled back');
            } catch (StateException $e) {
                Logger::warning('Installation status could not follow the rollback.', [
                    'installation_id' => (int) $installation['id'], 'from' => $installation['status'],
                    'error' => $e->getMessage(), 'source' => 'deployments',
                ]);
            }
        }
        return ['removed' => $removed, 'leaked' => $leaked, 'steps' => $rolledBack];
    }

    private function markStepResourcesRemoved($deploymentId, $stepId)
    {
        $step = $this->deployments->stepRow($stepId);
        $resources = Str::jsonDecode(isset($step['context']) ? $step['context'] : null, []);
        $count = 0;
        foreach ($resources as $resource) {
            if (!is_array($resource) || empty($resource['type'])) {
                continue;
            }
            $row = Db::first('created_resources', [
                'deployment_id' => (int) $deploymentId, 'resource_type' => (string) $resource['type'],
                'resource_name' => isset($resource['name']) ? (string) $resource['name'] : '',
            ]);
            if ($row && $this->deployments->markResourceRemoved((int) $row['id'], ['removed_by' => 'rollback'])) {
                $count++;
            }
        }
        return $count;
    }

    private function markStepResourcesLeaked($stepId, $reason)
    {
        $step = $this->deployments->stepRow($stepId);
        $resources = Str::jsonDecode(isset($step['context']) ? $step['context'] : null, []);
        $count = 0;
        foreach ($this->deployments->resourcesFor((int) $step['deployment_id']) as $row) {
            if (!in_array((string) $row['status'], [DeploymentService::RESOURCE_CREATED,
                DeploymentService::RESOURCE_ROLLBACK_PENDING], true)) {
                continue;
            }
            foreach ($resources as $resource) {
                if (is_array($resource) && isset($resource['type']) && $resource['type'] === $row['resource_type']
                    && isset($resource['name']) && $resource['name'] === $row['resource_name']) {
                    $this->deployments->markResourceLeaked((int) $row['id'], $reason);
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    private function markEverythingLeaked($deploymentId, $reason)
    {
        foreach ($this->deployments->resourcesFor((int) $deploymentId) as $row) {
            if (in_array((string) $row['status'], [DeploymentService::RESOURCE_CREATED,
                DeploymentService::RESOURCE_ROLLBACK_PENDING], true)) {
                $this->deployments->markResourceLeaked((int) $row['id'], Str::clip($reason, 300));
            }
        }
    }

    /* ------------------------------------------------------------- internals */

    /** Steps for this deployment, created on the first attempt and reused after. */
    private function prepareSteps($deploymentId, array $plan)
    {
        $existing = $this->deployments->steps($deploymentId);
        if ($existing === []) {
            return $this->deployments->addSteps($deploymentId, $plan);
        }
        $steps = [];
        foreach ($existing as $row) {
            $steps[(string) $row['key']] = $row;
        }
        // A plan may have changed between attempts (a new manifest snapshot):
        // append anything new so no work is silently skipped.
        $missing = [];
        $order = count($existing);
        foreach ($plan as $planned) {
            if (!isset($steps[(string) $planned['key']])) {
                $planned['step_order'] = ++$order;
                $missing[] = $planned;
            }
        }
        if ($missing !== []) {
            foreach ($this->deployments->addSteps($deploymentId, $missing) as $key => $row) {
                $steps[$key] = $row;
            }
        }
        // Reset steps that failed or were mid-flight so the retry can redo them.
        foreach ($steps as $key => $row) {
            if (in_array((string) $row['status'], [DeploymentService::STEP_FAILED,
                DeploymentService::STEP_RUNNING], true)) {
                Db::update('deployment_steps', ['status' => DeploymentService::STEP_PENDING,
                    'error' => null, 'error_code' => null, 'updated_at' => Clock::now()],
                    ['id' => (int) $row['id']]);
                $steps[$key] = $this->deployments->stepRow((int) $row['id']);
            }
        }
        return $steps;
    }

    private function adapter(DeploymentContext $context)
    {
        if ($this->adapterOverride !== null) {
            return $this->adapterOverride;
        }
        return AdapterFactory::forContext($context, $this->actor);
    }

    /** Move the installation's lifecycle status to match what is happening. */
    private function applyLifecycleStatus(DeploymentContext $context, $phase, array $summary = [])
    {
        $action = (string) $context->action;
        $current = (string) $context->installation['status'];
        $target = null;
        $extra = [];

        if ($phase === 'start') {
            switch ($action) {
                case DeploymentService::ACTION_INSTALL:
                case DeploymentService::ACTION_REINSTALL:
                    $target = InstallationState::DEPLOYING;
                    break;
                case DeploymentService::ACTION_UPDATE:
                    $target = InstallationState::UPDATING;
                    break;
                case DeploymentService::ACTION_START:
                    $target = InstallationState::STARTING;
                    break;
                case DeploymentService::ACTION_UNINSTALL:
                    $target = InstallationState::DELETING;
                    break;
                default:
                    $target = null;
            }
        } elseif ($phase === 'success') {
            switch ($action) {
                case DeploymentService::ACTION_INSTALL:
                case DeploymentService::ACTION_REINSTALL:
                case DeploymentService::ACTION_UPDATE:
                case DeploymentService::ACTION_START:
                case DeploymentService::ACTION_RESTART:
                    $state = isset($summary['health']) ? (string) $summary['health'] : 'unknown';
                    $target = $state === 'unhealthy' ? InstallationState::UNHEALTHY : InstallationState::HEALTHY;
                    $extra = ['health_status' => $state,
                        'health_message' => isset($summary['health_message']) ? $summary['health_message'] : null];
                    if (isset($summary['access_url'])) {
                        $extra['access_url'] = $summary['access_url'];
                    }
                    if (isset($summary['version'])) {
                        $extra['current_version'] = $summary['version'];
                    }
                    break;
                case DeploymentService::ACTION_STOP:
                    $target = InstallationState::STOPPED;
                    $extra = ['health_status' => 'unknown'];
                    break;
                case DeploymentService::ACTION_UNINSTALL:
                    $target = InstallationState::DELETED;
                    $extra = ['health_status' => 'unknown'];
                    break;
                default:
                    $target = null;
            }
        } elseif ($phase === 'retry') {
            $target = null; // keep the in-progress status; the queue will retry
        }

        if ($target === null || $target === $current) {
            if ($extra !== []) {
                Db::update('installations', $extra + ['updated_at' => Clock::now()],
                    ['id' => $context->installationId()]);
            }
            return null;
        }
        try {
            $row = InstallationState::apply($context->installationId(), $target, $extra, $this->actor,
                'Deployment ' . $action . ' ' . $phase);
            $context->installation = $row;
            return $target;
        } catch (StateException $e) {
            Logger::warning('Installation status could not follow the deployment.', [
                'installation_id' => $context->installationId(), 'from' => $current, 'to' => $target,
                'error' => $e->getMessage(), 'source' => 'deployments',
            ]);
            return null;
        }
    }

    /** What the engine can honestly report at the end of a successful deployment. */
    private function buildSummary(DeploymentContext $context, AdapterInterface $adapter)
    {
        $summary = [
            'adapter' => $adapter->name(),
            'project' => $context->project,
            'server_id' => $context->serverId(),
            'version' => (string) $context->version['version'],
            'domain' => $context->primaryDomain(),
        ];

        // Only ask for a health verdict when the plan actually probed health.
        $probed = false;
        foreach ($adapter->plan($context) as $step) {
            if ($step['key'] === 'health_check') {
                $probed = true;
                break;
            }
        }
        if ($probed) {
            $health = $adapter->health($context);
            $summary['health'] = isset($health['state']) ? $health['state'] : 'unknown';
            $summary['health_message'] = isset($health['message']) ? $health['message'] : null;
        } else {
            $summary['health'] = 'unknown';
            $summary['health_message'] = 'This action does not verify application health.';
        }

        $domain = $context->primaryDomain();
        if ($domain !== null && $domain !== '') {
            $summary['access_url'] = ($context->sslEnabled() ? 'https://' : 'http://') . $domain;
        }
        $summary['resources'] = $context->resourceLimits();
        return $summary;
    }

    private function outcome($deploymentId, DeploymentContext $context, AdapterInterface $adapter)
    {
        $row = $this->deployments->row($deploymentId);
        return [
            'status' => (string) $row['status'],
            'action' => (string) $row['action'],
            'adapter' => $adapter->name(),
            'steps' => array_map(function ($step) {
                return ['key' => $step['key'], 'name' => $step['name'], 'status' => $step['status'],
                    'error_code' => isset($step['error_code']) ? $step['error_code'] : null];
            }, $this->deployments->steps($deploymentId)),
            'resources' => array_map(function ($resource) {
                return ['type' => $resource['resource_type'], 'name' => $resource['resource_name'],
                    'status' => $resource['status']];
            }, $this->deployments->resourcesFor($deploymentId)),
            'error_code' => isset($row['error_code']) ? $row['error_code'] : null,
            'error_message' => isset($row['error_message']) ? $row['error_message'] : null,
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'installation_status' => (string) Db::first('installations',
                ['id' => $context->installationId()])['status'],
        ];
    }

    /** Give back what the installation reserved when it stops existing. */
    private function releaseCapacity(array $installation)
    {
        if (empty($installation['server_id'])) {
            return;
        }
        $version = Db::first('application_versions', ['id' => (int) $installation['application_version_id']]);
        if (!$version || empty($version['manifest'])) {
            return;
        }
        try {
            $manifest = \Ch247Apps\Catalog\Manifest::fromYaml($version['manifest'], 'version ' . $version['version']);
            $plan = $installation['plan_id'] ? Db::first('plans', ['id' => (int) $installation['plan_id']]) : [];
            $requirements = $manifest->requirements();
            $servers = new ServerService($this->actor);
            $servers->release((int) $installation['server_id'], [
                'cpu_millicores' => max((int) (isset($plan['cpu_millicores']) ? $plan['cpu_millicores'] : 0),
                    (int) $requirements['cpu_min_millicores']),
                'memory_mb' => max((int) (isset($plan['memory_mb']) ? $plan['memory_mb'] : 0),
                    (int) $requirements['memory_min_mb']),
                'storage_mb' => max((int) (isset($plan['storage_mb']) ? $plan['storage_mb'] : 0),
                    (int) $requirements['storage_min_mb']),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('Capacity could not be released for a removed installation.', [
                'installation_id' => (int) $installation['id'], 'error' => $e->getMessage(),
                'source' => 'deployments',
            ]);
        }
    }

    private function touchLease()
    {
        if ($this->job && !empty($this->job['id'])) {
            $this->queue->touch((int) $this->job['id']);
        }
    }

    private function jobCancelRequested()
    {
        return $this->job && !empty($this->job['id']) && $this->queue->cancelRequested((int) $this->job['id']);
    }

    private function payloadValue(array $row, $key)
    {
        $payload = Str::jsonDecode(isset($row['payload']) ? $row['payload'] : null, []);
        return isset($payload[$key]) ? $payload[$key] : null;
    }

    /* -------------------------------------------------------- maintenance cron */

    /**
     * Find deployments that are stuck: running with no live job, or queued for
     * longer than the timeout. Returns what it fixed.
     */
    public function reapStalled()
    {
        $timeout = Settings::int('deployment_timeout_seconds', 3600);
        $reaped = ['timed_out' => 0, 'orphaned' => 0];
        foreach (Db::fetch('deployments', ['status' => ['in', [DeploymentService::STATUS_RUNNING,
            DeploymentService::STATUS_QUEUED, DeploymentService::STATUS_ROLLING_BACK]]]) as $row) {
            $since = !empty($row['started_at']) ? $row['started_at'] : $row['created_at'];
            $age = Clock::diffSeconds($since, Clock::now());
            $job = $row['job_id'] ? Db::first('jobs', ['id' => (int) $row['job_id']]) : null;
            $jobLive = $job && in_array((string) $job['status'], JobQueue::LIVE, true);

            if ($age > $timeout && !$jobLive) {
                $this->deployments->markTimeout((int) $row['id']);
                $this->deployments->log((int) $row['id'], 'Reaped: no worker was running this deployment.',
                    'error', 'cron');
                $reaped['timed_out']++;
            } elseif (!$jobLive && (string) $row['status'] === DeploymentService::STATUS_QUEUED && $age > 300) {
                // Queued with no job: nothing will ever pick it up.
                $this->deployments->markFailed((int) $row['id'], 'No worker job was attached to this deployment.',
                    'DEPLOYMENT_ORPHANED');
                $reaped['orphaned']++;
            }
        }
        if ($reaped['timed_out'] || $reaped['orphaned']) {
            Logger::warning('Stalled deployments reaped.', $reaped + ['source' => 'deployments']);
            Audit::record(Actor::system('Orchestrator'), Audit::DEPLOYMENT_FAILED, [
                'resource_type' => 'deployment', 'resource_id' => 'reaper',
                'metadata' => $reaped, 'severity' => 'warning',
            ]);
        }
        return $reaped;
    }
}
