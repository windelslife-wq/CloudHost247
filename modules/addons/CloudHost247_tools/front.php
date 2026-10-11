<?php
/**
 * CloudHost247 Tools - front controller for the /tools/* surface.
 *
 * Pretty URLs are rewritten here by the root .htaccess:
 *
 *   /tools/dns-lookup  ->  front.php?route=dns-lookup
 *   /tools/api/whois    ->  front.php?route=api/whois  (POST: JSON execution)
 *
 * Without mod_rewrite the same URLs work by calling this file directly
 * (.../front.php?route=dns-lookup). On nginx, the equivalent is:
 *
 *   location /tools {
 *       try_files $uri $uri/ /modules/addons/CloudHost247_tools/front.php?route=$uri&$args;
 *   }
 *
 * The file only bootstraps and emits; all dispatch and rendering lives in
 * includes/Front.php (CloudHost247ToolsFront::dispatch), which is pure and
 * covered by tests/FrontTest.php. api/index.php delegates here too.
 */

if (!defined('CLOUDHOST247_TOOLS')) {
    define('CLOUDHOST247_TOOLS', true);
}

// WHMCS database, sessions and settings when deployed over WHMCS. Absent in
// isolation (tests, static analysis), where every consumer degrades safely.
$whmcsInit = dirname(dirname(dirname(__DIR__))) . '/init.php';
if (is_file($whmcsInit)) {
    require_once $whmcsInit;
}

require_once __DIR__ . '/includes/Front.php';
if (is_file(__DIR__ . '/includes/functions.php')) {
    require_once __DIR__ . '/includes/functions.php'; // Tools-Manager enable switch
}

// --- Resolve the request path -------------------------------------------
if (!empty($ch247ApiEntry)) {
    // api/index.php: slug from ?tool= (GET helper) or the POST body.
    $slug = isset($_GET['tool']) ? (string) $_GET['tool'] : (isset($_POST['tool']) ? (string) $_POST['tool'] : '');
    $route = 'api/' . $slug;
} elseif (isset($_GET['route'])) {
    $route = (string) $_GET['route'];
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = ltrim((string) $_SERVER['PATH_INFO'], '/');
} else {
    // Last resort: recover /tools/<path> from the raw request URI (covers
    // rewrites that forward the URI without a route parameter).
    $route = '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $pos = strpos($uri, '/tools');
    if ($pos !== false) {
        $route = trim(substr($uri, $pos + strlen('/tools')), '/');
        $qm = strpos($route, '?');
        if ($qm !== false) {
            $route = substr($route, 0, $qm);
        }
    }
}

$method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$rawBody = null;
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $rawBody = file_get_contents('php://input');
}

$response = CloudHost247ToolsFront::dispatch($method, $route, $_GET, $rawBody, $_POST, [
    'ip' => CloudHost247ToolsSecurity::clientIp(),
    'server' => $_SERVER,
]);

if (!headers_sent()) {
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
}
echo $response['body'];
