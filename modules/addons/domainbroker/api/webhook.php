<?php
/**
 * Domain Broker — escrow provider webhook endpoint.
 *
 * The raw body is passed to the payment service untouched: the signature is
 * verified against the bytes that were actually sent, before the payload is
 * parsed or trusted in any way. Replays are absorbed idempotently and every
 * delivery — accepted or rejected — is recorded.
 *
 * @package DomainBroker
 */

define('DOMAINBROKER_WEBHOOK', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use DomainBroker\Core\Http;
use DomainBroker\Core\Logger;
use DomainBroker\Services\PaymentService;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['accepted' => false, 'reason' => 'method_not_allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

try {
    $result = (new PaymentService())->handleEscrowWebhook($rawBody, Http::headers(), Http::clientIp());
} catch (\Throwable $e) {
    Logger::error('Escrow webhook handling failed.', ['message' => $e->getMessage()]);
    $result = ['accepted' => false, 'reason' => 'processing_error'];
}

// A rejected signature must not leak why. Everything else returns 200 so the
// provider does not retry a delivery we have already recorded.
http_response_code($result['accepted'] ? 200 : ($result['reason'] === 'invalid_signature' ? 401 : 202));
echo json_encode($result);
