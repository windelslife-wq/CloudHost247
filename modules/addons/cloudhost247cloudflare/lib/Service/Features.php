<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\AuthorizationException;

/** One entitlement registry used by both the UI and server-side authorization. */
class Features
{
    public static function all()
    {
        return [
            'dns.manage' => 'DNS management', 'dnssec.manage' => 'DNSSEC', 'analytics.read' => 'Analytics',
            'ssl.manage' => 'SSL / TLS', 'firewall.manage' => 'Firewall rules', 'speed.manage' => 'Speed optimization',
            'cache.manage' => 'Caching settings', 'cache.purge' => 'Cache purge', 'development_mode.manage' => 'Development mode',
            'scrape_shield.manage' => 'Scrape Shield', 'hotlink_protection.manage' => 'Hotlink protection',
            'email_obfuscation.manage' => 'Email address obfuscation', 'server_side_excludes.manage' => 'Server-side excludes',
            'plan.change' => 'Plan upgrade / downgrade', 'zone.manage' => 'Zone management',
        ];
    }
    public static function defaults() { return ['dns.manage' => true]; }
    public static function normalize($value)
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) $value = [];
        $out = [];
        foreach (self::all() as $key => $label) $out[$key] = !empty($value[$key]);
        return $out;
    }
    public static function enabled(array $features, $feature) { return !empty($features[$feature]); }
    public static function require(array $service, $feature)
    {
        $features = self::normalize($service['features_json'] ?? []);
        if (!self::enabled($features, $feature)) throw new AuthorizationException('This Cloudflare feature is not included in your service plan.');
        if (empty($service['zone_id'])) throw new AuthorizationException('This Cloudflare zone is not ready for management yet.');
        if (!in_array(strtoupper((string) ($service['status'] ?? '')), ['ACTIVE','PENDING_NAMESERVER_UPDATE'], true)) throw new AuthorizationException('This Cloudflare service is not active.');
        $whmcsStatus = strtolower((string) ($service['whmcs_service_status'] ?? $service['whmcs_addon_status'] ?? 'active'));
        $parentStatus = strtolower((string) ($service['addon_parent_status'] ?? 'active'));
        if ($whmcsStatus !== 'active' || $parentStatus !== 'active') throw new AuthorizationException('The related CloudHost247 service must be active before managing Cloudflare.');
    }
}
