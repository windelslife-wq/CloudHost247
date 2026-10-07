<?php
/** CloudHost247 Cloudflare WHMCS provisioning module. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once dirname(__DIR__, 2) . '/addons/cloudhost247cloudflare/autoload.php';

use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;
use CloudHost247\Cloudflare\Service\ProvisioningService;

function cloudhost247cloudflare_MetaData()
{
    return ['DisplayName' => 'CloudHost247 Cloudflare', 'APIVersion' => '1.1', 'RequiresServer' => false];
}
function cloudhost247cloudflare_ConfigOptions()
{
    // Product mapping, plan ID, feature entitlements and defaults are configured
    // in the CloudHost247 Cloudflare addon. WHMCS prices stay in tblpricing.
    return [];
}
function cloudhost247cloudflare_CreateAccount($params)
{
    try {
        $id = (int) ($params['serviceid'] ?? 0);
        if ($id <= 0) return 'Error: WHMCS service ID is missing.';
        $service = (new ProvisioningService())->provisionHosting($id);
        return in_array(strtoupper((string) ($service['status'] ?? '')), ['ACTIVE','PENDING_NAMESERVER_UPDATE'], true) ? 'success' : 'Cloudflare provisioning did not complete. Check the Cloudflare service status and retry.';
    } catch (\Throwable $e) {
        $message = $e instanceof \CloudHost247\Cloudflare\Core\CloudflareException ? $e->getMessage() : 'Cloudflare provisioning failed. Check the admin Cloudflare logs.';
        Logger::error('WHMCS CreateAccount failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Error: ' . $message;
    }
}
function cloudhost247cloudflare_SuspendAccount($params)
{
    try {
        $row = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
        if (!$row) return 'Error: Cloudflare service mapping not found.';
        (new ProvisioningService())->suspend((int) $row['id'], 'system', 0);
        return 'success';
    } catch (\Throwable $e) {
        Logger::error('WHMCS SuspendAccount failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Error: Cloudflare suspension failed. Check the admin Cloudflare logs.';
    }
}
function cloudhost247cloudflare_UnsuspendAccount($params)
{
    try {
        $row = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
        if (!$row) return 'Error: Cloudflare service mapping not found.';
        (new ProvisioningService())->unsuspend((int) $row['id'], 'system', 0);
        return 'success';
    } catch (\Throwable $e) {
        Logger::error('WHMCS UnsuspendAccount failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Error: Cloudflare unsuspension failed. Check the admin Cloudflare logs.';
    }
}
function cloudhost247cloudflare_TerminateAccount($params)
{
    try {
        $row = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
        if (!$row) return 'success';
        (new ProvisioningService())->terminate((int) $row['id'], 'system', 0);
        return 'success';
    } catch (\Throwable $e) {
        Logger::error('WHMCS TerminateAccount failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Error: Cloudflare termination failed. Check the admin Cloudflare logs.';
    }
}
function cloudhost247cloudflare_ChangePackage($params)
{
    try {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $newProductId = (int) ($params['newpackageid'] ?? $params['packageid'] ?? 0);
        if ($serviceId <= 0 || $newProductId <= 0) return 'Error: WHMCS upgrade parameters are incomplete.';
        (new ProvisioningService())->changePlan($serviceId, $newProductId, 'system', 0);
        return 'success';
    } catch (\Throwable $e) {
        $message = $e instanceof \CloudHost247\Cloudflare\Core\CloudflareException ? $e->getMessage() : 'Cloudflare plan change failed. Check the admin Cloudflare logs.';
        Logger::error('WHMCS ChangePackage failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Error: ' . $message;
    }
}
function cloudhost247cloudflare_TestConnection($params)
{
    try {
        $accounts = (new AccountRepository())->listAll();
        foreach ($accounts as $account) if (!empty($account['enabled'])) {
            $result = (new AccountRepository())->test((int) $account['id']);
            return ['success' => true, 'message' => 'Cloudflare connection verified for ' . ($result['account_name'] ?: $result['account_id']) . '.'];
        }
        return ['success' => false, 'message' => 'No enabled Cloudflare account is configured.'];
    } catch (\Throwable $e) {
        $message = $e instanceof \CloudHost247\Cloudflare\Core\CloudflareException ? $e->getMessage() : 'Cloudflare connection test failed.';
        return ['success' => false, 'message' => $message];
    }
}
function cloudhost247cloudflare_ClientArea($params)
{
    $service = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'cloudflare_service' => $service ?: [],
            'cloudflare_url' => 'index.php?m=cloudhost247cloudflare&action=service&id=' . (int) ($service['id'] ?? 0),
        ],
    ];
}
function cloudhost247cloudflare_AdminServicesTabFields($params)
{
    $service = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
    if (!$service) return ['Cloudflare Status' => 'Not provisioned'];
    $nameservers = json_decode((string) ($service['nameservers_json'] ?? '[]'), true) ?: [];
    return [
        'Cloudflare Status' => (string) $service['status'],
        'Cloudflare Plan' => (string) $service['plan_label'],
        'Cloudflare Zone ID' => (string) ($service['zone_id'] ?? ''),
        'Cloudflare Nameservers' => implode(', ', $nameservers),
        'Cloudflare Last Sync' => (string) ($service['last_synced_at'] ?? 'Never'),
        'Cloudflare Last Error' => trim((string) ($service['last_error_code'] ?? '') . ' ' . (string) ($service['last_error_message'] ?? '')),
    ];
}
function cloudhost247cloudflare_AdminCustomButtonArray()
{
    return ['Sync Cloudflare Zone' => 'SyncCloudflareZone'];
}
function cloudhost247cloudflare_SyncCloudflareZone($params)
{
    try {
        $service = (new ServiceRepository())->findByHostingId((int) ($params['serviceid'] ?? 0));
        if (!$service) return 'Cloudflare service mapping not found.';
        (new ProvisioningService())->sync((int) $service['id'], 'admin', (int) ($_SESSION['adminid'] ?? 0));
        return 'Cloudflare zone and DNS synchronized.';
    } catch (\Throwable $e) {
        Logger::error('WHMCS admin sync failed', ['service_id' => (int) ($params['serviceid'] ?? 0), 'error' => get_class($e)]);
        return 'Cloudflare sync failed. Check the Cloudflare addon logs.';
    }
}
