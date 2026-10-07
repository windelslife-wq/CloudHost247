<?php
namespace CloudHost247\Cloudflare\Repository;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Service\Features;

class PackageRepository
{
    public function listAll() { return Db::query('SELECT p.*, pr.name AS product_name, ad.name AS addon_name FROM `' . Db::table('packages') . '` p LEFT JOIN tblproducts pr ON pr.id = p.whmcs_product_id LEFT JOIN tbladdons ad ON ad.id = p.whmcs_addon_id ORDER BY p.id DESC'); }
    public function find($id) { return Db::first('packages', ['id' => (int) $id]); }
    public function forProduct($productId)
    {
        $row = Db::first('packages', ['whmcs_product_id' => (int) $productId]);
        return $row && (int) $row['enabled'] ? $this->hydrate($row) : null;
    }
    public function forAddon($addonId)
    {
        $row = Db::first('packages', ['whmcs_addon_id' => (int) $addonId]);
        return $row && (int) $row['enabled'] ? $this->hydrate($row) : null;
    }
    public function save(array $input)
    {
        $id = (int) ($input['id'] ?? 0); $productId = (int) ($input['whmcs_product_id'] ?? 0); $addonId = (int) ($input['whmcs_addon_id'] ?? 0);
        if (($productId > 0) === ($addonId > 0)) throw new ValidationException('Choose exactly one WHMCS product ID or addon ID.');
        if ($productId > 0 && !Db::firstQuery('SELECT id FROM tblproducts WHERE id = ?', [$productId])) throw new ValidationException('WHMCS product ID was not found.');
        if ($addonId > 0 && !Db::firstQuery('SELECT id FROM tbladdons WHERE id = ?', [$addonId])) throw new ValidationException('WHMCS addon ID was not found.');
        $label = trim((string) ($input['plan_label'] ?? '')); $providerPlan = trim((string) ($input['provider_plan_id'] ?? ''));
        if ($label === '' || strlen($label) > 100) throw new ValidationException('Enter a CloudHost247 plan label (up to 100 characters).');
        if ($providerPlan === '' || strlen($providerPlan) > 120) throw new ValidationException('Enter the provider plan identifier configured for this Cloudflare package.');
        $accountId = (int) ($input['account_id'] ?? 0);
        if ($accountId > 0 && !Db::first('accounts', ['id' => $accountId])) throw new ValidationException('The selected Cloudflare account was not found.');
        $features = [];
        foreach (Features::all() as $key => $unused) $features[$key] = !empty($input['features'][$key]);
        $mode = (string) ($input['zone_mode'] ?? ''); if (!in_array($mode, ['', 'full', 'partial'], true)) throw new ValidationException('Invalid zone activation mode.');
        $ssl = strtolower((string) ($input['ssl_mode'] ?? ''));
        if (!in_array($ssl, ['', 'off', 'flexible', 'full', 'strict'], true)) throw new ValidationException('Invalid SSL mode.');
        $cache = strtolower((string) ($input['cache_level'] ?? ''));
        if (!in_array($cache, ['', 'basic', 'standard', 'aggressive'], true)) throw new ValidationException('Invalid cache level.');
        $data = [
            'whmcs_product_id' => $productId ?: null, 'whmcs_addon_id' => $addonId ?: null,
            'account_id' => $accountId ?: null, 'provider_plan_id' => $providerPlan, 'plan_label' => $label,
            'max_domains' => max(1, min(1000, (int) ($input['max_domains'] ?? 1))),
            'features_json' => json_encode($features, JSON_UNESCAPED_SLASHES), 'zone_mode' => $mode ?: null,
            'ssl_mode' => $ssl ?: null, 'proxy_default' => !isset($input['proxy_default']) || $input['proxy_default'] === '' ? null : (!empty($input['proxy_default']) ? 1 : 0),
            'cache_level' => $cache ?: null, 'browser_cache_ttl' => isset($input['browser_cache_ttl']) && $input['browser_cache_ttl'] !== '' ? max(0, min(31536000, (int) $input['browser_cache_ttl'])) : null,
            'dns_discovery' => !empty($input['dns_discovery']) ? 1 : 0, 'enabled' => !empty($input['enabled']) ? 1 : 0, 'updated_at' => Clock::now(),
        ];
        if ($id) { if (!$this->find($id)) throw new ValidationException('Package mapping not found.'); Db::update('packages', ['id' => $id], $data); return $id; }
        $data['created_at'] = Clock::now();
        try { return Db::insert('packages', $data); }
        catch (\Throwable $e) { throw new ValidationException('A Cloudflare mapping already exists for that WHMCS product or addon.'); }
    }
    public function delete($id) { if (Db::count('services', ['package_mapping_id' => (int) $id])) throw new ValidationException('This package mapping is used by a Cloudflare service and cannot be deleted.'); return Db::delete('packages', ['id' => (int) $id]); }
    private function hydrate(array $row)
    {
        $row['features'] = Features::normalize($row['features_json'] ?? []);
        return $row;
    }
}
