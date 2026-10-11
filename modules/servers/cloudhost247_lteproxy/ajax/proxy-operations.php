<?php

declare(strict_types=1);

/**
 * CloudHost247 LTE Proxy Operations AJAX Handler
 *
 * Handles proxy CRUD operations via AJAX requests.
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

// Validate CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!Helpers::validateCsrfToken($csrfToken)) {
    Helpers::jsonError('Invalid security token. Please refresh the page.', 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$serviceId = (int) ($_POST['service_id'] ?? $_GET['service_id'] ?? 0);

if (empty($serviceId)) {
    Helpers::jsonError('Service ID is required', 400);
}

// Verify service ownership
$service = Capsule::table('tblhosting')
    ->where('id', $serviceId)
    ->where('userid', $_SESSION['uid'])
    ->first();

if (!$service) {
    Helpers::jsonError('Service not found or access denied', 404);
}

// Get product configuration
$product = Capsule::table('tblproducts')
    ->where('id', $service->packageid)
    ->first();

if (!$product || $product->servertype !== 'cloudhost247_lteproxy') {
    Helpers::jsonError('Invalid service type', 400);
}

// Build module params
$params = buildModuleParams($service, $product);
$logger = new Logger(getCh247Config($params));

try {
    $api = new ApiClient(getCh247Config($params), $logger);
    $orderId = $service->username ?? '';

    switch ($action) {
        case 'get_proxies':
            handleGetProxies($api, $orderId, $logger);
            break;

        case 'get_order_details':
            handleGetOrderDetails($api, $orderId, $logger);
            break;

        case 'update_client_ip':
            handleUpdateClientIp($api, $orderId, $logger);
            break;

        case 'update_region':
            handleUpdateRegion($api, $orderId, $logger);
            break;

        case 'update_carrier':
            handleUpdateCarrier($api, $orderId, $logger);
            break;

        case 'update_proxy_type':
            handleUpdateProxyType($api, $orderId, $logger);
            break;

        case 'update_auth':
            handleUpdateAuth($api, $orderId, $logger);
            break;

        case 'update_rotation':
            handleUpdateRotation($api, $orderId, $logger);
            break;

        case 'rotate_ip':
            handleRotateIp($api, $orderId, $logger);
            break;

        case 'reveal_ip':
            handleRevealIp($api, $orderId, $logger);
            break;

        case 'extend_order':
            handleExtendOrder($api, $orderId, $logger);
            break;

        case 'cancel_order':
            handleCancelOrder($api, $orderId, $logger);
            break;

        case 'get_balance':
            handleGetBalance($api, $logger);
            break;

        case 'get_available_proxies':
            handleGetAvailableProxies($api, $logger);
            break;

        case 'request_trial':
            handleRequestTrial($api, $params, $logger);
            break;

        default:
            Helpers::jsonError('Unknown action: ' . $action, 400);
    }
} catch (ApiException $e) {
    $logger->logException($e, 'AJAX: ' . $action);
    Helpers::jsonError($e->getUserMessage(), Helpers::safeHttpStatus($e->getCode()), [
        'error_code' => $e->getErrorCode(),
        'is_retryable' => $e->isRetryable(),
    ]);
} catch (\Exception $e) {
    $logger->logException($e, 'AJAX: ' . $action);
    Helpers::jsonError('An unexpected error occurred. Please try again.', 500);
}
















/**
 * Build module parameters from database records
 */
function buildModuleParams($service, $product): array
{
    return [
        'serviceid' => $service->id,
        'username' => $service->username ?? '',
        'password' => $service->password ?? '',
        'configoption1' => $product->configoption1 ?? '',
        'configoption2' => $product->configoption2 ?? '',
        'configoption3' => $product->configoption3 ?? 'https://api.cloudhost247.com',
        'configoption4' => $product->configoption4 ?? 30,
        'configoption5' => $product->configoption5 ?? 'SOCKS5',
        'configoption6' => $product->configoption6 ?? 'WIFI_AND_CELLULAR',
        'configoption7' => $product->configoption7 ?? 'manual',
        'configoption8' => $product->configoption8 ?? 60,
        'configoption9' => $product->configoption9 ?? 'us',
        'configoption10' => $product->configoption10 ?? 'verizon',
        'configoption11' => $product->configoption11 ?? 'username_password',
        'configoption12' => $product->configoption12 ?? '',
        'configoption13' => $product->configoption13 ?? 24,
        'configoption14' => $product->configoption14 ?? 'on',
        'configoption15' => $product->configoption15 ?? 'on',
        'configoption16' => $product->configoption16 ?? 'INFO',
        'configoption17' => $product->configoption17 ?? 'on',
        'configoption18' => $product->configoption18 ?? 60,
    ];
}

/**
 * Get module configuration
 */
function getCh247Config(array $params): array
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
