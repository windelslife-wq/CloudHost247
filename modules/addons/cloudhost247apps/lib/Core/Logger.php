<?php
/**
 * CloudHost247 App Cloud — logging.
 *
 * Centralised, structured and redaction-aware. In WHMCS entries go to the
 * activity log (and to the module's own log table when a deployment or job id is
 * attached); under CLI the same lines go to stderr. Secrets are removed by
 * redact() before anything is written, so a debug session can never leak a
 * server credential or a customer environment value into a log.
 *
 * Test seam: Logger::startCapture() / Logger::captured() / Logger::stopCapture().
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Logger
{
    const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400];

    /** Keys whose values are never written, in any context. */
    const REDACT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'access_key',
        'secret_key', 'private_key', 'authorization', 'auth', 'credential',
        'encrypted_secret', 'encryption_key', 'signing_key', 'session', 'cookie',
        'client_secret', 'refresh_token', 'access_token', 'ssh_key', 'key',
    ];

    /** @var array|null capture buffer */
    private static $capture;

    public static function startCapture()
    {
        self::$capture = [];
    }

    /** @return array */
    public static function captured()
    {
        return self::$capture === null ? [] : self::$capture;
    }

    public static function stopCapture()
    {
        self::$capture = null;
    }

    public static function debug($message, array $context = [])
    {
        self::log('debug', $message, $context);
    }

    public static function info($message, array $context = [])
    {
        self::log('info', $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::log('warning', $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::log('error', $message, $context);
    }

    public static function exception(\Throwable $e, array $context = [])
    {
        $context['exception'] = get_class($e);
        $context['file'] = basename($e->getFile()) . ':' . $e->getLine();
        if ($e instanceof AppsException) {
            $context['error_code'] = $e->errorCode();
        }
        self::log('error', $e->getMessage(), $context);
    }

    /** Minimum level that reaches the WHMCS activity log. */
    private static function threshold()
    {
        $level = Settings::string('log_level', 'info');
        return isset(self::LEVELS[$level]) ? self::LEVELS[$level] : self::LEVELS['info'];
    }

    private static function log($level, $message, array $context)
    {
        $context = self::redact($context);
        $message = Str::cleanText($message, 400);

        if (self::$capture !== null) {
            self::$capture[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

        if (self::LEVELS[$level] < self::threshold() && $level !== 'error') {
            return;
        }

        $line = '[appcloud:' . $level . '] ' . $message
            . ($context ? ' ' . Str::jsonEncode($context) : '');

        // The module's own table is the centralised log: API, worker, agent,
        // deployment, billing and admin actions all land in one queryable place.
        try {
            if (Db::isBound() && Db::tableExists('logs')) {
                Db::insert('logs', [
                    'level' => $level,
                    'source' => isset($context['source']) ? Str::clip($context['source'], 40) : self::source(),
                    'message' => $message,
                    'context' => $context ? Str::jsonEncode($context) : null,
                    'deployment_id' => isset($context['deployment_id']) ? (int) $context['deployment_id'] : null,
                    'installation_id' => isset($context['installation_id']) ? (int) $context['installation_id'] : null,
                    'server_id' => isset($context['server_id']) ? (int) $context['server_id'] : null,
                    'created_at' => Clock::now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Logging must never break the operation being logged.
            error_log($line);
            return;
        }

        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, $line . PHP_EOL);
        }

        // Errors are also visible to the operator in WHMCS' own activity log.
        if ($level === 'error' || ($level === 'warning' && Settings::bool('log_warnings_to_activity', false))) {
            Whmcs::logActivity('App Cloud: ' . $message);
        }
    }

    private static function source()
    {
        if (defined('CH247APPS_WORKER')) {
            return 'worker';
        }
        if (defined('CH247APPS_CRON')) {
            return 'cron';
        }
        if (defined('CH247APPS_AGENT')) {
            return 'agent';
        }
        if (defined('CH247APPS_API')) {
            return 'api';
        }
        if (defined('CH247APPS_ADMIN')) {
            return 'admin';
        }
        if (php_sapi_name() === 'cli') {
            return 'cli';
        }
        return 'web';
    }

    /**
     * Recursively remove secret-bearing keys. Values are replaced, never
     * partially revealed, so no log line can be assembled into a credential.
     */
    public static function redact(array $context, $depth = 0)
    {
        if ($depth > 6) {
            return ['[redacted:too-deep]'];
        }
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::REDACT_KEYS as $bad) {
                if ($lower === $bad || strpos($lower, $bad) !== false) {
                    $out[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $out[$key] = self::redact($value, $depth + 1);
                continue;
            }
            if ($value instanceof \Throwable) {
                $out[$key] = get_class($value) . ': ' . Str::clip($value->getMessage(), 200);
                continue;
            }
            if (is_object($value)) {
                $out[$key] = '[object:' . get_class($value) . ']';
                continue;
            }
            $out[$key] = is_scalar($value) || $value === null ? $value : '[non-scalar]';
        }
        return $out;
    }
}
