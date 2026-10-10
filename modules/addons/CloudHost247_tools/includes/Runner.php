<?php
/**
 * CloudHost247 Tools - Runner.
 *
 * The single execution gate for every server-side tool. Resolves the tool in
 * the catalog, enforces CSRF, rate limits, request size and timeouts, loads
 * the right handler file, invokes the handler and normalises the result into
 * one stable JSON envelope.
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/RateLimiter.php';

class CloudHost247ToolsRunner
{
    /** Hard ceiling on a single tool request body. */
    const MAX_REQUEST_BYTES = 1048576; // 1 MB

    /** Wall-clock budget for one tool execution. */
    const MAX_EXECUTION_SECONDS = 25;

    /** Inputs that must never be logged or echoed back. */
    const SENSITIVE_INPUTS = [
        'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'api_key',
        'apikey', 'card', 'card_number', 'cardnumber', 'cc', 'ccnum', 'cvv',
        'cvc', 'pin', 'iban', 'ssn', 'private_key', 'privatekey', 'passphrase',
    ];

    /**
     * Execute a tool and return a normalised response envelope.
     *
     * @param string $slug  Tool slug or legacy handler id.
     * @param array  $input Request payload.
     * @param array  $opts  ['csrf' => token, 'require_csrf' => bool, 'ip' => string]
     *
     * @return array
     */
    public static function run($slug, array $input, array $opts = [])
    {
        $started = microtime(true);

        try {
            $tool = self::resolve($slug);

            if (empty($tool['enabled'])) {
                return self::error($tool, 'This tool is currently disabled.', 'disabled', 503, $started);
            }

            if (($tool['status'] ?? '') === 'maintenance') {
                return self::error($tool, 'This tool is temporarily unavailable for maintenance.', 'maintenance', 503, $started);
            }

            if (($tool['status'] ?? '') === 'requires_configuration') {
                return self::error(
                    $tool,
                    self::configurationMessage($tool),
                    'requires_configuration',
                    501,
                    $started
                );
            }

            // Client-side tools have no server endpoint at all.
            if (($tool['exec'] ?? 'server') === 'client') {
                return self::error(
                    $tool,
                    'This tool runs entirely in your browser and has no server endpoint. '
                    . 'Your data is never transmitted to CloudHost247.',
                    'client_only',
                    400,
                    $started
                );
            }

            self::assertRequestSize($input);

            if (!empty($opts['require_csrf'])) {
                $token = $opts['csrf'] ?? ($input['csrf_token'] ?? '');
                if (!CloudHost247ToolsRateLimiter::validateToken($token)) {
                    CloudHost247ToolsSecurity::logSecurityEvent('csrf_failed', $tool['slug']);

                    return self::error($tool, 'Your session expired. Please reload the page and try again.', 'csrf', 419, $started);
                }
            }

            $quota = CloudHost247ToolsRateLimiter::check($tool, $opts['ip'] ?? null);

            $result = self::invoke($tool, $input);

            // Handlers signal failure with an 'error' key.
            if (is_array($result) && isset($result['error']) && $result['error'] !== '' && $result['error'] !== false) {
                return self::error($tool, (string) $result['error'], 'tool_error', 422, $started, $quota);
            }

            self::log($tool, $input, 'success', '');

            return [
                'success'   => true,
                'tool'      => $tool['slug'],
                'name'      => $tool['name'],
                'category'  => $tool['category'],
                'data'      => is_array($result) ? $result : ['result' => $result],
                'meta'      => self::meta($tool, $started, $quota),
            ];
        } catch (CloudHost247ToolsRateLimitException $e) {
            $tool = self::safeTool($slug);

            return self::error($tool, $e->getMessage(), 'rate_limited', 429, $started, null, ['retry_after' => $e->retryAfter]);
        } catch (CloudHost247ToolsSecurityException $e) {
            $tool = self::safeTool($slug);
            self::log($tool, $input, 'blocked', $e->getMessage());

            return self::error($tool, $e->getMessage(), 'invalid_input', 400, $started);
        } catch (\Throwable $e) {
            $tool = self::safeTool($slug);
            // Never leak internals to the client; log the detail server-side.
            self::log($tool, $input, 'error', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());

            return self::error($tool, 'This tool could not complete your request. Please try again.', 'server_error', 500, $started);
        }
    }

    /**
     * Resolve a slug (or legacy handler id) to a catalog record.
     */
    public static function resolve($slug)
    {
        $slug = trim((string) $slug);
        $tool = CloudHost247ToolsCatalog::tool($slug);

        if (!$tool) {
            $tool = CloudHost247ToolsCatalog::byHandler($slug);
        }

        if (!$tool) {
            throw new CloudHost247ToolsSecurityException('Unknown tool "' . CloudHost247ToolsSecurity::e($slug) . '".');
        }

        return $tool;
    }

    protected static function safeTool($slug)
    {
        try {
            return self::resolve($slug);
        } catch (\Throwable $e) {
            return ['slug' => (string) $slug, 'name' => (string) $slug, 'category' => 'unknown'];
        }
    }

    /**
     * Load the handler file for a tool's category and call it.
     */
    protected static function invoke(array $tool, array $input)
    {
        $fn = 'CloudHost247_tool_' . $tool['handler'];

        if (!function_exists($fn)) {
            $file = dirname(__DIR__) . '/includes/tools/' . $tool['category'] . '_tools.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!function_exists($fn)) {
            // Last resort: scan every handler file once.
            foreach (glob(dirname(__DIR__) . '/includes/tools/*_tools.php') as $file) {
                require_once $file;
                if (function_exists($fn)) {
                    break;
                }
            }
        }

        if (!function_exists($fn)) {
            throw new \RuntimeException('Handler ' . $fn . ' is not implemented.');
        }

        $previousLimit = null;
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_EXECUTION_SECONDS);
        }
        if (function_exists('ini_get')) {
            $previousLimit = @ini_get('default_socket_timeout');
            @ini_set('default_socket_timeout', (string) min(20, self::MAX_EXECUTION_SECONDS));
        }

        try {
            return $fn($input);
        } finally {
            if ($previousLimit !== null && $previousLimit !== false) {
                @ini_set('default_socket_timeout', $previousLimit);
            }
        }
    }

    protected static function assertRequestSize(array $input)
    {
        $size = strlen((string) json_encode($input));
        if ($size > self::MAX_REQUEST_BYTES) {
            throw new CloudHost247ToolsSecurityException(
                'Your input is too large (limit ' . round(self::MAX_REQUEST_BYTES / 1024) . ' KB).'
            );
        }
    }

    protected static function configurationMessage(array $tool)
    {
        return $tool['name'] . ' requires a search provider to be configured by the site administrator. '
            . 'CloudHost247 will not fabricate results, so the tool stays unavailable until a provider is connected.';
    }

    protected static function meta(array $tool, $started, $quota = null)
    {
        $meta = [
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
            'exec'        => $tool['exec'] ?? 'server',
            'cached'      => false,
        ];
        if (is_array($quota) && empty($quota['unlimited'])) {
            $meta['rate_limit'] = ['limit' => $quota['limit'], 'remaining' => $quota['remaining']];
        }

        return $meta;
    }

    protected static function error(array $tool, $message, $code, $httpStatus, $started, $quota = null, array $extra = [])
    {
        return array_merge([
            'success'     => false,
            'tool'        => $tool['slug'] ?? 'unknown',
            'name'        => $tool['name'] ?? 'Unknown tool',
            'category'    => $tool['category'] ?? 'unknown',
            'error'       => ['code' => $code, 'message' => $message],
            'http_status' => $httpStatus,
            'meta'        => self::meta($tool + ['exec' => 'server'], $started, $quota),
        ], $extra);
    }

    /**
     * Strip sensitive values before anything is written to the activity log.
     */
    public static function redact(array $input)
    {
        $out = [];
        foreach ($input as $key => $value) {
            $lower = strtolower((string) $key);
            $sensitive = false;
            foreach (self::SENSITIVE_INPUTS as $needle) {
                if ($lower === $needle || strpos($lower, $needle) !== false) {
                    $sensitive = true;
                    break;
                }
            }
            if ($key === 'csrf_token') {
                continue;
            }
            if ($sensitive) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value);
            } elseif (is_string($value) && strlen($value) > 256) {
                $out[$key] = substr($value, 0, 256) . '...[truncated]';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    protected static function log(array $tool, array $input, $status, $error)
    {
        if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            return;
        }
        try {
            \Illuminate\Database\Capsule\Manager::table('mod_CloudHost247_tools_log')->insert([
                'tool_id'    => $tool['slug'] ?? 'unknown',
                'ip'         => CloudHost247ToolsSecurity::clientIp(),
                'input'      => substr((string) json_encode(self::redact($input)), 0, 2000),
                'status'     => $status,
                'error'      => substr((string) $error, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Logging must never break a tool.
        }
    }

    /**
     * Emit the legacy AJAX response shape ({success,data} or {success,message})
     * while keeping the same security headers as respond().
     *
     * The legacy client bundle (assets/js/CloudHost247-tools.js) reads
     * response.data on success and response.message on failure, so the modern
     * envelope is translated rather than replaced. Server-side behaviour is
     * identical either way.
     */
    public static function respondLegacy(array $envelope)
    {
        $status = $envelope['success'] ? 200 : (int) ($envelope['http_status'] ?? 400);

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if (isset($envelope['retry_after'])) {
                header('Retry-After: ' . (int) $envelope['retry_after']);
            }
        }

        if ($envelope['success']) {
            echo json_encode(
                ['success' => true, 'data' => $envelope['data']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            return;
        }

        echo json_encode(
            ['success' => false, 'message' => isset($envelope['error']['message'])
                ? (string) $envelope['error']['message']
                : 'This tool could not complete your request.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Emit a JSON response for the AJAX endpoint.
     */
    public static function respond(array $envelope)
    {
        $status = $envelope['success'] ? 200 : (int) ($envelope['http_status'] ?? 400);
        unset($envelope['http_status']);

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, max-age=0');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if (isset($envelope['retry_after'])) {
                header('Retry-After: ' . (int) $envelope['retry_after']);
            }
        }

        echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
