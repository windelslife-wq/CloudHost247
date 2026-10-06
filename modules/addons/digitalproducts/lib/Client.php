<?php
namespace DigitalProducts;

use DigitalProducts\Core\Csrf;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

class Client
{
    protected $vars; protected $core; protected $clientId;
    public function __construct(array $vars = []) { $this->vars = $vars; $this->core = new Core(); $this->clientId = (int) ($_SESSION['uid'] ?? 0); }
    public function clientId() { return $this->clientId; }

    public function handleRequest()
    {
        if (!$this->clientId || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['dp_action'] ?? '') !== 'generate_token') return;
        Csrf::verify();
        $entitlementId = (int) ($_POST['entitlement_id'] ?? 0); $versionId = (int) ($_POST['version_id'] ?? 0);
        $entitlement = Capsule::table('mod_digitalproducts_entitlements')->where('id', $entitlementId)->where('client_id', $this->clientId)->where('status', 'active')->first();
        if (!$entitlement) throw new \RuntimeException('Download access could not be verified.');
        $product = Capsule::table('mod_digitalproducts_products')->where('id', $entitlement->product_id)->first();
        if (!$versionId) $versionId = ($entitlement->access_mode === 'purchase_version' ? (int) $entitlement->purchase_version_id : (int) ($product->current_version_id ?? 0));
        $version = Capsule::table('mod_digitalproducts_versions')->where('id', $versionId)->where('product_id', $entitlement->product_id)->where('status', 'active')->first();
        if (!$version) throw new \RuntimeException('This version is no longer available.');
        $issued = (new TokenService())->issue($entitlement->id, $version->id, $this->clientId);
        header('Location: ' . $issued['url']); exit;
    }

    public function viewData()
    {
        $rows = $this->clientId ? $this->core->getClientDownloads($this->clientId) : [];
        $downloads = [];
        foreach ($rows as $row) {
            $limit = (int) $row->download_limit; $used = (int) $row->downloads_used;
            $downloads[] = ['entitlement_id' => (int) $row->entitlement_id, 'product_name' => $row->name ?: $row->product_name, 'product_type' => $row->product_type ?: 'software', 'description' => $row->description, 'current_version' => $row->version, 'purchased_version' => $row->purchased_version ?: $row->version, 'purchase_date' => $row->purchase_date, 'license_key' => $row->license_key, 'license_status' => $row->license_status, 'downloads_used' => $used, 'downloads_remaining' => $limit === 0 ? null : max(0, $limit - $used), 'download_remaining' => $limit === 0 ? null : max(0, $limit - $used), 'download_limit' => $limit, 'file_size' => $row->file_size, 'checksum' => $row->checksum_sha256, 'changelog' => $row->changelog ?: $row->release_notes, 'new_version' => $row->purchased_version && $row->purchased_version !== $row->version];
        }
        return ['downloads' => $downloads, 'csrf_field' => Csrf::field(), 'modulelink' => $this->vars['modulelink'] ?? 'index.php?m=digitalproducts'];
    }

    public function productData($entitlementId)
    {
        if (!$this->clientId) return null;
        $entitlement = Capsule::table('mod_digitalproducts_entitlements as e')->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')->where('e.id', (int) $entitlementId)->where('e.client_id', $this->clientId)->where('e.status', 'active')->select('e.*', 'p.name', 'p.product_name', 'p.description', 'p.product_type')->first();
        if (!$entitlement) return null;
        $product = ['name' => $entitlement->name ?: $entitlement->product_name, 'description' => $entitlement->description, 'product_type' => $entitlement->product_type, 'purchased_version' => null];
        $product['versions'] = Capsule::table('mod_digitalproducts_versions')->where('product_id', $entitlement->product_id)->whereIn('status', ['active', 'retired'])->select('version', 'file_size', 'checksum_sha256', 'release_notes', 'changelog', 'min_php', 'max_php', 'min_whmcs', 'max_whmcs', 'required_extensions', 'release_date', 'status')->orderBy('release_date', 'desc')->get();
        if ($entitlement->purchase_version_id) $product['purchased_version'] = Capsule::table('mod_digitalproducts_versions')->where('id', $entitlement->purchase_version_id)->value('version');
        $license = Capsule::table('mod_digitalproducts_licenses')->where('service_id', $entitlement->service_id)->where('product_id', $entitlement->product_id)->first();
        $licenseData = null;
        if ($license) $licenseData = ['key' => (new License())->displayKey($license), 'status' => $license->status, 'expires_at' => $license->expires_at, 'activations' => $license->activations_count, 'activation_limit' => $license->domain_limit ?: $license->activation_limit];
        return ['product' => $product, 'entitlement' => $entitlement, 'license' => $licenseData];
    }

    // Kept for third-party templates that called the original method.
    public function render($action = 'downloads') { return ''; }
}
