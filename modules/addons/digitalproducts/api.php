<?php
/** CloudHost247 Digital Products JSON API. */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/autoload.php';

use DigitalProducts\Core\Csrf;
use DigitalProducts\Core\Http;
use DigitalProducts\Core\RateLimiter;
use DigitalProducts\Core\Settings;
use DigitalProducts\License;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function digitalproducts_api_reply($payload, $status = 200) { http_response_code((int) $status); echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function digitalproducts_api_body() { $raw = file_get_contents('php://input'); $json = json_decode($raw ?: '', true); return is_array($json) ? array_merge($_POST, $json) : $_POST; }
function digitalproducts_api_method($required) { if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $required) digitalproducts_api_reply(['status' => 'error', 'error' => 'method_not_allowed'], 405); }
function digitalproducts_api_auth($write = false)
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (isset($_GET['api_token'])) digitalproducts_api_reply(['status' => 'error', 'error' => 'query_auth_not_allowed'], 401);
    $clientId = 0; $bearer = false; $permissions = [];
    if (preg_match('/^Bearer\s+([A-Za-z0-9._-]{20,256})$/i', trim($header), $match)) {
        $bearer = true; $hash = hash('sha256', $match[1]);
        $record = Capsule::table('mod_digitalproducts_api_tokens')->where('token_hash', $hash)->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')); })->first();
        if (!$record) { \DigitalProducts\Core\Audit::record('api.auth_failed', 'api', ['reason' => 'invalid_bearer'], 'denied'); digitalproducts_api_reply(['status' => 'error', 'error' => 'authentication_required'], 401); }
        $clientId = (int) $record->client_id; $permissions = json_decode($record->permissions ?: '[]', true); if (!is_array($permissions)) $permissions = []; Capsule::table('mod_digitalproducts_api_tokens')->where('id', $record->id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    } elseif (!empty($_SESSION['uid']) && empty($_SERVER['HTTP_ORIGIN'])) {
        $clientId = (int) $_SESSION['uid'];
        if ($write) { try { Csrf::verify(); } catch (\Throwable $e) { digitalproducts_api_reply(['status' => 'error', 'error' => 'csrf_failed'], 403); } }
    }
    if (!$clientId) digitalproducts_api_reply(['status' => 'error', 'error' => 'authentication_required'], 401);
    return ['client_id' => $clientId, 'bearer' => $bearer, 'permissions' => $permissions];
}
function digitalproducts_api_permission($auth, $permission)
{
    if (!$auth['bearer'] || !$auth['permissions']) return true;
    if (!in_array($permission, $auth['permissions'], true)) digitalproducts_api_reply(['status' => 'error', 'error' => 'forbidden'], 403);
    return true;
}

$endpoint = trim((string) ($_GET['endpoint'] ?? ''));
$parts = $endpoint !== '' ? explode('/', trim($endpoint, '/')) : [];
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = digitalproducts_api_body();
$core = new \DigitalProducts\Core();

try {
    if ($endpoint === 'products' || ($parts[0] ?? '') === 'products' && count($parts) === 1) {
        digitalproducts_api_method('GET');
        $items = Capsule::table('mod_digitalproducts_products')->where('status', 'active')->select('id', 'name', 'product_name', 'slug', 'short_description', 'product_type', 'status', 'current_version_id', 'updated_at')->paginate(50);
        digitalproducts_api_reply(['status' => 'success', 'data' => $items]);
    }
    if ($parts[0] === 'product' && isset($parts[1])) {
        digitalproducts_api_method('GET'); $product = Capsule::table('mod_digitalproducts_products')->where('slug', $parts[1])->where('status', 'active')->first();
        if (!$product) digitalproducts_api_reply(['status' => 'error', 'error' => 'not_found'], 404);
        digitalproducts_api_reply(['status' => 'success', 'data' => ['id' => $product->id, 'name' => $product->name ?: $product->product_name, 'slug' => $product->slug, 'description' => $product->description, 'product_type' => $product->product_type, 'current_version_id' => $product->current_version_id]]);
    }
    if ($parts[0] === 'versions') {
        digitalproducts_api_method('GET'); $auth = digitalproducts_api_auth(false); $productId = (int) ($parts[1] ?? 0);
        $owns = Capsule::table('mod_digitalproducts_entitlements')->where('client_id', $auth['client_id'])->where('product_id', $productId)->where('status', 'active')->exists(); if (!$owns) digitalproducts_api_reply(['status' => 'error', 'error' => 'not_entitled'], 403);
        digitalproducts_api_reply(['status' => 'success', 'data' => Capsule::table('mod_digitalproducts_versions')->where('product_id', $productId)->where('status', 'active')->select('id', 'version', 'file_size', 'checksum_sha256', 'release_notes', 'changelog', 'min_php', 'max_php', 'min_whmcs', 'max_whmcs', 'required_extensions', 'release_date')->orderBy('release_date', 'desc')->get()]);
    }
    if (in_array($endpoint, ['my-downloads', 'my/downloads'], true)) {
        digitalproducts_api_method('GET'); $auth = digitalproducts_api_auth(false); digitalproducts_api_permission($auth, 'my-downloads'); $items = []; foreach ($core->getClientDownloads($auth['client_id']) as $item) $items[] = ['entitlement_id' => (int) $item->entitlement_id, 'product' => $item->name ?: $item->product_name, 'version' => $item->version, 'purchased_version' => $item->purchased_version, 'purchase_date' => $item->purchase_date, 'license_key' => $item->license_key, 'license_status' => $item->license_status, 'downloads_used' => (int) $item->downloads_used, 'download_limit' => (int) $item->download_limit, 'checksum_sha256' => $item->checksum_sha256]; digitalproducts_api_reply(['status' => 'success', 'data' => $items]);
    }
    if (in_array($endpoint, ['my-licenses', 'my/licenses'], true)) {
        digitalproducts_api_method('GET'); $auth = digitalproducts_api_auth(false); digitalproducts_api_permission($auth, 'my-licenses'); $licenses = []; foreach ((new License())->getClientLicenses($auth['client_id']) as $license) $licenses[] = ['id' => (int) $license->id, 'product' => $license->name ?: $license->product_name, 'status' => $license->status, 'license_prefix' => $license->license_prefix, 'expires_at' => $license->expires_at, 'activations_count' => (int) $license->activations_count, 'activation_limit' => (int) ($license->domain_limit ?: $license->activation_limit)]; digitalproducts_api_reply(['status' => 'success', 'data' => $licenses]);
    }
    if (in_array($endpoint, ['download-token', 'download-link'], true)) {
        digitalproducts_api_method('POST'); $auth = digitalproducts_api_auth(true); digitalproducts_api_permission($auth, 'download-token'); $entitlementId = (int) ($body['entitlement_id'] ?? 0); $versionId = (int) ($body['version_id'] ?? $body['file_id'] ?? 0);
        $entitlement = Capsule::table('mod_digitalproducts_entitlements as e')->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')->where('e.id', $entitlementId)->where('e.client_id', $auth['client_id'])->where('e.status', 'active')->select('e.*', 'p.current_version_id')->first();
        if (!$entitlement) digitalproducts_api_reply(['status' => 'error', 'error' => 'not_entitled'], 403);
        if (!$versionId) $versionId = $entitlement->access_mode === 'purchase_version' ? (int) $entitlement->purchase_version_id : (int) $entitlement->current_version_id;
        if (!Capsule::table('mod_digitalproducts_versions')->where('id', $versionId)->where('product_id', $entitlement->product_id)->where('status', 'active')->exists()) digitalproducts_api_reply(['status' => 'error', 'error' => 'invalid_version'], 422);
        $issued = (new TokenService())->issue($entitlement->id, $versionId, $auth['client_id']);
        digitalproducts_api_reply(['status' => 'success', 'data' => ['download_url' => $issued['url'], 'expires_at' => $issued['expires_at']]]);
    }
    if ($endpoint === 'validate-license') {
        digitalproducts_api_method('POST'); RateLimiter::hitOrFail('license.validate', RateLimiter::bucket(), 10, 60); $key = (string) ($body['license_key'] ?? ''); $domain = isset($body['domain']) ? (string) $body['domain'] : null; $result = (new License())->validateLicense($key, $domain);
        if (!$result['valid']) digitalproducts_api_reply(['status' => 'success', 'data' => ['valid' => false, 'status' => 'invalid', 'product' => null, 'expires_at' => null]]);
        $license = $result['license']; digitalproducts_api_reply(['status' => 'success', 'data' => ['valid' => true, 'status' => 'active', 'product' => $license->name ?: $license->product_name, 'expires_at' => $license->expires_at]]);
    }
    if ($endpoint === 'activate-license') {
        digitalproducts_api_method('POST'); RateLimiter::hitOrFail('license.activate', RateLimiter::bucket(), 10, 60); $result = (new License())->activateLicense((string) ($body['license_key'] ?? ''), (string) ($body['domain'] ?? '')); if (empty($result['success'])) digitalproducts_api_reply(['status' => 'success', 'data' => ['valid' => false, 'error' => 'activation_failed']]); digitalproducts_api_reply(['status' => 'success', 'data' => ['activated' => true]]);
    }
    digitalproducts_api_reply(['status' => 'error', 'error' => 'not_found'], 404);
} catch (\DigitalProducts\Core\RateLimitException $e) { \DigitalProducts\Core\Audit::record('api.rate_limited', 'api', [], 'denied'); digitalproducts_api_reply(['status' => 'error', 'error' => 'rate_limited'], 429); }
catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts API error: ' . $e->getMessage()); digitalproducts_api_reply(['status' => 'error', 'error' => 'service_unavailable'], 500); }
