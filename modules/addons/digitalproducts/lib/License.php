<?php
namespace DigitalProducts;

use DigitalProducts\Core\Clock;
use DigitalProducts\Core\Crypto;
use DigitalProducts\Core\RateLimiter;
use WHMCS\Database\Capsule;

class License
{
    public function generateLicense(array $data)
    {
        $productId = (int) ($data['product_id'] ?? 0); $serviceId = (int) ($data['service_id'] ?? 0); $clientId = (int) ($data['client_id'] ?? 0);
        $existing = Capsule::table('mod_digitalproducts_licenses')->where('service_id', $serviceId)->where('product_id', $productId)->first();
        if ($existing) return $this->displayKey($existing);
        $key = $this->generateKey();
        $domains = !empty($data['domain']) ? json_encode([$this->normaliseDomain($data['domain'])]) : json_encode([]);
        $values = ['product_id' => $productId, 'service_id' => $serviceId, 'client_id' => $clientId, 'entitlement_id' => (int) ($data['entitlement_id'] ?? 0) ?: null, 'license_hash' => hash('sha256', $key), 'license_prefix' => substr($key, 0, 10), 'license_encrypted' => Crypto::encrypt($key), 'status' => 'active', 'domains' => $domains, 'activation_limit' => (int) ($data['activation_limit'] ?? 0), 'activations_limit' => (int) ($data['activation_limit'] ?? 0), 'activations_count' => 0, 'domain_limit' => (int) ($data['domain_limit'] ?? 0), 'expires_at' => $data['expires_at'] ?? null, 'created_at' => Clock::now(), 'updated_at' => Clock::now()];
        // license_key is retained solely for legacy rows. New keys are encrypted.
        try { Capsule::table('mod_digitalproducts_licenses')->insert($values); }
        catch (\Throwable $e) {
            $existing = Capsule::table('mod_digitalproducts_licenses')->where('service_id', $serviceId)->where('product_id', $productId)->first();
            if ($existing) return $this->displayKey($existing);
            throw $e;
        }
        return $key;
    }

    public function validateLicense($licenseKey, $domain = null)
    {
        $licenseKey = trim((string) $licenseKey);
        if ($licenseKey === '' || strlen($licenseKey) > 128) return ['valid' => false, 'error' => 'Invalid license.'];
        $license = Capsule::table('mod_digitalproducts_licenses as l')->leftJoin('mod_digitalproducts_products as p', 'p.id', '=', 'l.product_id')->where('l.license_hash', hash('sha256', $licenseKey))->select('l.*', 'p.product_name', 'p.name')->first();
        // One-time compatibility for DP keys that were present before hashing.
        if (!$license) $license = Capsule::table('mod_digitalproducts_licenses as l')->leftJoin('mod_digitalproducts_products as p', 'p.id', '=', 'l.product_id')->where('l.license_key', $licenseKey)->select('l.*', 'p.product_name', 'p.name')->first();
        if (!$license || !hash_equals((string) ($license->license_hash ?: hash('sha256', (string) $license->license_key)), hash('sha256', $licenseKey))) return ['valid' => false, 'error' => 'Invalid license.'];
        if ($license->status !== 'active') return ['valid' => false, 'error' => 'License is not active.'];
        if ($license->expires_at && strtotime($license->expires_at) <= time()) { Capsule::table('mod_digitalproducts_licenses')->where('id', $license->id)->update(['status' => 'expired', 'updated_at' => Clock::now()]); return ['valid' => false, 'error' => 'License is not active.']; }
        if ($domain !== null && !$this->domainAllowed($license, $domain)) return ['valid' => false, 'error' => 'License is not valid for this domain.'];
        return ['valid' => true, 'license' => $license, 'status' => 'active'];
    }

    public function activateLicense($licenseKey, $domain)
    {
        $check = $this->validateLicense($licenseKey);
        if (!$check['valid']) return ['success' => false, 'error' => $check['error']];
        $license = $check['license']; $domain = $this->normaliseDomain($domain);
        if ($domain === '') return ['success' => false, 'error' => 'A valid domain is required.'];
        $domains = json_decode($license->domains ?: '[]', true); if (!is_array($domains)) $domains = [];
        if (in_array($domain, $domains, true)) return ['success' => true, 'already_active' => true];
        $domainLimit = (int) ($license->domain_limit ?: $license->activations_limit);
        if ($domainLimit > 0 && count($domains) >= $domainLimit) return ['success' => false, 'error' => 'Activation limit reached.'];
        Capsule::table('mod_digitalproducts_licenses')->where('id', $license->id)->update(['domains' => json_encode(array_values(array_merge($domains, [$domain]))), 'activations_count' => count($domains) + 1, 'updated_at' => Clock::now()]);
        return ['success' => true];
    }

    public function getLicenseByService($serviceId) { return Capsule::table('mod_digitalproducts_licenses')->where('service_id', (int) $serviceId)->first(); }
    public function getClientLicenses($clientId) { return Capsule::table('mod_digitalproducts_licenses as l')->leftJoin('mod_digitalproducts_products as p', 'p.id', '=', 'l.product_id')->where('l.client_id', (int) $clientId)->select('l.*', 'p.product_name', 'p.name')->get(); }
    public function updateLicenseStatus($id, $status) { if (!in_array($status, ['active', 'suspended', 'expired', 'cancelled'], true)) throw new \InvalidArgumentException('Invalid license status.'); return Capsule::table('mod_digitalproducts_licenses')->where('id', (int) $id)->update(['status' => $status, 'updated_at' => Clock::now()]); }

    public function displayKey($license) { return !empty($license->license_encrypted) ? Crypto::decrypt($license->license_encrypted) : ($license->license_key ?: null); }

    protected function generateKey()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do { $parts = []; for ($i = 0; $i < 4; $i++) { $part = ''; for ($j = 0; $j < 4; $j++) $part .= $alphabet[random_int(0, strlen($alphabet) - 1)]; $parts[] = $part; } $key = 'CH247-' . implode('-', $parts); } while (Capsule::table('mod_digitalproducts_licenses')->where('license_hash', hash('sha256', $key))->exists());
        return $key;
    }

    protected function domainAllowed($license, $domain)
    {
        $domains = json_decode($license->domains ?: '[]', true); if (!is_array($domains) || !$domains) return true;
        return in_array($this->normaliseDomain($domain), $domains, true);
    }
    protected function normaliseDomain($domain) { $domain = strtolower(trim((string) $domain)); $domain = preg_replace('#^https?://#', '', $domain); return trim(preg_replace('#/.*$#', '', $domain), '.'); }
}
