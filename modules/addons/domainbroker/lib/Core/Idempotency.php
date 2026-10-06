<?php
/**
 * Domain Broker — idempotency for financial and other non-repeatable
 * operations.
 *
 * A caller supplies an Idempotency-Key (or the UI supplies a derived one). The
 * first call claims the key; a replay with the same key and the same request
 * fingerprint returns the stored response instead of performing the operation
 * again. A replay with the same key but a *different* payload is a conflict,
 * never a silent second charge.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Idempotency
{
    const STATE_IN_PROGRESS = 'in_progress';
    const STATE_COMPLETED   = 'completed';
    const STATE_FAILED      = 'failed';

    /** How long a stored result is replayable. */
    const TTL_SECONDS = 86400;

    /**
     * Execute $operation at most once for ($scope, $key, fingerprint($payload)).
     *
     * @param  string   $scope      logical operation, e.g. "payment.authorise"
     * @param  string   $key        caller supplied idempotency key
     * @param  array    $payload    request payload used for the fingerprint
     * @param  callable $operation  returns a JSON-serialisable array
     * @return array{result: array, replayed: bool}
     * @throws ConflictException when the key is reused with a different payload
     *                           or while the first attempt is still running.
     */
    public static function run($scope, $key, array $payload, callable $operation)
    {
        $key = trim((string) $key);
        if ($key === '') {
            // No key supplied → execute, but do not record a replayable result.
            return ['result' => $operation(), 'replayed' => false];
        }
        if (strlen($key) > 190) {
            throw new ValidationException('Idempotency key is too long.', ['idempotency_key' => 'Too long.']);
        }

        $fingerprint = self::fingerprint($payload);
        $now = Clock::now();

        $existing = Db::first('idempotency', ['scope' => $scope, 'idempotency_key' => $key]);

        if ($existing) {
            if (!hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                throw new ConflictException(
                    'This idempotency key was already used with different parameters.',
                    ['scope' => $scope]
                );
            }
            if ($existing['state'] === self::STATE_COMPLETED) {
                return ['result' => Str::jsonDecode($existing['response'], []), 'replayed' => true];
            }
            if ($existing['state'] === self::STATE_IN_PROGRESS && !Clock::isPast($existing['locked_until'])) {
                throw new ConflictException('A request with this idempotency key is still being processed.');
            }
            // Previous attempt failed or its lock expired: retry under the same key.
            Db::update('idempotency', [
                'state'        => self::STATE_IN_PROGRESS,
                'locked_until' => Clock::at(120),
                'attempts'     => (int) $existing['attempts'] + 1,
                'updated_at'   => $now,
            ], ['id' => $existing['id']]);
            $recordId = (int) $existing['id'];
        } else {
            try {
                $recordId = Db::insert('idempotency', [
                    'scope'           => Str::clip($scope, 100),
                    'idempotency_key' => $key,
                    'fingerprint'     => $fingerprint,
                    'state'           => self::STATE_IN_PROGRESS,
                    'attempts'        => 1,
                    'locked_until'    => Clock::at(120),
                    'expires_at'      => Clock::at(self::TTL_SECONDS),
                    'response'        => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            } catch (\Throwable $e) {
                // Unique index collision — another node claimed it first.
                throw new ConflictException('A request with this idempotency key is already in flight.');
            }
        }

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            Db::update('idempotency', [
                'state'      => self::STATE_FAILED,
                'error'      => Str::clip($e->getMessage(), 500),
                'updated_at' => Clock::now(),
            ], ['id' => $recordId]);
            throw $e;
        }

        Db::update('idempotency', [
            'state'      => self::STATE_COMPLETED,
            'response'   => Str::jsonEncode(is_array($result) ? $result : ['value' => $result]),
            'updated_at' => Clock::now(),
        ], ['id' => $recordId]);

        return ['result' => $result, 'replayed' => false];
    }

    /** Stable fingerprint of the semantically relevant payload. */
    public static function fingerprint(array $payload)
    {
        self::ksortRecursive($payload);
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Derive a deterministic key when the client did not provide one.
     *
     * Parts may be nested arrays (path parameters, a request body), so they
     * are canonically encoded rather than cast to string — two different
     * payloads must never collapse onto the same key.
     */
    public static function deriveKey($scope, array $parts)
    {
        $encoded = array_map(function ($part) {
            if (is_array($part)) {
                self::ksortRecursive($part);
                return json_encode($part, JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($part)) {
                return $part ? 'true' : 'false';
            }
            if ($part === null) {
                return 'null';
            }
            if (is_object($part)) {
                return json_encode($part, JSON_UNESCAPED_SLASHES);
            }
            return (string) $part;
        }, $parts);

        return substr(hash('sha256', $scope . '|' . implode('|', $encoded)), 0, 48);
    }

    public static function prune()
    {
        return Db::run(
            'DELETE FROM ' . Db::quoteIdentifier(Db::table('idempotency')) . ' WHERE expires_at < ?',
            [Clock::now()]
        )->rowCount();
    }

    public static function ksortRecursive(array &$array)
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
