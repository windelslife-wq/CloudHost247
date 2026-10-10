<?php
/**
 * REST API Entry Point
 * All API requests route through here
 */

use PhoneServices\Core\Router;
use PhoneServices\API\Middleware\AuthMiddleware;

// Bootstrap WHMCS (session, select_query, ...). Fail closed if it is missing.
$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (!file_exists($whmcsInit)) {
    http_response_code(500);
    exit;
}
require_once $whmcsInit;

require_once __DIR__ . '/../autoload.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$router = new Router();
$router->addMiddleware(AuthMiddleware::class);
$router->dispatch();
