<?php
namespace DigitalProducts;

use DigitalProducts\Core\Clock;
use DigitalProducts\Core\Settings;
use DigitalProducts\Security\TokenService;
use DigitalProducts\Storage\StorageFactory;
use WHMCS\Database\Capsule;

/** Compatibility facade for the original addon API plus the hardened model. */
class Core
{
    protected $settings;
    public function getSettings()
    {
        if ($this->settings === null) {
            $this->settings = [];
            foreach (Settings::DEFAULTS as $key => $default) $this->settings[$key] = Settings::get($key, $default);
            try { foreach (Capsule::table('tbladdonmodules')->where('module', 'digitalproducts')->get() as $row) $this->settings[$row->setting] = $row->value; } catch (\Throwable $e) {}
        }
        return $this->settings;
    }

    public function getStoragePath() { return StorageFactory::make()->root(); }

    public function getProductByWhmcsId($productId)
    {
        return Capsule::table('mod_digitalproducts_products')->where('whmcs_product_id', (int) $productId)->first();
    }

    public function getAllProducts($status = 'active')
    {
        $query = Capsule::table('mod_digitalproducts_products as p')->leftJoin('tblproducts as w', 'w.id', '=', 'p.whmcs_product_id')->select('p.*', 'w.name as whmcs_product_name', 'w.paytype');
        if ($status !== null) $query->where('p.status', $status);
        return $query->orderBy('p.created_at', 'desc')->get();
    }

    public function getFileById($fileId)
    {
        $v = Capsule::table('mod_digitalproducts_versions')->where('id', (int) $fileId)->first();
        if (!$v) return null;
        $v->filename = basename((string) $v->storage_key); $v->original_name = $v->original_filename; $v->file_hash = $v->checksum_sha256; $v->changelog = $v->changelog ?: $v->release_notes; return $v;
    }

    public function getLatestFile($productId) { return Capsule::table('mod_digitalproducts_versions')->where('product_id', (int) $productId)->where('status', 'active')->orderBy('release_date', 'desc')->first(); }
    public function getProductFiles($productId, $status = null) { $q = Capsule::table('mod_digitalproducts_versions')->where('product_id', (int) $productId); if ($status) $q->where('status', $status); return $q->orderBy('release_date', 'desc')->get(); }

    public function getClientDownloads($clientId)
    {
        $rows = Capsule::table('mod_digitalproducts_entitlements as e')->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')->leftJoin('mod_digitalproducts_versions as v', 'v.id', '=', 'p.current_version_id')->leftJoin('mod_digitalproducts_versions as pv', 'pv.id', '=', 'e.purchase_version_id')->leftJoin('mod_digitalproducts_licenses as l', function ($join) { $join->on('l.product_id', '=', 'e.product_id')->on('l.service_id', '=', 'e.service_id'); })->where('e.client_id', (int) $clientId)->where('e.status', 'active')->where('p.status', 'active')->select('e.id as entitlement_id', 'e.service_id', 'e.order_id', 'e.client_id', 'e.purchase_version_id', 'e.access_mode', 'e.download_limit', 'e.downloads_used', 'e.purchased_at as purchase_date', 'p.id as dp_product_id', 'p.whmcs_product_id', 'p.name', 'p.product_name', 'p.description', 'p.product_type', 'p.current_version_id', 'p.status as product_status', 'v.id as file_id', 'v.version', 'v.file_size', 'v.checksum_sha256', 'v.changelog', 'v.release_notes', 'pv.version as purchased_version', 'l.license_hash', 'l.license_encrypted', 'l.license_key', 'l.status as license_status')->get();
        $license = new License();
        foreach ($rows as $row) { if ($row->license_encrypted) $row->license_key = $license->displayKey($row); $row->nextduedate = null; $row->download_count = (int) $row->downloads_used; $row->download_limit = (int) ($row->download_limit ?: 0); }
        return $rows;
    }

    public function getDownloadCount($clientId, $serviceId, $fileId)
    {
        return (int) Capsule::table('mod_digitalproducts_downloads')->where('client_id', (int) $clientId)->where('service_id', (int) $serviceId)->where('version_id', (int) $fileId)->where('status', 'success')->count();
    }

    public function logDownload(array $data)
    {
        $status = (string) ($data['status'] ?? 'success');
        if ($status === 'failed') $status = 'denied';
        if ($status === 'limit') $status = 'limit_exceeded';
        return Capsule::table('mod_digitalproducts_downloads')->insertGetId(['file_id' => (int) ($data['file_id'] ?? 0), 'product_id' => (int) ($data['product_id'] ?? 0), 'version_id' => (int) ($data['version_id'] ?? $data['file_id'] ?? 0), 'entitlement_id' => (int) ($data['entitlement_id'] ?? 0), 'token_id' => (int) ($data['token_id'] ?? 0), 'service_id' => (int) ($data['service_id'] ?? 0), 'order_id' => (int) ($data['order_id'] ?? 0), 'client_id' => (int) ($data['client_id'] ?? 0), 'ip_hash' => hash('sha256', \DigitalProducts\Core\Http::ip()), 'user_agent' => \DigitalProducts\Core\Http::userAgent(), 'status' => $status, 'failure_reason' => $data['failure_reason'] ?? null, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    }

    public function validateServiceOwnership($serviceId, $clientId)
    {
        return Capsule::table('tblhosting as h')->join('mod_digitalproducts_products as p', 'p.whmcs_product_id', '=', 'h.packageid')->where('h.id', (int) $serviceId)->where('h.userid', (int) $clientId)->whereIn('h.domainstatus', ['Active', 'Completed'])->where('p.status', 'active')->exists();
    }

    public function generateToken($serviceId, $fileId, $clientId)
    {
        $entitlement = Capsule::table('mod_digitalproducts_entitlements')->where('service_id', (int) $serviceId)->where('client_id', (int) $clientId)->where('status', 'active')->first();
        if (!$entitlement) return null;
        $versionId = (int) $fileId;
        return (new TokenService())->issue($entitlement->id, $versionId, $clientId);
    }

    public function validateToken($token)
    {
        $row = (new TokenService())->find($token);
        return $row ? ['token_id' => $row->id, 'entitlement_id' => $row->entitlement_id, 'version_id' => $row->version_id, 'client_id' => $row->client_id, 'expires_at' => $row->expires_at] : false;
    }

    public function incrementFileDownloadCount($fileId) { return Capsule::table('mod_digitalproducts_versions')->where('id', (int) $fileId)->increment('download_count'); }

    public function getDashboardStats()
    {
        return ['products' => Capsule::table('mod_digitalproducts_products')->count(), 'active_products' => Capsule::table('mod_digitalproducts_products')->where('status', 'active')->count(), 'files' => Capsule::table('mod_digitalproducts_versions')->count(), 'versions' => Capsule::table('mod_digitalproducts_versions')->count(), 'purchases' => Capsule::table('mod_digitalproducts_entitlements')->count(), 'licenses' => Capsule::table('mod_digitalproducts_licenses')->where('status', 'active')->count(), 'downloads' => Capsule::table('mod_digitalproducts_downloads')->where('status', 'success')->count(), 'today_downloads' => Capsule::table('mod_digitalproducts_downloads')->where('status', 'success')->whereDate('created_at', date('Y-m-d'))->count(), 'month_downloads' => Capsule::table('mod_digitalproducts_downloads')->where('status', 'success')->where('created_at', '>=', date('Y-m-01 00:00:00'))->count(), 'failed_downloads' => Capsule::table('mod_digitalproducts_downloads')->where('status', '!=', 'success')->count()];
    }

    public function getDownloadLogs($page = 1, $perPage = 25, array $filters = [])
    {
        $query = Capsule::table('mod_digitalproducts_downloads as d')->leftJoin('mod_digitalproducts_products as p', 'p.id', '=', 'd.product_id')->leftJoin('mod_digitalproducts_versions as v', 'v.id', '=', 'd.version_id')->leftJoin('tblclients as c', 'c.id', '=', 'd.client_id')->select('d.*', 'p.name as product_name', 'p.product_name', 'v.version', 'v.original_filename', 'c.firstname', 'c.lastname', 'c.email')->orderBy('d.created_at', 'desc');
        foreach (['client_id', 'product_id', 'status'] as $field) if (!empty($filters[$field])) $query->where('d.' . $field, $filters[$field]);
        if (!empty($filters['date_from'])) $query->whereDate('d.created_at', '>=', $filters['date_from']); if (!empty($filters['date_to'])) $query->whereDate('d.created_at', '<=', $filters['date_to']);
        $total = (clone $query)->count(); $page = max(1, (int) $page); $perPage = min(100, max(1, (int) $perPage));
        return ['data' => $query->forPage($page, $perPage)->get(), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))];
    }

    public function getUnlinkedWhmcsProducts()
    {
        $linked = Capsule::table('mod_digitalproducts_products')->pluck('whmcs_product_id')->toArray(); $q = Capsule::table('tblproducts')->where('hidden', 0); if ($linked) $q->whereNotIn('id', $linked); return $q->select('id', 'name', 'type')->orderBy('name')->get();
    }

    public function createDigitalProduct($whmcsProductId, array $data = [])
    {
        $whmcsProductId = (int) $whmcsProductId; if (!$whmcsProductId || !$this->getProductByWhmcsId($whmcsProductId)) { $product = Capsule::table('tblproducts')->where('id', $whmcsProductId)->first(); if (!$product) throw new \RuntimeException('WHMCS product not found.'); } else throw new \RuntimeException('Product already linked.');
        $name = trim((string) ($data['name'] ?? $data['product_name'] ?? $product->name)); $slug = $this->slug($data['slug'] ?? $name); $baseSlug = $slug; $suffix = 2; while (Capsule::table('mod_digitalproducts_products')->where('slug', $slug)->exists()) $slug = $baseSlug . '-' . $suffix++;
        return Capsule::table('mod_digitalproducts_products')->insertGetId(['product_id' => $whmcsProductId, 'whmcs_product_id' => $whmcsProductId, 'product_name' => $name, 'name' => $name, 'slug' => $slug, 'short_description' => $data['short_description'] ?? '', 'description' => $data['description'] ?? '', 'product_type' => $data['product_type'] ?? 'software', 'status' => $data['status'] ?? 'draft', 'download_limit' => (int) ($data['download_limit'] ?? Settings::int('download_limit', 5)), 'link_expiry_hours' => (int) ($data['link_expiry_hours'] ?? Settings::int('link_expiry_hours', 48)), 'download_expiry_hours' => (int) ($data['download_expiry_hours'] ?? Settings::int('link_expiry_hours', 48)), 'access_mode' => $data['access_mode'] ?? Settings::get('access_mode', 'current_version'), 'license_enabled' => !isset($data['license_enabled']) || (bool) $data['license_enabled'], 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    }

    public function updateDigitalProduct($id, array $data)
    {
        $allowed = ['product_name', 'name', 'slug', 'short_description', 'description', 'product_type', 'status', 'current_version_id', 'current_file_id', 'download_limit', 'link_expiry_hours', 'download_expiry_hours', 'access_mode', 'license_enabled', 'license_expiry_mode']; $update = []; foreach ($allowed as $key) if (array_key_exists($key, $data)) $update[$key] = $data[$key]; if (!$update) return false; $update['updated_at'] = Clock::now(); return Capsule::table('mod_digitalproducts_products')->where('id', (int) $id)->update($update);
    }

    public function deleteDigitalProduct($id)
    {
        // Destructive deletion is intentionally gone. Existing purchases and
        // audit history must remain addressable; retire the product instead.
        return $this->updateDigitalProduct($id, ['status' => 'retired']);
    }

    protected function slug($value) { $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $value), '-')); return $slug ?: 'product-' . bin2hex(random_bytes(4)); }
}
