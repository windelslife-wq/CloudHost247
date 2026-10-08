<?php
/**
 * App Cloud provider/server REST API.
 *
 * Paths work with URL rewriting or the explicit `path` query parameter:
 *   /modules/addons/cloudhost247apps/api/index.php?path=/v1/servers
 *   /modules/addons/cloudhost247apps/api/index.php/v1/servers
 *
 * @package Ch247Apps
 */

define('CH247APPS_API', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (is_file($whmcsInit)) {
    require_once $whmcsInit;
}
require_once dirname(__DIR__) . '/autoload.php';

use Ch247Apps\Api\ApiAuth;
use Ch247Apps\Api\InfrastructureApi;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\Logger;
use Ch247Apps\Infrastructure\ProviderBootstrap;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Content-Type: application/json; charset=UTF-8');

$method = Http::method();
$path = '';
if (isset($_GET['path'])) {
    $path = (string) $_GET['path'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $path = (string) $_SERVER['PATH_INFO'];
} else {
    $uriPath = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
    $marker = '/api/index.php';
    $position = is_string($uriPath) ? strpos($uriPath, $marker) : false;
    $path = $position === false ? '/' : substr($uriPath, $position + strlen($marker));
}

$input = [];
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $raw = Http::rawBody();
    if (strlen($raw) > 1048576) {
        http_response_code(413);
        echo json_encode(['error' => ['code' => 'REQUEST_TOO_LARGE', 'message' => 'Request body exceeds 1 MiB.']]);
        exit;
    }
    if ($raw !== '') {
        $contentType = strtolower(trim((string) (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : Http::header('Content-Type'))));
        if (!preg_match('/^application\\/json(?:\\s*;|$)/i', $contentType)) {
            http_response_code(415);
            echo json_encode(['error' => ['code' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Send a JSON request body.']]);
            exit;
        }
        $decoded = InfrastructureApi::decodeJsonObject($raw);
        if ($decoded === null) {
            http_response_code(400);
            echo json_encode(['error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be a JSON object.']]);
            exit;
        }
        $input = $decoded;
    }
}

try {
    ProviderBootstrap::boot();
    $actor = ApiAuth::resolve();
    $response = (new InfrastructureApi($actor))->dispatch($method, $path, $input, Http::headers());
} catch (AppsException $e) {
    http_response_code($e->status());
    echo json_encode(['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], JSON_UNESCAPED_SLASHES);
    exit;
} catch (\Throwable $e) {
    Logger::error('App Cloud API bootstrap failed.', ['exception' => get_class($e), 'source' => 'api']);
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']]);
    exit;
}

http_response_code((int) $response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
if ((int) $response['status'] !== 204 && $method !== 'HEAD') {
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
