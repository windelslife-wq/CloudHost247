<?php
namespace CloudHost247\Cloudflare\Core;
class Logger
{
    public static function info($event, array $context = []) { self::write('INFO', $event, $context); }
    public static function error($event, array $context = []) { self::write('ERROR', $event, $context); }
    private static function write($level, $event, array $context)
    {
        unset($context['api_token'], $context['token'], $context['authorization'], $context['encrypted_api_token']);
        $text = '[Cloudflare][' . $level . '] ' . (string) $event . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : '');
        if (function_exists('logActivity')) @logActivity(substr($text, 0, 1800));
        else if (function_exists('error_log')) @error_log($text);
    }
}
