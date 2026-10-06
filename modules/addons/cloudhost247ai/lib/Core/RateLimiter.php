<?php
/** Sliding-window rate limiter (ported from Chs\Core\RateLimiter). */

namespace Ch247Ai\Core;

class RateLimiter
{
    public static function hit($action, $bucket, $max, $windowSeconds)
    {
        $now = Clock::time();
        $row = Db::first('rate_limits', ['bucket' => $bucket, 'action' => $action]);
        $nowStr = Clock::now();
        if ($row === null) {
            Db::insert('rate_limits', ['bucket' => $bucket, 'action' => $action, 'hits' => 1, 'window_start' => $nowStr]);
            return true;
        }
        $windowStart = Clock::toTime($row['window_start']);
        if ($now - $windowStart > $windowSeconds) {
            Db::update('rate_limits', ['bucket' => $bucket, 'action' => $action], ['hits' => 1, 'window_start' => $nowStr]);
            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        Db::update('rate_limits', ['bucket' => $bucket, 'action' => $action], ['hits' => (int) $row['hits'] + 1]);
        return true;
    }
    public static function hitOrFail($action, $bucket, $max, $windowSeconds)
    {
        if (!self::hit($action, $bucket, $max, $windowSeconds)) {
            throw new RateLimitException('Rate limit reached for ' . $action . ' (max ' . $max . ' per ' . $windowSeconds . 's).');
        }
    }
    public static function bucketForAdmin($adminId)
    {
        return 'admin:' . (int) $adminId;
    }
    public static function bucketForClient($clientId)
    {
        return 'client:' . (int) $clientId;
    }
    public static function bucketForCurrentRequest()
    {
        $clientId = Identity::clientId();
        if ($clientId) {
            return 'client:' . $clientId;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return 'ip:' . hash('sha256', $ip);
    }
    public static function purge($olderThanSeconds = 86400)
    {
        return Db::exec('DELETE FROM ' . Db::t('rate_limits') . ' WHERE window_start < ?', [Clock::ago((int) $olderThanSeconds)]);
    }
}
