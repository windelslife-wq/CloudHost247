<?php
/**
 * Domain Broker — operational logging.
 *
 * Routes through WHMCS' own activity / module log when it is available so the
 * operator has one place to look, and falls back to error_log otherwise.
 * Payloads are redacted before they leave the module.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Logger
{
    /** Keys whose values are never written to a log. */
    const REDACT = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'epp', 'epp_code', 'auth_code', 'authcode', 'card', 'cardnumber', 'cvv', 'cvc',
        'encryption_key', 'signature', 'webhook_secret', 'owner_email', 'owner_phone',
    ];

    /** @var array captured lines (tests) */
    protected static $captured = [];

    /** @var bool */
    protected static $capturing = false;

    public static function startCapture()
    {
        self::$capturing = true;
        self::$captured = [];
    }

    public static function captured()
    {
        return self::$captured;
    }

    public static function stopCapture()
    {
        self::$capturing = false;
    }

    public static function debug($message, array $context = [])
    {
        self::write('debug', $message, $context);
    }

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

    public static function exception(\Throwable $e, array $context = [])
    {
        self::write('error', get_class($e) . ': ' . $e->getMessage(), array_merge($context, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]));
    }

    protected static function write($level, $message, array $context)
    {
        $redacted = self::redact($context);
        $line = '[domainbroker][' . $level . '] ' . (string) $message;
        if ($redacted) {
            $line .= ' ' . json_encode($redacted, JSON_UNESCAPED_SLASHES);
        }

        if (self::$capturing) {
            self::$captured[] = ['level' => $level, 'message' => (string) $message, 'context' => $redacted];
        }

        if (function_exists('logModuleCall') && $level !== 'debug') {
            try {
                logModuleCall('domainbroker', $level . ':' . Str::clip($message, 80), $redacted, '', '', array_values(self::REDACT));
                return;
            } catch (\Throwable $e) {
                // fall through to error_log
            }
        }

        if ($level !== 'debug' || Settings::bool('debug_logging', false)) {
            error_log($line);
        }
    }

    public static function redact(array $context)
    {
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            $sensitive = false;
            foreach (self::REDACT as $needle) {
                if (strpos($lower, $needle) !== false) {
                    $sensitive = true;
                    break;
                }
            }
            if ($sensitive) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = is_string($value) ? Str::clip($value, 500) : $value;
            } else {
                $out[$key] = '[object]';
            }
        }
        return $out;
    }
}
