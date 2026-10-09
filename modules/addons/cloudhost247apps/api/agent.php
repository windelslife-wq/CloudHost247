<?php
/**
 * Fixed-path, default-off agent heartbeat endpoint.
 * Signature path: /agent/v1/heartbeat (not the public PHP URL).
 * Only liveness and separately gated host uptime; no arbitrary metrics,
 * deployment operations, logs, commands or agent daemon are exposed.
 */

define('CH247APPS_API', true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (!is_file($whmcsInit)) {
    http_response_code(503);
    echo json_encode(['error' => ['code' => 'WHMCS_UNAVAILABLE', 'message' => 'WHMCS is unavailable.']]);
    exit;
}
require_once $whmcsInit;
require_once dirname(__DIR__) . '/autoload.php';

use Ch247Apps\Api\AgentHeartbeatIngress;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Http;

try {
    // WHMCS binds its Capsule connection lazily; isBound() alone would reject
    // every real request before the first query initializes the PDO handle.
    Db::pdo();
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => ['code' => 'DATABASE_UNAVAILABLE', 'message' => 'Database is unavailable.']]);
    exit;
}

$method = Http::method();
$path = empty($_SERVER['PATH_INFO'])
    ? AgentHeartbeatIngress::PATH : (string) $_SERVER['PATH_INFO'];
$contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE']
    : (string) Http::header('Content-Type');
if ($method === 'POST' && !preg_match('/^application\/json(?:\s*;|\s*$)/i', trim($contentType))) {
    http_response_code(415);
    echo json_encode(['error' => ['code' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Send a JSON request body.']]);
    exit;
}
$response = (new AgentHeartbeatIngress())->dispatch($method, $path, Http::rawBody());
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
