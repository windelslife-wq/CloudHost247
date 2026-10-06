<?php
/** CloudHost247 Digital Products lifecycle hooks. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once __DIR__ . '/autoload.php';

use DigitalProducts\Core\Audit;
use DigitalProducts\Services\EntitlementService;
use WHMCS\Database\Capsule;

function digitalproducts_entitlementService() { static $service; if (!$service) $service = new EntitlementService(); return $service; }
function digitalproducts_grantOrder($vars) { try { $orderId = (int) ($vars['orderId'] ?? $vars['orderid'] ?? 0); if ($orderId) digitalproducts_entitlementService()->grantForOrder($orderId, ['event' => 'order_paid']); } catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts grant failed: ' . $e->getMessage()); } }
function digitalproducts_grantInvoice($vars) { try { $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invoiceId'] ?? 0); if (!$invoiceId) return; $orders = Capsule::table('tblorders as o')->where('o.invoiceid', $invoiceId)->pluck('o.id'); foreach ($orders as $orderId) digitalproducts_entitlementService()->grantForOrder($orderId, ['event' => 'invoice_paid']); } catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts invoice grant failed: ' . $e->getMessage()); } }
function digitalproducts_grantService($vars) { try { $id = (int) ($vars['params']['serviceid'] ?? $vars['serviceid'] ?? $vars['serviceId'] ?? 0); if ($id) digitalproducts_entitlementService()->grantForService($id, ['event' => 'module_created']); } catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts service grant failed: ' . $e->getMessage()); } }
function digitalproducts_revokeOrder($vars) { try { $id = (int) ($vars['orderId'] ?? $vars['orderid'] ?? 0); if ($id) digitalproducts_entitlementService()->revokeByOrder($id, 'order_cancelled'); } catch (\Throwable $e) {} }
function digitalproducts_revokeService($vars, $reason = 'service_revoked') { try { $id = (int) ($vars['serviceid'] ?? $vars['serviceId'] ?? $vars['params']['serviceid'] ?? 0); if ($id) digitalproducts_entitlementService()->revokeByService($id, $reason); } catch (\Throwable $e) {} }

add_hook('OrderPaid', 1, 'digitalproducts_grantOrder');
add_hook('InvoicePaid', 1, 'digitalproducts_grantInvoice');
add_hook('AfterModuleCreate', 1, 'digitalproducts_grantService');
add_hook('AcceptOrder', 1, 'digitalproducts_grantOrder');
add_hook('CancelOrder', 1, 'digitalproducts_revokeOrder');
add_hook('OrderRefunded', 1, 'digitalproducts_revokeOrder');
add_hook('FraudOrder', 1, 'digitalproducts_revokeOrder');
add_hook('ServiceSuspend', 1, function ($vars) { try { digitalproducts_entitlementService()->suspendByService((int) ($vars['serviceid'] ?? $vars['serviceId'] ?? 0)); } catch (\Throwable $e) {} });
add_hook('ServiceUnsuspend', 1, function ($vars) { try { digitalproducts_entitlementService()->restoreByService((int) ($vars['serviceid'] ?? $vars['serviceId'] ?? 0)); } catch (\Throwable $e) {} });
add_hook('ServiceDelete', 1, function ($vars) { digitalproducts_revokeService($vars, 'service_deleted'); });
add_hook('AfterModuleTerminate', 1, function ($vars) { digitalproducts_revokeService($vars, 'service_terminated'); });

add_hook('ClientAreaPrimaryNavbar', 1, function ($primaryNavbar) {
    if (!(int) ($_SESSION['uid'] ?? 0) || !is_object($primaryNavbar)) return;
    try {
        $item = $primaryNavbar->addChild('My Downloads', ['label' => 'My Downloads', 'uri' => 'index.php?m=digitalproducts', 'order' => 70]);
        if (is_object($item) && method_exists($item, 'setIcon')) $item->setIcon('fa-download');
    } catch (\Throwable $e) {}
});
add_hook('ClientAreaPrimarySidebar', 1, function ($sidebar) {
    if (!(int) ($_SESSION['uid'] ?? 0) || !is_object($sidebar)) return;
    try { $sidebar->addChild('CloudHost247MyDownloads', ['label' => 'My Downloads', 'uri' => 'index.php?m=digitalproducts', 'icon' => 'fa-download', 'order' => 70]); } catch (\Throwable $e) {}
});
add_hook('ClientAreaPage', 90, function ($vars) {
    if (!(int) ($_SESSION['uid'] ?? 0) || !is_array($vars) || empty($vars['topMenusData']) || !is_array($vars['topMenusData'])) return $vars;
    // HostX's menu helper is optional; do not make this addon depend on
    // cloudhost247services being enabled.
    if (class_exists('Chs\\Http\\HostxMenu')) {
        try { $vars['topMenusData'] = \Chs\Http\HostxMenu::merge($vars['topMenusData']); } catch (\Throwable $e) {}
    }
    return $vars;
});
add_hook('DailyCronJob', 1, function () {
    try { require __DIR__ . '/cron/digitalproducts.php'; } catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts cron failed: ' . $e->getMessage()); }
});
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    if (($vars['filename'] ?? '') !== 'addonmodules' || ($_GET['module'] ?? '') !== 'digitalproducts') return '';
    return '<style>.dp-stat-card{border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:18px;background:#fff}.dp-stat-card .number{font-size:28px;font-weight:700}.dp-dropzone{border:2px dashed #cbd5e1;border-radius:8px;padding:30px;text-align:center;background:#f8fafc}</style>';
});
