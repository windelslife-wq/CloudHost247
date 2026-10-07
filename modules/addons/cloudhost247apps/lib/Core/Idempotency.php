<?php
/**
 * CloudHost247 App Cloud — idempotency.
 *
 * Every mutating operation that a client might retry (install, start, stop,
 * update, backup, restore, webhook delivery, order creation) is wrapped in an
 * idempotency key. The first caller wins the row with a conditional insert and
 * stores its result; a retry with the same key and the same payload replays the
 * stored result instead of deploying twice. A retry with the same key and a
 * *different* payload is a conflict and is rejected — that is how accidental
 * duplicate deployments are made impossible (specification §12).
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Idempotency
{
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    /** @var int how long a completed key is honoured (seconds) */
    const TTL = 604800; // 7 days

    /**
     * Run an operation exactly once per (scope, key).
     *
     * @param string   $scope   logical operation, e.g. 'deployment.install'
     * @param string   $key     client supplied or derived key
     * @param array    $payload the request the key was issued for
     * @param callable $operation fn() returning an array
     * @return array{result: array, replayed: bool}
     *
     * @throws ConflictException when the key exists with a different payload
     */
    public static function run($scope, $key, array $payload, callable $operation)
    {
        $scope = (string) $scope;
        $key = Str::clip(trim((string) $key), 120);
        if ($key === '') {
            throw new ValidationException('An idempotency key is required for this operation.');
        }
        $fingerprint = self::fingerprint($payload);
        $now = Clock::now();

        $existing = Db::first('idempotency_keys', ['scope' => $scope, 'idempotency_key' => $key]);

        if ($existing) {
            if ((string) $existing['payload_hash'] !== $fingerprint) {
                throw new ConflictException(
                    'This idempotency key was already used with different data.',
                    ['scope' => $scope, 'key' => $key]
                );
            }
            if ($existing['status'] === self::STATUS_COMPLETED) {
                return ['result' => Str::jsonDecode($existing['response'], []), 'replayed' => true];
            }
            if ($existing['status'] === self::STATUS_RUNNING
                && !Clock::isPast($existing['expires_at'])) {
                // Another worker holds this key right now.
                throw new ConflictException('This operation is already in progress.', [
                    'scope' => $scope, 'key' => $key, 'started_at' => $existing['created_at'],
                ]);
            }
            // A previous attempt failed or was abandoned: take it over.
            $taken = Db::compareAndSet('idempotency_keys', [
                'status' => self::STATUS_RUNNING,
                'attempts' => (int) $existing['attempts'] + 1,
                'expires_at' => Clock::at(self::ttl()),
                'updated_at' => $now,
            ], ['id' => (int) $existing['id'], 'status' => $existing['status']]);
            if (!$taken) {
                throw new ConflictException('This operation is already in progress.', ['scope' => $scope, 'key' => $key]);
            }
            $id = (int) $existing['id'];
        } else {
            $id = Db::insert('idempotency_keys', [
                'scope' => $scope,
                'idempotency_key' => $key,
                'payload_hash' => $fingerprint,
                'status' => self::STATUS_RUNNING,
                'attempts' => 1,
                'expires_at' => Clock::at(self::ttl()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        try {
            $result = $operation();
            Db::update('idempotency_keys', [
                'status' => self::STATUS_COMPLETED,
                'response' => Str::jsonEncode(is_array($result) ? $result : ['value' => $result]),
                'completed_at' => Clock::now(),
                'expires_at' => Clock::at(self::TTL),
                'updated_at' => Clock::now(),
            ], ['id' => $id]);
            return ['result' => is_array($result) ? $result : ['value' => $result], 'replayed' => false];
        } catch (\Throwable $e) {
            Db::update('idempotency_keys', [
                'status' => self::STATUS_FAILED,
                'response' => Str::jsonEncode([
                    'error' => $e instanceof AppsException ? $e->errorCode() : 'ERROR',
                    'message' => Str::clip($e->getMessage(), 400),
                ]),
                'expires_at' => Clock::at(60),
                'updated_at' => Clock::now(),
            ], ['id' => $id]);
            throw $e;
        }
    }

    /** Stable hash of a payload, independent of key order. */
    public static function fingerprint(array $payload)
    {
        $copy = $payload;
        self::ksortRecursive($copy);
        return hash('sha256', Str::jsonEncode($copy));
    }

    /**
     * Derive a deterministic key from the parts that identify the operation, so
     * a retried HTTP request that carried no Idempotency-Key header still cannot
     * create a second deployment.
     */
    public static function deriveKey($scope, array $parts)
    {
        $normalised = [];
        foreach ($parts as $key => $value) {
            $normalised[$key] = is_scalar($value) ? (string) $value : Str::jsonEncode($value);
        }
        self::ksortRecursive($normalised);
        return $scope . ':' . substr(hash('sha256', Str::jsonEncode($normalised)), 0, 40);
    }

    public static function ksortRecursive(&$array)
    {
        if (!is_array($array)) {
            return;
        }
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);
        ksort($array);
    }

    public static function ttl()
    {
        return Settings::int('idempotency_running_ttl_seconds', 600);
    }

    /** Drop keys whose honour window has passed. */
    public static function prune()
    {
        if (!Db::tableExists('idempotency_keys')) {
            return 0;
        }
        return Db::delete('idempotency_keys', [
            'status' => [ '!=', self::STATUS_RUNNING ],
            'expires_at' => ['<', Clock::now()],
        ]);
    }
}
