<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;

/** Cloudflare is authoritative; mod_ch247cf_dns_records is only a synchronized index. */
class DnsRecordService
{
    private $services; private $accounts;
    public function __construct(ServiceRepository $services = null, AccountRepository $accounts = null)
    {
        $this->services = $services ?: new ServiceRepository(); $this->accounts = $accounts ?: new AccountRepository();
    }
    public function listForService(array $service)
    {
        Features::require($service, 'dns.manage');
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        return $this->syncRecords($service, $api);
    }
    public function create(array $service, array $input, $actorType, $actorId)
    {
        $record = DnsRecordValidator::validate($input, $service['zone_name']);
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $remote = $api->listDnsRecords($service['zone_id']);
        foreach ($remote as $item) {
            if (strtoupper((string) ($item['type'] ?? '')) === $record['type'] && strtolower(rtrim((string) ($item['name'] ?? ''), '.')) === $record['name'] && (string) ($item['content'] ?? '') === $record['content']) {
                $saved = $this->store($service, $item, 'CUSTOMER_MANAGED');
                return $saved;
            }
        }
        $created = $api->createDnsRecord($service['zone_id'], DnsRecordValidator::toApi($record));
        $saved = $this->store($service, $created, 'CUSTOMER_MANAGED');
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'DNS_RECORD_CREATED', 'dns_record', $created['id'] ?? null, $service['customer_id'], $service['id'], true, ['type' => $record['type'], 'name' => $record['name']]);
        return $saved;
    }
    public function update(array $service, $recordId, array $input, $actorType, $actorId)
    {
        $local = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => (string) $recordId]);
        if (!$local) throw new NotFoundException('DNS record not found for this Cloudflare service.');
        $record = DnsRecordValidator::validate($input + ['type' => $local['type']], $service['zone_name'], $local['type']);
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $updated = $api->updateDnsRecord($service['zone_id'], (string) $recordId, DnsRecordValidator::toApi($record));
        $saved = $this->store($service, $updated, 'CUSTOMER_MANAGED');
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'DNS_RECORD_UPDATED', 'dns_record', $recordId, $service['customer_id'], $service['id'], true, ['type' => $record['type'], 'name' => $record['name']]);
        return $saved;
    }
    public function delete(array $service, $recordId, $actorType, $actorId)
    {
        $local = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => (string) $recordId]);
        if (!$local) throw new NotFoundException('DNS record not found for this Cloudflare service.');
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        try { $api->deleteDnsRecord($service['zone_id'], (string) $recordId); }
        catch (CloudflareException $e) { if ($e->errorCode() !== 'CLOUDFLARE_RECORD_NOT_FOUND') throw $e; }
        Db::delete('dns_records', ['id' => (int) $local['id'], 'service_id' => (int) $service['id']]);
        \CloudHost247\Cloudflare\Core\Audit::record($actorType, $actorId, 'DNS_RECORD_DELETED', 'dns_record', $recordId, $service['customer_id'], $service['id'], true, ['type' => $local['type'], 'name' => $local['name']]);
        return true;
    }

    /** Called only from a trusted hosting-origin synchronization path. */
    public function synchronizeOrigin(array $service, $ipAddress)
    {
        $ipAddress = trim((string) $ipAddress);
        if ($ipAddress === '' || !filter_var($ipAddress, FILTER_VALIDATE_IP)) return ['updated' => 0, 'skipped' => 0];
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $remote = $this->syncRecords($service, $api); $updated = 0; $skipped = 0;
        $targets = [
            ['type' => strpos($ipAddress, ':') !== false ? 'AAAA' : 'A', 'name' => $service['zone_name'], 'content' => $ipAddress],
        ];
        if (strpos($ipAddress, ':') === false) $targets[] = ['type' => 'CNAME', 'name' => 'www.' . $service['zone_name'], 'content' => $service['zone_name']];
        foreach ($targets as $target) {
            $matching = null;
            foreach ($remote as $item) {
                if (strtoupper((string) ($item['type'] ?? '')) === $target['type'] && strtolower(rtrim((string) ($item['name'] ?? ''), '.')) === $target['name']) { $matching = $item; break; }
            }
            if ($matching) {
                $local = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => (string) ($matching['id'] ?? '')]);
                if (!$local || $local['ownership'] !== 'SYSTEM_MANAGED') { $skipped++; continue; }
                if ((string) ($matching['content'] ?? '') !== $target['content']) {
                    $payload = [
                        'type' => $target['type'], 'name' => $target['name'], 'content' => $target['content'],
                        'ttl' => (int) ($matching['ttl'] ?? 1), 'proxied' => array_key_exists('proxied', $matching) ? (bool) $matching['proxied'] : !empty($service['proxy_default']),
                    ];
                    $saved = $api->updateDnsRecord($service['zone_id'], (string) $matching['id'], $payload);
                    $this->store($service, $saved, 'SYSTEM_MANAGED'); $updated++;
                }
                continue;
            }
            $record = $api->createDnsRecord($service['zone_id'], [
                'type' => $target['type'], 'name' => $target['name'], 'content' => $target['content'], 'ttl' => 1,
                'proxied' => !empty($service['proxy_default']),
            ]);
            $this->store($service, $record, 'SYSTEM_MANAGED'); $updated++;
        }
        return ['updated' => $updated, 'skipped' => $skipped];
    }

    public function syncRecords(array $service, $api = null)
    {
        if (!$api) list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $remote = $api->listDnsRecords($service['zone_id']); $now = Clock::now(); $seen = [];
        foreach ($remote as $record) {
            if (!isset($record['id'])) continue;
            $seen[] = (string) $record['id'];
            $local = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => (string) $record['id']]);
            $ownership = $local ? (string) $local['ownership'] : 'CUSTOMER_MANAGED';
            $this->store($service, $record, $ownership);
        }
        foreach (Db::all('dns_records', ['service_id' => (int) $service['id']]) as $local) {
            if (!in_array((string) $local['cloudflare_record_id'], $seen, true)) Db::delete('dns_records', ['id' => (int) $local['id'], 'service_id' => (int) $service['id']]);
        }
        return $this->localRecords((int) $service['id']);
    }
    public function localRecords($serviceId)
    {
        return Db::query('SELECT * FROM `' . Db::table('dns_records') . '` WHERE service_id=? ORDER BY name,type,id', [(int) $serviceId]);
    }
    private function store(array $service, array $record, $ownership)
    {
        $id = (string) ($record['id'] ?? '');
        if ($id === '') throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare did not return a DNS record identifier.');
        $existing = Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => $id]);
        $data = [
            'service_id' => (int) $service['id'], 'cloudflare_record_id' => $id,
            'type' => strtoupper((string) ($record['type'] ?? '')), 'name' => strtolower(rtrim((string) ($record['name'] ?? ''), '.')),
            'content' => (string) ($record['content'] ?? ''), 'ttl' => max(1, (int) ($record['ttl'] ?? 1)),
            'proxied' => array_key_exists('proxied', $record) && $record['proxied'] !== null ? (!empty($record['proxied']) ? 1 : 0) : null,
            'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
            'comment' => isset($record['comment']) ? substr((string) $record['comment'], 0, 512) : null,
            'ownership' => $ownership, 'metadata_json' => isset($record['data']) ? json_encode($record['data'], JSON_UNESCAPED_SLASHES) : null,
            'provider_created_at' => self::date($record['created_on'] ?? null), 'provider_updated_at' => self::date($record['modified_on'] ?? null),
            'last_synced_at' => Clock::now(), 'updated_at' => Clock::now(),
        ];
        if ($existing) Db::update('dns_records', ['id' => (int) $existing['id'], 'service_id' => (int) $service['id']], $data);
        else { $data['created_at'] = Clock::now(); Db::insert('dns_records', $data); }
        return Db::first('dns_records', ['service_id' => (int) $service['id'], 'cloudflare_record_id' => $id]);
    }
    private static function date($value)
    {
        if (!$value) return null; $timestamp = strtotime((string) $value); return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }
}
