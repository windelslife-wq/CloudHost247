<?php
namespace CloudHost247\Cloudflare\Provider;

use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\ValidationException;

/** Domain-specific Cloudflare v4 operations. Callers pass trusted service zones, never browser-supplied zone IDs. */
class CloudflareApi
{
    private $client;
    private $accountId;
    public function __construct(CloudflareClient $client, $accountId) { $this->client = $client; $this->accountId = (string) $accountId; }

    public function testConnection()
    {
        $token = $this->client->call('GET', '/user/tokens/verify', [], null, 'connection.token_verify');
        $account = $this->client->call('GET', '/accounts/' . rawurlencode($this->accountId), [], null, 'connection.account_read');
        // A second probe proves that the token can at least list this account's zones.
        $zones = $this->client->call('GET', '/zones', ['account.id' => $this->accountId, 'per_page' => 1], null, 'connection.zone_read');
        return [
            'connected' => true,
            'account_id' => (string) ($account['id'] ?? $this->accountId),
            'account_name' => (string) ($account['name'] ?? ''),
            'token_status' => (string) ($token['status'] ?? 'active'),
            'permissions' => ['Account:Read' => 'granted', 'Zone:Read' => 'granted', 'other_permissions' => 'not_tested'],
            'zone_probe_count' => is_array($zones) ? count($zones) : 0,
        ];
    }

    public function listZones($name = null)
    {
        $out = []; $page = 1;
        do {
            $query = ['account.id' => $this->accountId, 'per_page' => 50, 'page' => $page];
            if ($name !== null && $name !== '') $query['name'] = $name;
            $result = $this->client->call('GET', '/zones', $query, null, 'zone.list');
            if (!is_array($result)) return $out;
            foreach ($result as $zone) if (is_array($zone)) $out[] = $zone;
            $page++;
        } while (count($result) === 50 && $page <= 20);
        return $out;
    }
    public function findZone($domain)
    {
        foreach ($this->listZones($domain) as $zone) {
            if (strtolower(rtrim((string) ($zone['name'] ?? ''), '.')) === strtolower(rtrim((string) $domain, '.'))) return $zone;
        }
        return null;
    }
    public function createZone($domain, $mode = 'full', $jumpStart = true)
    {
        $mode = in_array($mode, ['full', 'partial'], true) ? $mode : 'full';
        return $this->client->call('POST', '/zones', [], [
            'name' => strtolower(rtrim((string) $domain, '.')),
            'account' => ['id' => $this->accountId], 'type' => $mode,
            'jump_start' => (bool) $jumpStart,
        ], 'zone.create');
    }
    public function getZone($zoneId) { return $this->client->call('GET', $this->zonePath($zoneId), [], null, 'zone.get'); }
    public function updateZonePaused($zoneId, $paused) { return $this->client->call('PATCH', $this->zonePath($zoneId), [], ['paused' => (bool) $paused], $paused ? 'zone.pause' : 'zone.unpause'); }
    public function deleteZone($zoneId) { return $this->client->call('DELETE', $this->zonePath($zoneId), [], null, 'zone.delete'); }

    public function listDnsRecords($zoneId)
    {
        $out = []; $page = 1;
        do {
            $result = $this->client->call('GET', $this->zonePath($zoneId) . '/dns_records', ['per_page' => 100, 'page' => $page], null, 'dns.list');
            if (!is_array($result)) return $out;
            foreach ($result as $record) if (is_array($record)) $out[] = $record;
            $page++;
        } while (count($result) === 100 && $page <= 50);
        return $out;
    }
    public function getDnsRecord($zoneId, $recordId) { return $this->client->call('GET', $this->zonePath($zoneId) . '/dns_records/' . $this->idPath($recordId), [], null, 'dns.get'); }
    public function createDnsRecord($zoneId, array $record) { return $this->client->call('POST', $this->zonePath($zoneId) . '/dns_records', [], $record, 'dns.create'); }
    public function updateDnsRecord($zoneId, $recordId, array $record) { return $this->client->call('PATCH', $this->zonePath($zoneId) . '/dns_records/' . $this->idPath($recordId), [], $record, 'dns.update'); }
    public function deleteDnsRecord($zoneId, $recordId) { return $this->client->call('DELETE', $this->zonePath($zoneId) . '/dns_records/' . $this->idPath($recordId), [], null, 'dns.delete'); }

    public function dnssec($zoneId) { return $this->client->call('GET', $this->zonePath($zoneId) . '/dnssec', [], null, 'dnssec.read'); }
    public function setDnssec($zoneId, $enabled) { return $this->client->call('PATCH', $this->zonePath($zoneId) . '/dnssec', [], ['status' => $enabled ? 'active' : 'disabled'], 'dnssec.change'); }

    public function analytics($zoneId, $since, $until)
    {
        return $this->client->call('GET', $this->zonePath($zoneId) . '/analytics/dashboard', ['since' => $since, 'until' => $until], null, 'analytics.read');
    }

    public function getSetting($zoneId, $setting)
    {
        $setting = $this->settingKey($setting);
        return $this->client->call('GET', $this->zonePath($zoneId) . '/settings/' . rawurlencode($setting), [], null, 'setting.read.' . $setting);
    }
    public function setSetting($zoneId, $setting, $value)
    {
        $setting = $this->settingKey($setting);
        return $this->client->call('PATCH', $this->zonePath($zoneId) . '/settings/' . rawurlencode($setting), [], ['value' => $value], 'setting.change.' . $setting);
    }

    public function listFirewallRules($zoneId)
    {
        try {
            $result = $this->client->call('GET', $this->zonePath($zoneId) . '/rulesets/phases/http_request_firewall_custom/entrypoint', [], null, 'firewall.list');
            return is_array($result) && isset($result['rules']) && is_array($result['rules']) ? ['id' => $result['id'] ?? '', 'rules' => $result['rules']] : ['id' => '', 'rules' => []];
        } catch (CloudflareException $e) {
            if ($e->httpStatus() === 404 || $e->errorCode() === 'CLOUDFLARE_ZONE_NOT_FOUND') {
                // Cloudflare has no phase entrypoint before its first custom rule.
                $this->getZone($zoneId);
                return ['id' => '', 'rules' => []];
            }
            throw $e;
        }
    }
    public function createFirewallRuleset($zoneId, array $rule)
    {
        return $this->client->call('POST', $this->zonePath($zoneId) . '/rulesets', [], [
            'name' => 'CloudHost247 managed firewall', 'description' => 'Managed by CloudHost247 Cloudflare service.',
            'kind' => 'zone', 'phase' => 'http_request_firewall_custom', 'rules' => [$rule],
        ], 'firewall.ruleset_create');
    }
    public function createFirewallRule($zoneId, $rulesetId, array $rule)
    {
        return $this->client->call('POST', $this->zonePath($zoneId) . '/rulesets/' . $this->idPath($rulesetId) . '/rules', [], $rule, 'firewall.rule_create');
    }
    public function updateFirewallRule($zoneId, $rulesetId, $ruleId, array $rule)
    {
        return $this->client->call('PATCH', $this->zonePath($zoneId) . '/rulesets/' . $this->idPath($rulesetId) . '/rules/' . $this->idPath($ruleId), [], $rule, 'firewall.rule_update');
    }
    public function deleteFirewallRule($zoneId, $rulesetId, $ruleId)
    {
        return $this->client->call('DELETE', $this->zonePath($zoneId) . '/rulesets/' . $this->idPath($rulesetId) . '/rules/' . $this->idPath($ruleId), [], null, 'firewall.rule_delete');
    }

    public function purgeCache($zoneId, array $urls = null)
    {
        $payload = $urls === null ? ['purge_everything' => true] : ['files' => array_values($urls)];
        return $this->client->call('POST', $this->zonePath($zoneId) . '/purge_cache', [], $payload, 'cache.purge');
    }

    public function getPlan($zoneId) { return $this->client->call('GET', $this->zonePath($zoneId) . '/subscription', [], null, 'plan.read'); }
    public function changePlan($zoneId, $providerPlanId)
    {
        if (trim((string) $providerPlanId) === '') throw new ValidationException('The target Cloudflare plan mapping is incomplete.');
        return $this->client->call('PATCH', $this->zonePath($zoneId) . '/subscription', [], ['plan' => ['id' => (string) $providerPlanId]], 'plan.change');
    }

    private function zonePath($id) { return '/zones/' . $this->idPath($id); }
    private function idPath($id)
    {
        $id = (string) $id;
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id)) throw new ValidationException('Invalid Cloudflare resource identifier.');
        return rawurlencode($id);
    }
    private function settingKey($key)
    {
        static $allowed = ['ssl','min_tls_version','tls_1_3','always_use_https','automatic_https_rewrites','opportunistic_encryption','cache_level','browser_cache_ttl','development_mode','minify','rocket_loader','brotli','http2','http3','ip_geolocation','early_hints','email_obfuscation','server_side_exclude','hotlink_protection'];
        if (!in_array((string) $key, $allowed, true)) throw new ValidationException('Unsupported Cloudflare setting.');
        return (string) $key;
    }
}
