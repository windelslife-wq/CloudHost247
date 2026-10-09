<?php
/**
 * The suite's job queue (mod_chs_jobs).
 *
 * A small, durable, database-backed queue with the guarantees the domain
 * platform needs:
 *
 *   - idempotent enqueue: an idempotency key returns the existing job instead
 *     of inserting a duplicate (registration/renewal/transfer/settlement
 *     deliveries are safe to retry);
 *   - lease-based claiming: a running job is locked until its lease expires,
 *     so two workers can never run the same job concurrently, and a crashed
 *     worker's job becomes claimable again after the lease;
 *   - bounded retries with quadratic backoff, then a terminal 'failed' state
 *     that stays visible in Domain Operations for an admin retry;
 *   - correlation ids threaded from the originating request into audit logs.
 *
 * @package Chs\Workflow
 */

namespace Chs\Workflow;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\NotFoundException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;

class JobQueue
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /**
     * Enqueue a job.
     *
     * @param string $type    DomainJobTypes::*
     * @param array  $payload JSON-serialisable job payload
     * @param array  $opts    idempotency_key, correlation_id, entity_type,
     *                        entity_id, available_at (datetime), max_attempts
     * @return array the stored job row
     */
    public function enqueue($type, array $payload = [], array $opts = [])
    {
        $type = (string) $type;
        if (!JobTypes::isKnown($type)) {
            throw new ValidationException(['type' => 'Unknown job type: ' . $type]);
        }

        $idem = isset($opts['idempotency_key']) ? trim((string) $opts['idempotency_key']) : '';
        if ($idem !== '') {
            $existing = Db::first('jobs', ['idempotency_key' => $idem]);
            if ($existing) {
                // Already enqueued (any state): the retry is a no-op. Callers
                // that need the outcome read the job row by idempotency key.
                return $existing;
            }
        }

        $maxAttempts = isset($opts['max_attempts'])
            ? max(1, (int) $opts['max_attempts'])
            : max(1, Settings::int('jobs_max_attempts', 5));
        $availableAt = !empty($opts['available_at'])
            ? (string) $opts['available_at']
            : Clock::now();

        $id = Db::insert('jobs', [
            'job_id'          => Str::random(16),
            'type'            => $type,
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'max_attempts'    => $maxAttempts,
            'correlation_id'  => substr((string) (isset($opts['correlation_id']) ? $opts['correlation_id'] : Str::random(12)), 0, 64),
            'entity_type'     => substr((string) (isset($opts['entity_type']) ? $opts['entity_type'] : ''), 0, 32),
            'entity_id'       => isset($opts['entity_id']) ? (int) $opts['entity_id'] : null,
            'idempotency_key' => $idem !== '' ? substr($idem, 0, 96) : null,
            'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'available_at'    => $availableAt,
            'created_at'      => Clock::now(),
            'updated_at'      => Clock::now(),
        ]);

        return Db::first('jobs', ['id' => $id]);
    }

    /**
     * Claim the next due job, marking it running under a lease. Returns null
     * when nothing is due. Due means: a pending job whose available_at has
     * passed, OR a running job whose lease expired (a crashed worker's job
     * becomes claimable again). The UPDATE is conditional so two racing
     * workers cannot both claim the same job.
     *
     * @return array|null job row (with decoded payload)
     */
    public function claim($leaseSeconds = 300)
    {
        $leaseSeconds = max(30, (int) $leaseSeconds);
        $now = Clock::now();
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('jobs')
            . " WHERE (status = 'pending' AND available_at <= ?)"
            . " OR (status = 'running' AND locked_until IS NOT NULL AND locked_until <= ?)"
            . ' ORDER BY id ASC LIMIT 1',
            [$now, $now]
        );
        if (!$rows) {
            return null;
        }
        $job = $rows[0];
        $lockedUntil = Clock::in($leaseSeconds);
        $affected = Db::exec(
            'UPDATE ' . Db::t('jobs')
            . " SET status = 'running', locked_until = ?, attempts = attempts + 1,"
            . ' started_at = COALESCE(started_at, ?), updated_at = ?'
            . " WHERE id = ? AND (status = 'pending' OR (status = 'running' AND locked_until <= ?))",
            [$lockedUntil, $now, $now, (int) $job['id'], $now]
        );
        if ($affected === 0) {
            return null; // lost the race — another worker claimed it
        }
        $job['status'] = self::STATUS_RUNNING;
        $job['attempts'] = (int) $job['attempts'] + 1;
        $job['locked_until'] = $lockedUntil;
        $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
        return $job;
    }

    /** Mark a claimed job completed. */
    public function complete($jobId)
    {
        return Db::update('jobs', ['id' => (int) $jobId], [
            'status'       => self::STATUS_COMPLETED,
            'locked_until' => null,
            'completed_at' => Clock::now(),
            'error_code'   => '',
            'error_message' => '',
            'updated_at'   => Clock::now(),
        ]) > 0;
    }

    /**
     * Mark a claimed job failed. Below max_attempts the job returns to pending
     * with quadratic backoff; at the limit it becomes terminally failed and
     * stays visible for an admin retry.
     */
    public function fail($jobId, $errorCode, $errorMessage)
    {
        $job = Db::first('jobs', ['id' => (int) $jobId]);
        if (!$job) {
            return false;
        }
        $attempts = (int) $job['attempts'];
        $max = (int) $job['max_attempts'];
        $terminal = $attempts >= $max;
        $set = [
            'status'       => $terminal ? self::STATUS_FAILED : self::STATUS_PENDING,
            'locked_until' => null,
            'error_code'   => substr((string) $errorCode, 0, 64),
            'error_message' => substr((string) $errorMessage, 0, 500),
            'updated_at'   => Clock::now(),
        ];
        if (!$terminal) {
            $backoff = min(3600, 60 * $attempts * $attempts);
            $set['available_at'] = Clock::in($backoff);
        }
        return Db::update('jobs', ['id' => (int) $jobId], $set) > 0;
    }

    /**
     * Mark a claimed job terminally failed — no further automatic retries.
     * Used for permanent configuration errors (unconfigured provider,
     * unavailable image, invalid configuration): retrying blindly would
     * only burn attempts. The job stays visible for an admin retry.
     */
    public function failTerminal($jobId, $errorCode, $errorMessage)
    {
        return Db::update('jobs', ['id' => (int) $jobId], [
            'status'        => self::STATUS_FAILED,
            'locked_until'  => null,
            'error_code'    => substr((string) $errorCode, 0, 64),
            'error_message' => substr((string) $errorMessage, 0, 500),
            'updated_at'    => Clock::now(),
        ]) > 0;
    }

    /**
     * Drain due jobs through $handlers (type => callable(array $job): void).
     * A handler exception fails the job with the exception's machine code.
     *
     * @param callable[] $handlers
     * @return array{ran:int, completed:int, failed:int}
     */
    public function run($handlers, $maxJobs = 25, $leaseSeconds = 300)
    {
        $ran = 0;
        $completed = 0;
        $failed = 0;
        while ($ran < $maxJobs) {
            $job = $this->claim($leaseSeconds);
            if (!$job) {
                break;
            }
            $ran++;
            $type = (string) $job['type'];
            try {
                if (!isset($handlers[$type]) || !is_callable($handlers[$type])) {
                    throw new \Chs\Core\ChsException('No handler registered for job type ' . $type);
                }
                call_user_func($handlers[$type], $job);
                $this->complete($job['id']);
                $completed++;
            } catch (\Throwable $e) {
                $code = $e instanceof \Chs\Core\ChsException ? $e->machineCode() : 'job_error';
                $this->fail($job['id'], $code, $e->getMessage());
                $failed++;
            }
        }
        return ['ran' => $ran, 'completed' => $completed, 'failed' => $failed];
    }

    /* ------------------------------------------------------------- queries -- */

    /** @return array|null job row with decoded payload */
    public function find($jobId)
    {
        $row = Db::first('jobs', ['id' => (int) $jobId]);
        if ($row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }
        return $row;
    }

    /** @return array|null */
    public function findByIdempotencyKey($key)
    {
        $row = Db::first('jobs', ['idempotency_key' => (string) $key]);
        if ($row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }
        return $row;
    }

    /**
     * @param array $filters status, type, search (job id / correlation / entity)
     * @return array{rows:array[], total:int, page:int, per_page:int}
     */
    public function list(array $filters = [], $page = 1, $perPage = 25)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, min(200, (int) $perPage));
        $where = [];
        $bind = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $bind[] = (string) $filters['status'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $bind[] = (string) $filters['type'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . strtolower(trim((string) $filters['search'])) . '%';
            $where[] = '(LOWER(job_id) LIKE ? OR LOWER(correlation_id) LIKE ? OR LOWER(entity_type) LIKE ? OR LOWER(error_message) LIKE ?)';
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
            $bind[] = $like;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countRow = Db::query('SELECT COUNT(*) AS c FROM ' . Db::t('jobs') . $whereSql, $bind);
        $total = $countRow ? (int) $countRow[0]['c'] : 0;
        $rows = Db::query(
            'SELECT * FROM ' . Db::t('jobs') . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage
            . ' OFFSET ' . (($page - 1) * $perPage),
            $bind
        );
        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array<string,int> status => count */
    public function stats()
    {
        $out = [
            self::STATUS_PENDING   => 0,
            self::STATUS_RUNNING   => 0,
            self::STATUS_COMPLETED => 0,
            self::STATUS_FAILED    => 0,
        ];
        foreach (Db::query('SELECT status, COUNT(*) AS c FROM ' . Db::t('jobs') . ' GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Admin retry: a terminally failed (or stuck) job goes back to pending,
     * immediately available, lease released. The idempotency key is preserved
     * so a duplicate delivery still cannot double-apply.
     */
    public function retry($jobId)
    {
        $job = Db::first('jobs', ['id' => (int) $jobId]);
        if (!$job) {
            throw new NotFoundException('Job not found.');
        }
        if (!in_array($job['status'], [self::STATUS_FAILED, self::STATUS_RUNNING], true)) {
            throw new DuplicateOperationException('Only failed or stuck jobs can be retried.');
        }
        Db::update('jobs', ['id' => (int) $jobId], [
            'status'       => self::STATUS_PENDING,
            'locked_until' => null,
            'available_at' => Clock::now(),
            'error_code'   => '',
            'error_message' => '',
            'updated_at'   => Clock::now(),
        ]);
        return $this->find($jobId);
    }

    /** Delete completed/failed jobs older than $days (housekeeping). */
    public function purgeOlderThan($days)
    {
        $days = max(1, (int) $days);
        return Db::exec(
            'DELETE FROM ' . Db::t('jobs')
            . " WHERE status IN ('completed','failed') AND updated_at < ?",
            [Clock::ago($days * 86400)]
        );
    }
}
