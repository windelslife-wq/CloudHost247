<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;

class JobQueue
{
    public static function enqueue($kind, $serviceId = null, array $payload = [], $idempotencyKey = '')
    {
        $kind = preg_replace('/[^a-z_]/', '', strtolower((string) $kind));
        if ($kind === '') throw new \InvalidArgumentException('Invalid job type.');
        $key = $idempotencyKey !== '' ? substr($idempotencyKey, 0, 191) : hash('sha256', $kind . '|' . (int) $serviceId . '|' . json_encode($payload));
        $existing = Db::first('jobs', ['idempotency_key' => $key]);
        if ($existing) return (int) $existing['id'];
        try {
            return Db::insert('jobs', ['service_id' => $serviceId ? (int) $serviceId : null, 'kind' => $kind, 'idempotency_key' => $key,
                'payload_json' => $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null, 'status' => 'pending', 'attempts' => 0,
                'max_attempts' => 8, 'next_attempt_at' => Clock::now(), 'locked_at' => null, 'last_error_code' => null,
                'last_error_message' => null, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        } catch (\Throwable $e) {
            $existing = Db::first('jobs', ['idempotency_key' => $key]);
            if ($existing) return (int) $existing['id'];
            throw $e;
        }
    }
    public static function retryForService($serviceId, $kind = 'provision_service')
    {
        $jobs = Db::query('SELECT * FROM `' . Db::table('jobs') . '` WHERE service_id=? AND kind=? ORDER BY id DESC LIMIT 1', [(int) $serviceId, (string) $kind]);
        if ($jobs && in_array($jobs[0]['status'], ['pending','retry','running'], true)) {
            if ($jobs[0]['status'] === 'failed') return self::enqueue($kind, $serviceId, [], 'retry:' . $kind . ':' . (int) $serviceId . ':' . time());
            return (int) $jobs[0]['id'];
        }
        return self::enqueue($kind, $serviceId, [], 'retry:' . $kind . ':' . (int) $serviceId . ':' . time());
    }
    public static function due($limit = 20)
    {
        return Db::query('SELECT * FROM `' . Db::table('jobs') . '` WHERE status IN (\'pending\',\'retry\') AND next_attempt_at<=? ORDER BY id ASC LIMIT ' . max(1, min(100, (int) $limit)), [Clock::now()]);
    }
    public static function claim(array $job)
    {
        $updated = Db::exec('UPDATE `' . Db::table('jobs') . '` SET status=\'running\',locked_at=?,attempts=attempts+1,updated_at=? WHERE id=? AND status IN (\'pending\',\'retry\') AND next_attempt_at<=?', [Clock::now(), Clock::now(), (int) $job['id'], Clock::now()]);
        return $updated > 0;
    }
    public static function complete($jobId)
    {
        Db::update('jobs', ['id' => (int) $jobId], ['status' => 'completed', 'locked_at' => null, 'last_error_code' => null, 'last_error_message' => null, 'updated_at' => Clock::now()]);
    }
    public static function fail(array $job, $errorCode, $safeMessage)
    {
        $attempts = (int) ($job['attempts'] ?? 0) + 1; // claimed job increment is not reflected in the original row
        $max = (int) ($job['max_attempts'] ?? 8);
        $terminal = $attempts >= $max;
        $delay = min(21600, 30 * (int) pow(2, min(10, max(0, $attempts - 1))));
        Db::update('jobs', ['id' => (int) $job['id']], [
            'status' => $terminal ? 'failed' : 'retry', 'locked_at' => null,
            'next_attempt_at' => Clock::after($delay), 'last_error_code' => substr((string) $errorCode, 0, 80),
            'last_error_message' => substr((string) $safeMessage, 0, 500), 'updated_at' => Clock::now(),
        ]);
        return ['terminal' => $terminal, 'delay' => $delay];
    }
}
