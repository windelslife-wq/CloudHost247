<?php

declare(strict_types=1);

/**
 * CloudHost247 LTE Proxy Statistics AJAX Handler
 *
 * Handles statistics and data retrieval for the dashboard.
 *
 * @package CloudHost247\LTEProxy\AJAX
 * @version 1.0.0
 */

require_once __DIR__ . '/../../../../init.php';
require_once __DIR__ . '/../lib/ApiException.php';
require_once __DIR__ . '/../lib/Logger.php';
require_once __DIR__ . '/../lib/Cache.php';
require_once __DIR__ . '/../lib/Helpers.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/AjaxHandlers.php';

use WHMCS\Database\Capsule;
use CloudHost247\LTEProxy\ApiClient;
use CloudHost247\LTEProxy\Helpers;
use CloudHost247\LTEProxy\Logger;

if (!isset($_SESSION['uid'])) {
    Helpers::jsonError('Unauthorized', 401);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!Helpers::validateCsrfToken($csrfToken)) {
    Helpers::jsonError('Invalid token', 403);
}

$action = $_GET['action'] ?? '';
$serviceId = (int) ($_GET['service_id'] ?? 0);

if (empty($serviceId)) {
    Helpers::jsonError('Service ID required', 400);
}

$service = Capsule::table('tblhosting')
    ->where('id', $serviceId)
    ->where('userid', $_SESSION['uid'])
    ->first();

if (!$service) {
    Helpers::jsonError('Service not found', 404);
}

$product = Capsule::table('tblproducts')
    ->where('id', $service->packageid)
    ->first();

if (!$product || $product->servertype !== 'cloudhost247_lteproxy') {
    Helpers::jsonError('Invalid service', 400);
}

$params = [
    'configoption1' => $product->configoption1 ?? '',
    'configoption2' => $product->configoption2 ?? '',
    'configoption3' => $product->configoption3 ?? 'https://api.cloudhost247.com',
    'configoption4' => $product->configoption4 ?? 30,
    'configoption15' => $product->configoption15 ?? 'on',
    'configoption16' => $product->configoption16 ?? 'INFO',
    'configoption17' => $product->configoption17 ?? 'on',
    'configoption18' => $product->configoption18 ?? 60,
];

$config = [
    'api_key' => $params['configoption1'] ?? '',
    'api_secret' => $params['configoption2'] ?? '',
    'api_base_url' => $params['configoption3'] ?? 'https://api.cloudhost247.com',
    'api_timeout' => (int) ($params['configoption4'] ?? 30),
    'logging_enabled' => (bool) ($params['configoption15'] ?? true),
    'log_level' => $params['configoption16'] ?? 'INFO',
    'cache_enabled' => (bool) ($params['configoption17'] ?? true),
    'rate_limit_requests' => (int) ($params['configoption18'] ?? 60),
    'log_directory' => __DIR__ . '/../logs',
    'cache_directory' => __DIR__ . '/../cache',
];

$logger = new Logger($config);

try {
    $api = new ApiClient($config, $logger);
    $orderId = $service->username ?? '';

    switch ($action) {
        case 'get_usage_stats':
            getUsageStats($api, $orderId);
            break;

        case 'get_order_history':
            getOrderHistory($api, $orderId);
            break;

        case 'get_rotation_status':
            getRotationStatus($api, $orderId);
            break;

        case 'get_account_summary':
            getAccountSummary($api);
            break;

        default:
            Helpers::jsonError('Unknown action', 400);
    }
} catch (\Exception $e) {
    $logger->logException($e, 'Stats: ' . $action);
    Helpers::jsonError('Failed to load statistics', 500);
}




