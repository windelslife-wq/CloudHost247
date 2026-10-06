<?php
/**
 * Sliding-window rate limiter (mod_chs_rate_limits).
 *
 * hit() answers true/false; hitOrFail() additionally throws
 * RateLimitException so controllers can hand a clean 429-style error to the
 * UI. Buckets identify the caller (client id or pseudonymised IP).
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class RateLimiter
{
    /** @return bool true when the call is inside quota (deducted) */
    public static function hit($action, $bucket, $max, $windowSeconds)
    {
        $now = Clock::time();
        $row = Db::first('rate_limits', ['bucket' => $bucket, 'action' => $action]);
        $nowStr = Clock::now();
        if ($row === null) {
            Db::insert('rate_limits', [
                'bucket' => $bucket, 'action' => $action,
                'hits' => 1, 'window_start' => $nowStr,
            ]);
            return true;
        }
        $windowStart = Clock::toTime($row['window_start']);
        if ($now - $windowStart > $windowSeconds) {
            Db::update('rate_limits', ['bucket' => $bucket, 'action' => $action], [
                'hits' => 1, 'window_start' => $nowStr,
            ]);
            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        Db::update('rate_limits', ['bucket' => $bucket, 'action' => $action], [
            'hits' => (int) $row['hits'] + 1,
        ]);
        return true;
    }

    /** @throws RateLimitException when over quota */
    public static function hitOrFail($action, $bucket, $max, $windowSeconds)
    {
        if (!self::hit($action, $bucket, $max, $windowSeconds)) {
            throw new RateLimitException('Rate limit reached for ' . $action . ' (max ' . $max . ' per ' . $windowSeconds . 's).');
        }
    }

    /**
     * Bucket for the current request: signed-in clients by id, otherwise a
     * pseudonymised IP so the raw address never lands in the table.
     */
    public static function bucketForCurrentRequest($clientId = null)
    {
        $clientId = $clientId !== null ? (int) $clientId : (int) (Identity::clientId() ?: 0);
        if ($clientId > 0) {
            return 'client:' . $clientId;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return 'ip:' . Str::pseudonym($ip);
    }

    /** Drop expired windows; returns rows removed. */
    public static function purge($olderThanSeconds = 86400)
    {
        $cutoff = Clock::ago((int) $olderThanSeconds);
        return Db::exec(
            'DELETE FROM ' . Db::t('rate_limits') . ' WHERE window_start < ?',
            [$cutoff]
        );
    }
}
