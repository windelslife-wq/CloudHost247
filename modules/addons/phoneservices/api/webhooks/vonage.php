<?php
/**
 * Vonage Webhook Handler
 * Handles inbound SMS and voice callbacks from Vonage
 */

use PhoneServices\Services\SmsService;
use PhoneServices\Services\VoipService;
use PhoneServices\Core\Logger;
use PhoneServices\Core\Config;
use PhoneServices\Security\WebhookVerifier;

// Bootstrap WHMCS (select_query, full_query, ...). Fail closed if it is missing.
$whmcsInit = dirname(__DIR__, 5) . '/init.php';
if (!file_exists($whmcsInit)) {
    http_response_code(500);
    exit;
}
require_once $whmcsInit;

require_once __DIR__ . '/../../autoload.php';

// Reject requests without a valid Vonage signed-webhook JWT before any handler runs.
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!WebhookVerifier::vonageJwt((string) Config::get('vonage_signature_secret', ''), $authorization)) {
    Logger::warning('Vonage webhook rejected: invalid or missing signature');
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$type = $_GET['type'] ?? 'sms';

if ($type === 'sms') {
    $smsService = new SmsService();
    
    $messageData = [
        'provider' => 'vonage',
        'message_id' => $data['messageId'] ?? '',
        'from' => $data['msisdn'] ?? '',
        'to' => $data['to'] ?? '',
        'body' => $data['text'] ?? '',
        'channel' => 'sms',
    ];
    
    $result = $smsService->receiveInboundMessage($messageData);
    
    Logger::info('Vonage SMS webhook received', $messageData);
    
    http_response_code(200);
    echo 'OK';
    exit;
}

if ($type === 'voice') {
    $voipService = new VoipService();
    
    $callId = $data['uuid'] ?? '';
    $status = $data['status'] ?? '';
    $direction = $data['direction'] ?? '';
    $duration = $data['duration'] ?? 0;
    
    if ($callId) {
        $voipService->updateCallStatus($callId, $status, [
            'duration' => $duration,
            'direction' => $direction,
        ]);
        
        Logger::info('Vonage voice webhook received', ['call_id' => $callId, 'status' => $status]);
    }
    
    http_response_code(200);
    echo 'OK';
    exit;
}

if ($type === 'dlr') {
    $messageId = $data['messageId'] ?? '';
    $status = $data['status'] ?? '';
    
    Logger::info('Vonage DLR received', ['message_id' => $messageId, 'status' => $status]);
    
    http_response_code(200);
    echo 'OK';
    exit;
}
