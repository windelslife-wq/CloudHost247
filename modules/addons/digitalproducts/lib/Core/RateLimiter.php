<?php
namespace DigitalProducts\Core;

use WHMCS\Database\Capsule;

class RateLimiter
{
    public static function hit($action, $bucket, $max, $windowSeconds = 60)
    {
        $now = Clock::now();
        $table = 'mod_digitalproducts_rate_limits';
        try {
            $row = Capsule::table($table)->where('action', $action)->where('bucket', $bucket)->first();
            if (!$row) {
                Capsule::table($table)->insert(['action' => $action, 'bucket' => $bucket, 'hits' => 1, 'window_start' => $now]);
                return true;
            }
            if (strtotime($now) - strtotime($row->window_start) >= (int) $windowSeconds) {
                Capsule::table($table)->where('id', $row->id)->update(['hits' => 1, 'window_start' => $now]);
                return true;
            }
            if ((int) $row->hits >= (int) $max) return false;
            Capsule::table($table)->where('id', $row->id)->update(['hits' => (int) $row->hits + 1]);
            return true;
        } catch (\Throwable $e) {
            // A missing rate-limit table must fail closed for sensitive paths.
            return false;
        }
    }

    public static function hitOrFail($action, $bucket, $max, $windowSeconds = 60)
    {
        if (!self::hit($action, $bucket, $max, $windowSeconds)) throw new RateLimitException('Rate limit reached.');
    }

    public static function bucket($clientId = 0)
    {
        if ((int) $clientId > 0) return 'client:' . (int) $clientId;
        return 'ip:' . hash('sha256', Http::ip());
    }

    public static function purge($seconds = 86400)
    {
        try { return Capsule::table('mod_digitalproducts_rate_limits')->where('window_start', '<', date('Y-m-d H:i:s', time() - $seconds))->delete(); }
        catch (\Throwable $e) { return 0; }
    }
}
