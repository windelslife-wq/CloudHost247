<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\AuthorizationException;

/** Reuses WHMCS's invoice/order state; paid services only. */
class BillingGuard
{
    public static function paidHostingService($serviceId)
    {
        $row = Db::firstQuery('SELECT h.id,h.userid,h.packageid,h.domain,h.domainstatus,h.orderid,h.amount,h.dedicatedip,h.serverip,p.paytype,o.invoiceid,i.status AS invoice_status '
            . 'FROM tblhosting h LEFT JOIN tblproducts p ON p.id=h.packageid LEFT JOIN tblorders o ON o.id=h.orderid '
            . 'LEFT JOIN tblinvoices i ON i.id=o.invoiceid WHERE h.id=? LIMIT 1', [(int) $serviceId]);
        if (!$row) throw new AuthorizationException('CloudHost247 service not found.');
        if (!empty($row['invoiceid'])) {
            if (strtolower((string) ($row['invoice_status'] ?? '')) !== 'paid') throw new AuthorizationException('Payment must be confirmed before Cloudflare provisioning.');
        } elseif (strtolower((string) ($row['paytype'] ?? '')) !== 'free' && (float) ($row['amount'] ?? 0) > 0) {
            throw new AuthorizationException('Payment must be confirmed before Cloudflare provisioning.');
        }
        return $row;
    }
    public static function activeAddon($addonInstanceId)
    {
        $row = Db::firstQuery('SELECT a.id,a.hostingid,a.addonid,a.status,a.name,h.userid,h.packageid,h.domain,h.domainstatus '
            . 'FROM tblhostingaddons a JOIN tblhosting h ON h.id=a.hostingid WHERE a.id=? LIMIT 1', [(int) $addonInstanceId]);
        if (!$row || strtolower((string) $row['status']) !== 'active' || strtolower((string) $row['domainstatus']) !== 'active') throw new AuthorizationException('The paid CloudHost247 addon or its hosting service is not active.');
        return $row;
    }
}
