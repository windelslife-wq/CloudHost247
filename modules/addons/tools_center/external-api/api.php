<?php
/**
 * Tools Center API - Main Entry Point
 * All requests come through here and are routed to appropriate tool modules.
 *
 * @package ToolsCenterAPI
 * @version 1.1.0
 */

if (!defined('TOOLS_API')) {
    define('TOOLS_API', true);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('Vary: Origin');

function apiResponse($data, $status = 200) {
    http_response_code($status);
    $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        http_response_code(500);
        echo '{"success":false,"error":"Unable to encode API response","code":"RESPONSE_ENCODING_ERROR"}';
        exit;
    }
    echo $encoded;
    exit;
}

function apiError($message, $status = 400, $code = null) {
    $response = ['success' => false, 'error' => $message];
    if ($code) $response['code'] = $code;
    apiResponse($response, $status);
}

// CORS is opt-in by exact origin. Server-to-server WHMCS requests normally do
// not send Origin and do not need CORS. Never reflect arbitrary Origin values.
$origin = isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])
    ? $_SERVER['HTTP_ORIGIN']
    : '';
$allowedOrigins = isset($config['allowed_origins']) && is_array($config['allowed_origins'])
    ? $config['allowed_origins']
    : [];
$originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);
if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
if ($method === 'OPTIONS') {
    if (!$originAllowed) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

if ($origin !== '' && !$originAllowed) {
    apiError('Origin not allowed', 403, 'ORIGIN_NOT_ALLOWED');
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    apiError('Only POST requests are supported', 405, 'METHOD_NOT_ALLOWED');
}

// Load authentication, cache, throttling and guarded outbound transports.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/outbound.php';

/** Read a bounded JSON or form body; query strings and cookies are ignored. */
function apiReadInput($config) {
    $maxBytes = max(1024, min(1048576, (int) (isset($config['max_request_bytes']) ? $config['max_request_bytes'] : 65536)));
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : '';
    if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBytes) {
        apiError('Request body is too large', 413, 'REQUEST_TOO_LARGE');
    }

    $contentType = isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])
        ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'], 2)[0]))
        : '';

    if ($contentType === 'application/json' || $contentType === '') {
        $stream = @fopen('php://input', 'rb');
        $raw = $stream === false ? false : stream_get_contents($stream, $maxBytes + 1);
        if (is_resource($stream)) fclose($stream);
        if (!is_string($raw)) {
            apiError('Unable to read request body', 400, 'INVALID_BODY');
        }
        if (strlen($raw) > $maxBytes) {
            apiError('Request body is too large', 413, 'REQUEST_TOO_LARGE');
        }
        if (trim($raw) === '' && $contentType === '' && !empty($_POST)) {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            apiError('Request body must be a JSON object', 400, 'INVALID_JSON');
        }
        return $decoded;
    }

    if ($contentType === 'application/x-www-form-urlencoded') {
        // Older cURL examples posted JSON without a content-type header, which
        // some clients label as form data. Preserve that POST-only format.
        if (empty($_POST)) {
            $stream = @fopen('php://input', 'rb');
            $raw = $stream === false ? false : stream_get_contents($stream, $maxBytes + 1);
            if (is_resource($stream)) fclose($stream);
            if (!is_string($raw)) {
                apiError('Unable to read request body', 400, 'INVALID_BODY');
            }
            if (strlen($raw) > $maxBytes) {
                apiError('Request body is too large', 413, 'REQUEST_TOO_LARGE');
            }
            if (trim($raw) !== '' && in_array(substr(ltrim($raw), 0, 1), ['{', '['], true)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    apiError('Request body must be a JSON object', 400, 'INVALID_JSON');
                }
                return $decoded;
            }
        }
        return is_array($_POST) ? $_POST : [];
    }

    apiError('Unsupported content type', 415, 'UNSUPPORTED_CONTENT_TYPE');
}

$input = apiReadInput($config);
$category = isset($input['category']) && is_string($input['category']) ? $input['category'] : '';
$action = isset($input['action']) && is_string($input['action']) ? $input['action'] : '';

if ($category === '') {
    apiError('Tool category is required', 400, 'MISSING_CATEGORY');
}
if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/iD', $category)) {
    apiError('Invalid tool category', 400, 'INVALID_CATEGORY');
}
if ($action === '') {
    apiError('Tool action is required', 400, 'MISSING_ACTION');
}
if (!preg_match('/^[a-z][A-Za-z0-9]{0,79}$/D', $action) || strpos($action, '__') === 0) {
    apiError('Invalid tool action', 400, 'INVALID_ACTION');
}

// Validate authentication. The auth class only sees this parsed POST body and
// request headers; GET parameters and cookies are never credentials.
$auth = new APIAuth($config);
if (!$auth->validateRequest($input)) {
    apiError('Unauthorized', 401, 'UNAUTHORIZED');
}

$rateLimiter = new RateLimiter($config);
$clientId = $auth->getClientId();
$clientIp = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
    ? $_SERVER['REMOTE_ADDR']
    : '0.0.0.0';
if (!$rateLimiter->check($clientId, $clientIp, $category, $action)) {
    apiError('Rate limit exceeded. Please try again later.', 429, 'RATE_LIMITED');
}

$categoryFile = __DIR__ . '/tools/' . $category . '.php';
if (!is_file($categoryFile)) {
    apiError('Tool category not found', 404, 'CATEGORY_NOT_FOUND');
}
require_once $categoryFile;

$toolClass = ucfirst($category) . 'Tools';
if (!class_exists($toolClass)) {
    apiError('Tool class not found', 500, 'CLASS_NOT_FOUND');
}
$toolInstance = new $toolClass($config);

// Only public methods declared directly on the tool class are callable.
$actionOk = method_exists($toolInstance, $action);
if ($actionOk) {
    $reflection = new ReflectionMethod($toolInstance, $action);
    $actionOk = $reflection->isPublic() && !$reflection->isStatic()
        && $reflection->getDeclaringClass()->getName() === $toolClass;
}
if (!$actionOk) {
    apiError('Tool action not found', 404, 'ACTION_NOT_FOUND');
}

// Never forward compatibility authentication fields to tool implementations.
$toolInput = $input;
unset($toolInput['api_token']);
$cache = new CacheManager($config);
$cacheKey = $category . '_' . $action . '_' . hash('sha256', serialize($toolInput));
$cached = $cache->get($cacheKey);
if ($cached !== null) {
    apiResponse(['success' => true, 'data' => $cached, 'cached' => true]);
}

try {
    $result = $toolInstance->$action($toolInput);
    if ($result !== null && $result !== false) {
        $cache->set($cacheKey, $result, $config['cache_duration'] ?? 300);
    }
    apiResponse(['success' => true, 'data' => $result]);
} catch (Throwable $e) {
    // Keep internals server-side and redact credential-shaped strings before
    // writing the diagnostic message. Never return exception text to callers.
    $logFile = rtrim((string) ($config['log_path'] ?? (__DIR__ . '/logs/')), '/\\')
        . DIRECTORY_SEPARATOR . 'error-' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $detail = get_class($e) . ': ' . $e->getMessage();
    $detail = preg_replace('/(?i)(api[_-]?token|authorization|password|secret)(\s*[=:]\s*)[^\s&,;]+/', '$1$2[redacted]', $detail);
    $detail = substr($detail, 0, 2000);
    @error_log(date('[Y-m-d H:i:s]') . ' ERROR: ' . $detail . PHP_EOL, 3, $logFile);
    apiError('Tool execution failed. The error has been logged.', 500, 'EXECUTION_ERROR');
}
