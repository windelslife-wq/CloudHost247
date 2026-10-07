<?php
namespace CloudHost247\Cloudflare\Repository;

use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Core\AuthorizationException;
use CloudHost247\Cloudflare\Core\Clock;

class ServiceRepository
{
    public function find($id) { return Db::first('services', ['id' => (int) $id]); }
    public function findByHostingId($hostingId) { return Db::first('services', ['whmcs_service_id' => (int) $hostingId]); }
    public function findByAddonId($addonId) { return Db::first('services', ['whmcs_addon_id' => (int) $addonId]); }
    public function update($id, array $values) { $values['updated_at'] = Clock::now(); return Db::update('services', ['id' => (int) $id], $values); }
    public function forCustomer($serviceId, $customerId)
    {
        $sql = 'SELECT s.*, h.domain AS origin_domain, h.domainstatus AS whmcs_service_status, '
            . 'ha.status AS whmcs_addon_status, parent.domain AS addon_parent_domain, parent.domainstatus AS addon_parent_status '
            . 'FROM `' . Db::table('services') . '` s '
            . 'LEFT JOIN tblhosting h ON h.id = s.whmcs_service_id '
            . 'LEFT JOIN tblhostingaddons ha ON ha.id = s.whmcs_addon_id '
            . 'LEFT JOIN tblhosting parent ON parent.id = ha.hostingid '
            . 'WHERE s.id = ? AND s.customer_id = ? AND '
            . '((s.whmcs_service_id IS NOT NULL AND h.id IS NOT NULL AND h.userid = ?) '
            . 'OR (s.whmcs_addon_id IS NOT NULL AND ha.id IS NOT NULL AND parent.id IS NOT NULL AND parent.userid = ?)) LIMIT 1';
        $row = Db::firstQuery($sql, [(int) $serviceId, (int) $customerId, (int) $customerId, (int) $customerId]);
        if (!$row) throw new NotFoundException('Cloudflare service not found.');
        return $row;
    }
    public function listForCustomer($customerId)
    {
        $sql = 'SELECT s.*, COALESCE(h.domain,parent.domain,s.zone_name) AS display_domain, '
            . 'COALESCE(h.domainstatus,ha.status) AS linked_status '
            . 'FROM `' . Db::table('services') . '` s '
            . 'LEFT JOIN tblhosting h ON h.id=s.whmcs_service_id AND h.userid=? '
            . 'LEFT JOIN tblhostingaddons ha ON ha.id=s.whmcs_addon_id '
            . 'LEFT JOIN tblhosting parent ON parent.id=ha.hostingid AND parent.userid=? '
            . 'WHERE s.customer_id=? AND (h.id IS NOT NULL OR parent.id IS NOT NULL) ORDER BY s.id DESC';
        return Db::query($sql, [(int) $customerId, (int) $customerId, (int) $customerId]);
    }
    public function listForAdmin($search = '', $limit = 200)
    {
        $sql = 'SELECT s.*, cl.firstname, cl.lastname, cl.email, COALESCE(h.domain,parent.domain,s.zone_name) AS display_domain '
            . 'FROM `' . Db::table('services') . '` s LEFT JOIN tblclients cl ON cl.id=s.customer_id '
            . 'LEFT JOIN tblhosting h ON h.id=s.whmcs_service_id LEFT JOIN tblhostingaddons ha ON ha.id=s.whmcs_addon_id '
            . 'LEFT JOIN tblhosting parent ON parent.id=ha.hostingid';
        $bind = [];
        if ($search !== '') {
            $sql .= ' WHERE (s.zone_name LIKE ? OR cl.email LIKE ? OR cl.firstname LIKE ? OR cl.lastname LIKE ? OR CAST(s.id AS CHAR) = ?)';
            $like = '%' . $search . '%'; $bind = [$like,$like,$like,$like,$search];
        }
        $sql .= ' ORDER BY s.id DESC LIMIT ' . max(1, min(500, (int) $limit));
        return Db::query($sql, $bind);
    }
    public function countByStatus($status)
    {
        return Db::count('services', ['status' => strtoupper((string) $status)]);
    }
    public function recentActivity($customerId, $serviceId, $limit = 40)
    {
        return Db::query('SELECT action, entity_type, entity_id, success, details_json, error_code, created_at FROM `' . Db::table('audit_logs') . '` WHERE customer_id=? AND service_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, (int) $limit)), [(int) $customerId, (int) $serviceId]);
    }
}
