<?php

/**
 * Smtphosting mail usage and sent-log lookup for the client area.
 *
 *   GET smtp-api.php?fn=usage&serviceid=<id>
 *   GET smtp-api.php?fn=logs&serviceid=<id>[&page=<n>][&per_page=<1..100>]
 *
 * Requires a logged-in WHMCS client who owns the service. The upstream
 * credentials are read server-side (environment variables or
 * storage/config/smtp-usage-secrets.php) and are never sent to the browser.
 * See docs/SMTPHOSTING_USAGE_PROXY.md.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

$moduleDir = __DIR__;

/**
 * Module lives at <whmcs>/modules/servers/Smtphosting/, so WHMCS root is three levels up.
 */
$whmcsInit = dirname(__DIR__, 3) . '/init.php';
if (!is_file($whmcsInit)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'Service temporarily unavailable.']);
    exit;
}

try {
    require_once $whmcsInit;
    require_once $moduleDir . '/Helpers/SmtpUsageProxy.php';
    require_once $moduleDir . '/Helpers/SmtpUsageRateLimiter.php';

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
    if ($method !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['status' => 'error', 'msg' => 'Method not allowed.']);
        exit;
    }

    $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;

    $serviceLookup = function ($serviceId, $clientId) {
        $row = \WHMCS\Database\Capsule::table('tblhosting')
            ->select('username', 'domain')
            ->where('id', $serviceId)
            ->where('userid', $clientId)
            ->first();

        if (!$row) {
            return null;
        }

        return ['username' => (string) $row->username, 'domain' => (string) $row->domain];
    };

    $secrets = \ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageProxy::loadSecrets(
        $moduleDir . '/storage/config/smtp-usage-secrets.php'
    );

    $proxy = new \ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageProxy(
        $secrets,
        $serviceLookup,
        [\ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageProxy::class, 'curlTransport'],
        new \ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageRateLimiter(
            $moduleDir . '/storage/app/smtp-usage-ratelimit'
        )
    );

    $result = $proxy->handle($_GET, $clientId);

    http_response_code($result['httpStatus']);
    echo json_encode($result['payload']);
} catch (\Throwable $e) {
    // Never expose exception details to the browser.
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'Service temporarily unavailable.']);
}
