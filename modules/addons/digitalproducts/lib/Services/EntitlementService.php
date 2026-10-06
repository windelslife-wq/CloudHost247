<?php
namespace DigitalProducts\Services;

use DigitalProducts\Core\Audit;
use DigitalProducts\Core\Clock;
use DigitalProducts\License;
use DigitalProducts\Security\TokenService;
use WHMCS\Database\Capsule;

class EntitlementService
{
    protected $license;
    public function __construct() { $this->license = new License(); }

    public function grantForOrder($orderId, array $context = [])
    {
        $services = Capsule::table('tblhosting')->where('orderid', (int) $orderId)->pluck('id');
        $out = [];
        foreach ($services as $serviceId) $out[] = $this->grantForService($serviceId, array_merge($context, ['order_id' => (int) $orderId]));
        return $out;
    }

    public function grantForService($serviceId, array $context = [])
    {
        $service = Capsule::table('tblhosting as h')->join('mod_digitalproducts_products as p', 'p.whmcs_product_id', '=', 'h.packageid')->where('h.id', (int) $serviceId)->select('h.*', 'p.id as dp_product_id', 'p.whmcs_product_id', 'p.current_version_id', 'p.access_mode', 'p.download_limit', 'p.license_enabled', 'p.status as product_status')->first();
        if (!$service || $service->product_status !== 'active' || !$service->current_version_id) return null;
        if (!$this->serviceMayReceive($service)) return null;
        $existing = Capsule::table('mod_digitalproducts_entitlements')->where('client_id', (int) $service->userid)->where('service_id', (int) $service->id)->where('product_id', (int) $service->dp_product_id)->first();
        if ($existing) {
            if ($existing->status !== 'active') Capsule::table('mod_digitalproducts_entitlements')->where('id', $existing->id)->update(['status' => 'active', 'revoked_reason' => null, 'updated_at' => Clock::now()]);
            $entitlement = Capsule::table('mod_digitalproducts_entitlements')->where('id', $existing->id)->first();
        } else {
            $id = Capsule::table('mod_digitalproducts_entitlements')->insertGetId(['product_id' => (int) $service->dp_product_id, 'whmcs_product_id' => (int) $service->whmcs_product_id, 'order_id' => (int) ($context['order_id'] ?? $service->orderid) ?: null, 'service_id' => (int) $service->id, 'client_id' => (int) $service->userid, 'purchase_version_id' => (int) $service->current_version_id, 'access_mode' => $service->access_mode ?: 'current_version', 'status' => 'active', 'download_limit' => (int) $service->download_limit, 'downloads_used' => 0, 'purchased_at' => $service->regdate ?: Clock::now(), 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
            $entitlement = Capsule::table('mod_digitalproducts_entitlements')->where('id', $id)->first();
            Audit::record('entitlement.granted', 'entitlement:' . $id, ['service_id' => $service->id, 'product_id' => $service->dp_product_id]);
        }
        $licenseKey = null;
        if ($service->license_enabled) {
            $licenseKey = $this->license->generateLicense(['product_id' => $service->dp_product_id, 'service_id' => $service->id, 'client_id' => $service->userid, 'entitlement_id' => $entitlement->id, 'domain' => $service->domain]);
        }
        // Email is best effort and recorded so a replayed payment hook does not resend it.
        if (empty($entitlement->email_sent_at)) $this->sendEmail($service, $entitlement, $licenseKey);
        return $entitlement;
    }

    public function revokeByOrder($orderId, $reason = 'order_revoked') { return Capsule::table('mod_digitalproducts_entitlements')->where('order_id', (int) $orderId)->update(['status' => 'revoked', 'revoked_reason' => $reason, 'updated_at' => Clock::now()]); }
    public function revokeByService($serviceId, $reason = 'service_revoked') { return Capsule::table('mod_digitalproducts_entitlements')->where('service_id', (int) $serviceId)->update(['status' => 'revoked', 'revoked_reason' => $reason, 'updated_at' => Clock::now()]); }
    public function suspendByService($serviceId) { return Capsule::table('mod_digitalproducts_entitlements')->where('service_id', (int) $serviceId)->where('status', 'active')->update(['status' => 'suspended', 'revoked_reason' => 'service_suspended', 'updated_at' => Clock::now()]); }
    public function restoreByService($serviceId) { return Capsule::table('mod_digitalproducts_entitlements')->where('service_id', (int) $serviceId)->where('status', 'suspended')->update(['status' => 'active', 'revoked_reason' => null, 'updated_at' => Clock::now()]); }
    public function resetCounter($id) { return Capsule::table('mod_digitalproducts_entitlements')->where('id', (int) $id)->update(['downloads_used' => 0, 'updated_at' => Clock::now()]); }
    public function getForClient($clientId) { return Capsule::table('mod_digitalproducts_entitlements as e')->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')->leftJoin('mod_digitalproducts_versions as v', 'v.id', '=', 'p.current_version_id')->leftJoin('mod_digitalproducts_versions as pv', 'pv.id', '=', 'e.purchase_version_id')->where('e.client_id', (int) $clientId)->where('e.status', 'active')->select('e.*', 'p.name', 'p.product_name', 'p.description', 'p.product_type', 'p.status as product_status', 'p.current_version_id', 'v.version as current_version', 'v.file_size as current_file_size', 'v.checksum_sha256 as current_checksum', 'v.changelog as current_changelog', 'pv.version as purchased_version')->orderBy('e.created_at', 'desc')->get(); }

    protected function serviceMayReceive($service)
    {
        $state = strtolower((string) $service->domainstatus);
        return in_array($state, ['active', 'completed'], true);
    }

    protected function sendEmail($service, $entitlement, $licenseKey)
    {
        try {
            if (!\DigitalProducts\Core\Settings::bool('email_delivery', true)) return;
            $version = Capsule::table('mod_digitalproducts_versions')->where('id', $service->current_version_id)->first();
            $product = Capsule::table('mod_digitalproducts_products')->where('id', $service->dp_product_id)->first();
            if (!$version || !$product) return;
            $token = (new TokenService())->issue($entitlement->id, $version->id, $service->userid);
            $merge = ['product_name' => $product->name ?: $product->product_name, 'product_version' => $version->version, 'purchase_date' => $entitlement->purchased_at, 'download_link' => $token['url'], 'client_area_link' => (\DigitalProducts\Core\Http::systemUrl() ?: '') . '/index.php?m=digitalproducts', 'license_key' => $licenseKey ?: ''];
            if (function_exists('sendMessage')) { $sent = @sendMessage('Digital Product Download Info', $service->id, $merge); if ($sent === false) throw new \RuntimeException('WHMCS email service rejected the message.'); }
            elseif (function_exists('logActivity')) logActivity('DigitalProducts: download email queued for service #' . (int) $service->id);
            Capsule::table('mod_digitalproducts_entitlements')->where('id', $entitlement->id)->update(['email_sent_at' => Clock::now(), 'updated_at' => Clock::now()]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) logActivity('DigitalProducts email failure: ' . $e->getMessage());
            // Do not change entitlement/payment state when email delivery fails.
        }
    }
}
