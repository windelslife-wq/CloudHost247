<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;

class ZoneManagementService
{
    private $accounts; private $services;
    public function __construct(AccountRepository $accounts = null, ServiceRepository $services = null)
    {
        $this->accounts = $accounts ?: new AccountRepository(); $this->services = $services ?: new ServiceRepository();
    }
    public function plan(array $service)
    {
        if (empty($service['zone_id'])) throw new ValidationException('Cloudflare zone is not available yet.');
        list($api) = $this->api($service);
        $result = $api->getPlan($service['zone_id']);
        return is_array($result) ? $result : ['status' => 'DATA_UNAVAILABLE'];
    }
    public function dnssec(array $service)
    {
        Features::require($service, 'dnssec.manage'); list($api) = $this->api($service);
        $result = $api->dnssec($service['zone_id']);
        return is_array($result) ? $result : ['status' => 'DATA_UNAVAILABLE'];
    }
    public function setDnssec(array $service, $enabled, $actorType, $actorId)
    {
        Features::require($service, 'dnssec.manage'); list($api) = $this->api($service);
        $result = $api->setDnssec($service['zone_id'], (bool) $enabled);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, $enabled ? 'DNSSEC_ENABLED' : 'DNSSEC_DISABLED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true);
        return $result;
    }
    public function analytics(array $service, $range = '24h', $customSince = '', $customUntil = '')
    {
        Features::require($service, 'analytics.read');
        list($since, $until) = $this->range($range, $customSince, $customUntil);
        $cacheKey = hash('sha256', $since . '|' . $until);
        $cached = Db::first('analytics_cache', ['service_id' => (int) $service['id'], 'cache_key' => $cacheKey]);
        if ($cached && strtotime((string) $cached['expires_at']) > time()) {
            $raw = json_decode((string) $cached['payload_json'], true);
            return $this->analyticsView(is_array($raw) ? $raw : []);
        }
        list($api) = $this->api($service);
        $raw = $api->analytics($service['zone_id'], $since, $until);
        if (!is_array($raw)) $raw = [];
        $this->saveAnalyticsCache($service, $cacheKey, $raw);
        return $this->analyticsView($raw);
    }
    public function sslSettings(array $service)
    {
        Features::require($service, 'ssl.manage');
        return $this->readSettings($service, ['ssl','min_tls_version','tls_1_3','always_use_https','automatic_https_rewrites','opportunistic_encryption']);
    }
    public function saveSslSetting(array $service, $key, $value, $actorType, $actorId)
    {
        Features::require($service, 'ssl.manage');
        if (!in_array($key, ['ssl','min_tls_version','tls_1_3','always_use_https','automatic_https_rewrites','opportunistic_encryption'], true)) throw new ValidationException('Unsupported SSL/TLS setting.');
        $value = $this->validateSettingValue($key, $value);
        list($api) = $this->api($service); $result = $api->setSetting($service['zone_id'], $key, $value);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'SSL_CONFIGURATION_UPDATED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['setting' => $key, 'value' => $value]);
        return $result;
    }
    public function firewallSettings(array $service)
    {
        Features::require($service, 'firewall.manage');
        return $this->readSettings($service, ['security_level','browser_check','challenge_ttl']);
    }
    public function saveFirewallSetting(array $service, $key, $value, $actorType, $actorId)
    {
        Features::require($service, 'firewall.manage');
        if (!in_array($key, ['security_level','browser_check','challenge_ttl'], true)) throw new ValidationException('Unsupported firewall setting.');
        $value = $this->validateSettingValue($key, $value); list($api) = $this->api($service);
        $result = $api->setSetting($service['zone_id'], $key, $value);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'FIREWALL_SETTING_UPDATED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['setting' => $key, 'value' => $value]);
        return $result;
    }
    public function speedSettings(array $service)
    {
        Features::require($service, 'speed.manage');
        return $this->readSettings($service, ['minify','rocket_loader','brotli','http2','http3','ip_geolocation','early_hints']);
    }
    public function saveSpeedSetting(array $service, $key, $value, $actorType, $actorId)
    {
        Features::require($service, 'speed.manage');
        if (!in_array($key, ['minify','rocket_loader','brotli','http2','http3','ip_geolocation','early_hints'], true)) throw new ValidationException('Unsupported speed optimization setting.');
        $value = $this->validateSettingValue($key, $value); list($api) = $this->api($service);
        $result = $api->setSetting($service['zone_id'], $key, $value);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'SPEED_SETTING_UPDATED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['setting' => $key]);
        return $result;
    }
    public function cacheSettings(array $service)
    {
        Features::require($service, 'cache.manage');
        $settings = $this->readSettings($service, ['cache_level','browser_cache_ttl','development_mode']);
        $settings['development_mode_changed_at'] = $service['development_mode_changed_at'] ?? null;
        $settings['development_mode_expires_at'] = !empty($service['development_mode']) && !empty($service['development_mode_changed_at'])
            ? gmdate('Y-m-d H:i:s', strtotime($service['development_mode_changed_at']) + 10800) : null;
        return $settings;
    }
    public function saveCacheSetting(array $service, $key, $value, $actorType, $actorId)
    {
        Features::require($service, $key === 'development_mode' ? 'development_mode.manage' : 'cache.manage');
        if (!in_array($key, ['cache_level','browser_cache_ttl','development_mode'], true)) throw new ValidationException('Unsupported cache setting.');
        $value = $this->validateSettingValue($key, $value); list($api) = $this->api($service);
        $result = $api->setSetting($service['zone_id'], $key, $value);
        if ($key === 'development_mode') {
            $on = strtolower((string) $value) === 'on';
            $this->services->update((int) $service['id'], ['development_mode' => $on ? 1 : 0, 'development_mode_changed_at' => Clock::now()]);
        }
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, $key === 'development_mode' ? ($value === 'on' ? 'DEVELOPMENT_MODE_ENABLED' : 'DEVELOPMENT_MODE_DISABLED') : 'CACHE_SETTING_UPDATED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['setting' => $key, 'value' => $value]);
        return $result;
    }
    public function scrapeShieldSettings(array $service)
    {
        Features::require($service, 'scrape_shield.manage');
        return $this->readSettings($service, ['email_obfuscation','server_side_exclude','hotlink_protection']);
    }
    public function saveScrapeShieldSetting(array $service, $key, $value, $actorType, $actorId)
    {
        $feature = ['email_obfuscation' => 'email_obfuscation.manage', 'server_side_exclude' => 'server_side_excludes.manage', 'hotlink_protection' => 'hotlink_protection.manage'][$key] ?? '';
        if ($feature === '') throw new ValidationException('Unsupported content protection setting.');
        Features::require($service, $feature); $value = $this->validateSettingValue($key, $value);
        list($api) = $this->api($service); $result = $api->setSetting($service['zone_id'], $key, $value);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'CONTENT_PROTECTION_UPDATED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['setting' => $key, 'value' => $value]);
        return $result;
    }
    public function purgeCache(array $service, array $urls = null, $actorType = 'admin', $actorId = 0)
    {
        Features::require($service, 'cache.purge');
        if ($urls !== null && count($urls) > 30) throw new ValidationException('You can purge up to 30 URLs at a time.');
        $normalized = [];
        foreach ((array) $urls as $url) {
            $url = trim((string) $url); if ($url === '') continue;
            $parts = parse_url($url);
            if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !DomainName::isWithinZone($parts['host'], $service['zone_name'])) throw new ValidationException('Cache purge URLs must be HTTPS URLs inside this service domain.');
            $normalized[] = $url;
        }
        if ($urls !== null && !$normalized) throw new ValidationException('Enter at least one HTTPS URL to purge, or choose Purge Everything.');
        if ($urls === null) $normalized = null;
        list($api) = $this->api($service); $result = $api->purgeCache($service['zone_id'], $normalized);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'CACHE_PURGED', 'zone', $service['zone_id'], $service['customer_id'], $service['id'], true, ['mode' => $normalized === null ? 'everything' : 'urls', 'count' => $normalized === null ? 0 : count($normalized)]);
        return $result;
    }
    public function firewallRules(array $service)
    {
        Features::require($service, 'firewall.manage'); list($api) = $this->api($service);
        $result = $api->listFirewallRules($service['zone_id']);
        return $result;
    }
    public function createFirewallRule(array $service, array $input, $actorType, $actorId)
    {
        Features::require($service, 'firewall.manage');
        $rule = DnsRecordValidator::firewallRule($input); list($api) = $this->api($service);
        $ruleset = $api->listFirewallRules($service['zone_id']);
        $body = ['action' => $rule['action'], 'expression' => $rule['expression'], 'description' => $rule['description'], 'enabled' => true];
        if (empty($ruleset['id'])) {
            $created = $api->createFirewallRuleset($service['zone_id'], $body);
            $rulesetId = (string) ($created['id'] ?? '');
            $remoteRule = isset($created['rules'][0]) && is_array($created['rules'][0]) ? $created['rules'][0] : [];
        } else {
            $rulesetId = (string) $ruleset['id'];
            $remoteRule = $api->createFirewallRule($service['zone_id'], $rulesetId, $body);
        }
        $ruleId = (string) ($remoteRule['id'] ?? '');
        if ($rulesetId === '' || $ruleId === '') throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare did not return the firewall rule identifiers.');
        Db::insert('security_rules', ['service_id' => (int) $service['id'], 'cloudflare_rule_id' => $ruleId, 'ruleset_id' => $rulesetId, 'address' => $rule['address'], 'action' => $rule['action'], 'description' => $rule['description'], 'expression' => $rule['expression'], 'status' => 'active', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'FIREWALL_RULE_CREATED', 'firewall_rule', $ruleId, $service['customer_id'], $service['id'], true, ['address' => $rule['address'], 'action' => $rule['action']]);
        return $remoteRule;
    }
    public function deleteFirewallRule(array $service, $ruleId, $actorType, $actorId)
    {
        Features::require($service, 'firewall.manage');
        $local = Db::first('security_rules', ['service_id' => (int) $service['id'], 'cloudflare_rule_id' => (string) $ruleId]);
        if (!$local) throw new NotFoundException('Firewall rule not found for this service.');
        list($api) = $this->api($service); $api->deleteFirewallRule($service['zone_id'], $local['ruleset_id'], $local['cloudflare_rule_id']);
        Db::delete('security_rules', ['id' => (int) $local['id'], 'service_id' => (int) $service['id']]);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'FIREWALL_RULE_DELETED', 'firewall_rule', $ruleId, $service['customer_id'], $service['id'], true);
        return true;
    }

    private function readSettings(array $service, array $keys)
    {
        list($api) = $this->api($service); $out = [];
        foreach ($keys as $key) {
            try { $value = $api->getSetting($service['zone_id'], $key); $out[$key] = is_array($value) && array_key_exists('value', $value) ? $value['value'] : null; }
            catch (\Throwable $e) { $out[$key] = ['status' => 'DATA_UNAVAILABLE']; }
        }
        return $out;
    }
    private function api(array $service)
    {
        return $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
    }
    private function validateSettingValue($key, $value)
    {
        $value = is_string($value) ? trim($value) : $value;
        $boolKeys = ['tls_1_3','always_use_https','automatic_https_rewrites','opportunistic_encryption','rocket_loader','brotli','http2','http3','ip_geolocation','early_hints','browser_check','development_mode','email_obfuscation','server_side_exclude','hotlink_protection'];
        if (in_array($key, $boolKeys, true)) {
            $string = strtolower((string) $value);
            if (in_array($string, ['1','on','true','yes'], true)) return 'on';
            if (in_array($string, ['0','off','false','no'], true)) return 'off';
            throw new ValidationException('Choose On or Off.');
        }
        if ($key === 'ssl') {
            $value = strtolower((string) $value); if (!in_array($value, ['off','flexible','full','strict'], true)) throw new ValidationException('Choose a supported SSL mode.'); return $value;
        }
        if ($key === 'min_tls_version') {
            $value = (string) $value; if (!in_array($value, ['1.0','1.1','1.2','1.3'], true)) throw new ValidationException('Choose a supported minimum TLS version.'); return $value;
        }
        if ($key === 'security_level') {
            $value = strtolower((string) $value); if (!in_array($value, ['off','essentially_off','low','medium','high','under_attack'], true)) throw new ValidationException('Choose a supported security level.'); return $value;
        }
        if ($key === 'cache_level') {
            $value = strtolower((string) $value); if (!in_array($value, ['basic','standard','aggressive'], true)) throw new ValidationException('Choose a supported cache level.'); return $value;
        }
        if ($key === 'minify') {
            $input = is_array($value) ? $value : [];
            return ['css' => !empty($input['css']), 'html' => !empty($input['html']), 'js' => !empty($input['js'])];
        }
        if ($key === 'browser_cache_ttl') {
            if (!is_numeric($value) || (int) $value < 0 || (int) $value > 31536000) throw new ValidationException('Browser cache TTL must be between 0 and 31536000 seconds.'); return (int) $value;
        }
        if ($key === 'challenge_ttl') {
            if (!is_numeric($value) || !in_array((int) $value, [300, 900, 1800, 2700, 3600, 7200, 10800, 14400, 28800, 57600, 86400], true)) throw new ValidationException('Select a supported challenge duration.'); return (int) $value;
        }
        throw new ValidationException('Unsupported Cloudflare setting.');
    }
    private function range($range, $since, $until)
    {
        $now = time();
        if ($range === '7d') return [gmdate('Y-m-d\TH:i:s\Z', $now - 7 * 86400), gmdate('Y-m-d\TH:i:s\Z', $now)];
        if ($range === '30d') return [gmdate('Y-m-d\TH:i:s\Z', $now - 30 * 86400), gmdate('Y-m-d\TH:i:s\Z', $now)];
        if ($range === 'custom') {
            $start = strtotime((string) $since); $end = strtotime((string) $until);
            if (!$start || !$end || $end <= $start || $end - $start > 90 * 86400) throw new ValidationException('Choose a valid custom analytics range of 90 days or less.');
            return [gmdate('Y-m-d\TH:i:s\Z', $start), gmdate('Y-m-d\TH:i:s\Z', $end)];
        }
        return [gmdate('Y-m-d\TH:i:s\Z', $now - 86400), gmdate('Y-m-d\TH:i:s\Z', $now)];
    }
    private function analyticsView(array $raw)
    {
        $totals = $raw['totals'] ?? [];
        $values = [
            'requests' => $totals['requests']['all'] ?? null,
            'bandwidth' => $totals['bandwidth']['all'] ?? null,
            'cached_requests' => $totals['requests']['cached'] ?? null,
            'uncached_requests' => $totals['requests']['uncached'] ?? null,
            'threats' => $totals['threats']['all'] ?? null,
            'visitors' => $totals['uniques']['all'] ?? null,
            'traffic' => $totals['bandwidth']['all'] ?? null,
            'http_status' => $totals['requests']['http_status'] ?? null,
        ];
        foreach ($values as $key => $value) {
            if (is_array($value)) $values[$key] = json_encode($value, JSON_UNESCAPED_SLASHES);
            elseif (!is_numeric($value)) $values[$key] = 'DATA_UNAVAILABLE';
        }
        return ['metrics' => $values, 'time_series' => $raw['time_series'] ?? null, 'raw' => $raw];
    }
    private function saveAnalyticsCache(array $service, $key, array $raw)
    {
        $existing = Db::first('analytics_cache', ['service_id' => (int) $service['id'], 'cache_key' => $key]);
        $data = ['payload_json' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 'expires_at' => Clock::after(900), 'updated_at' => Clock::now()];
        if ($existing) Db::update('analytics_cache', ['id' => (int) $existing['id']], $data);
        else Db::insert('analytics_cache', ['service_id' => (int) $service['id'], 'cache_key' => $key] + $data);
    }
}
