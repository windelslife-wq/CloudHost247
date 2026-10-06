<?php
/**
 * The email queue.
 *
 * Properties that matter:
 *  - Idempotent: (campaign, email) hashes to a unique key, so re-running
 *    enqueue after a crash cannot double-send.
 *  - Lease-based locking: a worker claims rows with a locked_by/locked_until
 *    lease, so two overlapping crons do not send the same message twice and a
 *    worker that dies releases its work automatically.
 *  - Exponential backoff with jitter on transient failures; permanent
 *    failures never retry.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Delivery;

use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;

class QueueService
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT       = 'sent';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    /**
     * Queue every pending recipient of a campaign.
     *
     * @return int rows enqueued
     */
    public static function enqueueCampaign($campaignId, $availableAt = null)
    {
        $campaignId = (int) $campaignId;
        $now = Clock::now();
        $availableAt = $availableAt ?: $now;
        $enqueued = 0;

        $recipients = Db::query(
            'SELECT id, subscriber_id, email, name FROM ' . Db::t('campaign_recipients') . ' WHERE campaign_id = ? AND status = ?',
            [$campaignId, 'pending']
        );

        foreach ($recipients as $recipient) {
            $key = Str::idempotencyKey($campaignId, $recipient['email']);
            if (Db::count('email_queue', ['idempotency_key' => $key]) > 0) {
                continue; // already queued by a previous run
            }
            Db::insert('email_queue', [
                'campaign_id'     => $campaignId,
                'recipient_id'    => (int) $recipient['id'],
                'subscriber_id'   => (int) $recipient['subscriber_id'],
                'idempotency_key' => $key,
                'to_email'        => (string) $recipient['email'],
                'to_name'         => (string) $recipient['name'],
                'status'          => self::STATUS_PENDING,
                'priority'        => 5,
                'attempts'        => 0,
                'available_at'    => $availableAt,
                'created_at'      => $now,
            ]);
            Db::update('campaign_recipients', ['id' => (int) $recipient['id']], ['status' => 'queued']);
            $enqueued++;
        }
        return $enqueued;
    }

    /**
     * Queue a one-off message (test send, opt-in confirmation).
     * These carry their body inline and have campaign_id = 0.
     */
    public static function enqueueOneOff(array $data)
    {
        $email = Str::normalizeEmail($data['to_email'] ?? '');
        if (!Str::isEmail($email)) {
            throw new ValidationException('"' . Str::clip($data['to_email'] ?? '', 60) . '" is not a valid email address.');
        }
        $payload = [
            'subject'    => (string) ($data['subject'] ?? ''),
            'html'       => (string) ($data['html'] ?? ''),
            'text'       => (string) ($data['text'] ?? ''),
            'from_name'  => (string) ($data['from_name'] ?? ''),
            'from_email' => (string) ($data['from_email'] ?? ''),
            'reply_to'   => (string) ($data['reply_to'] ?? ''),
            'headers'    => (array) ($data['headers'] ?? []),
            'tag'        => (string) ($data['tag'] ?? 'oneoff'),
        ];
        $key = hash('sha256', $payload['tag'] . '|' . $email . '|' . Clock::time() . '|' . Str::token(8));
        return Db::insert('email_queue', [
            // Deliberately 0: a one-off carries its own rendered body, so the
            // worker must not re-render it from a campaign. An automation send
            // still passes recipient_id, which is what ties the delivery back
            // to the per-recipient ledger and its tracking token.
            'campaign_id'     => 0,
            'recipient_id'    => (int) ($data['recipient_id'] ?? 0),
            'subscriber_id'   => (int) ($data['subscriber_id'] ?? 0),
            'idempotency_key' => $key,
            'to_email'        => $email,
            'to_name'         => Str::clip($data['to_name'] ?? '', 190),
            'payload'         => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'status'          => self::STATUS_PENDING,
            'priority'        => 1, // one-offs jump the queue
            'attempts'        => 0,
            'available_at'    => Clock::now(),
            'created_at'      => Clock::now(),
        ]);
    }

    /**
     * Claim a batch of due rows for this worker.
     *
     * Rows belonging to a paused or cancelled campaign are skipped without
     * being claimed, so pausing takes effect on the very next batch.
     *
     * @return array[] claimed rows
     */
    public static function claimBatch($limit, $workerId)
    {
        $limit = max(1, min(1000, (int) $limit));
        $lockSeconds = max(30, Settings::int('lock_seconds', 300));
        $now = Clock::now();
        $until = Clock::in($lockSeconds);

        $candidates = Db::query(
            'SELECT q.* FROM ' . Db::t('email_queue') . ' q
               LEFT JOIN ' . Db::t('campaigns') . ' c ON c.id = q.campaign_id
              WHERE q.status = ?
                AND (q.available_at IS NULL OR q.available_at <= ?)
                AND (q.locked_until IS NULL OR q.locked_until < ?)
                AND (q.campaign_id = 0 OR c.status = ?)
           ORDER BY q.priority ASC, q.id ASC
              LIMIT ' . $limit,
            [self::STATUS_PENDING, $now, $now, \Ch247Mkt\Campaign\CampaignService::STATUS_SENDING]
        );

        $claimed = [];
        foreach ($candidates as $row) {
            // Conditional claim: only succeeds if nobody else took it first.
            $affected = Db::exec(
                'UPDATE ' . Db::t('email_queue') . '
                    SET status = ?, locked_by = ?, locked_until = ?
                  WHERE id = ? AND status = ? AND (locked_until IS NULL OR locked_until < ?)',
                [self::STATUS_PROCESSING, (string) $workerId, $until, (int) $row['id'], self::STATUS_PENDING, $now]
            );
            if ($affected > 0) {
                $row['status'] = self::STATUS_PROCESSING;
                $row['locked_by'] = $workerId;
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    public static function markSent($queueId, $messageId = '')
    {
        Db::update('email_queue', ['id' => (int) $queueId], [
            'status'              => self::STATUS_SENT,
            'sent_at'             => Clock::now(),
            'locked_by'           => null,
            'locked_until'        => null,
            'last_error'          => null,
            'provider_message_id' => Str::clip($messageId, 190),
        ]);
    }

    /**
     * Record a failed attempt.
     *
     * @param bool $permanent true = give up now
     * @return array{dead:bool,next_attempt_at:string|null}
     */
    public static function markFailed($queueId, $error, $permanent = false)
    {
        $row = Db::first('email_queue', ['id' => (int) $queueId]);
        if ($row === null) {
            return ['dead' => true, 'next_attempt_at' => null];
        }
        $attempts = (int) $row['attempts'] + 1;
        $maxAttempts = max(1, Settings::int('max_attempts', 5));

        if ($permanent || $attempts >= $maxAttempts) {
            Db::update('email_queue', ['id' => (int) $queueId], [
                'status'       => self::STATUS_FAILED,
                'attempts'     => $attempts,
                'last_error'   => Str::clip($error, 1000),
                'locked_by'    => null,
                'locked_until' => null,
            ]);
            return ['dead' => true, 'next_attempt_at' => null];
        }

        $next = Clock::datetime(Clock::time() + self::backoffSeconds($attempts));
        Db::update('email_queue', ['id' => (int) $queueId], [
            'status'       => self::STATUS_PENDING,
            'attempts'     => $attempts,
            'last_error'   => Str::clip($error, 1000),
            'available_at' => $next,
            'locked_by'    => null,
            'locked_until' => null,
        ]);
        return ['dead' => false, 'next_attempt_at' => $next];
    }

    /** Exponential backoff with jitter, capped. */
    public static function backoffSeconds($attempt)
    {
        $base = max(5, Settings::int('retry_base_seconds', 60));
        $max = max($base, Settings::int('retry_max_seconds', 21600));
        $delay = (int) min($max, $base * pow(2, max(0, $attempt - 1)));
        // ±20% jitter stops a whole failed batch retrying in lockstep. The cap
        // is re-applied afterwards so jitter can never push a retry past the
        // operator's configured ceiling.
        $jitter = (int) round($delay * 0.2);
        return max(1, min($max, $delay + random_int(-$jitter, $jitter)));
    }

    /** Release leases held by workers that died mid-batch. */
    public static function releaseStaleLocks()
    {
        return Db::exec(
            'UPDATE ' . Db::t('email_queue') . '
                SET status = ?, locked_by = NULL, locked_until = NULL
              WHERE status = ? AND locked_until IS NOT NULL AND locked_until < ?',
            [self::STATUS_PENDING, self::STATUS_PROCESSING, Clock::now()]
        );
    }

    public static function cancelCampaign($campaignId)
    {
        return Db::exec(
            'UPDATE ' . Db::t('email_queue') . ' SET status = ?, locked_by = NULL, locked_until = NULL
              WHERE campaign_id = ? AND status IN (?, ?)',
            [self::STATUS_CANCELLED, (int) $campaignId, self::STATUS_PENDING, self::STATUS_PROCESSING]
        );
    }

    /** Re-queue the failed rows of a campaign for one more run. */
    public static function retryFailed($campaignId)
    {
        return Db::exec(
            'UPDATE ' . Db::t('email_queue') . '
                SET status = ?, attempts = 0, available_at = ?, last_error = NULL
              WHERE campaign_id = ? AND status = ?',
            [self::STATUS_PENDING, Clock::now(), (int) $campaignId, self::STATUS_FAILED]
        );
    }

    /** @return array<string,int> */
    public static function stats($campaignId = null)
    {
        $sql = 'SELECT status, COUNT(*) AS c FROM ' . Db::t('email_queue');
        $bind = [];
        if ($campaignId !== null) {
            $sql .= ' WHERE campaign_id = ?';
            $bind[] = (int) $campaignId;
        }
        $sql .= ' GROUP BY status';
        $out = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach (Db::query($sql, $bind) as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    public static function pendingCount()
    {
        return Db::count('email_queue', ['status' => self::STATUS_PENDING]);
    }

    /** How many messages may still go out in the current minute. */
    public static function rateBudget()
    {
        $perMinute = Settings::int('send_rate_per_minute', 600);
        if ($perMinute <= 0) {
            return PHP_INT_MAX;
        }
        $rows = Db::query(
            'SELECT COUNT(*) AS c FROM ' . Db::t('email_queue') . ' WHERE status = ? AND sent_at >= ?',
            [self::STATUS_SENT, Clock::ago(60)]
        );
        $sentLastMinute = $rows ? (int) $rows[0]['c'] : 0;
        return max(0, $perMinute - $sentLastMinute);
    }

    /** Housekeeping: drop finished rows past the retention window. */
    public static function purge($days = null)
    {
        $days = $days === null ? Settings::int('retention_days_queue', 90) : (int) $days;
        if ($days <= 0) {
            return 0;
        }
        return Db::exec(
            'DELETE FROM ' . Db::t('email_queue') . ' WHERE status IN (?, ?, ?) AND created_at < ?',
            [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED, Clock::ago($days * 86400)]
        );
    }
}
