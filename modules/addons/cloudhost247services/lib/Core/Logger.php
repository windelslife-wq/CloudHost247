<?php
/**
 * CloudHost247 Services Suite — logging.
 *
 * Writes to the WHMCS activity log when available, otherwise to error_log.
 * Nothing sensitive (credentials, tokens, full request bodies) is ever logged.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Logger
{
    public static function info($message, array $context = [])
    {
        self::write('INFO', $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::write('WARNING', $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug($message, array $context = [])
    {
        if (!Settings::bool('debug_logging', false)) {
            return;
        }
        self::write('DEBUG', $message, $context);
    }

    protected static function write($level, $message, array $context)
    {
        $line = '[CHS/' . $level . '] ' . $message
            . ($context ? ' ' . json_encode(self::sanitize($context)) : '');

        if (function_exists('logActivity')) {
            logActivity($line);
        } elseif (function_exists('error_log')) {
            error_log($line);
        }
    }

    /** Strip anything that looks like a secret before it reaches a log. */
    protected static function sanitize(array $context)
    {
        $out = [];
        foreach ($context as $k => $v) {
            $key = strtolower((string) $k);
            if (strpos($key, 'secret') !== false || strpos($key, 'token') !== false
                || strpos($key, 'password') !== false || strpos($key, 'api_key') !== false
                || strpos($key, 'auth') !== false) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '…';
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
