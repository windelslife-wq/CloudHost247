<?php
namespace CloudHost247\SoYouStart\Cron;

/** Shared safeguards and pure cursor arithmetic for scheduled SoYouStart jobs. */
final class CronGuard
{
    public static function isCliSapi($sapi)
    {
        return is_string($sapi) && $sapi === 'cli';
    }

    public static function requireCli()
    {
        if (self::isCliSapi(PHP_SAPI)) {
            return;
        }

        if (!headers_sent()) {
            if (function_exists('http_response_code')) {
                http_response_code(404);
            }
            header('Content-Type: text/plain; charset=UTF-8');
        }

        exit('Not Found');
    }
}

/**
 * Cursor helpers for priceSync's bounded product batches.
 * These functions intentionally have no WHMCS or provider dependencies.
 */
final class AdditionalIpQuantity
{
    /** Returns an integer from 1 through 256, or false for any invalid input. */
    public static function parse($value)
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        return filter_var((string) $value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 256],
        ]);
    }
}

final class PriceSyncBatch
{
    public static function normalizeOffset($offset, $total)
    {
        $total = max(0, (int) $total);
        $offset = max(0, (int) $offset);

        if ($total === 0 || $offset >= $total) {
            return 0;
        }

        return $offset;
    }

    public static function nextOffset($offset, $total, $batchSize = 10)
    {
        $total = max(0, (int) $total);
        $batchSize = (int) $batchSize;
        if ($total === 0 || $batchSize < 1) {
            return 0;
        }

        $offset = self::normalizeOffset($offset, $total);
        return min($offset + $batchSize, $total);
    }
}
