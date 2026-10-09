<?php
/**
 * Executes customer-owned provider VM jobs from the shared leased queue.
 *
 * Provider calls occur only here, never in a WHMCS/API request. Asynchronous
 * create/rebuild/resize/delete results become separate delayed polling jobs.
 * A provider resource being ready is recorded as `server_ready`; the server
 * becomes customer-visible `active` only after the health/security readiness
 * gates below pass (provider-confirmed address + provider-sourced metrics when
 * the adapter supports them, plus a deployed-spec integrity check). A failed
 * gate never activates: transient gate failures keep the server provisioning
 * and keep polling, permanent ones fail the server honestly.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\ConflictException;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Deployments\JobQueue;

class ServerProvisioningWorker
{
    const JOB_TYPES = [
        JobQueue::TYPE_SERVER_CREATE,
        JobQueue::TYPE_SERVER_POLL,
        JobQueue::TYPE_SERVER_REBOOT,
        JobQueue::TYPE_SERVER_POWER_ON,
        JobQueue::TYPE_SERVER_POWER_OFF,
        JobQueue::TYPE_SERVER_REBUILD,
        JobQueue::TYPE_SERVER_RESIZE,
        JobQueue::TYPE_SERVER_DELETE,
    ];

    private $actor;
    private $queue;
    private $servers;
    private $accounts;

    public function __construct(Actor $actor, JobQueue $queue = null,
        CustomerServerService $servers = null, ProviderAccountService $accounts = null)
    {
        if (!$actor->isSystem()) {
            throw new AuthorizationException('Only the system worker may execute provider jobs.');
        }
        $this->actor = $actor;
        $this->queue = $queue ?: new JobQueue();
        $this->accounts = $accounts ?: new ProviderAccountService($actor);
        $this->servers = $servers ?: new CustomerServerService($actor, null, $this->accounts, $this->queue);
    }

    public static function handles($jobType)
    {
        return in_array((string) $jobType, self::JOB_TYPES, true);
    }

    public function runJob(array $job)
    {
        $jobId = (int) $job['id'];
        $serverId = !empty($job['customer_server_id']) ? (int) $job['customer_server_id'] : 0;
        $payload = $this->queue->payload($job);
        if ($serverId <= 0 && !empty($payload['customer_server_id'])) {
            $serverId = (int) $payload['customer_server_id'];
        }
        if ($serverId <= 0) {
            $this->queue->fail($jobId, 'The job does not reference a customer-owned server.',
                'JOB_MISSING_CUSTOMER_SERVER', false);
            return ['status' => 'failed', 'error' => ['code' => 'JOB_MISSING_CUSTOMER_SERVER']];
        }

        $this->queue->start($jobId, isset($job['leased_by']) ? $job['leased_by'] : 'provider-worker');
        try {
            if ($this->queue->cancelRequested($jobId)) {
                throw new StateException('The provider job was cancelled before its next operation.');
            }
            $this->queue->touch($jobId);
            $result = $this->execute($job, $payload, $serverId);
            $this->queue->complete($jobId, $result);
            return ['status' => 'completed', 'customer_server_id' => $serverId, 'result' => $result];
        } catch (\Throwable $e) {
            $retryable = $e instanceof AppsException ? $e->isRetryable() : false;
            $code = $e instanceof AppsException ? $e->errorCode() : 'PROVISIONING_FAILED';
            $current = $this->queue->find($jobId);
            $attempts = $current ? (int) $current['attempts'] : 1;
            $maxAttempts = $current ? max(1, (int) $current['max_attempts']) : 1;
            $willRetry = $retryable && $attempts < $maxAttempts;
            $safeMessage = $this->safeFailureMessage($e);
            $this->servers->workerFailure($serverId, $code, $safeMessage, !$willRetry,
                isset($job['job_type']) ? (string) $job['job_type'] : '');
            $this->queue->fail($jobId, $safeMessage, $code, $retryable, $e);
            if ($retryable) {
                throw new RetryableProviderException($safeMessage, ['error_code' => $code], $e);
            }
            return ['status' => 'failed', 'customer_server_id' => $serverId,
                'error' => ['code' => $code, 'message' => $safeMessage, 'retryable' => false]];
        }
    }

    private function safeFailureMessage(\Throwable $e)
    {
        if ($e instanceof \Ch247Apps\Core\ProviderAuthenticationException) {
            return 'The provider rejected the configured credentials.';
        }
        if ($e instanceof ProviderOperationException) {
            if ($e->errorCode() === 'PROVIDER_CREATE_UNCERTAIN'
                || $e->errorCode() === 'PROVIDER_ACTION_UNCERTAIN') {
                return 'The provider operation may have succeeded. Reconcile the provider resource and billing before any retry.';
            }
            return 'The provider operation failed. Review the provider account and adapter logs.';
        }
        if ($e instanceof \Ch247Apps\Core\ProviderUnavailableException
            || $e instanceof \Ch247Apps\Core\ProviderConfigurationException) {
            return $e->getMessage();
        }
        if ($e instanceof AppsException) {
            return $e->getMessage();
        }
        return 'The provider operation failed unexpectedly.';
    }

    private function execute(array $job, array $payload, $serverId)
    {
        $row = $this->servers->internalRow($serverId);
        $jobId = (int) $job['id'];
        $type = (string) $job['job_type'];
        $accountId = (int) $row['provider_account_id'];
        $providerServerId = isset($row['provider_server_id']) ? (string) $row['provider_server_id'] : '';
        $idempotencyKey = !empty($job['idempotency_key'])
            ? (string) $job['idempotency_key'] : 'customer-server:' . $type . ':' . $serverId;

        if ($type === JobQueue::TYPE_SERVER_CREATE) {
            if (!empty($row['product_mapping_id'])) {
                // An operator may revoke/remap a product while the job waits in
                // the queue. Fail before any provider call, never orphan a new VM.
                (new ServerProductMappingService($this->actor))->assertCurrent($row);
            }
            return $this->create($job, $payload, $row, $serverId, $accountId, $idempotencyKey);
        }
        if ($type === JobQueue::TYPE_SERVER_POLL) {
            return $this->poll($job, $row, $serverId, $accountId);
        }
        if ($providerServerId === '') {
            throw new ConflictException('The provider has not returned a server identifier for this operation.');
        }

        $operation = $this->operationForType($type);
        $expectedState = [
            JobQueue::TYPE_SERVER_REBOOT => CustomerServerService::STATE_SERVER_READY,
            JobQueue::TYPE_SERVER_POWER_ON => CustomerServerService::STATE_SERVER_READY,
            JobQueue::TYPE_SERVER_POWER_OFF => CustomerServerService::STATE_SERVER_READY,
            JobQueue::TYPE_SERVER_REBUILD => CustomerServerService::STATE_REBUILDING,
            JobQueue::TYPE_SERVER_RESIZE => CustomerServerService::STATE_RESIZING,
            JobQueue::TYPE_SERVER_DELETE => CustomerServerService::STATE_TERMINATING,
        ];
        if (!isset($expectedState[$type]) || (string) $row['provisioning_state'] !== $expectedState[$type]) {
            throw new ConflictException('The queued provider operation no longer matches the customer-server lifecycle state.');
        }
        if ($type === JobQueue::TYPE_SERVER_DELETE) {
            // Reconfirm WHMCS cancellation before decrypting provider credentials.
            $this->servers->workerAssertDeleteAllowed($serverId);
        } else {
            // WHMCS suspension/cancellation may happen after a job was queued.
            $this->servers->workerAssertActionAllowed($serverId, $operation);
        }
        $capability = 'server.' . $operation;
        $context = $this->accounts->operationalContext($accountId, [$capability]);
        $adapter = $context['adapter'];
        $credentials = $context['credentials'];
        $config = $context['config'];

        if ($type === JobQueue::TYPE_SERVER_DELETE) {
            $result = $adapter->deleteServer($credentials, $config, $providerServerId, $idempotencyKey);
            if (!is_array($result)) {
                throw new ProviderOperationException('The provider returned an invalid delete response.');
            }
            if (!empty($result['confirmed'])) {
                $this->servers->workerTerminate($serverId, 'deleted');
                return ['customer_server_id' => $serverId, 'provider_state' => 'deleted', 'confirmed' => true];
            }
            $resource = ProviderResource::normalise($result, $providerServerId);
            $this->servers->workerApplyResource($serverId, $resource, 'delete');
            $poll = $this->servers->workerQueuePoll($serverId, $jobId);
            return ['customer_server_id' => $serverId, 'provider_state' => $resource['status'],
                'delete_confirmed' => false, 'poll_job_id' => (int) $poll['id']];
        }

        if ($type === JobQueue::TYPE_SERVER_REBUILD) {
            $spec = isset($payload['spec']) && is_array($payload['spec']) ? $payload['spec'] : [];
            $raw = $adapter->rebuildServer($credentials, $config, $providerServerId, $spec, $idempotencyKey);
            return $this->applyMutationResource($raw, $serverId, $providerServerId, 'rebuild', $jobId,
                $context, JobQueue::TYPE_SERVER_REBUILD);
        }
        if ($type === JobQueue::TYPE_SERVER_RESIZE) {
            $spec = isset($payload['spec']) && is_array($payload['spec']) ? $payload['spec'] : [];
            $raw = $adapter->resizeServer($credentials, $config, $providerServerId, $spec, $idempotencyKey);
            return $this->applyMutationResource($raw, $serverId, $providerServerId, 'resize', $jobId,
                $context, JobQueue::TYPE_SERVER_RESIZE);
        }

        if ($type === JobQueue::TYPE_SERVER_REBOOT) {
            $result = $adapter->rebootServer($credentials, $config, $providerServerId, $idempotencyKey);
        } elseif ($type === JobQueue::TYPE_SERVER_POWER_ON) {
            $result = $adapter->powerOnServer($credentials, $config, $providerServerId, $idempotencyKey);
        } elseif ($type === JobQueue::TYPE_SERVER_POWER_OFF) {
            $result = $adapter->powerOffServer($credentials, $config, $providerServerId, $idempotencyKey);
        } else {
            throw new ProviderOperationException('The worker does not implement this provider operation.', [
                'job_type' => $type,
            ]);
        }
        if (!is_array($result) || empty($result['confirmed'])) {
            throw new RetryableProviderException(
                'The provider has not confirmed completion of the requested server action.'
            );
        }
        return ['customer_server_id' => $serverId, 'action' => $operation, 'confirmed' => true];
    }

    private function create(array $job, array $payload, array $row, $serverId, $accountId, $idempotencyKey)
    {
        $this->servers->workerAssertProvisioningAllowed($serverId);
        $row = $this->servers->workerBegin($serverId, 'create');
        $context = $this->accounts->operationalContext($accountId, ['server.create', 'server.get']);
        $spec = isset($payload['spec']) && is_array($payload['spec'])
            ? ServerSpec::normalise($payload['spec']) : ServerSpec::normalise([]);
        // Keep the final authoritative WHMCS check adjacent to the external
        // create call; service/payment state or the operator mapping may have
        // changed while credentials were loaded or the worker was scheduled.
        $this->servers->workerAssertProvisioningAllowed($serverId);
        if (!empty($row['product_mapping_id'])) {
            (new ServerProductMappingService($this->actor))->assertCurrent($row);
        }
        $raw = $context['adapter']->createServer($context['credentials'], $context['config'], $spec, $idempotencyKey);
        if (!is_array($raw)) {
            throw new ProviderOperationException('The provider returned an invalid server-create response.');
        }
        $resource = ProviderResource::normalise($raw);
        if (ProviderResource::isFailed($resource)) {
            throw new ProviderOperationException('The provider reports that the new server could not be created.', [
                'provider_state' => $resource['status'],
            ]);
        }
        $this->servers->workerApplyResource($serverId, $resource, 'create');
        if (!ProviderResource::isReady($resource)) {
            $poll = $this->servers->workerQueuePoll($serverId, (int) $job['id']);
            return ['customer_server_id' => $serverId, 'provider_server_id' => $resource['id'],
                'provider_state' => $resource['status'], 'provisioning_state' => 'server_creating',
                'poll_job_id' => (int) $poll['id']];
        }
        return $this->activateReadyServer($context, $resource, $serverId, 'create',
            (int) $job['id'], JobQueue::TYPE_SERVER_CREATE);
    }

    private function poll(array $job, array $row, $serverId, $accountId)
    {
        $providerServerId = isset($row['provider_server_id']) ? (string) $row['provider_server_id'] : '';
        if ($providerServerId === '') {
            throw new ConflictException('The provider operation cannot be polled without a server identifier.');
        }
        $context = $this->accounts->operationalContext($accountId, ['server.get']);
        $raw = $context['adapter']->getServer($context['credentials'], $context['config'], $providerServerId);
        if (!is_array($raw)) {
            throw new ProviderOperationException('The provider returned an invalid server-status response.');
        }
        $resource = ProviderResource::normalise($raw, $providerServerId);
        $operation = isset($row['provider_operation']) ? (string) $row['provider_operation'] : 'create';
        if ($operation === 'delete' && in_array($resource['status'], ['deleted', 'not_found'], true)) {
            $this->servers->workerTerminate($serverId, $resource['status']);
            return ['customer_server_id' => $serverId, 'provider_state' => $resource['status'],
                'provisioning_state' => 'terminated', 'confirmed' => true];
        }
        if (ProviderResource::isFailed($resource)) {
            throw new ProviderOperationException('The provider reports that the server operation failed.', [
                'provider_state' => $resource['status'], 'operation' => $operation,
            ]);
        }
        $current = $this->servers->internalRow($serverId);
        if ($operation !== 'delete' && ProviderResource::isReady($resource)
            && (string) $current['status'] === CustomerServerService::STATUS_ACTIVE
            && (string) $current['provisioning_state'] === CustomerServerService::STATE_SERVER_READY) {
            // A duplicate poll must never demote an already-activated server.
            return ['customer_server_id' => $serverId, 'provider_state' => $resource['status'],
                'provisioning_state' => 'server_ready', 'customer_status' => 'active',
                'idempotent' => true];
        }
        $this->servers->workerApplyResource($serverId, $resource, $operation);
        if ($operation === 'delete') {
            $next = $this->servers->workerQueuePoll($serverId, (int) $job['id']);
            return ['customer_server_id' => $serverId, 'provider_state' => $resource['status'],
                'provisioning_state' => 'terminating', 'delete_confirmed' => false,
                'poll_job_id' => (int) $next['id']];
        }
        if (!ProviderResource::isReady($resource)) {
            $next = $this->servers->workerQueuePoll($serverId, (int) $job['id']);
            return ['customer_server_id' => $serverId, 'provider_state' => $resource['status'],
                'provisioning_state' => $row['provisioning_state'], 'poll_job_id' => (int) $next['id']];
        }
        return $this->activateReadyServer($context, $resource, $serverId, $operation,
            (int) $job['id'], JobQueue::TYPE_SERVER_POLL);
    }

    private function applyMutationResource($raw, $serverId, $providerServerId, $operation, $jobId,
        array $context, $jobType)
    {
        if (!is_array($raw)) {
            throw new ProviderOperationException('The provider returned an invalid server-operation response.');
        }
        $resource = ProviderResource::normalise($raw, $providerServerId);
        if (ProviderResource::isFailed($resource)) {
            throw new ProviderOperationException('The provider reports that the server operation failed.', [
                'provider_state' => $resource['status'], 'operation' => $operation,
            ]);
        }
        $this->servers->workerApplyResource($serverId, $resource, $operation);
        if (!ProviderResource::isReady($resource)) {
            $poll = $this->servers->workerQueuePoll($serverId, (int) $jobId);
            return ['customer_server_id' => $serverId, 'operation' => $operation,
                'provider_state' => $resource['status'], 'poll_job_id' => (int) $poll['id']];
        }
        return $this->activateReadyServer($context, $resource, $serverId, $operation, $jobId, $jobType);
    }

    /**
     * Run the health/security readiness gates for a provider-ready server and,
     * only when every gate passes, mark it customer-active. Transient gate
     * failures keep the server provisioning and keep polling; permanent gate
     * failures propagate and fail the server.
     */
    private function activateReadyServer(array $context, array $resource, $serverId, $operation,
        $jobId, $jobType)
    {
        try {
            $gates = $this->readinessGates($context, $resource, $this->servers->internalRow($serverId));
        } catch (HealthCheckException $e) {
            // Transient gate failure (address not yet assigned, provider metrics
            // endpoint unavailable): record the machine-readable error, keep the
            // server provisioning, and keep polling like a slow provider.
            $this->servers->workerFailure($serverId, $e->errorCode(), $e->getMessage(), false, $jobType);
            $next = $this->servers->workerQueuePoll($serverId, $jobId);
            return ['customer_server_id' => $serverId, 'operation' => $operation,
                'provider_state' => $resource['status'], 'provisioning_state' => 'server_ready',
                'customer_status' => 'provisioning', 'gates_passed' => false,
                'gate_error_code' => $e->errorCode(),
                'gate_error_message' => Str::clip($e->getMessage(), 200),
                'poll_job_id' => (int) $next['id']];
        }
        $this->servers->workerActivate($serverId, $gates);
        return ['customer_server_id' => $serverId, 'operation' => $operation,
            'provider_state' => $resource['status'], 'provisioning_state' => 'server_ready',
            'customer_status' => 'active', 'gates_passed' => true, 'gates' => $gates];
    }

    /**
     * Health/security gates that must pass before a provider-ready server
     * becomes customer-visible ACTIVE. Every input is provider-sourced or
     * already persisted on the server row; nothing is guessed or synthesized.
     *
     * Health: the provider confirms the ordered resource is ready and has a
     * routable address, and — when the adapter declares `server.metrics` —
     * returns provider-sourced liveness metrics for it. Adapters without the
     * capability are recorded as unsupported, never faked.
     *
     * Security: the running server must be the server that was ordered — the
     * persisted row still matches the approved requested specification exactly.
     * Guest-OS hardening is the guest's responsibility; the control plane does
     * not claim to verify it.
     */
    private function readinessGates(array $context, array $resource, array $row)
    {
        $report = ['checked_at' => Clock::now()];

        $ipv4 = isset($resource['ipv4']) ? $resource['ipv4'] : null;
        $ipv6 = isset($resource['ipv6']) ? $resource['ipv6'] : null;
        if ($ipv4 === null && $ipv6 === null) {
            throw new HealthCheckException(
                'The provider reports the server ready without an IP address.',
                ['provider_state' => (string) $resource['status']]
            );
        }
        $report['health'] = [
            'provider_state' => (string) $resource['status'],
            'ipv4_assigned' => $ipv4 !== null,
            'ipv6_assigned' => $ipv6 !== null,
        ];

        $capabilities = ProviderRegistry::normaliseCapabilities($context['adapter']->capabilities());
        if (!empty($capabilities['server.metrics'])) {
            try {
                $metrics = $context['adapter']->getMetrics($context['credentials'], $context['config'],
                    (string) $resource['id']);
            } catch (\Throwable $e) {
                // A failing metrics endpoint means the health gate cannot be
                // confirmed; that is transient, never a reason to activate.
                throw new HealthCheckException(
                    'The provider metrics endpoint failed for a server it reports ready.',
                    ['provider_state' => (string) $resource['status']],
                    $e
                );
            }
            if (!is_array($metrics) || $metrics === []) {
                throw new HealthCheckException(
                    'The provider returned no metrics for a server it reports ready.',
                    ['provider_state' => (string) $resource['status']]
                );
            }
            $report['metrics'] = ['provider_reported' => true, 'fields' => count($metrics)];
        } else {
            $report['metrics'] = [
                'provider_reported' => false,
                'reason' => 'adapter_declares_no_server_metrics',
            ];
        }

        $spec = Str::jsonDecode(isset($row['requested_spec']) ? $row['requested_spec'] : null, null);
        if (!is_array($spec)) {
            throw new ProviderOperationException(
                'The approved server specification is missing; activation is refused.',
                ['error_code' => 'SPEC_MISSING', 'customer_server_id' => (int) $row['id']]
            );
        }
        $spec = ServerSpec::normalise($spec);
        $drift = [];
        foreach (['name', 'hostname', 'region', 'image'] as $field) {
            if ((string) $row[$field] !== (string) $spec[$field]) {
                $drift[] = $field;
            }
        }
        foreach (['cpu_cores', 'memory_mb', 'storage_gb'] as $field) {
            if ((int) $row[$field] !== (int) $spec[$field]) {
                $drift[] = $field;
            }
        }
        if ($drift !== []) {
            throw new ProviderOperationException(
                'The deployed server does not match the approved specification; activation is refused.',
                ['error_code' => 'SPEC_DRIFT', 'fields' => $drift, 'customer_server_id' => (int) $row['id']]
            );
        }
        $report['security'] = [
            'spec_verified' => true,
            'image' => $spec['image'],
            'region' => $spec['region'],
        ];
        $report['passed'] = true;
        return $report;
    }

    private function operationForType($type)
    {
        $map = [
            JobQueue::TYPE_SERVER_REBOOT => 'reboot',
            JobQueue::TYPE_SERVER_POWER_ON => 'power_on',
            JobQueue::TYPE_SERVER_POWER_OFF => 'power_off',
            JobQueue::TYPE_SERVER_REBUILD => 'rebuild',
            JobQueue::TYPE_SERVER_RESIZE => 'resize',
            JobQueue::TYPE_SERVER_DELETE => 'delete',
        ];
        if (!isset($map[$type])) {
            throw new ProviderOperationException('Unsupported provider job type.', ['job_type' => $type]);
        }
        return $map[$type];
    }
}
