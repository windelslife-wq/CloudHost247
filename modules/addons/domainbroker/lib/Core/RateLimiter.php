<?php
/**
 * Domain Broker — rate limiting.
 *
 * Database-backed fixed-window counters keyed by action + principal, so the
 * limit holds across web nodes (unlike APCu) and survives a restart. Used on
 * request submission, offer actions, messaging, uploads, domain lookups and
 * every write API endpoint.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class RateLimiter
{
    /** action => [limit, windowSeconds] */
    const BUCKETS = [
        'request.create'   => [5, 3600],
        'request.update'   => [30, 3600],
        'offer.action'     => [40, 3600],
        'offer.create'     => [60, 3600],
        'message.send'     => [60, 3600],
        'document.upload'  => [30, 3600],
        'domain.lookup'    => [30, 60],
        'payment.action'   => [20, 3600],
        'dispute.open'     => [5, 86400],
        'api.read'         => [60, 60],
        'api.write'        => [20, 60],
        'webhook'          => [600, 60],
    ];

    /** @var array runtime bucket overrides: action => [limit, window] */
    protected static $configured = [];

    /**
     * Override one or more buckets at runtime. Deployments with unusual
     * traffic shapes (and the test suite) use this rather than editing code.
     *
     * @param array $buckets action => [limit, windowSeconds]
     */
    public static function configure(array $buckets)
    {
        foreach ($buckets as $action => $bucket) {
            if (is_array($bucket) && count($bucket) === 2) {
                self::$configured[$action] = [(int) $bucket[0], max(1, (int) $bucket[1])];
            }
        }
    }

    public static function resetConfiguration()
    {
        self::$configured = [];
    }

    /**
     * Consume one unit of quota.
     *
     * @param string $action one of BUCKETS (unknown actions fall back to api.write)
     * @param string $key    principal identity, e.g. "client:42" or an IP
     * @throws RateLimitException
     * @return array{limit:int, remaining:int, reset:int}
     */
    public static function hit($action, $key, $cost = 1)
    {
        list($limit, $window) = self::bucket($action);
        if ($limit <= 0) {
            return ['limit' => 0, 'remaining' => 0, 'reset' => 0];
        }

        $now = Clock::timestamp();
        $windowStart = (int) (floor($now / $window) * $window);
        $bucketKey = $action . '|' . $key . '|' . $windowStart;

        $row = Db::first('ratelimits', ['bucket_key' => $bucketKey]);
        if ($row) {
            $count = (int) $row['hits'] + (int) $cost;
            Db::update('ratelimits', [
                'hits' => $count,
                'updated_at' => Clock::now(),
            ], ['id' => $row['id']]);
        } else {
            $count = (int) $cost;
            try {
                Db::insert('ratelimits', [
                    'bucket_key'  => $bucketKey,
                    'action'      => $action,
                    'principal'   => Str::clip($key, 190),
                    'hits'        => $count,
                    'window_start' => gmdate('Y-m-d H:i:s', $windowStart),
                    'expires_at'  => gmdate('Y-m-d H:i:s', $windowStart + $window),
                    'created_at'  => Clock::now(),
                    'updated_at'  => Clock::now(),
                ]);
            } catch (\Throwable $e) {
                // Lost an insert race; re-read and increment.
                $row = Db::first('ratelimits', ['bucket_key' => $bucketKey]);
                $count = $row ? (int) $row['hits'] + (int) $cost : (int) $cost;
                if ($row) {
                    Db::update('ratelimits', ['hits' => $count, 'updated_at' => Clock::now()], ['id' => $row['id']]);
                }
            }
        }

        $reset = $windowStart + $window - $now;

        if ($count > $limit) {
            Logger::warning('Rate limit exceeded', ['action' => $action, 'principal' => $key]);
            throw new RateLimitException(
                'You are doing that too often. Please wait ' . max(1, $reset) . ' seconds and try again.',
                max(1, $reset)
            );
        }

        return ['limit' => $limit, 'remaining' => max(0, $limit - $count), 'reset' => $reset];
    }

    /** Peek without consuming. */
    public static function remaining($action, $key)
    {
        list($limit, $window) = self::bucket($action);
        $windowStart = (int) (floor(Clock::timestamp() / $window) * $window);
        $row = Db::first('ratelimits', ['bucket_key' => $action . '|' . $key . '|' . $windowStart]);
        $used = $row ? (int) $row['hits'] : 0;
        return max(0, $limit - $used);
    }

    /** Remove expired windows (called by the module cron). */
    public static function prune()
    {
        return Db::run(
            'DELETE FROM ' . Db::quoteIdentifier(Db::table('ratelimits')) . ' WHERE expires_at < ?',
            [Clock::now()]
        )->rowCount();
    }

    protected static function bucket($action)
    {
        if (isset(self::$configured[$action])) {
            return self::$configured[$action];
        }
        if (isset(self::BUCKETS[$action])) {
            $bucket = self::BUCKETS[$action];
        } else {
            $bucket = self::BUCKETS['api.write'];
        }

        // Operators can tune the two API buckets from settings.
        if ($action === 'api.read') {
            $bucket[0] = Settings::int('api_rate_limit_per_minute', $bucket[0]);
        } elseif ($action === 'api.write') {
            $bucket[0] = Settings::int('api_write_rate_limit_per_minute', $bucket[0]);
        }

        return $bucket;
    }
}
