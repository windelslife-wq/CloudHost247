<?php
/** Activity-log bridge: WHMCS logActivity when present, stderr in tests. */

namespace Ch247Ai\Core;

class Logger
{
    public static function info($message, array $context = [])
    {
        self::write('info', $message, $context);
    }
    public static function warning($message, array $context = [])
    {
        self::write('warning', $message, $context);
    }
    public static function error($message, array $context = [])
    {
        self::write('error', $message, $context);
    }
    protected static function write($level, $message, array $context)
    {
        $line = 'CloudHost247 AI [' . $level . '] ' . $message
            . ($context ? ' ' . json_encode(Redaction::clean($context), JSON_UNESCAPED_SLASHES) : '');
        if (function_exists('logActivity')) {
            @logActivity($line);
            return;
        }
        if (php_sapi_name() === 'cli') {
            @file_put_contents('php://stderr', $line . "\n");
        }
    }
}
