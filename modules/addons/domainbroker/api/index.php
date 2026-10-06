<?php
/**
 * Domain Broker — REST API front controller.
 *
 * Entry point for machine clients. Authentication is by bearer token (issued
 * in the admin area) or, for same-origin browser calls, the WHMCS session plus
 * a CSRF token. Routing, authorisation, validation, rate limiting, idempotency
 * and audit logging are all performed by the router and services — this file
 * only wires PHP's superglobals to them.
 *
 * Paths work with or without URL rewriting:
 *   /modules/addons/domainbroker/api/index.php/requests
 *   /modules/addons/domainbroker/api/index.php?path=requests
 *
 * @package DomainBroker
 */

define('DOMAINBROKER_API', true);

// Boot WHMCS so the module shares its database, currencies, clients and
// billing stack. The API is part of the installation, not a parallel system.
$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use DomainBroker\Api\ApiRequest;
use DomainBroker\Api\ApiResponse;
use DomainBroker\Api\Router;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Settings;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!Settings::bool('service_enabled', true)) {
    ApiResponse::error(503, 'service_unavailable', 'The Domain Broker service is currently disabled.')->send();
    exit;
}

// Pre-flight for browser clients on the same origin.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

try {
    $router = new Router();
    $response = $router->dispatch(ApiRequest::capture());
} catch (\Throwable $e) {
    Logger::error('Unhandled Domain Broker API error.', [
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    $response = ApiResponse::error(500, 'server_error', 'An unexpected error occurred.');
}

$response->send();
