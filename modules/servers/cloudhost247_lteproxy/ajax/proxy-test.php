<?php

declare(strict_types=1);

/**
 * CloudHost247 LTE Proxy Testing AJAX Handler
 *
 * Handles proxy alive and speed test operations.
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
use CloudHost247\LTEProxy\ApiException;
use CloudHost247\LTEProxy\Helpers;
use CloudHost247\LTEProxy\Logger;

// Security checks
if (!isset($_SESSION['uid'])) {
    Helpers::jsonError('Unauthorized access', 401);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!Helpers::validateCsrfToken($csrfToken)) {
    Helpers::jsonError('Invalid security token', 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$serviceId = (int) ($_POST['service_id'] ?? $_GET['service_id'] ?? 0);

if (empty($serviceId)) {
    Helpers::jsonError('Service ID is required', 400);
}

// Verify ownership
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
    Helpers::jsonError('Invalid service type', 400);
}

$params = buildTestModuleParams($service, $product);
$logger = new Logger(getTestConfig($params));

try {
    $api = new ApiClient(getTestConfig($params), $logger);
    $orderId = $service->username ?? '';

    switch ($action) {
        case 'test_alive':
            handleTestAlive($api, $orderId, $logger);
            break;

        case 'test_speed':
            handleTestSpeed($api, $orderId, $logger);
            break;

        case 'batch_test':
            handleBatchTest($api, $service->username ?? '', $logger);
            break;

        default:
            Helpers::jsonError('Unknown test action', 400);
    }
} catch (ApiException $e) {
    $logger->logException($e, 'ProxyTest: ' . $action);
    Helpers::jsonError($e->getUserMessage(), Helpers::safeHttpStatus($e->getCode()));
} catch (\Exception $e) {
    $logger->logException($e, 'ProxyTest: ' . $action);
    Helpers::jsonError('An unexpected error occurred. Please try again.', 500);
}





function buildTestModuleParams($service, $product): array
{
    return [
        'configoption1' => $product->configoption1 ?? '',
        'configoption2' => $product->configoption2 ?? '',
        'configoption3' => $product->configoption3 ?? 'https://api.cloudhost247.com',
        'configoption4' => $product->configoption4 ?? 30,
        'configoption15' => $product->configoption15 ?? 'on',
        'configoption16' => $product->configoption16 ?? 'INFO',
        'configoption17' => $product->configoption17 ?? 'on',
        'configoption18' => $product->configoption18 ?? 60,
    ];
}

function getTestConfig(array $params): array
{
    return [
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
}
