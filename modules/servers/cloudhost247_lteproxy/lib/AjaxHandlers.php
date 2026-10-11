<?php

declare(strict_types=1);

/**
 * CloudHost247 LTE Proxy AJAX operation handlers.
 *
 * Pure request handlers shared by the ajax/ endpoints (moved verbatim out of
 * the endpoint files so they can be unit-tested without the WHMCS bootstrap).
 *
 * @package CloudHost247\LTEProxy\AJAX
 * @version 1.0.0
 */

use CloudHost247\LTEProxy\ApiClient;
use CloudHost247\LTEProxy\Helpers;
use CloudHost247\LTEProxy\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Handle get proxies request
 */
function handleGetProxies(ApiClient $api, string $orderId, Logger $logger): void
{
    if (empty($orderId)) {
        Helpers::jsonSuccess(['proxies' => [], 'count' => 0]);
    }

    $response = $api->getOrderProxies($orderId);
    $proxies = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];

    // Format proxies for display
    $formatted = [];
    foreach ($proxies as $proxy) {
        $formatted[] = [
            'id' => $proxy['id'] ?? '',
            'ip' => $proxy['ip'] ?? $proxy['host'] ?? '',
            'port' => $proxy['port'] ?? '',
            'type' => strtoupper($proxy['proxy_type'] ?? 'SOCKS5'),
            'region' => $proxy['region'] ?? '',
            'carrier' => $proxy['carrier'] ?? '',
            'status' => $proxy['status'] ?? 'unknown',
            'connection_type' => $proxy['connection_type'] ?? 'WIFI_AND_CELLULAR',
            'username' => $proxy['username'] ?? '',
            'password' => Helpers::maskString($proxy['password'] ?? '', 3),
            'full_password' => $proxy['password'] ?? '',
            'auth_type' => $proxy['auth_type'] ?? 'username_password',
            'client_ip' => $proxy['client_ip'] ?? '',
            'rotation_type' => $proxy['rotation_type'] ?? 'manual',
            'rotation_interval' => $proxy['rotation_interval'] ?? 60,
            'last_rotated' => $proxy['last_rotated'] ?? '',
            'expires_at' => $proxy['expires_at'] ?? '',
            'formatted_proxy' => Helpers::formatProxy($proxy),
            'copy_format' => Helpers::formatProxyForCopy($proxy),
            'is_1by1' => (bool) ($proxy['is_1by1'] ?? false),
            'time_remaining' => Helpers::getTimeRemaining($proxy['expires_at'] ?? ''),
        ];
    }

    Helpers::jsonSuccess([
        'proxies' => $formatted,
        'count' => count($formatted),
    ]);
}

/**
 * Handle get order details request
 */
function handleGetOrderDetails(ApiClient $api, string $orderId, Logger $logger): void
{
    if (empty($orderId)) {
        Helpers::jsonSuccess(['order' => null]);
    }

    $response = $api->getOrders(['order_id' => $orderId]);
    $order = isset($response['data'][0]) && is_array($response['data'][0]) ? $response['data'][0] : [];

    Helpers::jsonSuccess([
        'order' => [
            'id' => $order['id'] ?? $orderId,
            'status' => $order['status'] ?? 'unknown',
            'quantity' => $order['quantity'] ?? 0,
            'region' => $order['region'] ?? '',
            'carrier' => $order['carrier'] ?? '',
            'proxy_type' => $order['proxy_type'] ?? '',
            'created_at' => Helpers::formatDate($order['created_at'] ?? ''),
            'expires_at' => Helpers::formatDate($order['expires_at'] ?? ''),
            'time_remaining' => Helpers::getTimeRemaining($order['expires_at'] ?? ''),
            'connection_type' => $order['connection_type'] ?? '',
            'rotation_type' => $order['rotation_type'] ?? '',
        ],
    ]);
}

/**
 * Handle update client IP request
 */
function handleUpdateClientIp(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $clientIp = $_POST['client_ip'] ?? '';

    if (empty($proxyId) || empty($clientIp)) {
        Helpers::jsonError('Proxy ID and client IP are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    if (!Helpers::isValidIp($clientIp)) {
        Helpers::jsonError('Invalid IP address format', 400);
    }

    $response = $api->updateClientIp($proxyId, $clientIp);
    $logger->info('Updated client IP', ['proxy_id' => $proxyId, 'ip' => $clientIp]);

    Helpers::jsonSuccess([
        'message' => 'Client IP updated successfully',
        'ip' => $clientIp,
    ]);
}

/**
 * Handle update region request
 */
function handleUpdateRegion(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $region = $_POST['region'] ?? '';

    if (empty($proxyId) || empty($region)) {
        Helpers::jsonError('Proxy ID and region are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    if (!Helpers::isValidRegion($region)) {
        Helpers::jsonError('Invalid region selected', 400);
    }

    $response = $api->updateRegion($proxyId, $region);
    $logger->info('Updated proxy region', ['proxy_id' => $proxyId, 'region' => $region]);

    Helpers::jsonSuccess([
        'message' => 'Region updated to ' . Helpers::getRegions()[$region] ?? $region,
        'region' => $region,
    ]);
}

/**
 * Handle update carrier request
 */
function handleUpdateCarrier(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $carrier = $_POST['carrier'] ?? '';

    if (empty($proxyId) || empty($carrier)) {
        Helpers::jsonError('Proxy ID and carrier are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    if (!Helpers::isValidCarrier($carrier)) {
        Helpers::jsonError('Invalid carrier selected', 400);
    }

    $response = $api->updateCarrier($proxyId, $carrier);
    $logger->info('Updated proxy carrier', ['proxy_id' => $proxyId, 'carrier' => $carrier]);

    Helpers::jsonSuccess([
        'message' => 'Carrier updated successfully',
        'carrier' => $carrier,
    ]);
}

/**
 * Handle update proxy type request
 */
function handleUpdateProxyType(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $proxyType = $_POST['proxy_type'] ?? '';

    if (empty($proxyId) || empty($proxyType)) {
        Helpers::jsonError('Proxy ID and type are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $response = $api->updateProxyType($proxyId, $proxyType);
    $logger->info('Updated proxy type', ['proxy_id' => $proxyId, 'type' => $proxyType]);

    Helpers::jsonSuccess([
        'message' => 'Proxy type updated to ' . strtoupper($proxyType),
        'type' => strtoupper($proxyType),
    ]);
}

/**
 * Handle update auth request
 */
function handleUpdateAuth(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $authType = $_POST['auth_type'] ?? '';

    if (empty($proxyId) || empty($authType)) {
        Helpers::jsonError('Proxy ID and auth type are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $authParams = ['auth_type' => $authType];

    if (in_array($authType, ['username_password', 'both'], true)) {
        $password = $_POST['password'] ?? '';
        if (!empty($password)) {
            $authParams['password'] = $password;
        }
    }

    if (in_array($authType, ['ip_whitelist', 'both'], true)) {
        $clientIp = $_POST['client_ip'] ?? Helpers::getClientIp();
        $authParams['client_ip'] = $clientIp;
    }

    $response = $api->updateProxyAuth($proxyId, $authParams);
    $logger->info('Updated proxy auth', ['proxy_id' => $proxyId, 'auth_type' => $authType]);

    Helpers::jsonSuccess([
        'message' => 'Authentication updated successfully',
        'auth_type' => $authType,
    ]);
}

/**
 * Handle update rotation request
 */
function handleUpdateRotation(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';
    $rotationType = $_POST['rotation_type'] ?? '';
    $rotationInterval = (int) ($_POST['rotation_interval'] ?? 60);

    if (empty($proxyId) || empty($rotationType)) {
        Helpers::jsonError('Proxy ID and rotation type are required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $rotationParams = [
        'rotation_type' => $rotationType,
        'rotation_interval' => max(1, min(1440, $rotationInterval)),
    ];

    $response = $api->updateProxyRotation($proxyId, $rotationParams);
    $logger->info('Updated proxy rotation', ['proxy_id' => $proxyId, 'type' => $rotationType]);

    Helpers::jsonSuccess([
        'message' => 'Rotation settings updated',
        'rotation_type' => $rotationType,
        'interval' => $rotationParams['rotation_interval'],
    ]);
}

/**
 * Handle rotate IP request
 */
function handleRotateIp(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';

    if (empty($proxyId)) {
        Helpers::jsonError('Proxy ID is required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $response = $api->rotateProxyIp($proxyId);
    $newIp = $response['data']['new_ip'] ?? '';

    $logger->info('Rotated proxy IP', ['proxy_id' => $proxyId, 'new_ip' => $newIp]);

    Helpers::jsonSuccess([
        'message' => 'IP rotated successfully',
        'new_ip' => $newIp,
        'timestamp' => date('c'),
    ]);
}

/**
 * Handle reveal IP request (for 1:1 proxies)
 */
function handleRevealIp(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';

    if (empty($proxyId)) {
        Helpers::jsonError('Proxy ID is required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $response = $api->reveal1By1ProxyIp($proxyId);
    $revealedIp = $response['data']['ip'] ?? '';

    $logger->info('Revealed 1:1 proxy IP', ['proxy_id' => $proxyId, 'ip' => $revealedIp]);

    Helpers::jsonSuccess([
        'message' => 'IP revealed successfully',
        'ip' => $revealedIp,
    ]);
}

/**
 * Handle extend order request
 */
function handleExtendOrder(ApiClient $api, string $orderId, Logger $logger): void
{
    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $duration = (int) ($_POST['duration'] ?? 30);
    $duration = max(1, min(365, $duration));

    $response = $api->extendOrder($orderId, $duration);
    $logger->info('Extended order', ['order_id' => $orderId, 'duration' => $duration]);

    Helpers::jsonSuccess([
        'message' => 'Order extended by ' . Helpers::formatDuration($duration),
        'duration' => $duration,
        'new_expiry' => $response['data']['new_expires_at'] ?? '',
    ]);
}

/**
 * Handle cancel order request
 */
function handleCancelOrder(ApiClient $api, string $orderId, Logger $logger): void
{
    // Client-initiated vendor cancels bypass WHMCS billing/refunds and desync
    // the service record. Cancellations must go through the WHMCS request flow.
    Helpers::jsonError('Please request cancellation from the service page so billing stays in sync.', 403);
}

/**
 * Handle get balance request
 */
function handleGetBalance(ApiClient $api, Logger $logger): void
{
    $response = $api->getBalance();

    Helpers::jsonSuccess([
        'balance' => $response['data']['balance'] ?? 0,
        'currency' => $response['data']['currency'] ?? 'USD',
        'formatted_balance' => '$' . number_format((float) ($response['data']['balance'] ?? 0), 2),
    ]);
}

/**
 * Handle get available proxies request
 */
function handleGetAvailableProxies(ApiClient $api, Logger $logger): void
{
    $type = $_GET['type'] ?? 'us';

    // if/elseif (not match): the module baseline is PHP 7.4-parseable syntax.
    if ($type === '1by1') {
        $response = $api->getAvailable1By1Proxies();
    } elseif ($type === 'non_us') {
        $response = $api->getAvailableNonUsProxies();
    } elseif ($type === 'us_carrier') {
        $response = $api->getAvailableUsCarrierProxies($_GET['carrier'] ?? null);
    } else {
        $response = $api->getAvailableUsProxies();
    }

    Helpers::jsonSuccess([
        'proxies' => $response['data'] ?? [],
        'type' => $type,
    ]);
}

/**
 * Handle request trial request
 */
function handleRequestTrial(ApiClient $api, array $params, Logger $logger): void
{
    $trialEnabled = (bool) ($params['configoption12'] ?? false);

    if (!$trialEnabled) {
        Helpers::jsonError('Trial proxies are not enabled for this product', 403);
    }

    $region = $_POST['region'] ?? 'us';
    $carrier = $_POST['carrier'] ?? 'verizon';
    $proxyType = $_POST['proxy_type'] ?? 'SOCKS5';

    if (!Helpers::isValidRegion($region)) {
        Helpers::jsonError('Invalid region selected', 400);
    }

    if (!Helpers::isValidCarrier($carrier)) {
        Helpers::jsonError('Invalid carrier selected', 400);
    }

    if (!Helpers::isValidProxyType($proxyType)) {
        Helpers::jsonError('Invalid proxy type selected', 400);
    }

    $trialParams = [
        'client_id' => $_SESSION['uid'],
        'region' => $region,
        'carrier' => $carrier,
        'proxy_type' => $proxyType,
    ];

    $response = $api->addTrial($trialParams);
    $logger->info('Trial proxy requested', ['client_id' => $_SESSION['uid']]);

    Helpers::jsonSuccess([
        'message' => 'Trial proxy activated',
        'trial_id' => $response['data']['trial_id'] ?? '',
        'expires_at' => $response['data']['expires_at'] ?? '',
    ]);
}

/**
 * Test if proxy is alive
 */
function handleTestAlive(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';

    if (empty($proxyId)) {
        Helpers::jsonError('Proxy ID is required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $startTime = microtime(true);
    $response = $api->testProxyAlive($proxyId);
    $responseTime = round((microtime(true) - $startTime) * 1000, 2);

    $isAlive = $response['data']['alive'] ?? false;
    $status = $response['data']['status'] ?? 'unknown';
    $ip = $response['data']['ip'] ?? '';
    $location = $response['data']['location'] ?? '';
    $isp = $response['data']['isp'] ?? '';

    $logger->info('Proxy alive test', [
        'proxy_id' => $proxyId,
        'alive' => $isAlive,
        'response_time_ms' => $responseTime,
    ]);

    Helpers::jsonSuccess([
        'proxy_id' => $proxyId,
        'alive' => $isAlive,
        'status' => $status,
        'ip' => $ip,
        'location' => $location,
        'isp' => $isp,
        'response_time_ms' => $responseTime,
        'tested_at' => date('c'),
    ]);
}

/**
 * Test proxy connection speed
 */
function handleTestSpeed(ApiClient $api, string $orderId, Logger $logger): void
{
    $proxyId = $_POST['proxy_id'] ?? '';

    if (empty($proxyId)) {
        Helpers::jsonError('Proxy ID is required', 400);
    }

    if (empty($orderId)) {
        Helpers::jsonError('No active order found', 400);
    }

    $orderProxies = $api->getOrderProxies($orderId);
    if (!Helpers::proxyBelongsToOrder($orderProxies['data'] ?? [], $proxyId)) {
        Helpers::jsonError('Proxy not found in this order', 404);
    }

    $startTime = microtime(true);
    $response = $api->testProxySpeed($proxyId);
    $totalTime = round((microtime(true) - $startTime) * 1000, 2);

    $downloadSpeed = (float) ($response['data']['download_speed'] ?? 0);
    $uploadSpeed = (float) ($response['data']['upload_speed'] ?? 0);
    $latency = (float) ($response['data']['latency'] ?? 0);
    $jitter = (float) ($response['data']['jitter'] ?? 0);
    $packetLoss = (float) ($response['data']['packet_loss'] ?? 0);

    // Calculate quality rating
    $quality = calculateQualityRating($downloadSpeed, $latency, $packetLoss);

    $logger->info('Proxy speed test', [
        'proxy_id' => $proxyId,
        'download' => $downloadSpeed,
        'latency' => $latency,
    ]);

    Helpers::jsonSuccess([
        'proxy_id' => $proxyId,
        'download_speed' => [
            'bytes_per_second' => $downloadSpeed,
            'formatted' => Helpers::formatSpeed($downloadSpeed),
        ],
        'upload_speed' => [
            'bytes_per_second' => $uploadSpeed,
            'formatted' => Helpers::formatSpeed($uploadSpeed),
        ],
        'latency_ms' => round($latency, 2),
        'jitter_ms' => round($jitter, 2),
        'packet_loss_percent' => round($packetLoss, 2),
        'total_test_time_ms' => $totalTime,
        'quality_rating' => $quality,
        'tested_at' => date('c'),
    ]);
}

/**
 * Batch test all proxies in an order
 */
function handleBatchTest(ApiClient $api, string $orderId, Logger $logger): void
{
    if (empty($orderId)) {
        Helpers::jsonError('No active order', 400);
    }

    $proxies = $api->getOrderProxies($orderId);
    $proxyList = isset($proxies['data']) && is_array($proxies['data']) ? $proxies['data'] : [];

    $truncated = false;
    if (count($proxyList) > 50) {
        $proxyList = array_slice($proxyList, 0, 50);
        $truncated = true;
    }

    $results = [];
    $alive = 0;
    $dead = 0;
    $totalSpeed = 0;
    $tested = 0;

    foreach ($proxyList as $proxy) {
        $proxyId = $proxy['id'] ?? '';
        if (empty($proxyId)) {
            continue;
        }

        try {
            $aliveResult = $api->testProxyAlive($proxyId);
            $isAlive = $aliveResult['data']['alive'] ?? false;

            if ($isAlive) {
                $alive++;

                try {
                    $speedResult = $api->testProxySpeed($proxyId);
                    $speed = (float) ($speedResult['data']['download_speed'] ?? 0);
                    $totalSpeed += $speed;
                    $tested++;

                    $results[] = [
                        'proxy_id' => $proxyId,
                        'ip' => $proxy['ip'] ?? '',
                        'alive' => true,
                        'speed' => Helpers::formatSpeed($speed),
                        'latency' => round((float) ($speedResult['data']['latency'] ?? 0), 2),
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'proxy_id' => $proxyId,
                        'ip' => $proxy['ip'] ?? '',
                        'alive' => true,
                        'speed' => 'N/A',
                        'latency' => 'N/A',
                    ];
                }
            } else {
                $dead++;
                $results[] = [
                    'proxy_id' => $proxyId,
                    'ip' => $proxy['ip'] ?? '',
                    'alive' => false,
                    'speed' => 'N/A',
                    'latency' => 'N/A',
                ];
            }
        } catch (\Exception $e) {
            $dead++;
            $results[] = [
                'proxy_id' => $proxyId,
                'ip' => $proxy['ip'] ?? '',
                'alive' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    $avgSpeed = $tested > 0 ? $totalSpeed / $tested : 0;

    Helpers::jsonSuccess([
        'summary' => [
            'total' => count($proxyList),
            'alive' => $alive,
            'dead' => $dead,
            'avg_speed' => Helpers::formatSpeed($avgSpeed),
            'success_rate' => count($proxyList) > 0 ? round(($alive / count($proxyList)) * 100, 1) : 0,
            'truncated' => $truncated,
        ],
        'results' => $results,
        'tested_at' => date('c'),
    ]);
}

/**
 * Calculate quality rating
 */
function calculateQualityRating(float $downloadSpeed, float $latency, float $packetLoss): string
{
    $score = 0;

    // Speed score (0-50)
    $speedMbps = ($downloadSpeed * 8) / 1000000;
    if ($speedMbps >= 50) {
        $score += 50;
    } elseif ($speedMbps >= 20) {
        $score += 40;
    } elseif ($speedMbps >= 10) {
        $score += 30;
    } elseif ($speedMbps >= 5) {
        $score += 20;
    } elseif ($speedMbps >= 1) {
        $score += 10;
    }

    // Latency score (0-30)
    if ($latency <= 50) {
        $score += 30;
    } elseif ($latency <= 100) {
        $score += 25;
    } elseif ($latency <= 200) {
        $score += 20;
    } elseif ($latency <= 500) {
        $score += 10;
    }

    // Packet loss score (0-20). Loose comparison: the boundary cast feeds
    // floats, and 0.0 must score the same full marks as integer 0.
    if ($packetLoss == 0) {
        $score += 20;
    } elseif ($packetLoss <= 1) {
        $score += 15;
    } elseif ($packetLoss <= 5) {
        $score += 10;
    } elseif ($packetLoss <= 10) {
        $score += 5;
    }

    if ($score >= 80) {
        return 'Excellent';
    } elseif ($score >= 60) {
        return 'Good';
    } elseif ($score >= 40) {
        return 'Fair';
    } elseif ($score >= 20) {
        return 'Poor';
    }

    return 'Very Poor';
}

function getUsageStats(ApiClient $api, string $orderId): void
{
    if (empty($orderId)) {
        Helpers::jsonSuccess(['stats' => null]);
    }

    $proxies = $api->getOrderProxies($orderId);
    $proxyList = isset($proxies['data']) && is_array($proxies['data']) ? $proxies['data'] : [];

    $totalRequests = 0;
    $totalBandwidth = 0;
    $activeProxies = 0;
    $regionCounts = [];
    $carrierCounts = [];

    foreach ($proxyList as $proxy) {
        if (($proxy['status'] ?? '') === 'active') {
            $activeProxies++;
        }

        $totalRequests += $proxy['request_count'] ?? 0;
        $totalBandwidth += $proxy['bandwidth_used'] ?? 0;

        $region = $proxy['region'] ?? 'unknown';
        $regionCounts[$region] = ($regionCounts[$region] ?? 0) + 1;

        $carrier = $proxy['carrier'] ?? 'unknown';
        $carrierCounts[$carrier] = ($carrierCounts[$carrier] ?? 0) + 1;
    }

    Helpers::jsonSuccess([
        'stats' => [
            'total_proxies' => count($proxyList),
            'active_proxies' => $activeProxies,
            'inactive_proxies' => count($proxyList) - $activeProxies,
            'total_requests' => number_format($totalRequests),
            'total_bandwidth' => Helpers::formatBytes((int) $totalBandwidth),
            'region_distribution' => $regionCounts,
            'carrier_distribution' => $carrierCounts,
        ],
    ]);
}

function getOrderHistory(ApiClient $api, string $orderId): void
{
    if (empty($orderId)) {
        Helpers::jsonSuccess(['history' => []]);
    }

    $activeOrders = $api->getOrders(['order_id' => $orderId]);
    $expiredOrders = $api->getExpiredOrders(['order_id' => $orderId]);
    $activeList = isset($activeOrders['data']) && is_array($activeOrders['data']) ? $activeOrders['data'] : [];
    $expiredList = isset($expiredOrders['data']) && is_array($expiredOrders['data']) ? $expiredOrders['data'] : [];

    $history = [];

    foreach ($activeList as $order) {
        $history[] = [
            'id' => $order['id'] ?? '',
            'status' => $order['status'] ?? 'unknown',
            'type' => 'active',
            'quantity' => $order['quantity'] ?? 0,
            'region' => $order['region'] ?? '',
            'carrier' => $order['carrier'] ?? '',
            'created_at' => Helpers::formatDate($order['created_at'] ?? ''),
            'expires_at' => Helpers::formatDate($order['expires_at'] ?? ''),
        ];
    }

    foreach ($expiredList as $order) {
        $history[] = [
            'id' => $order['id'] ?? '',
            'status' => 'expired',
            'type' => 'expired',
            'quantity' => $order['quantity'] ?? 0,
            'region' => $order['region'] ?? '',
            'carrier' => $order['carrier'] ?? '',
            'created_at' => Helpers::formatDate($order['created_at'] ?? ''),
            'expires_at' => Helpers::formatDate($order['expires_at'] ?? ''),
        ];
    }

    // Sort by created date descending
    usort($history, function ($a, $b) {
        return strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0');
    });

    Helpers::jsonSuccess(['history' => $history]);
}

function getRotationStatus(ApiClient $api, string $orderId): void
{
    if (empty($orderId)) {
        Helpers::jsonSuccess(['rotations' => []]);
    }

    $proxies = $api->getOrderProxies($orderId);
    $proxyList = isset($proxies['data']) && is_array($proxies['data']) ? $proxies['data'] : [];

    $rotations = [];

    foreach ($proxyList as $proxy) {
        $rotations[] = [
            'proxy_id' => $proxy['id'] ?? '',
            'ip' => $proxy['ip'] ?? '',
            'rotation_type' => $proxy['rotation_type'] ?? 'manual',
            'rotation_interval' => $proxy['rotation_interval'] ?? 60,
            'last_rotated' => $proxy['last_rotated'] ?? '',
            'next_rotation' => $proxy['next_rotation'] ?? '',
            'time_until_next' => !empty($proxy['next_rotation'])
                ? max(0, (int) floor((strtotime($proxy['next_rotation']) - time()) / 60))
                : 0,
        ];
    }

    Helpers::jsonSuccess(['rotations' => $rotations]);
}

function getAccountSummary(ApiClient $api): void
{
    // Fetch inside try, respond outside it: the JSON-throw test seam raises
    // an \Exception subclass, which this catch must never swallow.
    try {
        $account = $api->getAccountInfo();
        $balance = $api->getBalance();
    } catch (\Exception $e) {
        Helpers::jsonSuccess([
            'account' => ['status' => 'unknown'],
            'balance' => ['amount' => 0, 'formatted' => '$0.00'],
        ]);
        return;
    }

    Helpers::jsonSuccess([
        'account' => [
            'status' => $account['data']['status'] ?? 'unknown',
            'api_version' => $account['data']['api_version'] ?? '1.0',
            'rate_limit' => $account['data']['rate_limit'] ?? 60,
        ],
        'balance' => [
            'amount' => $balance['data']['balance'] ?? 0,
            'currency' => $balance['data']['currency'] ?? 'USD',
            'formatted' => '$' . number_format((float) ($balance['data']['balance'] ?? 0), 2),
        ],
    ]);
}
