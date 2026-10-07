<?php
/** Fixed-window limiter that stores only domain-separated principal hashes. */

namespace CloudHost247\Passkey\Core;

use CloudHost247\Passkey\Model\RateLimitRecord;

class PasskeyRateLimiter
{
    const DEFAULT_WINDOW_SECONDS = 60;
    const MAX_WINDOW_SECONDS = 86400;
    const MAX_LIMIT = 10000;

    /**
     * Count one attempt and return a redacted decision. The raw principal is
     * used only in memory for hashing and is never written to this addon's DB.
     */
    public function consume($action, $principal, $limit, $windowSeconds = self::DEFAULT_WINDOW_SECONDS, $nowEpoch = null)
    {
        $action = $this->validateAction($action);
        $principal = $this->validatePrincipal($principal);
        $limit = $this->validatePositiveInt($limit, 'limit', self::MAX_LIMIT);
        $windowSeconds = $this->validatePositiveInt($windowSeconds, 'windowSeconds', self::MAX_WINDOW_SECONDS);
        if ($nowEpoch === null) {
            $nowEpoch = time();
        }
        $nowEpoch = $this->validateEpoch($nowEpoch);

        $windowStartEpoch = (int) (floor($nowEpoch / $windowSeconds) * $windowSeconds);
        $windowStart = gmdate('Y-m-d H:i:s', $windowStartEpoch);
        $expiresEpoch = $windowStartEpoch + $windowSeconds;
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresEpoch);
        $now = gmdate('Y-m-d H:i:s', $nowEpoch);
        $principalHash = self::principalHash($principal);

        // Optimistic updates make the common path atomic and avoid an
        // unscoped write. A unique-key race retries against the winning row.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $row = Db::firstQuery(
                'SELECT * FROM `' . Db::table('rate_limits') . '` '
                    . 'WHERE `action` = ? AND `principal_hash` = ? AND `window_started_at` = ?',
                [$action, $principalHash, $windowStart]
            );
            if ($row === null) {
                try {
                    Db::insert('rate_limits', [
                        'action' => $action,
                        'principal_hash' => $principalHash,
                        'window_started_at' => $windowStart,
                        'hit_count' => 1,
                        'expires_at' => $expiresAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    return $this->decision(true, $limit, $limit - 1, $expiresEpoch, $nowEpoch);
                } catch (\PDOException $error) {
                    // Another request may have created this exact bucket.
                    // Re-read it; do not fail open on an unexpected DB error.
                    continue;
                }
            }

            $record = new RateLimitRecord($row);
            $data = $record->toArray();
            if ($data['hit_count'] >= $limit) {
                return $this->decision(false, $limit, 0, $expiresEpoch, $nowEpoch);
            }
            $nextCount = $data['hit_count'] + 1;
            $changed = Db::update(
                'rate_limits',
                ['id' => $data['id'], 'hit_count' => $data['hit_count']],
                ['hit_count' => $nextCount, 'updated_at' => $now]
            );
            if ($changed === 1) {
                return $this->decision(true, $limit, $limit - $nextCount, $expiresEpoch, $nowEpoch);
            }
        }

        throw new \RuntimeException('Passkey rate-limit storage is unavailable; request denied.');
    }

    public static function principalHash($principal)
    {
        if (!is_string($principal) || trim($principal) === '') {
            throw new \InvalidArgumentException('A non-empty rate-limit principal is required.');
        }
        return hash('sha256', 'CloudHost247PasskeyRateLimit' . chr(0) . $principal);
    }

    private function decision($allowed, $limit, $remaining, $expiresEpoch, $nowEpoch)
    {
        return [
            'allowed' => (bool) $allowed,
            'limit' => (int) $limit,
            'remaining' => max(0, (int) $remaining),
            'retry_after' => max(0, $expiresEpoch - $nowEpoch),
            'window_expires_at' => gmdate('Y-m-d H:i:s', $expiresEpoch),
        ];
    }

    private function validateAction($action)
    {
        $action = trim((string) $action);
        if ($action === '' || strlen($action) > 40 || !preg_match('/^[a-z][a-z0-9_.-]*$/', $action)) {
            throw new \InvalidArgumentException('Invalid Passkey rate-limit action.');
        }
        return $action;
    }

    private function validatePrincipal($principal)
    {
        if (!is_string($principal) || trim($principal) === '' || strlen($principal) > 512) {
            throw new \InvalidArgumentException('Rate-limit principal must be non-empty text.');
        }
        return $principal;
    }

    private function validatePositiveInt($value, $field, $maximum)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || (int) $value < 1 || (int) $value > $maximum) {
            throw new \InvalidArgumentException($field . ' is outside the supported range.');
        }
        return (int) $value;
    }

    private function validateEpoch($value)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || (int) $value < 0) {
            throw new \InvalidArgumentException('nowEpoch must be a non-negative Unix timestamp.');
        }
        return (int) $value;
    }
}
