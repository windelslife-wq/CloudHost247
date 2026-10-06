<?php
/** Token-only private download endpoint. */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/autoload.php';

use DigitalProducts\Core\Http;
use DigitalProducts\Core\Settings;
use DigitalProducts\Security\DownloadAuthorizer;
use DigitalProducts\Security\TokenService;
use DigitalProducts\Storage\StorageFactory;
use WHMCS\Database\Capsule;

while (ob_get_level() > 0) ob_end_clean();

function digitalproducts_download_error($status, $message, $reason = null, $context = [])
{
    http_response_code((int) $status);
    try { (new \DigitalProducts\Core())->logDownload(array_merge($context, ['status' => $reason ?: 'denied', 'failure_reason' => $reason ?: 'denied', 'client_id' => (int) ($_SESSION['uid'] ?? 0)])); } catch (\Throwable $e) {}
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Download unavailable</title></head><body><h1>Download unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="index.php?m=digitalproducts">Return to My Downloads</a></p></body></html>';
    exit;
}

$rawToken = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) digitalproducts_download_error(403, 'This download link is invalid or has expired.', 'invalid_token');

$tokens = new TokenService();
try { $token = $tokens->find($rawToken); } catch (\Throwable $e) { digitalproducts_download_error(503, 'This download is currently unavailable. Please contact support.', 'invalid_token'); }
if (!$token) digitalproducts_download_error(403, 'This download link is invalid or has expired.', 'expired');

$sessionClient = (int) ($_SESSION['uid'] ?? 0);
if ($sessionClient && $sessionClient !== (int) $token->client_id) digitalproducts_download_error(403, 'You do not have permission to download this file.', 'not_entitled', ['token_id' => $token->id]);

$auth = (new DownloadAuthorizer())->resolve($token->id, $sessionClient);
if (empty($auth['ok'])) {
    digitalproducts_download_error(403, 'This download is currently unavailable. Please return to My Downloads or contact support.', $auth['reason'] ?? 'not_entitled', ['token_id' => $token->id, 'entitlement_id' => $auth['record']->entitlement_id ?? null, 'service_id' => $auth['record']->service_id ?? null, 'product_id' => $auth['record']->product_id ?? null, 'version_id' => $auth['record']->version_id ?? null]);
}

$record = $auth['record'];
try { $storage = StorageFactory::make(); }
catch (\Throwable $e) { digitalproducts_download_error(503, 'This download is currently unavailable. Please contact support.', 'file_missing', ['token_id' => $token->id, 'entitlement_id' => $record->entitlement_id, 'product_id' => $record->product_id, 'version_id' => $record->version_id]); }
if (!$record->storage_key || !$storage->exists($record->storage_key)) digitalproducts_download_error(404, 'This download is currently unavailable. Please contact support.', 'file_missing', ['token_id' => $token->id, 'entitlement_id' => $record->entitlement_id, 'product_id' => $record->product_id, 'version_id' => $record->version_id]);

// A one-time token is consumed atomically before the limit claim. A caller
// cannot replay it, while the limit itself is an atomic conditional update.
if (!$tokens->consume($token->id)) digitalproducts_download_error(403, 'This download link has already been used.', 'invalid_token', ['token_id' => $token->id, 'entitlement_id' => $record->entitlement_id]);
$authorizer = new DownloadAuthorizer();
if (!$authorizer->claim($record)) digitalproducts_download_error(403, 'Your download limit has been reached. Please contact support if you need assistance.', 'limit_exceeded', ['token_id' => $token->id, 'entitlement_id' => $record->entitlement_id, 'product_id' => $record->product_id, 'version_id' => $record->version_id]);

$download = new \DigitalProducts\Core();
$download->logDownload(['status' => 'success', 'token_id' => $token->id, 'entitlement_id' => $record->entitlement_id, 'product_id' => $record->product_id, 'version_id' => $record->version_id, 'file_id' => $record->version_id, 'service_id' => $record->service_id, 'order_id' => $record->order_id, 'client_id' => $record->client_id]);
$download->incrementFileDownloadCount($record->version_id);

$filename = basename(str_replace(["\r", "\n", '"'], '', (string) $record->original_filename));
if ($filename === '' || $filename === '.' || $filename === '..') $filename = 'download';
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$types = ['zip' => 'application/zip', 'gz' => 'application/gzip', 'pdf' => 'application/pdf', 'js' => 'application/javascript', 'css' => 'text/css', 'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain', 'md' => 'text/markdown', 'php' => 'application/octet-stream'];
$size = $storage->size($record->storage_key);
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string) $size);
header('Content-Disposition: attachment; filename="' . addcslashes($filename, '\\"') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
$handle = $storage->stream($record->storage_key);
while (!feof($handle)) { echo fread($handle, 1024 * 1024); if (function_exists('flush')) flush(); }
fclose($handle);
exit;
