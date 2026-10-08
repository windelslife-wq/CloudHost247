<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Audit;
use CloudHost247\Cloudflare\Core\AuthorizationException;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\NotFoundException;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;

/**
 * Read-only DNS inventory for a customer-owned Cloudflare service.
 *
 * This is an internal service boundary only: it deliberately adds no route,
 * adapter registration, DNS cache writes, or DNS mutation operations.
 */
class DnsInventoryService
{
    private $services;
    private $accounts;

    /**
     * Cloudflare's DNS record types supported by its v4 DNS-record API. This
     * inventory list is broader than the addon's editable-record form.
     */
    private const RECORD_TYPES = [
        'A', 'AAAA', 'CAA', 'CERT', 'CNAME', 'DNSKEY', 'DS', 'HTTPS', 'LOC',
        'MX', 'NAPTR', 'NS', 'OPENPGPKEY', 'PTR', 'SMIMEA', 'SPF', 'SRV',
        'SSHFP', 'SVCB', 'TLSA', 'TXT', 'URI',
    ];

    public function __construct(ServiceRepository $services = null, AccountRepository $accounts = null)
    {
        $this->services = $services ?: new ServiceRepository();
        $this->accounts = $accounts ?: new AccountRepository();
    }

    /** Resolve one unambiguous customer-owned Cloudflare service by its linked zone name. */
    public function listForDomain($customerId, $domain)
    {
        $customerId = (int) $customerId;
        if ($customerId <= 0) throw new NotFoundException('Cloudflare service not found.');
        if (!is_string($domain)) throw new ValidationException('Enter a valid domain name for this Cloudflare service.');
        $domain = DomainName::normalize($domain);
        $rows = $this->services->listForCustomer($customerId);
        if (!is_array($rows)) throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare services could not be resolved.');
        $matches = [];
        foreach ($rows as $candidate) {
            if (!is_array($candidate) || (int) ($candidate['customer_id'] ?? 0) !== $customerId
                || (int) ($candidate['id'] ?? 0) <= 0) continue;
            try { $candidateName = DomainName::normalize($candidate['zone_name'] ?? ''); }
            catch (\Throwable $e) { continue; }
            if ($candidateName === $domain) $matches[(int) $candidate['id']] = true;
        }
        if (!$matches) throw new NotFoundException('Cloudflare service not found.');
        if (count($matches) !== 1) {
            throw new ValidationException('This domain maps to multiple Cloudflare services; a specific service is required.');
        }
        $serviceIds = array_keys($matches);
        return $this->listForCustomer($customerId, (int) $serviceIds[0]);
    }

    /**
     * Fetch a complete, validated DNS inventory for a service owned by this
     * WHMCS customer. The method fails closed on disabled gates or any
     * malformed/partial Cloudflare response.
     *
     * @return array{service_id:int,zone_id:string,zone_name:string,records:array}
     */
    public function listForCustomer($customerId, $serviceId)
    {
        $customerId = (int) $customerId;
        $serviceId = (int) $serviceId;
        if ($customerId <= 0 || $serviceId <= 0) {
            throw new NotFoundException('Cloudflare service not found.');
        }

        // This repository verifies both the addon row and its live WHMCS owner.
        $service = $this->services->forCustomer($serviceId, $customerId);
        if (!is_array($service) || (int) ($service['id'] ?? 0) !== $serviceId
            || (int) ($service['customer_id'] ?? 0) !== $customerId) {
            throw new NotFoundException('Cloudflare service not found.');
        }
        Features::require($service, 'dns.manage');
        IntegrationStatus::requireEnabled();
        IntegrationStatus::requireDnsInventoryAdapterEnabled();

        try {
            $zoneId = $this->localZoneId($service['zone_id'] ?? null);
            $zoneName = $this->serviceZoneName($service);
            if ((int) ($service['account_id'] ?? 0) <= 0) {
                throw new ValidationException('The Cloudflare service account mapping is invalid.');
            }

            list($api, $account) = $this->accounts->api((int) $service['account_id'], [
                'service_id' => $serviceId,
                'customer_id' => $customerId,
            ]);
            $providerAccountId = is_array($account)
                ? strtolower(trim((string) ($account['account_id'] ?? ''))) : '';
            if (!preg_match('/^[a-f0-9]{32}$/', $providerAccountId)) {
                throw new ValidationException('The configured Cloudflare account identity is invalid.');
            }

            $zone = $api->getZone($zoneId);
            $this->validateProviderZone($zone, $zoneId, $zoneName, $providerAccountId);

            $records = $api->listDnsRecords($zoneId);
            $validatedRecords = $this->validateRecords($records, $zoneName);
            $typeCounts = [];
            foreach ($validatedRecords as $record) {
                $typeCounts[$record['type']] = ($typeCounts[$record['type']] ?? 0) + 1;
            }
            ksort($typeCounts, SORT_STRING);

            // Intentionally record counts/types only; DNS contents and record
            // names can contain customer secrets and never enter audit metadata.
            Audit::record('client', $customerId, 'DNS_INVENTORY_READ', 'cloudflare_service',
                $serviceId, $customerId, $serviceId, true, [
                    'record_count' => count($validatedRecords),
                    'record_types' => $typeCounts,
                ]);

            return [
                'service_id' => $serviceId,
                'zone_id' => $zoneId,
                'zone_name' => $zoneName,
                'records' => $validatedRecords,
            ];
        } catch (\Throwable $e) {
            $errorCode = $e instanceof CloudflareException ? $e->errorCode() : 'CLOUDFLARE_API_ERROR';
            Audit::record('client', $customerId, 'DNS_INVENTORY_READ', 'cloudflare_service',
                $serviceId, $customerId, $serviceId, false, [], $errorCode);
            throw $e;
        }
    }

    private function localZoneId($zoneId)
    {
        $zoneId = strtolower(trim((string) $zoneId));
        if (!preg_match('/^[a-f0-9]{32}$/', $zoneId)) {
            throw new ValidationException('This Cloudflare service has an invalid zone mapping.');
        }
        return $zoneId;
    }

    private function serviceZoneName(array $service)
    {
        $zoneName = DomainName::normalize($service['zone_name'] ?? '');
        $linkedDomain = trim((string) ($service['origin_domain'] ?? ''));
        if ($linkedDomain === '') $linkedDomain = trim((string) ($service['addon_parent_domain'] ?? ''));
        if ($linkedDomain === '') {
            throw new AuthorizationException('This Cloudflare zone is not linked to the requested WHMCS service.');
        }
        if (DomainName::normalize($linkedDomain) !== $zoneName) {
            throw new AuthorizationException('This Cloudflare zone is not linked to the requested WHMCS service.');
        }
        return $zoneName;
    }

    private function validateProviderZone($zone, $expectedId, $expectedName, $expectedAccountId)
    {
        if (!is_array($zone) || !isset($zone['id'], $zone['name'], $zone['status'])
            || !is_string($zone['id']) || !is_string($zone['name']) || !is_string($zone['status'])
            || !array_key_exists('paused', $zone) || !is_bool($zone['paused'])
            || !isset($zone['account']) || !is_array($zone['account'])
            || !isset($zone['account']['id']) || !is_string($zone['account']['id'])) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS inventory zone.');
        }

        try {
            $actualName = DomainName::normalize($zone['name']);
        } catch (\Throwable $e) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS inventory zone.');
        }
        $actualId = strtolower($zone['id']);
        $actualAccountId = strtolower($zone['account']['id']);
        $status = strtolower($zone['status']);
        if (!preg_match('/^[a-f0-9]{32}$/', $actualId)
            || $actualId !== $expectedId
            || $actualName !== $expectedName
            || !preg_match('/^[a-f0-9]{32}$/', $actualAccountId)
            || $actualAccountId !== $expectedAccountId
            || !in_array($status, ['active', 'pending', 'initializing'], true)
            || $zone['paused']) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare zone identity or state did not match the linked service.');
        }
    }

    private function validateRecords($records, $zoneName)
    {
        if (!is_array($records)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS inventory.');
        }

        $validated = [];
        $expectedKey = 0;
        $seenIds = [];
        foreach ($records as $key => $record) {
            if ($key !== $expectedKey || !is_array($record)) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS inventory.');
            }
            $expectedKey++;
            $validatedRecord = $this->validateRecord($record, $zoneName);
            $normalizedId = strtolower($validatedRecord['id']);
            if (isset($seenIds[$normalizedId])) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned a duplicate DNS record identifier.');
            }
            $seenIds[$normalizedId] = true;
            $validated[] = $validatedRecord;
        }
        return $validated;
    }

    private function validateRecord(array $record, $zoneName)
    {
        $id = $record['id'] ?? null;
        $type = $record['type'] ?? null;
        $name = $record['name'] ?? null;
        $content = $record['content'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id)
            || !is_string($type) || !is_string($name) || !is_string($content)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record.');
        }

        $type = strtoupper(trim($type));
        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an unsupported DNS record type.');
        }
        $name = $this->recordName($name, $zoneName);
        if (trim($content) === '' || strlen($content) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $content)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned invalid DNS record content.');
        }

        if ($type === 'A' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid A record.');
        }
        if ($type === 'AAAA' && !filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid AAAA record.');
        }
        if (in_array($type, ['CNAME', 'MX', 'NS', 'PTR'], true)) {
            try {
                if (trim($content) !== $content) throw new \InvalidArgumentException();
                DomainName::normalize($content);
            } catch (\Throwable $e) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS target.');
            }
        }
        if ($type === 'TXT' && strlen($content) > 2048) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an oversized TXT record.');
        }
        if ($type === 'CAA' && !preg_match('/^(?:0|128)\s+(?:issue|issuewild|iodef)\s+.{1,500}$/i', $content)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid CAA record.');
        }
        if ($type === 'SRV') {
            if (!preg_match('/^(\d{1,5})\s+(\d{1,5})\s+([a-z0-9.-]+)$/i', $content, $srv)
                || (int) $srv[1] > 65535 || (int) $srv[2] < 1 || (int) $srv[2] > 65535) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid SRV record.');
            }
            try {
                DomainName::normalize($srv[3]);
            } catch (\Throwable $e) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid SRV target.');
            }
        }

        $ttl = $record['ttl'] ?? null;
        if ((!is_int($ttl) && !(is_string($ttl) && ctype_digit($ttl)))
            || ((int) $ttl !== 1 && ((int) $ttl < 60 || (int) $ttl > 86400))) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record TTL.');
        }
        $ttl = (int) $ttl;

        if (!array_key_exists('proxied', $record)
            || (!is_bool($record['proxied']) && $record['proxied'] !== null)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS proxy flag.');
        }
        $proxied = $record['proxied'];
        if ($proxied === true && !in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS proxy flag.');
        }

        $priority = null;
        if (in_array($type, ['MX', 'SRV'], true)) {
            $priorityValue = $record['priority'] ?? null;
            if ((!is_int($priorityValue) && !(is_string($priorityValue) && ctype_digit($priorityValue)))
                || (int) $priorityValue < 0 || (int) $priorityValue > 65535) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record priority.');
            }
            $priority = (int) $priorityValue;
        } elseif (array_key_exists('priority', $record) && $record['priority'] !== null) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record priority.');
        }

        $comment = $record['comment'] ?? null;
        if ($comment !== null && (!is_string($comment) || strlen($comment) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $comment))) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record comment.');
        }

        return [
            'id' => strtolower($id),
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
            'priority' => $priority,
            'comment' => $comment,
        ];
    }

    /** Validate a provider-returned FQDN and keep it strictly inside the zone. */
    private function recordName($name, $zoneName)
    {
        if ($name === '' || strlen($name) > 254 || trim($name) !== $name
            || strpos($name, '@') !== false || substr($name, -2) === '..') {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned a DNS record outside the linked zone.');
        }
        $normalized = strtolower(rtrim($name, '.'));
        if ($normalized === '' || strlen($normalized) > 253
            || ($normalized !== $zoneName && substr($normalized, -strlen('.' . $zoneName)) !== '.' . $zoneName)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned a DNS record outside the linked zone.');
        }

        $labels = explode('.', $normalized);
        foreach ($labels as $index => $label) {
            if ($label === '*' && $index === 0) continue;
            $valid = preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|_[a-z0-9](?:[a-z0-9-]{0,60}[a-z0-9])?)$/', $label);
            if (!$valid) {
                throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record name.');
            }
        }
        if (in_array('*', array_slice($labels, 1), true)) {
            throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned an invalid DNS record name.');
        }
        return $normalized;
    }
}
