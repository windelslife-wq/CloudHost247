<?php
/**
 * CloudHost247 App Cloud — job queue.
 *
 * The queue is the boundary between "somebody asked for something" and
 * "something touched a server". Every privileged operation — install, start,
 * stop, update, backup, restore, SSL, DNS, health probe, cleanup — is enqueued
 * here by an API/portal handler and executed by a worker. Nothing in a request
 * path ever talks to Docker, WHM or Kubernetes.
 *
 * Guarantees this class provides:
 *   • at-most-one live job per idempotency key (a retry cannot double-deploy)
 *   • leases with expiry, so a crashed worker's job is picked up again
 *   • bounded attempts with exponential backoff and jitter
 *   • a dead-letter state that keeps the failure, the payload and the error code
 *     for an administrator to inspect and requeue
 *   • every transition recorded in the events table (and logged), never silent
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Deployments;

use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;

class JobQueue
{
    const TYPE_INSTALL           = 'install';
    const TYPE_DESTROY           = 'destroy';
    const TYPE_START             = 'start';
    const TYPE_STOP              = 'stop';
    const TYPE_RESTART           = 'restart';
    const TYPE_UPDATE            = 'update';
    const TYPE_BACKUP            = 'backup';
    const TYPE_RESTORE           = 'restore';
    const TYPE_SSL               = 'ssl';
    const TYPE_DOMAIN_CONFIGURE  = 'domain_configure';
    const TYPE_HEALTHCHECK       = 'healthcheck';
    const TYPE_PROVISION_RESOURCE = 'provision_resource';
    const TYPE_SUSPEND           = 'suspend';
    const TYPE_TERMINATE         = 'terminate';
    const TYPE_CLEANUP           = 'cleanup';
    const TYPE_SERVER_CREATE     = 'server_create';
    const TYPE_SERVER_POLL       = 'server_poll';
    const TYPE_SERVER_REBOOT     = 'server_reboot';
    const TYPE_SERVER_POWER_ON   = 'server_power_on';
    const TYPE_SERVER_POWER_OFF  = 'server_power_off';
    const TYPE_SERVER_REBUILD    = 'server_rebuild';
    const TYPE_SERVER_RESIZE     = 'server_resize';
    const TYPE_SERVER_DELETE     = 'server_delete';
    const TYPE_PROVIDER_ACCOUNT_VERIFY = 'provider_account_verify';
    const TYPE_CONTABO_ADOPT = 'contabo_adopt_existing';
    const TYPE_PANEL_ACCOUNT_VERIFY = 'panel_account_verify';
    const TYPE_PANEL_ACCOUNT_DOMAINS = 'panel_account_domains_list';
    const TYPE_PANEL_ACCOUNT_ALIASES = 'panel_account_domain_aliases_list';
    const TYPE_PANEL_ACCOUNT_QUOTA_USAGE = 'panel_account_quota_usage_read';
    const TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE = 'panel_account_bandwidth_usage_read';
    const TYPE_PANEL_ACCOUNT_SUSPEND = 'panel_account_suspend';
    const TYPE_PANEL_ACCOUNT_UNSUSPEND = 'panel_account_unsuspend';
    const TYPE_PANEL_ACCOUNT_TERMINATE = 'panel_account_terminate';

    const TYPES = [
        self::TYPE_INSTALL, self::TYPE_DESTROY, self::TYPE_START, self::TYPE_STOP, self::TYPE_RESTART,
        self::TYPE_UPDATE, self::TYPE_BACKUP, self::TYPE_RESTORE, self::TYPE_SSL,
        self::TYPE_DOMAIN_CONFIGURE, self::TYPE_HEALTHCHECK, self::TYPE_PROVISION_RESOURCE,
        self::TYPE_SUSPEND, self::TYPE_TERMINATE, self::TYPE_CLEANUP,
        self::TYPE_SERVER_CREATE, self::TYPE_SERVER_POLL, self::TYPE_SERVER_REBOOT,
        self::TYPE_SERVER_POWER_ON, self::TYPE_SERVER_POWER_OFF, self::TYPE_SERVER_REBUILD,
        self::TYPE_SERVER_RESIZE, self::TYPE_SERVER_DELETE, self::TYPE_PROVIDER_ACCOUNT_VERIFY,
        self::TYPE_CONTABO_ADOPT,
        self::TYPE_PANEL_ACCOUNT_VERIFY, self::TYPE_PANEL_ACCOUNT_DOMAINS, self::TYPE_PANEL_ACCOUNT_ALIASES,
        self::TYPE_PANEL_ACCOUNT_QUOTA_USAGE, self::TYPE_PANEL_ACCOUNT_BANDWIDTH_USAGE,
        self::TYPE_PANEL_ACCOUNT_SUSPEND, self::TYPE_PANEL_ACCOUNT_UNSUSPEND, self::TYPE_PANEL_ACCOUNT_TERMINATE,
    ];

    /** Queues are kept separate: App Cloud target jobs never provision customer VMs. */
    const QUEUE_DEPLOYMENT = 'deployment';
    const QUEUE_MAINTENANCE = 'maintenance';
    const QUEUE_NOTIFICATION = 'notification';
    const QUEUE_PROVISIONING = 'provisioning';
    const QUEUE_CONTROL_PANEL = 'control-panel';

    const STATUS_QUEUED    = 'queued';
    const STATUS_LEASED    = 'leased';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_DEAD      = 'dead';
    const STATUS_CANCELLED = 'cancelled';

    /** Live states: a job in one of these is still going to run. */
    const LIVE = [self::STATUS_QUEUED, self::STATUS_LEASED, self::STATUS_RUNNING];

    /** Priority bands (lower runs first). */
    const PRIORITY_CRITICAL = 10;
    const PRIORITY_HIGH     = 50;
    const PRIORITY_NORMAL   = 100;
    const PRIORITY_LOW      = 200;
    const PRIORITY_BULK     = 500;

    /* ---------------------------------------------------------------- queue */

    /**
     * Enqueue a job.
     *
     * @param array $options queue, priority, available_at, max_attempts,
     *                       idempotency_key, installation_id, deployment_id,
     *                       server_id (App Cloud target), customer_server_id,
     *                       provider_account_id, panel_account_id, whmcs_service_id, client_id, requested_by
     * @return array the job row (an existing live job when the key matches)
     */
    public function enqueue($type, array $payload = [], array $options = [])
    {
        $type = strtolower((string) $type);
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Unknown job type "' . $type . '".', [
                'errors' => ['job_type' => 'Must be one of: ' . implode(', ', self::TYPES)],
            ]);
        }
        if (!Settings::bool('worker_enabled', true)) {
            throw new StateException('The deployment worker is disabled on this platform.', [
                'error_code' => 'WORKER_DISABLED',
            ]);
        }

        $key = isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : '';
        if ($key !== '') {
            $existing = Db::first('jobs', ['idempotency_key' => $key, 'status' => ['in', self::LIVE]]);
            if ($existing) {
                // The whole point of the key: a double submit, a retried webhook or
                // a customer clicking twice yields one job, not two deployments.
                Logger::info('Job enqueue deduplicated by idempotency key.', [
                    'job_id' => (int) $existing['id'], 'job_type' => $type, 'source' => 'worker',
                ]);
                return $this->present($existing);
            }
        }

        $now = Clock::now();
        $jobId = Db::insert('jobs', [
            'uuid' => Str::uuid4(),
            'job_type' => $type,
            'queue' => isset($options['queue']) ? (string) $options['queue'] : self::QUEUE_DEPLOYMENT,
            'payload' => Str::jsonEncode(Logger::redact($payload)),
            'status' => self::STATUS_QUEUED,
            'priority' => isset($options['priority']) ? (int) $options['priority'] : self::PRIORITY_NORMAL,
            'attempts' => 0,
            'max_attempts' => isset($options['max_attempts'])
                ? (int) $options['max_attempts'] : Settings::int('job_max_attempts', 5),
            'available_at' => isset($options['available_at']) ? (string) $options['available_at'] : $now,
            'idempotency_key' => $key !== '' ? Str::clip($key, 120) : null,
            'installation_id' => isset($options['installation_id']) ? (int) $options['installation_id'] : null,
            'deployment_id' => isset($options['deployment_id']) ? (int) $options['deployment_id'] : null,
            'server_id' => isset($options['server_id']) ? (int) $options['server_id'] : null,
            'customer_server_id' => isset($options['customer_server_id']) ? (int) $options['customer_server_id'] : null,
            'provider_account_id' => isset($options['provider_account_id']) ? (int) $options['provider_account_id'] : null,
            'panel_account_id' => isset($options['panel_account_id']) ? (int) $options['panel_account_id'] : null,
            'whmcs_service_id' => isset($options['whmcs_service_id']) ? (int) $options['whmcs_service_id'] : null,
            'client_id' => isset($options['client_id']) ? (int) $options['client_id'] : null,
            'requested_by' => isset($options['requested_by']) ? Str::clip((string) $options['requested_by'], 120) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Events::emit(Events::JOB_QUEUED, [
            'job_type' => $type,
            'priority' => isset($options['priority']) ? (int) $options['priority'] : self::PRIORITY_NORMAL,
        ], ['job_id' => $jobId,
            'installation_id' => isset($options['installation_id']) ? (int) $options['installation_id'] : null,
            'deployment_id' => isset($options['deployment_id']) ? (int) $options['deployment_id'] : null,
            'customer_server_id' => isset($options['customer_server_id']) ? (int) $options['customer_server_id'] : null,
            'provider_account_id' => isset($options['provider_account_id']) ? (int) $options['provider_account_id'] : null,
            'panel_account_id' => isset($options['panel_account_id']) ? (int) $options['panel_account_id'] : null,
            'whmcs_service_id' => isset($options['whmcs_service_id']) ? (int) $options['whmcs_service_id'] : null,
            'client_id' => isset($options['client_id']) ? (int) $options['client_id'] : null]);

        return $this->present($this->row($jobId));
    }

    /* ---------------------------------------------------------------- lease */

    /**
     * Claim up to $limit due jobs for a worker.
     *
     * The claim is a compare-and-set per candidate row, so two workers polling at
     * the same moment cannot both get the same job.
     *
     * @return array[] claimed job rows
     */
    public function lease($workerId, $queue = self::QUEUE_DEPLOYMENT, $limit = 1, $leaseSeconds = null)
    {
        $workerId = Str::clip((string) $workerId, 120);
        $limit = max(1, (int) $limit);
        $leaseSeconds = $leaseSeconds === null ? Settings::int('job_lease_seconds', 900) : (int) $leaseSeconds;
        $now = Clock::now();
        $expires = Clock::at($leaseSeconds);

        // Due: queued and available, plus leases that expired (worker died).
        $candidates = Db::fetch('jobs', [
            'queue' => (string) $queue,
            'status' => ['in', [self::STATUS_QUEUED, self::STATUS_LEASED, self::STATUS_RUNNING]],
            'available_at' => ['<=', $now],
        ], ['order' => 'priority', 'dir' => 'asc', 'order2' => 'available_at', 'limit' => $limit * 3]);

        $claimed = [];
        foreach ($candidates as $candidate) {
            $status = (string) $candidate['status'];
            $claimable = $status === self::STATUS_QUEUED
                || (($status === self::STATUS_LEASED || $status === self::STATUS_RUNNING)
                    && !empty($candidate['lease_expires_at']) && $candidate['lease_expires_at'] < $now);
            if (!$claimable) {
                continue;
            }
            // Serialise work per installation: never run two jobs for the same
            // installation at once, even if both are due.
            if (!empty($candidate['installation_id']) && $this->hasLiveSibling('installation_id',
                (int) $candidate['installation_id'], (int) $candidate['id'])) {
                continue;
            }
            // A customer-owned VM is serialized independently of App Cloud's
            // deployment-target `server_id` namespace.
            if (!empty($candidate['customer_server_id']) && $this->hasLiveSibling('customer_server_id',
                (int) $candidate['customer_server_id'], (int) $candidate['id'])) {
                continue;
            }
            // cPanel account mutations are serialised independently of customer VMs.
            if (!empty($candidate['panel_account_id']) && $this->hasLiveSibling('panel_account_id',
                (int) $candidate['panel_account_id'], (int) $candidate['id'])) {
                continue;
            }
            $ok = Db::compareAndSet('jobs', [
                'status' => self::STATUS_LEASED,
                'leased_at' => $now,
                'lease_expires_at' => $expires,
                'leased_by' => $workerId,
                'updated_at' => $now,
            ], ['id' => (int) $candidate['id'], 'status' => $status]);
            if ($ok) {
                $claimed[] = $this->row((int) $candidate['id']);
            }
            if (count($claimed) >= $limit) {
                break;
            }
        }
        return $claimed;
    }

    /** Is another job for the same lifecycle resource already being worked on? */
    private function hasLiveSibling($resourceColumn, $resourceId, $jobId)
    {
        $now = Clock::now();
        foreach (Db::fetch('jobs', [
            $resourceColumn => (int) $resourceId,
            'status' => ['in', [self::STATUS_LEASED, self::STATUS_RUNNING]],
        ]) as $row) {
            if ((int) $row['id'] === (int) $jobId) {
                continue;
            }
            if (!empty($row['lease_expires_at']) && $row['lease_expires_at'] >= $now) {
                return true;
            }
        }
        return false;
    }

    /** Extend a lease so a long deployment is not stolen mid-flight. */
    public function touch($jobId, $leaseSeconds = null)
    {
        $leaseSeconds = $leaseSeconds === null ? Settings::int('job_lease_seconds', 900) : (int) $leaseSeconds;
        return Db::update('jobs', [
            'lease_expires_at' => Clock::at($leaseSeconds), 'updated_at' => Clock::now(),
        ], ['id' => (int) $jobId, 'status' => ['in', [self::STATUS_LEASED, self::STATUS_RUNNING]]]) > 0;
    }

    public function start($jobId, $workerId = null)
    {
        $row = $this->row($jobId);
        if (!in_array((string) $row['status'], [self::STATUS_LEASED, self::STATUS_RUNNING], true)) {
            throw new StateException('Only a leased job can be started (status: ' . $row['status'] . ').', [
                'job_id' => (int) $row['id'], 'status' => $row['status'],
            ]);
        }
        $now = Clock::now();
        Db::update('jobs', [
            'status' => self::STATUS_RUNNING,
            'started_at' => empty($row['started_at']) ? $now : $row['started_at'],
            'attempts' => (int) $row['attempts'] + 1,
            'leased_by' => $workerId === null ? $row['leased_by'] : Str::clip((string) $workerId, 120),
            'lease_expires_at' => Clock::at(Settings::int('job_lease_seconds', 900)),
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::JOB_STARTED, [
            'job_type' => $row['job_type'], 'attempt' => (int) $row['attempts'] + 1,
        ], $this->eventScope($row));
        return $this->present($this->row($jobId));
    }

    public function complete($jobId, array $result = [])
    {
        $row = $this->row($jobId);
        $now = Clock::now();
        Db::update('jobs', [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $now,
            'result' => Str::jsonEncode(Logger::redact($result)),
            'error_code' => null,
            'error_message' => null,
            'lease_expires_at' => null,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::JOB_COMPLETED, [
            'job_type' => $row['job_type'], 'attempts' => (int) $row['attempts'],
            'duration_ms' => $this->durationMs($row, $now),
        ], $this->eventScope($row));
        Logger::info('Job completed.', [
            'job_id' => (int) $row['id'], 'job_type' => $row['job_type'], 'attempts' => (int) $row['attempts'],
            'source' => 'worker',
        ]);
        return $this->present($this->row($jobId));
    }

    /**
     * Record a failure. Retryable failures are requeued with backoff; anything
     * else (or the last attempt) goes to the dead-letter state.
     *
     * @param \Throwable|null $error
     */
    public function fail($jobId, $message, $errorCode = null, $retryable = true, $error = null)
    {
        $row = $this->row($jobId);
        $now = Clock::now();
        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, (int) $row['max_attempts']);
        $code = $errorCode !== null && $errorCode !== '' ? Str::clip((string) $errorCode, 60) : 'JOB_FAILED';

        $willRetry = (bool) $retryable && $attempts < $maxAttempts;
        $fields = [
            'error_code' => $code,
            'error_message' => Str::clip((string) $message, 2000),
            'lease_expires_at' => null,
            'leased_by' => null,
            'updated_at' => $now,
        ];
        if ($willRetry) {
            $delay = $this->backoffSeconds($attempts);
            $fields['status'] = self::STATUS_QUEUED;
            $fields['available_at'] = Clock::at($delay);
        } else {
            $fields['status'] = $attempts >= $maxAttempts ? self::STATUS_DEAD : self::STATUS_FAILED;
            $fields['completed_at'] = $now;
        }
        Db::update('jobs', $fields, ['id' => (int) $row['id']]);

        $context = $this->eventScope($row) + [
            'error_code' => $code, 'attempts' => $attempts, 'retryable' => (bool) $retryable,
        ];
        if ($willRetry) {
            Events::emit(Events::JOB_RETRIED, ['job_type' => $row['job_type'], 'error_code' => $code,
                'attempt' => $attempts, 'retry_in_seconds' => $fields['available_at']], $context);
            Logger::warning('Job failed, will retry.', [
                'job_id' => (int) $row['id'], 'job_type' => $row['job_type'], 'error_code' => $code,
                'attempt' => $attempts, 'retry_at' => $fields['available_at'], 'source' => 'worker',
            ]);
        } else {
            Events::emit($fields['status'] === self::STATUS_DEAD ? Events::JOB_DEAD : Events::JOB_FAILED, [
                'job_type' => $row['job_type'], 'error_code' => $code, 'message' => Str::clip((string) $message, 300),
            ], $context);
            Logger::error('Job entered the dead-letter queue.', [
                'job_id' => (int) $row['id'], 'job_type' => $row['job_type'], 'error_code' => $code,
                'message' => Str::clip((string) $message, 300), 'attempts' => $attempts, 'source' => 'worker',
            ]);
        }

        return $this->present($this->row($jobId));
    }

    /** Exponential backoff with jitter, capped. */
    public function backoffSeconds($attempts)
    {
        $base = Settings::int('job_backoff_base_seconds', 30);
        $max = Settings::int('job_backoff_max_seconds', 3600);
        $seconds = (int) min($base * pow(2, max(0, (int) $attempts - 1)), $max);
        // ±20% jitter so a fleet of failed jobs does not retry in lockstep.
        $jitter = (int) round($seconds * 0.2);
        return max(1, $seconds + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0));
    }

    public function cancel($jobId, $reason = '')
    {
        $row = $this->row($jobId);
        if (!in_array((string) $row['status'], self::LIVE, true)) {
            throw new StateException('Only a live job can be cancelled (status: ' . $row['status'] . ').', [
                'job_id' => (int) $row['id'], 'status' => $row['status'],
            ]);
        }
        if ((string) $row['status'] === self::STATUS_RUNNING) {
            // A running job is asked to stop at its next cancellation checkpoint;
            // the worker owns the actual interruption.
            Db::update('jobs', ['error_message' => Str::clip('cancel requested: ' . $reason, 2000),
                'updated_at' => Clock::now()], ['id' => (int) $row['id']]);
            return $this->present($this->row($jobId));
        }
        Db::update('jobs', [
            'status' => self::STATUS_CANCELLED, 'completed_at' => Clock::now(),
            'lease_expires_at' => null, 'error_message' => Str::clip($reason, 2000), 'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::JOB_FAILED, ['job_type' => $row['job_type'], 'cancelled' => true,
            'reason' => Str::clip($reason, 200)], $this->eventScope($row));
        return $this->present($this->row($jobId));
    }

    public function cancelRequested($jobId)
    {
        $row = $this->find($jobId);
        return $row !== null && (string) $row['status'] === self::STATUS_RUNNING
            && strpos((string) $row['error_message'], 'cancel requested') === 0;
    }

    /** Put a dead/failed job back in the queue (an administrator decision). */
    public function requeue($jobId, $maxAttempts = null)
    {
        $row = $this->row($jobId);
        if (!in_array((string) $row['status'], [self::STATUS_DEAD, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            throw new StateException('Only a dead, failed or cancelled job can be requeued.', [
                'job_id' => (int) $row['id'], 'status' => $row['status'],
            ]);
        }
        $now = Clock::now();
        Db::update('jobs', [
            'status' => self::STATUS_QUEUED,
            'available_at' => $now,
            'attempts' => 0,
            'max_attempts' => $maxAttempts === null ? max(1, (int) $row['max_attempts']) : max(1, (int) $maxAttempts),
            'leased_at' => null,
            'lease_expires_at' => null,
            'leased_by' => null,
            'started_at' => null,
            'completed_at' => null,
            'error_code' => null,
            'error_message' => null,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);
        Events::emit(Events::JOB_QUEUED, ['job_type' => $row['job_type'], 'requeued' => true],
            $this->eventScope($row));
        Logger::info('Job requeued by an administrator.', [
            'job_id' => (int) $row['id'], 'job_type' => $row['job_type'], 'source' => 'worker',
        ]);
        return $this->present($this->row($jobId));
    }

    /**
     * Return expired leases to the queue (cron). A worker that died mid-job must
     * not strand the deployment forever.
     */
    public function releaseExpiredLeases()
    {
        $now = Clock::now();
        $released = 0;
        foreach (Db::fetch('jobs', ['status' => ['in', [self::STATUS_LEASED, self::STATUS_RUNNING]]]) as $row) {
            if (empty($row['lease_expires_at']) || $row['lease_expires_at'] >= $now) {
                continue;
            }
            Db::update('jobs', [
                'status' => self::STATUS_QUEUED,
                'available_at' => $now,
                'leased_at' => null,
                'lease_expires_at' => null,
                'leased_by' => null,
                'error_message' => 'Lease expired; returned to the queue.',
                'updated_at' => $now,
            ], ['id' => (int) $row['id']]);
            Events::emit(Events::JOB_RETRIED, ['job_type' => $row['job_type'], 'reason' => 'lease_expired'],
                $this->eventScope($row));
            Logger::warning('Expired job lease released.', [
                'job_id' => (int) $row['id'], 'job_type' => $row['job_type'],
                'leased_by' => $row['leased_by'], 'source' => 'worker',
            ]);
            $released++;
        }
        return $released;
    }

    /** Delete finished jobs older than the retention window (cron). */
    public function prune($days = null)
    {
        $days = $days === null ? Settings::int('job_retention_days', 30) : (int) $days;
        $cutoff = Clock::at(-max(1, $days) * 86400);
        $pruned = 0;
        foreach ([self::STATUS_COMPLETED, self::STATUS_CANCELLED] as $status) {
            $pruned += Db::delete('jobs', ['status' => $status, 'completed_at' => ['<', $cutoff]]);
        }
        // Dead jobs are kept: they are the record of what needs human attention.
        return $pruned;
    }

    /* ----------------------------------------------------------------- read */

    /** @throws NotFoundException */
    public function row($jobId)
    {
        $row = Db::first('jobs', ['id' => (int) $jobId]);
        if (!$row) {
            throw new NotFoundException('That job does not exist.');
        }
        return $row;
    }

    public function find($jobId)
    {
        return Db::first('jobs', ['id' => (int) $jobId]);
    }

    /** The decoded payload (already redacted when it was stored). */
    public function payload(array $row)
    {
        return Str::jsonDecode(isset($row['payload']) ? $row['payload'] : null, []);
    }

    public function present(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => $row['uuid'],
            'job_type' => $row['job_type'],
            'queue' => $row['queue'],
            'status' => $row['status'],
            'priority' => (int) $row['priority'],
            'attempts' => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'available_at' => $row['available_at'],
            'leased_at' => isset($row['leased_at']) ? $row['leased_at'] : null,
            'lease_expires_at' => isset($row['lease_expires_at']) ? $row['lease_expires_at'] : null,
            'leased_by' => isset($row['leased_by']) ? $row['leased_by'] : null,
            'started_at' => isset($row['started_at']) ? $row['started_at'] : null,
            'completed_at' => isset($row['completed_at']) ? $row['completed_at'] : null,
            'duration_ms' => $this->durationMs($row, isset($row['completed_at']) ? $row['completed_at'] : Clock::now()),
            'error_code' => isset($row['error_code']) ? $row['error_code'] : null,
            'error_message' => isset($row['error_message']) ? $row['error_message'] : null,
            'result' => Str::jsonDecode(isset($row['result']) ? $row['result'] : null, []),
            'payload' => $this->payload($row),
            'idempotency_key' => isset($row['idempotency_key']) ? $row['idempotency_key'] : null,
            'installation_id' => $row['installation_id'] ? (int) $row['installation_id'] : null,
            'deployment_id' => $row['deployment_id'] ? (int) $row['deployment_id'] : null,
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'customer_server_id' => !empty($row['customer_server_id']) ? (int) $row['customer_server_id'] : null,
            'provider_account_id' => !empty($row['provider_account_id']) ? (int) $row['provider_account_id'] : null,
            'panel_account_id' => !empty($row['panel_account_id']) ? (int) $row['panel_account_id'] : null,
            'whmcs_service_id' => !empty($row['whmcs_service_id']) ? (int) $row['whmcs_service_id'] : null,
            'client_id' => $row['client_id'] ? (int) $row['client_id'] : null,
            'requested_by' => isset($row['requested_by']) ? $row['requested_by'] : null,
            'retryable' => $this->isRetryable($row),
            'created_at' => $row['created_at'],
        ];
    }

    public function isRetryable(array $row)
    {
        return (string) $row['status'] === self::STATUS_QUEUED && (int) $row['attempts'] < (int) $row['max_attempts'];
    }

    /** Jobs still to run for an installation (the console's "in progress" panel). */
    public function pendingFor($installationId)
    {
        $out = [];
        foreach (Db::fetch('jobs', ['installation_id' => (int) $installationId, 'status' => ['in', self::LIVE]],
            ['order' => 'priority', 'dir' => 'asc']) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    /** Live job for an idempotency key, if any. */
    public function forIdempotencyKey($key)
    {
        $row = Db::first('jobs', ['idempotency_key' => (string) $key]);
        return $row ? $this->present($row) : null;
    }

    /** Dead-letter list for the admin console. */
    public function dead($limit = 100)
    {
        $out = [];
        foreach (Db::fetch('jobs', ['status' => self::STATUS_DEAD],
            ['order' => 'id', 'dir' => 'desc', 'limit' => (int) $limit]) as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function statistics()
    {
        $counts = [];
        foreach (self::LIVE as $status) {
            $counts[$status] = 0;
        }
        foreach ([self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_DEAD, self::STATUS_CANCELLED] as $status) {
            $counts[$status] = 0;
        }
        foreach (Db::fetch('jobs', [], ['columns' => ['status']]) as $row) {
            $status = (string) $row['status'];
            $counts[$status] = isset($counts[$status]) ? $counts[$status] + 1 : 1;
        }
        $oldest = Db::first('jobs', ['status' => self::STATUS_QUEUED], ['order' => 'available_at', 'dir' => 'asc']);
        return [
            'counts' => $counts,
            'dead' => $counts[self::STATUS_DEAD],
            'backlog' => $counts[self::STATUS_QUEUED],
            'oldest_waiting_seconds' => $oldest ? Clock::diffSeconds($oldest['available_at'], Clock::now()) : 0,
            'worker_enabled' => Settings::bool('worker_enabled', true),
        ];
    }

    private function durationMs(array $row, $until)
    {
        if (empty($row['started_at'])) {
            return null;
        }
        return max(0, Clock::diffSeconds($row['started_at'], $until) * 1000);
    }

    private function eventScope(array $row)
    {
        return [
            'job_id' => (int) $row['id'],
            'installation_id' => $row['installation_id'] ? (int) $row['installation_id'] : null,
            'deployment_id' => $row['deployment_id'] ? (int) $row['deployment_id'] : null,
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'customer_server_id' => !empty($row['customer_server_id']) ? (int) $row['customer_server_id'] : null,
            'provider_account_id' => !empty($row['provider_account_id']) ? (int) $row['provider_account_id'] : null,
            'panel_account_id' => !empty($row['panel_account_id']) ? (int) $row['panel_account_id'] : null,
            'whmcs_service_id' => !empty($row['whmcs_service_id']) ? (int) $row['whmcs_service_id'] : null,
            'client_id' => $row['client_id'] ? (int) $row['client_id'] : null,
        ];
    }
}
