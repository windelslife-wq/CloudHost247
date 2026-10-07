<?php
namespace CloudHost247\Cloudflare\Core;
class RateLimiter
{
    /** Per-customer operation limiter. Persistent and shared across web workers. */
    public static function consume($customerId, $action, $limit = 30, $windowSeconds = 60)
    {
        $bucket = 'client:' . (int) $customerId;
        $now = Clock::now(); $window = Clock::before($windowSeconds);
        $row = Db::first('rate_limits', ['bucket' => $bucket, 'action' => (string) $action]);
        if (!$row || strtotime((string) $row['window_start']) < strtotime($window)) {
            if ($row) Db::update('rate_limits', ['id' => (int) $row['id']], ['hits' => 1, 'window_start' => $now]);
            else {
                try { Db::insert('rate_limits', ['bucket' => $bucket, 'action' => (string) $action, 'hits' => 1, 'window_start' => $now]); }
                catch (\Throwable $e) { return; }
            }
            return;
        }
        if ((int) $row['hits'] >= (int) $limit) throw new CloudflareException('RATE_LIMITED', 'Too many requests. Wait a minute and try again.', 429, $windowSeconds);
        Db::update('rate_limits', ['id' => (int) $row['id']], ['hits' => (int) $row['hits'] + 1]);
    }
}
