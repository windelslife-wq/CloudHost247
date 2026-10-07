<?php
/** WHMCS hooks for CloudHost247 Cloudflare. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once __DIR__ . '/autoload.php';

use CloudHost247\Cloudflare\Core\Audit;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Repository\PackageRepository;
use CloudHost247\Cloudflare\Service\IntegrationStatus;
use CloudHost247\Cloudflare\Service\JobQueue;
use CloudHost247\Cloudflare\Service\Worker;

/** Schedule paid native WHMCS addon provisioning; cart/order creation never provisions. */
function ch247cf_invoice_paid($vars)
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invoiceId'] ?? 0);
        if ($invoiceId <= 0 || !IntegrationStatus::enabled()) return;
        $items = Db::query("SELECT relid FROM tblinvoiceitems WHERE invoiceid=? AND LOWER(type)='addon' AND relid>0", [$invoiceId]);
        $packages = new PackageRepository();
        foreach ($items as $item) {
            $addon = Db::firstQuery('SELECT id,addonid,hostingid,status FROM tblhostingaddons WHERE id=? LIMIT 1', [(int) $item['relid']]);
            if (!$addon || strtolower((string) $addon['status']) !== 'active' || !$packages->forAddon((int) $addon['addonid'])) continue;
            JobQueue::enqueue('provision_addon', null, ['whmcs_addon_id' => (int) $addon['id']], 'paid-addon:' . (int) $addon['id']);
            $parent = Db::firstQuery('SELECT userid FROM tblhosting WHERE id=? LIMIT 1', [(int) $addon['hostingid']]);
            Audit::record('system', 0, 'CLOUDFLARE_ADDON_QUEUED', 'whmcs_addon', (int) $addon['id'], $parent ? (int) $parent['userid'] : null, null, true, ['invoice_id' => $invoiceId]);
        }
    } catch (\Throwable $e) {
        Logger::error('InvoicePaid addon queue failed', ['error' => get_class($e)]);
    }
}

if (function_exists('add_hook')) {
    add_hook('InvoicePaid', 20, 'ch247cf_invoice_paid');
    add_hook('ClientAreaPrimaryNavbar', 60, function ($navbar) {
        if (!(int) ($_SESSION['uid'] ?? 0) || !is_object($navbar)) return;
        try {
            $item = $navbar->addChild('Cloudflare', ['label' => 'Cloudflare', 'uri' => 'index.php?m=cloudhost247cloudflare', 'order' => 70]);
            if (is_object($item) && method_exists($item, 'setIcon')) $item->setIcon('fa-cloud');
        } catch (\Throwable $e) {}
    });
    add_hook('AfterCronJob', 80, function () {
        try {
            if (!IntegrationStatus::enabled()) return;
            $worker = new Worker(); $worker->enqueueDueSyncs(5); $summary = $worker->run(5);
            if ($summary['completed'] || $summary['failed']) Logger::info('WHMCS cron worker tick', $summary);
        } catch (\Throwable $e) { Logger::error('WHMCS cron worker tick failed', ['error' => get_class($e)]); }
    });
    add_hook('ClientAreaHeadOutput', 10, function ($vars) {
        if (($_GET['m'] ?? '') !== 'cloudhost247cloudflare') return '';
        $base = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
        return '<link rel="stylesheet" href="' . htmlspecialchars($base . '/modules/addons/cloudhost247cloudflare/assets/css/client.css', ENT_QUOTES, 'UTF-8') . '">';
    });
}
