<?php
namespace DigitalProducts\Security;

use DigitalProducts\Core\Clock;
use DigitalProducts\Core\Http;
use DigitalProducts\Core\Settings;
use WHMCS\Database\Capsule;

class TokenService
{
    public function issue($entitlementId, $versionId, $clientId, $expiryHours = null, $singleUse = true)
    {
        $raw = bin2hex(random_bytes(32));
        if ($expiryHours === null) {
            $productHours = Capsule::table('mod_digitalproducts_entitlements as e')->join('mod_digitalproducts_products as p', 'p.id', '=', 'e.product_id')->where('e.id', (int) $entitlementId)->value('p.download_expiry_hours');
            $expiryHours = $productHours === null ? Settings::int('link_expiry_hours', 48) : (int) $productHours;
        }
        $hours = max(0, (int) $expiryHours);
        $expires = $hours === 0 ? null : date('Y-m-d H:i:s', time() + ($hours * 3600));
        $id = Capsule::table('mod_digitalproducts_download_tokens')->insertGetId([
            'token_hash' => hash('sha256', $raw), 'entitlement_id' => (int) $entitlementId,
            'version_id' => (int) $versionId, 'client_id' => (int) $clientId,
            'single_use' => $singleUse ? 1 : 0, 'expires_at' => $expires,
            'created_ip_hash' => hash('sha256', Http::ip()), 'created_at' => Clock::now(),
        ]);
        return ['token' => $raw, 'id' => $id, 'expires_at' => $expires, 'url' => $this->url($raw)];
    }

    public function find($raw)
    {
        if (!is_string($raw) || !preg_match('/^[a-f0-9]{64}$/i', $raw)) return null;
        $hash = hash('sha256', strtolower($raw));
        $token = Capsule::table('mod_digitalproducts_download_tokens')->where('token_hash', $hash)->first();
        if (!$token) return null;
        if ($token->expires_at && strtotime($token->expires_at) <= time()) return null;
        if ((int) $token->single_use && $token->used_at) return null;
        return $token;
    }

    public function consume($id)
    {
        return Capsule::table('mod_digitalproducts_download_tokens')->where('id', (int) $id)->where(function ($query) {
            $query->where('single_use', 0)->orWhereNull('used_at');
        })->update(['used_at' => Clock::now()]) > 0;
    }

    public function purge()
    {
        return Capsule::table('mod_digitalproducts_download_tokens')->where(function ($q) {
            $q->where('expires_at', '<', Clock::now())->orWhere(function ($q2) { $q2->whereNotNull('used_at')->where('used_at', '<', date('Y-m-d H:i:s', time() - 86400)); });
        })->delete();
    }

    public function url($raw)
    {
        $base = Http::systemUrl();
        return ($base ? $base : '') . '/modules/addons/digitalproducts/download.php?token=' . rawurlencode($raw);
    }
}
