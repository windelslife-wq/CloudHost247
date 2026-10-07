<?php
/**
 * CloudHost247 App Cloud — deployment records.
 *
 * Owns the `deployments`, `deployment_steps`, `deployment_logs` and
 * `created_resources` tables: the complete, ordered account of what the engine
 * did, what it made, and what it undid.
 *
 * Two properties matter more than convenience here:
 *   • a deployment always has a unique idempotency key, so a retried request, a
 *     double-clicked button or a replayed webhook can never start two of them
 *   • every resource created is recorded the moment it exists, so a failure at
 *     any later step can be compensated exactly, and anything that could not be
 *     removed is left marked `leaked` for an operator rather than forgotten
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Deployments;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class DeploymentService
{
    const ACTION_INSTALL         = 'install';
    const ACTION_START           = 'start';
    const ACTION_STOP            = 'stop';
    const ACTION_RESTART         = 'restart';
    const ACTION_UPDATE          = 'update';
    const ACTION_BACKUP          = 'backup';
    const ACTION_RESTORE         = 'restore';
    const ACTION_REINSTALL       = 'reinstall';
    const ACTION_UNINSTALL       = 'uninstall';
    const ACTION_SSL_PROVISION   = 'ssl_provision';
    const ACTION_DOMAIN_CONFIGURE = 'domain_configure';
    const ACTION_HEALTHCHECK     = 'healthcheck';
    const ACTION_SUSPEND         = 'suspend';
    const ACTION_TERMINATE       = 'terminate';

    const ACTIONS = [
        self::ACTION_INSTALL, self::ACTION_START, self::ACTION_STOP, self::ACTION_RESTART,
        self::ACTION_UPDATE, self::ACTION_BACKUP, self::ACTION_RESTORE, self::ACTION_REINSTALL,
        self::ACTION_UNINSTALL, self::ACTION_SSL_PROVISION, self::ACTION_DOMAIN_CONFIGURE,
        self::ACTION_HEALTHCHECK, self::ACTION_SUSPEND, self::ACTION_TERMINATE,
    ];

    const STATUS_QUEUED      = 'queued';
    const STATUS_RUNNING     = 'running';
    const STATUS_SUCCEEDED   = 'succeeded';
    const STATUS_FAILED      = 'failed';
    const STATUS_ROLLING_BACK = 'rolling_back';
    const STATUS_ROLLED_BACK = 'rolled_back';
    const STATUS_CANCELLED   = 'cancelled';
    const STATUS_TIMEOUT     = 'timeout';

    const TERMINAL = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_ROLLED_BACK,
        self::STATUS_CANCELLED, self::STATUS_TIMEOUT];

    const STEP_PENDING     = 'pending';
    const STEP_RUNNING     = 'running';
    const STEP_SUCCEEDED   = 'succeeded';
    const STEP_FAILED      = 'failed';
    const STEP_SKIPPED     = 'skipped';
    const STEP_ROLLED_BACK = 'rolled_back';

    const RESOURCE_CREATED         = 'created';
    const RESOURCE_ROLLBACK_PENDING = 'rollback_pending';
    const RESOURCE_REMOVED         = 'removed';
    const RESOURCE_LEAKED          = 'leaked';

    /** @var Actor */
    private $actor;

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Actor::system('DeploymentService');
    }

    /* --------------------------------------------------------------- create */

    /**
     * Create a deployment record.
     *
     * @param array $options idempotency_key, job_id, adapter, payload, requested_by_*
     * @return array the deployment (an existing one when the idempotency key is live)
     */
    public function create($installationId, $action, array $options = [])
    {
        $action = strtolower((string) $action);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new ValidationException('Unknown deployment action "' . $action . '".', [
                'errors' => ['action' => 'Must be one of: ' . implode(', ', self::ACTIONS)],
            ]);
        }
        $installation = Db::first('installations', ['id' => (int) $installationId, 'deleted_at' => null]);
        if (!$installation) {
            throw new NotFoundException('That installation does not exist.');
        }

        $key = isset($options['idempotency_key']) && $options['idempotency_key'] !== ''
            ? Str::clip((string) $options['idempotency_key'], 120)
            : $action . ':' . $installationId . ':' . Str::token(8);

        $existing = Db::first('deployments', ['idempotency_key' => $key]);
        if ($existing) {
            // Same key, same work: hand back the deployment that is already
            // queued or running instead of starting a second one.
            Logger::info('Deployment deduplicated by idempotency key.', [
                'deployment_id' => (int) $existing['id'], 'action' => $action, 'source' => 'deployments',
            ]);
            return $this->present($existing);
        }

        $now = Clock::now();
        $deploymentId = Db::insert('deployments', [
            'uuid' => Str::uuid4(),
            'reference' => Str::reference('DEP'),
            'installation_id' => (int) $installationId,
            'server_id' => $installation['server_id'] ? (int) $installation['server_id'] : null,
            'job_id' => isset($options['job_id']) ? (int) $options['job_id'] : null,
            'action' => $action,
            'status' => self::STATUS_QUEUED,
            'adapter' => isset($options['adapter']) ? Str::clip((string) $options['adapter'], 30)
                : (string) $installation['adapter'],
            'idempotency_key' => $key,
            'requested_by' => isset($options['requested_by']) ? Str::clip((string) $options['requested_by'], 120)
                : $this->actor->identity(),
            'requested_by_id' => $this->actor->actorId() ?: null,
            'requested_by_type' => $this->actor->type,
            'progress' => 0,
            'steps_total' => 0,
            'steps_completed' => 0,
            'attempts' => 0,
            'payload' => isset($options['payload']) ? Str::jsonEncode(Logger::redact((array) $options['payload'])) : null,
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::update('installations', ['deployment_id' => $deploymentId, 'updated_at' => $now],
            ['id' => (int) $installationId]);

        Events::emit(Events::DEPLOYMENT_QUEUED, [
            'action' => $action, 'reference' => 'DEP-' . $deploymentId, 'adapter' => $installation['adapter'],
        ], ['deployment_id' => $deploymentId, 'installation_id' => (int) $installationId,
            'client_id' => (int) $installation['customer_id']]);

        return $this->present($this->row($deploymentId));
    }

    /* -------------------------------------------------------------- lifecycle */

    public function markRunning($deploymentId)
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        Db::update('deployments', [
            'status' => self::STATUS_RUNNING,
            'started_at' => empty($row['started_at']) ? $now : $row['started_at'],
            'attempts' => (int) $row['attempts'] + 1,
            'error_code' => null,
            'error_message' => null,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::DEPLOYMENT_STARTED, ['action' => $row['action'],
            'attempt' => (int) $row['attempts'] + 1], $this->scope($row));
        return $this->present($this->row($deploymentId));
    }

    public function markSucceeded($deploymentId, array $result = [])
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        Db::update('deployments', [
            'status' => self::STATUS_SUCCEEDED,
            'progress' => 100,
            'completed_at' => $now,
            'duration_ms' => $this->durationMs($row, $now),
            'result' => Str::jsonEncode(Logger::redact($result)),
            'error_code' => null,
            'error_message' => null,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::DEPLOYMENT_COMPLETED, ['action' => $row['action'],
            'duration_ms' => $this->durationMs($row, $now)], $this->scope($row));
        Audit::record($this->actor, Audit::DEPLOYMENT_COMPLETED, [
            'resource_type' => 'deployment', 'resource_id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'metadata' => ['action' => $row['action'], 'reference' => $row['reference']],
        ]);
        return $this->present($this->row($deploymentId));
    }

    /**
     * Record a failure.
     *
     * @param int|null $failedStepId the step that broke, so an operator sees where
     */
    public function markFailed($deploymentId, $message, $errorCode = null, $failedStepId = null, array $extra = [])
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        $code = $errorCode !== null && $errorCode !== '' ? Str::clip((string) $errorCode, 60) : 'DEPLOYMENT_FAILED';
        Db::update('deployments', array_merge([
            'status' => self::STATUS_FAILED,
            'completed_at' => $now,
            'duration_ms' => $this->durationMs($row, $now),
            'error_code' => $code,
            'error_message' => Str::clip((string) $message, 2000),
            'failed_step_id' => $failedStepId === null ? null : (int) $failedStepId,
            'updated_at' => $now,
        ], $extra), ['id' => (int) $row['id']]);

        Events::emit(Events::DEPLOYMENT_FAILED, [
            'action' => $row['action'], 'error_code' => $code,
            'message' => Str::clip((string) $message, 300),
            'failed_step' => $failedStepId === null ? null : $this->stepName((int) $failedStepId),
        ], $this->scope($row));
        Audit::record($this->actor, Audit::DEPLOYMENT_FAILED, [
            'resource_type' => 'deployment', 'resource_id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'metadata' => ['action' => $row['action'], 'error_code' => $code,
                'message' => Str::clip((string) $message, 300),
                'failed_step' => $failedStepId === null ? null : $this->stepName((int) $failedStepId)],
            'severity' => 'error',
        ]);
        Logger::error('Deployment failed.', [
            'deployment_id' => (int) $row['id'], 'action' => $row['action'], 'error_code' => $code,
            'message' => Str::clip((string) $message, 300), 'source' => 'deployments',
        ]);
        return $this->present($this->row($deploymentId));
    }

    public function markRollingBack($deploymentId, $reason = '')
    {
        $row = $this->row($deploymentId);
        Db::update('deployments', ['status' => self::STATUS_ROLLING_BACK, 'updated_at' => Clock::now()],
            ['id' => (int) $row['id']]);
        foreach ($this->resourcesFor((int) $row['id'], [self::RESOURCE_CREATED]) as $resource) {
            Db::update('created_resources', ['status' => self::RESOURCE_ROLLBACK_PENDING],
                ['id' => (int) $resource['id']]);
        }
        $this->log((int) $row['id'], 'Rolling back: ' . Str::clip($reason, 400), 'warning', 'worker');
        Events::emit(Events::DEPLOYMENT_ROLLED_BACK, ['action' => $row['action'], 'phase' => 'started',
            'reason' => Str::clip($reason, 200)], $this->scope($row));
        return $this->present($this->row($deploymentId));
    }

    public function markRolledBack($deploymentId, array $summary = [])
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        Db::update('deployments', [
            'status' => self::STATUS_ROLLED_BACK,
            'completed_at' => $now,
            'duration_ms' => $this->durationMs($row, $now),
            'rolled_back' => 1,
            'result' => Str::jsonEncode(Logger::redact($summary)),
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        $leaked = 0;
        foreach ($this->resourcesFor((int) $row['id']) as $resource) {
            if ($resource['status'] === self::RESOURCE_ROLLBACK_PENDING) {
                $leaked++;
            }
        }
        Events::emit(Events::DEPLOYMENT_ROLLED_BACK, ['action' => $row['action'], 'phase' => 'completed',
            'leaked_resources' => $leaked], $this->scope($row));
        Audit::record($this->actor, Audit::DEPLOYMENT_ROLLED_BACK, [
            'resource_type' => 'deployment', 'resource_id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'metadata' => ['action' => $row['action'], 'leaked_resources' => $leaked],
            'severity' => $leaked ? 'error' : 'warning',
        ]);
        if ($leaked) {
            Logger::error('Rollback left resources behind.', [
                'deployment_id' => (int) $row['id'], 'leaked' => $leaked, 'source' => 'deployments',
            ]);
        }
        return $this->present($this->row($deploymentId));
    }

    public function markCancelled($deploymentId, $reason = '')
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        Db::update('deployments', [
            'status' => self::STATUS_CANCELLED, 'completed_at' => $now,
            'duration_ms' => $this->durationMs($row, $now),
            'error_code' => 'DEPLOYMENT_CANCELLED',
            'error_message' => Str::clip($reason !== '' ? $reason : 'Cancelled by request.', 2000),
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::DEPLOYMENT_CANCELLED, ['action' => $row['action'],
            'reason' => Str::clip($reason, 200)], $this->scope($row));
        Audit::record($this->actor, Audit::DEPLOYMENT_CANCELLED, [
            'resource_type' => 'deployment', 'resource_id' => (int) $row['id'],
            'installation_id' => (int) $row['installation_id'],
            'metadata' => ['action' => $row['action'], 'reason' => Str::clip($reason, 200)],
        ]);
        return $this->present($this->row($deploymentId));
    }

    public function markTimeout($deploymentId)
    {
        $row = $this->row($deploymentId);
        $now = Clock::now();
        Db::update('deployments', [
            'status' => self::STATUS_TIMEOUT, 'completed_at' => $now,
            'duration_ms' => $this->durationMs($row, $now),
            'error_code' => 'DEPLOYMENT_TIMEOUT',
            'error_message' => 'The deployment exceeded its time limit and was stopped.',
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::DEPLOYMENT_FAILED, ['action' => $row['action'], 'error_code' => 'DEPLOYMENT_TIMEOUT'],
            $this->scope($row));
        return $this->present($this->row($deploymentId));
    }

    public function progress($deploymentId, $percent, $currentStep = null)
    {
        $fields = ['progress' => max(0, min(100, (int) $percent)), 'updated_at' => Clock::now()];
        if ($currentStep !== null) {
            $fields['current_step'] = Str::clip((string) $currentStep, 120);
        }
        Db::update('deployments', $fields, ['id' => (int) $deploymentId]);
        return $fields['progress'];
    }

    /* ---------------------------------------------------------------- steps */

    /** Insert the adapter's plan as ordered step rows. @return array key => row */
    public function addSteps($deploymentId, array $plan)
    {
        $now = Clock::now();
        $steps = [];
        $order = 1;
        foreach ($plan as $step) {
            $id = Db::insert('deployment_steps', [
                'deployment_id' => (int) $deploymentId,
                'step_order' => isset($step['step_order']) ? (int) $step['step_order'] : $order,
                'name' => Str::clip(isset($step['name']) ? $step['name'] : $step['key'], 120),
                'key' => Str::clip((string) $step['key'], 80),
                'status' => self::STEP_PENDING,
                'reversible' => empty($step['reversible']) ? 0 : 1,
                'creates_resource' => empty($step['creates_resource']) ? 0 : 1,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $order++;
            $steps[(string) $step['key']] = $this->stepRow($id);
        }
        Db::update('deployments', ['steps_total' => count($steps), 'updated_at' => $now],
            ['id' => (int) $deploymentId]);
        return $steps;
    }

    public function startStep($stepId, $deploymentId = null)
    {
        $step = $this->stepRow($stepId);
        $now = Clock::now();
        Db::update('deployment_steps', [
            'status' => self::STEP_RUNNING,
            'started_at' => empty($step['started_at']) ? $now : $step['started_at'],
            'attempts' => (int) $step['attempts'] + 1,
            'updated_at' => $now,
        ], ['id' => (int) $step['id']]);
        if ($deploymentId === null) {
            $deploymentId = (int) $step['deployment_id'];
        }
        Db::update('deployments', ['current_step' => $step['name'], 'updated_at' => $now],
            ['id' => (int) $deploymentId]);
        $row = Db::first('deployments', ['id' => (int) $deploymentId]);
        Events::emit(Events::DEPLOYMENT_STEP_STARTED, ['step' => $step['key'], 'name' => $step['name'],
            'order' => (int) $step['step_order']], $this->scope($row ?: []));
        return $this->stepRow($stepId);
    }

    public function completeStep($stepId, $output = null, array $resources = [], $message = '')
    {
        $step = $this->stepRow($stepId);
        $now = Clock::now();
        Db::update('deployment_steps', [
            'status' => self::STEP_SUCCEEDED,
            'completed_at' => $now,
            'duration_ms' => $this->stepDurationMs($step, $now),
            'output' => $output === null ? null : Str::jsonEncode(Logger::redact(
                is_array($output) ? $output : ['message' => (string) $output])),
            'error' => null,
            'error_code' => null,
            'context' => $resources === [] ? null : Str::jsonEncode(Logger::redact($resources)),
            'updated_at' => $now,
        ], ['id' => (int) $step['id']]);

        foreach ($resources as $resource) {
            if (!is_array($resource) || empty($resource['type'])) {
                continue;
            }
            $this->recordResource((int) $step['deployment_id'], (string) $resource['type'],
                isset($resource['name']) ? (string) $resource['name'] : '',
                isset($resource['metadata']) ? (array) $resource['metadata'] : [],
                (int) $step['id']);
        }

        $deploymentId = (int) $step['deployment_id'];
        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('deployments'))
            . ' SET steps_completed = steps_completed + 1, updated_at = ? WHERE id = ?',
            [$now, $deploymentId]);
        $row = $this->row($deploymentId);
        $total = max(1, (int) $row['steps_total']);
        $this->progress($deploymentId, (int) floor((int) $row['steps_completed'] / $total * 100), $step['name']);

        Events::emit(Events::DEPLOYMENT_STEP_DONE, ['step' => $step['key'], 'name' => $step['name'],
            'message' => Str::clip($message, 200), 'resources' => count($resources)], $this->scope($row));
        if ($message !== '') {
            $this->log($deploymentId, $message, 'info', 'worker', (int) $step['id']);
        }
        return $this->stepRow($stepId);
    }

    public function failStep($stepId, $message, $errorCode = null, $output = null)
    {
        $step = $this->stepRow($stepId);
        $now = Clock::now();
        $code = $errorCode !== null && $errorCode !== '' ? Str::clip((string) $errorCode, 60) : 'STEP_FAILED';
        Db::update('deployment_steps', [
            'status' => self::STEP_FAILED,
            'completed_at' => $now,
            'duration_ms' => $this->stepDurationMs($step, $now),
            'error' => Str::clip((string) $message, 2000),
            'error_code' => $code,
            'output' => $output === null ? $step['output'] : Str::jsonEncode(Logger::redact(
                is_array($output) ? $output : ['message' => (string) $output])),
            'updated_at' => $now,
        ], ['id' => (int) $step['id']]);
        $row = Db::first('deployments', ['id' => (int) $step['deployment_id']]);
        Events::emit(Events::DEPLOYMENT_STEP_FAILED, ['step' => $step['key'], 'name' => $step['name'],
            'error_code' => $code, 'message' => Str::clip((string) $message, 300)], $this->scope($row ?: []));
        $this->log((int) $step['deployment_id'], 'Step "' . $step['name'] . '" failed: '
            . Str::clip((string) $message, 400), 'error', 'worker', (int) $step['id']);
        return $this->stepRow($stepId);
    }

    public function skipStep($stepId, $reason = '')
    {
        $step = $this->stepRow($stepId);
        Db::update('deployment_steps', [
            'status' => self::STEP_SKIPPED, 'error' => Str::clip($reason, 2000), 'updated_at' => Clock::now(),
        ], ['id' => (int) $step['id']]);
        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('deployments'))
            . ' SET steps_completed = steps_completed + 1, updated_at = ? WHERE id = ?',
            [Clock::now(), (int) $step['deployment_id']]);
        return $this->stepRow($stepId);
    }

    public function markStepRolledBack($stepId)
    {
        $step = $this->stepRow($stepId);
        Db::update('deployment_steps', ['status' => self::STEP_ROLLED_BACK, 'updated_at' => Clock::now()],
            ['id' => (int) $step['id']]);
        return $this->stepRow($stepId);
    }

    /** Steps of a deployment, in order. */
    public function steps($deploymentId)
    {
        return Db::fetch('deployment_steps', ['deployment_id' => (int) $deploymentId],
            ['order' => 'step_order', 'dir' => 'asc']);
    }

    public function stepRow($stepId)
    {
        $row = Db::first('deployment_steps', ['id' => (int) $stepId]);
        if (!$row) {
            throw new NotFoundException('That deployment step does not exist.');
        }
        return $row;
    }

    private function stepName($stepId)
    {
        $row = Db::first('deployment_steps', ['id' => (int) $stepId]);
        return $row ? $row['name'] : null;
    }

    /* --------------------------------------------------------------- ledger */

    /**
     * Record a resource the engine created.
     *
     * Called the moment the resource exists — not at the end of the deployment —
     * so a crash cannot leave infrastructure nobody knows about.
     */
    public function recordResource($deploymentId, $type, $name, array $metadata = [], $stepId = null)
    {
        $deployment = Db::first('deployments', ['id' => (int) $deploymentId]);
        $existing = Db::first('created_resources', [
            'deployment_id' => (int) $deploymentId, 'resource_type' => (string) $type,
            'resource_name' => (string) $name, 'status' => ['in', [self::RESOURCE_CREATED,
                self::RESOURCE_ROLLBACK_PENDING]],
        ]);
        $fields = [
            'deployment_id' => (int) $deploymentId,
            'installation_id' => $deployment ? (int) $deployment['installation_id'] : null,
            'server_id' => $deployment && $deployment['server_id'] ? (int) $deployment['server_id'] : null,
            'resource_type' => Str::clip((string) $type, 40),
            'resource_name' => Str::clip((string) $name, 255),
            'metadata' => $metadata === [] ? null : Str::jsonEncode(Logger::redact($metadata)),
            'status' => self::RESOURCE_CREATED,
        ];
        if ($existing) {
            Db::update('created_resources', $fields, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }
        $fields['created_at'] = Clock::now();
        $id = Db::insert('created_resources', $fields);
        $this->log((int) $deploymentId, 'Created ' . $type . ' "' . Str::clip($name, 120) . '".', 'debug', 'worker',
            $stepId === null ? null : (int) $stepId);
        return $id;
    }

    public function markResourceRemoved($resourceId, array $metadata = [])
    {
        $row = Db::first('created_resources', ['id' => (int) $resourceId]);
        if (!$row) {
            return false;
        }
        $existing = Str::jsonDecode($row['metadata'], []);
        Db::update('created_resources', [
            'status' => self::RESOURCE_REMOVED,
            'removed_at' => Clock::now(),
            'metadata' => Str::jsonEncode(Logger::redact(array_merge($existing, $metadata,
                ['removed_at' => Clock::now()]))),
        ], ['id' => (int) $resourceId]);
        return true;
    }

    /** A resource that could not be removed: surfaced to administrators forever. */
    public function markResourceLeaked($resourceId, $reason = '')
    {
        $row = Db::first('created_resources', ['id' => (int) $resourceId]);
        if (!$row) {
            return false;
        }
        $existing = Str::jsonDecode($row['metadata'], []);
        $existing['leak_reason'] = Str::clip($reason, 300);
        Db::update('created_resources', ['status' => self::RESOURCE_LEAKED,
            'metadata' => Str::jsonEncode(Logger::redact($existing))], ['id' => (int) $resourceId]);
        Logger::error('A resource could not be cleaned up and is marked leaked.', [
            'resource_id' => (int) $resourceId, 'resource_type' => $row['resource_type'],
            'resource_name' => $row['resource_name'], 'reason' => Str::clip($reason, 300),
            'source' => 'deployments',
        ]);
        return true;
    }

    public function resourcesFor($deploymentId, array $statuses = [])
    {
        $where = ['deployment_id' => (int) $deploymentId];
        if ($statuses !== []) {
            $where['status'] = ['in', $statuses];
        }
        return Db::fetch('created_resources', $where, ['order' => 'id', 'dir' => 'asc']);
    }

    /** Everything the platform created for an installation and has not removed. */
    public function liveResources($installationId)
    {
        return Db::fetch('created_resources', [
            'installation_id' => (int) $installationId,
            'status' => ['in', [self::RESOURCE_CREATED, self::RESOURCE_ROLLBACK_PENDING, self::RESOURCE_LEAKED]],
        ], ['order' => 'id', 'dir' => 'asc']);
    }

    /** Leaked resources across the platform (admin console / cron alert). */
    public function leaked($limit = 200)
    {
        return Db::fetch('created_resources', ['status' => self::RESOURCE_LEAKED],
            ['order' => 'id', 'dir' => 'desc', 'limit' => (int) $limit]);
    }

    /* ----------------------------------------------------------------- logs */

    /** Append a line to the deployment's live log stream. */
    public function log($deploymentId, $message, $level = 'info', $source = 'worker', $stepId = null)
    {
        $message = Str::clip(Logger::redactText((string) $message), 4000);
        $id = Db::insert('deployment_logs', [
            'deployment_id' => (int) $deploymentId,
            'step_id' => $stepId === null ? null : (int) $stepId,
            'level' => in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info',
            'source' => Str::clip((string) $source, 30),
            'message' => $message,
            'created_at' => Clock::now(),
        ]);
        // The same line goes to the event stream so a browser watching the
        // deployment can render it without polling the log table.
        Events::emit('deployment.log', ['level' => $level, 'source' => $source,
            'message' => Str::clip($message, 500)], ['deployment_id' => (int) $deploymentId, 'log_id' => $id]);
        return $id;
    }

    /**
     * Log lines for the console.
     *
     * @param array $filters level, source, after_id
     */
    public function logs($deploymentId, array $filters = [], $limit = 500)
    {
        $this->assertCanView($deploymentId);
        $where = ['deployment_id' => (int) $deploymentId];
        if (!empty($filters['level'])) {
            $where['level'] = (string) $filters['level'];
        }
        if (!empty($filters['source'])) {
            $where['source'] = (string) $filters['source'];
        }
        if (!empty($filters['after_id'])) {
            $where['id'] = ['>', (int) $filters['after_id']];
        }
        $rows = Db::fetch('deployment_logs', $where, ['order' => 'id', 'dir' => 'asc',
            'limit' => max(1, min(5000, (int) $limit))]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'level' => $row['level'],
                'source' => $row['source'],
                'step_id' => $row['step_id'] ? (int) $row['step_id'] : null,
                'message' => $row['message'],
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    /** Live events for SSE/WebSocket consumers: everything since an event id. */
    public function liveEvents($installationId, $sinceId = 0, $limit = 200)
    {
        $installation = Db::first('installations', ['id' => (int) $installationId]);
        if (!$installation) {
            throw new NotFoundException('That installation does not exist.');
        }
        $this->assertCanViewInstallation($installation);
        return Events::since((int) $sinceId, ['installation_id' => (int) $installationId], (int) $limit);
    }

    /* ----------------------------------------------------------------- read */

    /** @throws NotFoundException */
    public function row($deploymentId)
    {
        $row = Db::first('deployments', ['id' => (int) $deploymentId]);
        if (!$row) {
            throw new NotFoundException('That deployment does not exist.');
        }
        return $row;
    }

    public function latestFor($installationId, $action = null)
    {
        $where = ['installation_id' => (int) $installationId];
        if ($action !== null) {
            $where['action'] = (string) $action;
        }
        return Db::first('deployments', $where, ['order' => 'id', 'dir' => 'desc']);
    }

    public function present(array $row)
    {
        $installation = Db::first('installations', ['id' => (int) $row['installation_id']]);
        $steps = $this->steps((int) $row['id']);
        $failedStep = null;
        foreach ($steps as $step) {
            if ($step['status'] === self::STEP_FAILED) {
                $failedStep = ['id' => (int) $step['id'], 'key' => $step['key'], 'name' => $step['name'],
                    'error' => $step['error'], 'error_code' => $step['error_code']];
                break;
            }
        }
        return [
            'id' => (int) $row['id'],
            'uuid' => $row['uuid'],
            'reference' => $row['reference'],
            'action' => $row['action'],
            'status' => $row['status'],
            'adapter' => $row['adapter'],
            'progress' => (int) $row['progress'],
            'current_step' => isset($row['current_step']) ? $row['current_step'] : null,
            'steps_total' => (int) $row['steps_total'],
            'steps_completed' => (int) $row['steps_completed'],
            'attempts' => (int) $row['attempts'],
            'idempotency_key' => $row['idempotency_key'],
            'requested_by' => isset($row['requested_by']) ? $row['requested_by'] : null,
            'requested_by_type' => $row['requested_by_type'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'job_id' => $row['job_id'] ? (int) $row['job_id'] : null,
            'installation' => $installation ? [
                'id' => (int) $installation['id'],
                'reference' => $installation['reference'],
                'name' => $installation['name'],
                'status' => $installation['status'],
                'customer_id' => (int) $installation['customer_id'],
            ] : null,
            'error_code' => isset($row['error_code']) ? $row['error_code'] : null,
            'error_message' => isset($row['error_message']) ? $row['error_message'] : null,
            'failed_step' => $failedStep,
            'result' => Str::jsonDecode(isset($row['result']) ? $row['result'] : null, []),
            'payload' => Str::jsonDecode(isset($row['payload']) ? $row['payload'] : null, []),
            'rolled_back' => (bool) $row['rolled_back'],
            'rollback_of' => $row['rollback_of'] ? (int) $row['rollback_of'] : null,
            'queued_at' => isset($row['queued_at']) ? $row['queued_at'] : null,
            'started_at' => isset($row['started_at']) ? $row['started_at'] : null,
            'completed_at' => isset($row['completed_at']) ? $row['completed_at'] : null,
            'duration_ms' => $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            'is_terminal' => in_array((string) $row['status'], self::TERMINAL, true),
            'retryable' => in_array((string) $row['status'], [self::STATUS_FAILED, self::STATUS_TIMEOUT,
                self::STATUS_CANCELLED], true),
            'created_at' => $row['created_at'],
        ];
    }

    /** Full detail: deployment + steps + resources + the tail of the log. */
    public function detail($deploymentId, $logLines = 500)
    {
        $this->assertCanView($deploymentId);
        $row = $this->row($deploymentId);
        $detail = $this->present($row);
        $detail['steps'] = [];
        foreach ($this->steps((int) $row['id']) as $step) {
            $detail['steps'][] = [
                'id' => (int) $step['id'],
                'order' => (int) $step['step_order'],
                'key' => $step['key'],
                'name' => $step['name'],
                'status' => $step['status'],
                'reversible' => (bool) $step['reversible'],
                'creates_resource' => (bool) $step['creates_resource'],
                'attempts' => (int) $step['attempts'],
                'started_at' => isset($step['started_at']) ? $step['started_at'] : null,
                'completed_at' => isset($step['completed_at']) ? $step['completed_at'] : null,
                'duration_ms' => $step['duration_ms'] === null ? null : (int) $step['duration_ms'],
                'output' => Str::jsonDecode(isset($step['output']) ? $step['output'] : null, null),
                'error' => isset($step['error']) ? $step['error'] : null,
                'error_code' => isset($step['error_code']) ? $step['error_code'] : null,
                'resources' => Str::jsonDecode(isset($step['context']) ? $step['context'] : null, []),
            ];
        }
        $detail['resources'] = [];
        foreach ($this->resourcesFor((int) $row['id']) as $resource) {
            $detail['resources'][] = [
                'id' => (int) $resource['id'],
                'type' => $resource['resource_type'],
                'name' => $resource['resource_name'],
                'status' => $resource['status'],
                'created_at' => $resource['created_at'],
                'removed_at' => isset($resource['removed_at']) ? $resource['removed_at'] : null,
                'metadata' => Str::jsonDecode(isset($resource['metadata']) ? $resource['metadata'] : null, []),
            ];
        }
        $detail['logs'] = $this->logs((int) $row['id'], [], $logLines);
        return $detail;
    }

    /** Admin list with filters. */
    public function listing(array $filters = [], $limit = 50, $offset = 0)
    {
        $isAdmin = $this->actor->can(Rbac::DEPLOYMENT_VIEW_ALL);
        $where = [];
        if (!$isAdmin) {
            // Customers only ever see their own deployments; the customer portal
            // scopes by client id, never by a filter the caller supplies.
            Rbac::assert($this->actor, Rbac::DEPLOYMENT_VIEW_OWN);
            $where['installation_id'] = ['in', $this->ownInstallationIds()];
        }
        foreach (['status', 'action', 'adapter', 'error_code'] as $key) {
            if (!empty($filters[$key])) {
                $where[$key] = (string) $filters[$key];
            }
        }
        if (!empty($filters['server_id'])) {
            $where['server_id'] = (int) $filters['server_id'];
        }
        if (!empty($filters['installation_id']) && $isAdmin) {
            $where['installation_id'] = (int) $filters['installation_id'];
        }
        if (!empty($filters['failed_only'])) {
            $where['status'] = ['in', [self::STATUS_FAILED, self::STATUS_TIMEOUT, self::STATUS_ROLLED_BACK]];
        }
        if (!empty($filters['from'])) {
            $where['created_at'] = ['>=', (string) $filters['from']];
        }
        if (!empty($filters['to'])) {
            $where['created_at'] = isset($where['created_at'])
                ? ['range', [$where['created_at'][1], (string) $filters['to']]]
                : ['<=', (string) $filters['to']];
        }

        $rows = Db::fetch('deployments', $where, [
            'order' => 'id', 'dir' => 'desc', 'limit' => max(1, min(200, (int) $limit)), 'offset' => (int) $offset,
        ]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    /** Deployments per status for the admin dashboard. */
    public function statistics()
    {
        $counts = [];
        foreach (Db::fetch('deployments', [], ['columns' => ['status']]) as $row) {
            $status = (string) $row['status'];
            $counts[$status] = isset($counts[$status]) ? $counts[$status] + 1 : 1;
        }
        $failed = isset($counts[self::STATUS_FAILED]) ? $counts[self::STATUS_FAILED] : 0;
        $succeeded = isset($counts[self::STATUS_SUCCEEDED]) ? $counts[self::STATUS_SUCCEEDED] : 0;
        $total = $failed + $succeeded;
        return [
            'counts' => $counts,
            'total' => Db::count('deployments', []),
            'running' => isset($counts[self::STATUS_RUNNING]) ? $counts[self::STATUS_RUNNING] : 0,
            'queued' => isset($counts[self::STATUS_QUEUED]) ? $counts[self::STATUS_QUEUED] : 0,
            'failed' => $failed,
            'rolled_back' => isset($counts[self::STATUS_ROLLED_BACK]) ? $counts[self::STATUS_ROLLED_BACK] : 0,
            'success_rate' => $total > 0 ? round($succeeded / $total * 100, 1) : null,
            'leaked_resources' => Db::count('created_resources', ['status' => self::RESOURCE_LEAKED]),
        ];
    }

    /* --------------------------------------------------------- cancellation */

    /**
     * Ask for a deployment to stop.
     *
     * A queued deployment is cancelled immediately. A running one is flagged: the
     * orchestrator checks between steps and stops at the next safe point, because
     * interrupting a container operation mid-write is how you get half-created
     * state.
     */
    public function cancel($deploymentId, $reason = '')
    {
        $row = $this->row($deploymentId);
        $this->assertCanManage($row);
        $status = (string) $row['status'];
        if (in_array($status, self::TERMINAL, true)) {
            throw new StateException('That deployment has already finished (' . $status . ').', [
                'deployment_id' => (int) $row['id'], 'status' => $status,
            ]);
        }
        if ($status === self::STATUS_QUEUED) {
            if ($row['job_id']) {
                $queue = new JobQueue();
                try {
                    $queue->cancel((int) $row['job_id'], $reason !== '' ? $reason : 'Deployment cancelled.');
                } catch (StateException $e) {
                    // The job may already have been picked up; fall through to the
                    // running-deployment path rather than reporting success.
                    $status = self::STATUS_RUNNING;
                }
            }
            if ($status === self::STATUS_QUEUED) {
                return $this->markCancelled((int) $row['id'], $reason);
            }
        }
        $this->log((int) $row['id'], 'Cancellation requested: ' . Str::clip($reason !== '' ? $reason : 'no reason given', 300),
            'warning', 'worker');
        Db::update('deployments', ['error_code' => 'DEPLOYMENT_CANCEL_REQUESTED', 'updated_at' => Clock::now()],
            ['id' => (int) $row['id']]);
        if ($row['job_id']) {
            Db::update('jobs', ['error_message' => 'cancel requested: ' . Str::clip($reason, 200)],
                ['id' => (int) $row['job_id'], 'status' => ['in', JobQueue::LIVE]]);
        }
        return $this->present($this->row($deploymentId));
    }

    /** Was cancellation requested for a running deployment? */
    public function cancelRequested($deploymentId)
    {
        $row = Db::first('deployments', ['id' => (int) $deploymentId]);
        return $row !== null && (string) $row['error_code'] === 'DEPLOYMENT_CANCEL_REQUESTED';
    }

    /* ------------------------------------------------------------ permissions */

    private function assertCanView($deploymentId)
    {
        $row = $this->row($deploymentId);
        if ($this->actor->can(Rbac::DEPLOYMENT_VIEW_ALL)) {
            return $row;
        }
        Rbac::assert($this->actor, Rbac::DEPLOYMENT_VIEW_OWN);
        $installation = Db::first('installations', ['id' => (int) $row['installation_id']]);
        if (!$installation || (int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That deployment does not exist.');
        }
        return $row;
    }

    private function assertCanViewInstallation(array $installation)
    {
        if ($this->actor->can(Rbac::DEPLOYMENT_VIEW_ALL) || $this->actor->isMachine()) {
            return true;
        }
        if ((int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That installation does not exist.');
        }
        return true;
    }

    private function assertCanManage(array $row)
    {
        if ($this->actor->can(Rbac::DEPLOYMENT_CANCEL) || $this->actor->isMachine()) {
            return true;
        }
        Rbac::assert($this->actor, Rbac::INSTALL_DELETE);
        $installation = Db::first('installations', ['id' => (int) $row['installation_id']]);
        if (!$installation || (int) $installation['customer_id'] !== (int) $this->actor->clientId) {
            throw new NotFoundException('That deployment does not exist.');
        }
        return true;
    }

    private function ownInstallationIds()
    {
        $ids = [];
        foreach (Db::fetch('installations', ['customer_id' => (int) $this->actor->clientId, 'deleted_at' => null],
            ['columns' => ['id']]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids === [] ? [0] : $ids;
    }

    /* ------------------------------------------------------------ internals */

    private function scope(array $row)
    {
        $installation = !empty($row['installation_id'])
            ? Db::first('installations', ['id' => (int) $row['installation_id']]) : null;
        return [
            'deployment_id' => isset($row['id']) ? (int) $row['id'] : null,
            'installation_id' => !empty($row['installation_id']) ? (int) $row['installation_id'] : null,
            'server_id' => !empty($row['server_id']) ? (int) $row['server_id'] : null,
            'client_id' => $installation ? (int) $installation['customer_id'] : null,
        ];
    }

    private function durationMs(array $row, $until)
    {
        $from = !empty($row['started_at']) ? $row['started_at']
            : (!empty($row['queued_at']) ? $row['queued_at'] : null);
        return $from === null ? null : max(0, Clock::diffSeconds($from, $until) * 1000);
    }

    private function stepDurationMs(array $step, $until)
    {
        return empty($step['started_at']) ? null : max(0, Clock::diffSeconds($step['started_at'], $until) * 1000);
    }

    /** Delete log lines older than the retention window (cron). */
    public function pruneLogs($days = null)
    {
        $days = $days === null ? Settings::int('deployment_log_retention_days', 14) : (int) $days;
        $cutoff = Clock::at(-max(1, $days) * 86400);
        return Db::delete('deployment_logs', ['created_at' => ['<', $cutoff]]);
    }
}
