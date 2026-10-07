<?php
/**
 * CloudHost247 App Cloud — rate limiting.
 *
 * Fixed-window counters in the database, keyed per principal and per bucket, so
 * the limits apply across every node of the installation without needing Redis.
 * Buckets are declared here (not per-route) so the whole platform shares one
 * policy; the router passes the route's bucket and the resolved actor identity.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class RateLimiter
{
    /**
     * bucket => [limit, windowSeconds]. Deliberately conservative for anything
     * that mutates infrastructure, generous for reads.
     */
    const BUCKETS = [
        'default'            => [120, 60],
        'app.browse'         => [300, 60],
        'install.create'     => [10, 3600],
        'install.action'     => [60, 3600],
        'deployment.view'    => [300, 60],
        'server.action'      => [60, 60],
        'domain.action'      => [60, 600],
        'ssl.action'         => [20, 3600],
        'backup.action'      => [20, 3600],
        'catalog.write'      => [120, 3600],
        'auth'               => [20, 900],
        'webhook'            => [300, 60],
        'agent'              => [600, 60],
        'logs.view'          => [120, 60],
    ];

    /** @var array runtime configuration overrides (tests) */
    private static $config = [];

    public static function configure(array $buckets)
    {
        self::$config = array_merge(self::BUCKETS, $buckets);
    }

    public static function resetConfiguration()
    {
        self::$config = [];
    }

    private static function bucket($name)
    {
        $config = self::$config ?: self::BUCKETS;
        return isset($config[$name]) ? $config[$name] : $config['default'];
    }

    /**
     * Consume one unit. Throws when the window is exhausted.
     *
     * @throws RateLimitException
     */
    public static function hit($action, $key, $cost = 1)
    {
        list($limit, $window) = self::bucket($action);
        $key = Str::clip((string) $key, 120);
        $now = Clock::timestamp();
        $windowStart = $now - ($now % max(1, $window));
        $cost = max(1, (int) $cost);

        $row = Db::first('rate_limits', ['bucket' => $action, 'identifier' => $key, 'window_start' => $windowStart]);

        if ($row) {
            $used = (int) $row['hits'] + $cost;
            if ($used > $limit) {
                throw new RateLimitException('Too many requests. Please slow down.', [
                    'bucket' => $action,
                    'limit' => $limit,
                    'retry_after' => max(1, ($windowStart + $window) - $now),
                ]);
            }
            Db::update('rate_limits', ['hits' => $used, 'updated_at' => Clock::now()], ['id' => (int) $row['id']]);
            return ['limit' => $limit, 'remaining' => max(0, $limit - $used), 'reset' => $windowStart + $window];
        }

        if ($cost > $limit) {
            throw new RateLimitException('Too many requests. Please slow down.', [
                'bucket' => $action, 'limit' => $limit, 'retry_after' => $window,
            ]);
        }

        Db::insert('rate_limits', [
            'bucket' => $action,
            'identifier' => $key,
            'window_start' => $windowStart,
            'hits' => $cost,
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);

        return ['limit' => $limit, 'remaining' => max(0, $limit - $cost), 'reset' => $windowStart + $window];
    }

    public static function remaining($action, $key)
    {
        list($limit, $window) = self::bucket($action);
        $now = Clock::timestamp();
        $windowStart = $now - ($now % max(1, $window));
        $row = Db::first('rate_limits', [
            'bucket' => $action, 'identifier' => Str::clip((string) $key, 120), 'window_start' => $windowStart,
        ]);
        return $row ? max(0, $limit - (int) $row['hits']) : $limit;
    }

    /** Remove expired windows; called by the scheduler. */
    public static function prune()
    {
        if (!Db::tableExists('rate_limits')) {
            return 0;
        }
        return Db::delete('rate_limits', ['window_start' => ['<', Clock::timestamp() - 86400]]);
    }
}
