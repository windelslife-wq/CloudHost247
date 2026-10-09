<?php
/**
 * Customer domain management.
 *
 * The module's domain-services layer over WHMCS' own domain records: it never
 * duplicates tbldomains — it links to them (whmcs_domain_id) and adds the
 * module-managed state the platform does not store (provider mapping, module
 * sync state, DNS record intent, notice deduping).
 *
 * Nameserver and DNS operations go through the configured provider only.
 * With the default WHMCS provider those capabilities are honestly reported as
 * unsupported (WHMCS manages domain DNS at the registrar); the UI shows the
 * capability state instead of a fake editor. When an HTTP registrar provider
 * is configured, the same operations perform real provider calls.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Audit;
use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DomainName;
use Chs\Core\ForbiddenException;
use Chs\Core\Money;
use Chs\Core\NotFoundException;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\Settings;
use Chs\Core\Str;
use Chs\Core\ValidationException;
use Chs\Providers\Domain\DomainProviderInterface;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Workflow\DomainJobTypes;
use Chs\Workflow\JobQueue;

class DomainManagementService
{
    public const DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    /** @var ProviderRegistry|null */
    private $registry;
    /** @var JobQueue|null */
    private $queue;

    public function __construct(ProviderRegistry $registry = null, JobQueue $queue = null)
    {
        $this->registry = $registry;
        $this->queue = $queue;
    }

    protected function registry()
    {
        return $this->registry ?: $this->registry = new ProviderRegistry();
    }

    protected function queue()
    {
        return $this->queue ?: $this->queue = new JobQueue();
    }

    /* ------------------------------------------------------------- listing -- */

    /**
     * The client's domains: module rows merged with live platform data.
     * A light sync runs first so the list reflects WHMCS' own records.
     *
     * @return array[]
     */
    public function listFor($clientId)
    {
        $clientId = (int) $clientId;
        $this->syncClient($clientId);
        return Db::all('domain_services', ['client_id' => $clientId], 'domain ASC');
    }

    /** Ownership-checked single domain. */
    public function getFor($clientId, $domainServiceId)
    {
        $row = Db::first('domain_services', ['id' => (int) $domainServiceId]);
        if (!$row || (int) $row['client_id'] !== (int) $clientId) {
            throw new NotFoundException('Domain not found.');
        }
        return $row;
    }

    /** Detail view model: adds capability flags, DNS intent rows, events. */
    public function detailFor($clientId, $domainServiceId)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $provider = $this->registry()->forTld($row['tld']);
        $caps = $provider->capabilities();
        $events = new DomainEventService();
        return [
            'domain'        => $row,
            'nameservers'   => json_decode((string) $row['nameservers'], true) ?: [],
            'provider'      => $provider->providerName(),
            'provider_id'   => $provider->providerId(),
            'capabilities'  => $caps,
            'dns_enabled'   => Settings::bool('domain_dns_enabled', true),
            'dns_records'   => Db::all('domain_dns_records', ['domain_service_id' => (int) $row['id']], 'id ASC'),
            'history'       => $events->historyForService((int) $row['id'], 50),
            'transfer'      => Db::first('domain_transfers', ['domain' => $row['domain'], 'client_id' => (int) $clientId]),
            'renewals'      => Db::all('domain_renewals', ['domain_service_id' => (int) $row['id']], 'id DESC', 20),
        ];
    }

    /* ----------------------------------------------------------- mutations -- */

    public function setAutoRenew($clientId, $domainServiceId, $on)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $on = $on ? 1 : 0;
        Db::update('domain_services', ['id' => (int) $row['id']], [
            'auto_renew' => $on,
            'updated_at' => Clock::now(),
        ]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_AUTO_RENEW_CHANGED, $row['domain'], (int) $clientId, [
            'auto_renew' => (bool) $on,
        ], (int) $row['id']);
        return true;
    }

    /**
     * WHOIS privacy toggle — pushed to the provider when it supports domain
     * updates; honest ServiceUnavailable otherwise.
     */
    public function setPrivacy($clientId, $domainServiceId, $on)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $provider = $this->registry()->forTld($row['tld']);
        if (empty($provider->capabilities()['update_domain'])) {
            throw new ServiceUnavailableException(
                'WHOIS privacy for this domain is managed at the registrar and cannot be changed here. '
                . 'Contact support or your registrar.'
            );
        }
        try {
            $provider->updateDomain($row['domain'], ['whois_privacy' => (bool) $on]);
        } catch (ProviderNotConfiguredException $e) {
            throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
        }
        Db::update('domain_services', ['id' => (int) $row['id']], [
            'whois_privacy' => $on ? 1 : 0,
            'updated_at'    => Clock::now(),
        ]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_ADMIN_UPDATED, $row['domain'], (int) $clientId, [
            'setting' => 'whois_privacy',
            'value'   => (bool) $on,
        ], (int) $row['id']);
        return true;
    }

    /* --------------------------------------------------------- nameservers -- */

    /** @return array<int,string> */
    public function nameservers($clientId, $domainServiceId)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $provider = $this->registry()->forTld($row['tld']);
        if (empty($provider->capabilities()['get_nameservers'])) {
            $stored = json_decode((string) $row['nameservers'], true);
            return is_array($stored) ? $stored : [];
        }
        try {
            return $provider->getNameservers($row['domain']);
        } catch (ProviderNotConfiguredException $e) {
            throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
        }
    }

    /** @param array<int,string> $nameservers */
    public function updateNameservers($clientId, $domainServiceId, array $nameservers)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $nameservers = $this->validateNameservers($nameservers);
        $provider = $this->registry()->forTld($row['tld']);
        if (empty($provider->capabilities()['update_nameservers'])) {
            throw new ServiceUnavailableException(
                'Nameservers for this domain are managed at the registrar and cannot be changed here.'
            );
        }
        try {
            $provider->updateNameservers($row['domain'], $nameservers);
        } catch (ProviderNotConfiguredException $e) {
            throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
        }
        Db::update('domain_services', ['id' => (int) $row['id']], [
            'nameservers' => json_encode(array_values($nameservers)),
            'updated_at'  => Clock::now(),
        ]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_DNS_UPDATED, $row['domain'], (int) $clientId, [
            'setting'     => 'nameservers',
            'count'       => count($nameservers),
        ], (int) $row['id']);
        return true;
    }

    /* ----------------------------------------------------------------- DNS -- */

    /**
     * Create a DNS record: strict validation, provider call when capable,
     * desired-state row + async sync job otherwise honest about capability.
     */
    public function createDnsRecord($clientId, $domainServiceId, array $input)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        if (!Settings::bool('domain_dns_enabled', true)) {
            throw new ServiceUnavailableException('DNS management is temporarily unavailable.');
        }
        $record = $this->validateDnsRecord($input);
        $provider = $this->registry()->forTld($row['tld']);
        $caps = $provider->capabilities();
        if (empty($caps['create_dns_record'])) {
            // Defense in depth: the UI hides the editor without the
            // capability, and the API refuses too — no fake DNS editor.
            throw new ServiceUnavailableException(
                'DNS records for this domain are managed at the registrar ('
                . $provider->providerName() . '); this interface cannot edit them.'
            );
        }

        $providerRecordId = '';
        $syncStatus = 'pending';
        $lastError = '';
        try {
            $answer = $provider->createDnsRecord($row['domain'], $record);
            $providerRecordId = (string) (isset($answer['id']) ? $answer['id'] : '');
            $syncStatus = 'synced';
        } catch (ProviderNotConfiguredException $e) {
            throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
        } catch (ProviderException $e) {
            // The desired state is still recorded (honest error state) and a
            // sync job retries the push asynchronously.
            $syncStatus = 'error';
            $lastError = substr($e->getMessage(), 0, 255);
        }

        $id = Db::insert('domain_dns_records', [
            'domain_service_id'  => (int) $row['id'],
            'provider_id'        => is_numeric($provider->providerId()) ? (int) $provider->providerId() : null,
            'record_type'        => $record['type'],
            'name'               => $record['name'],
            'value'              => $record['value'],
            'ttl'                => $record['ttl'],
            'priority'           => $record['priority'],
            'provider_record_id' => $providerRecordId,
            'sync_status'        => $syncStatus,
            'last_error'         => $lastError,
            'created_at'         => Clock::now(),
            'updated_at'         => Clock::now(),
        ]);

        if ($syncStatus === 'error') {
            $this->queue()->enqueue(DomainJobTypes::DNS_SYNC, [
                'domain_service_id' => (int) $row['id'],
                'record_id'         => (int) $id,
            ], [
                'idempotency_key' => 'dns-sync-record:' . (int) $id,
                'correlation_id'  => Str::random(12),
                'entity_type'     => 'domain_dns_record',
                'entity_id'       => (int) $id,
            ]);
        }

        (new DomainEventService())->client(DomainEventService::DOMAIN_DNS_UPDATED, $row['domain'], (int) $clientId, [
            'action' => 'create_record',
            'record' => $record,
            'sync'   => $syncStatus,
        ], (int) $row['id']);
        return Db::first('domain_dns_records', ['id' => $id]);
    }

    public function updateDnsRecord($clientId, $domainServiceId, $recordId, array $input)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $existing = Db::first('domain_dns_records', ['id' => (int) $recordId, 'domain_service_id' => (int) $row['id']]);
        if (!$existing) {
            throw new NotFoundException('DNS record not found.');
        }
        $record = $this->validateDnsRecord($input);
        $provider = $this->registry()->forTld($row['tld']);
        $caps = $provider->capabilities();
        if (empty($caps['update_dns_record'])) {
            throw new ServiceUnavailableException(
                'DNS records for this domain are managed at the registrar ('
                . $provider->providerName() . '); this interface cannot edit them.'
            );
        }

        $syncStatus = 'pending';
        $lastError = '';
        try {
            $provider->updateDnsRecord($row['domain'], (string) $existing['provider_record_id'], $record);
            $syncStatus = 'synced';
        } catch (ProviderNotConfiguredException $e) {
            throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
        } catch (ProviderException $e) {
            $syncStatus = 'error';
            $lastError = substr($e->getMessage(), 0, 255);
        }

        Db::update('domain_dns_records', ['id' => (int) $existing['id']], [
            'record_type' => $record['type'],
            'name'        => $record['name'],
            'value'       => $record['value'],
            'ttl'         => $record['ttl'],
            'priority'    => $record['priority'],
            'sync_status' => $syncStatus,
            'last_error'  => $lastError,
            'updated_at'  => Clock::now(),
        ]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_DNS_UPDATED, $row['domain'], (int) $clientId, [
            'action' => 'update_record',
            'record' => $record,
            'sync'   => $syncStatus,
        ], (int) $row['id']);
        return Db::first('domain_dns_records', ['id' => (int) $existing['id']]);
    }

    public function deleteDnsRecord($clientId, $domainServiceId, $recordId)
    {
        $row = $this->getFor($clientId, $domainServiceId);
        $existing = Db::first('domain_dns_records', ['id' => (int) $recordId, 'domain_service_id' => (int) $row['id']]);
        if (!$existing) {
            throw new NotFoundException('DNS record not found.');
        }
        $provider = $this->registry()->forTld($row['tld']);
        $caps = $provider->capabilities();
        if (!empty($caps['delete_dns_record']) && $existing['provider_record_id'] !== '') {
            try {
                $provider->deleteDnsRecord($row['domain'], (string) $existing['provider_record_id']);
            } catch (ProviderNotConfiguredException $e) {
                throw new ServiceUnavailableException('DOMAIN_PROVIDER_NOT_CONFIGURED: ' . $e->getMessage());
            } catch (ProviderException $e) {
                throw new ProviderException('The provider refused the delete: ' . $e->getMessage());
            }
        }
        Db::delete('domain_dns_records', ['id' => (int) $existing['id']]);
        (new DomainEventService())->client(DomainEventService::DOMAIN_DNS_UPDATED, $row['domain'], (int) $clientId, [
            'action' => 'delete_record',
            'record' => ['type' => $existing['record_type'], 'name' => $existing['name'], 'value' => $existing['value']],
        ], (int) $row['id']);
        return true;
    }

    /**
     * DOMAIN_DNS_SYNC handler: push pending record intent to the provider.
     * Records the provider's answer honestly (synced / error with message).
     */
    public function syncDns($domainServiceId)
    {
        $row = Db::first('domain_services', ['id' => (int) $domainServiceId]);
        if (!$row) {
            throw new NotFoundException('Domain not found.');
        }
        $provider = $this->registry()->forTld($row['tld']);
        $caps = $provider->capabilities();
        if (empty($caps['create_dns_record'])) {
            throw new ServiceUnavailableException(
                'DNS sync is unavailable: the configured provider for .' . $row['tld'] . ' does not manage DNS records.'
            );
        }
        // Re-push desired state that has not landed at the provider yet
        // (pending or previously errored records).
        $pending = Db::query(
            'SELECT * FROM ' . Db::t('domain_dns_records')
            . " WHERE domain_service_id = ? AND sync_status IN ('pending','error') ORDER BY id ASC",
            [(int) $row['id']]
        );
        $synced = 0;
        foreach ($pending as $record) {
            $payload = [
                'type'     => $record['record_type'],
                'name'     => $record['name'],
                'value'    => $record['value'],
                'ttl'      => (int) $record['ttl'],
                'priority' => $record['priority'] === null ? null : (int) $record['priority'],
            ];
            try {
                if ($record['provider_record_id'] !== '') {
                    $provider->updateDnsRecord($row['domain'], (string) $record['provider_record_id'], $payload);
                } else {
                    $answer = $provider->createDnsRecord($row['domain'], $payload);
                    Db::update('domain_dns_records', ['id' => (int) $record['id']], [
                        'provider_record_id' => isset($answer['id']) ? (string) $answer['id'] : '',
                    ]);
                }
                Db::update('domain_dns_records', ['id' => (int) $record['id']], [
                    'sync_status' => 'synced',
                    'last_error'  => '',
                    'updated_at'  => Clock::now(),
                ]);
                $synced++;
            } catch (\Throwable $e) {
                Db::update('domain_dns_records', ['id' => (int) $record['id']], [
                    'sync_status' => 'error',
                    'last_error'  => substr($e->getMessage(), 0, 255),
                    'updated_at'  => Clock::now(),
                ]);
            }
        }
        return ['domain_service_id' => (int) $row['id'], 'synced' => $synced, 'pending' => count($pending)];
    }

    /* ------------------------------------------------------- platform sync -- */

    /**
     * Sync module domain rows from WHMCS' own records (DOMAIN_PROVIDER_SYNC /
     * reconciliation jobs). Upserts by domain name; records last-known
     * nameservers where WHMCS exposes them; flags rows whose domain vanished
     * from the platform as missing_at_platform (never silently deleted).
     */
    public function syncFromPlatform()
    {
        if (!Settings::bool('domain_sync_enabled', true)) {
            return 0;
        }
        $synced = 0;
        try {
            $rows = Db::query(
                'SELECT d.id, d.userid, d.domain, d.status, d.registrar, d.expirydate, d.nextduedate, d.autorenew, d.donotrenew
                 FROM tbldomains d ORDER BY d.id'
            );
        } catch (\Throwable $e) {
            return 0; // platform tables unreachable — nothing to sync
        }
        $seen = [];
        foreach ($rows as $row) {
            $fqdn = strtolower((string) $row['domain']);
            if ($fqdn === '') {
                continue;
            }
            $seen[$fqdn] = true;
            $existing = Db::first('domain_services', ['domain' => $fqdn]);
            $tld = substr($fqdn, (int) strrpos($fqdn, '.') + 1);
            $provider = $this->registry()->forTld($tld);
            $data = [
                'client_id'        => (int) $row['userid'],
                'whmcs_domain_id'  => (int) $row['id'],
                'tld'              => $tld,
                'status'           => strtolower((string) $row['status']),
                'registrar'        => substr((string) $row['registrar'], 0, 64),
                'provider_id'      => is_numeric($provider->providerId()) ? (int) $provider->providerId() : null,
                'auto_renew'       => (!empty($row['autorenew']) && empty($row['donotrenew'])) ? 1 : 0,
                'expires_at'       => $row['expirydate'] ? (string) $row['expirydate'] . ' 00:00:00' : null,
                'last_synced_at'   => Clock::now(),
                'updated_at'       => Clock::now(),
            ];
            // Server-side renewal price from the catalogue.
            $catalog = (new TldCatalogService())->detail($tld);
            if ($catalog && $catalog['renew_minor'] !== null) {
                $data['renewal_price_minor'] = (int) $catalog['renew_minor'];
                $data['currency'] = Platform::gateway()->defaultCurrency();
            }
            if ($existing) {
                Db::update('domain_services', ['id' => (int) $existing['id']], $data);
            } else {
                $data['domain'] = $fqdn;
                $data['registered_at'] = $row['nextduedate'] ? (string) $row['nextduedate'] . ' 00:00:00' : null;
                $data['created_at'] = Clock::now();
                Db::insert('domain_services', $data);
            }
            $synced++;
        }

        // Rows whose domain no longer exists at the platform are flagged, not deleted.
        $stale = Db::query(
            'SELECT id, domain FROM ' . Db::t('domain_services')
            . " WHERE status NOT IN ('missing_at_platform','transferring_in')"
        );
        foreach ($stale as $row) {
            if (!isset($seen[strtolower((string) $row['domain'])])) {
                Db::update('domain_services', ['id' => (int) $row['id']], [
                    'status'     => 'missing_at_platform',
                    'updated_at' => Clock::now(),
                ]);
            }
        }
        return $synced;
    }

    /** Sync one client's rows only (used by listFor). */
    public function syncClient($clientId)
    {
        try {
            $domains = Platform::gateway()->clientDomains((int) $clientId);
        } catch (\Throwable $e) {
            return 0;
        }
        $count = 0;
        foreach ($domains as $row) {
            $fqdn = strtolower((string) $row['domain']);
            if ($fqdn === '') {
                continue;
            }
            $existing = Db::first('domain_services', ['domain' => $fqdn]);
            $tld = substr($fqdn, (int) strrpos($fqdn, '.') + 1);
            $data = [
                'client_id'      => (int) $clientId,
                'tld'            => $tld,
                'status'         => strtolower((string) $row['status']),
                'expires_at'     => !empty($row['expiry']) ? (string) $row['expiry'] . ' 00:00:00' : null,
                'last_synced_at' => Clock::now(),
                'updated_at'     => Clock::now(),
            ];
            if ($existing) {
                Db::update('domain_services', ['id' => (int) $existing['id']], $data);
            } else {
                $data['domain'] = $fqdn;
                $data['created_at'] = Clock::now();
                Db::insert('domain_services', $data);
            }
            $count++;
        }
        return $count;
    }

    /** Reconciliation report for the admin overview. */
    public function reconciliationReport()
    {
        return [
            'total'               => Db::count('domain_services'),
            'active'              => Db::count('domain_services', ['status' => 'active']),
            'expiring_30d'        => Db::query(
                'SELECT COUNT(*) AS c FROM ' . Db::t('domain_services')
                . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?",
                [Clock::in(30 * 86400)]
            )[0]['c'] ?? 0,
            'auto_renew_enabled'  => Db::count('domain_services', ['auto_renew' => 1]),
            'missing_at_platform' => Db::count('domain_services', ['status' => 'missing_at_platform']),
            'dns_records'         => Db::count('domain_dns_records'),
            'dns_pending_sync'    => Db::count('domain_dns_records', ['sync_status' => 'pending']),
            'dns_errors'          => Db::count('domain_dns_records', ['sync_status' => 'error']),
        ];
    }

    /* ---------------------------------------------------------- validation -- */

    /** @param array<int,string> $nameservers @return array<int,string> */
    public function validateNameservers(array $nameservers)
    {
        $out = [];
        foreach ($nameservers as $ns) {
            $ns = strtolower(trim((string) $ns));
            if ($ns === '') {
                continue;
            }
            if (DomainName::tryParse($ns) === null) {
                throw new ValidationException(['nameservers' => '"' . $ns . '" is not a valid nameserver hostname.']);
            }
            $out[] = $ns;
        }
        if (count($out) < 2) {
            throw new ValidationException(['nameservers' => 'At least two nameservers are required.']);
        }
        if (count($out) > 13) {
            throw new ValidationException(['nameservers' => 'At most 13 nameservers are allowed.']);
        }
        return array_values(array_unique($out));
    }

    /**
     * Validate one DNS record (type + value + ttl + priority).
     *
     * @return array{type:string,name:string,value:string,ttl:int,priority:?int}
     */
    public function validateDnsRecord(array $input)
    {
        $errors = [];
        $type = strtoupper(trim((string) (isset($input['type']) ? $input['type'] : '')));
        if (!in_array($type, self::DNS_TYPES, true)) {
            $errors['type'] = 'Record type must be one of: ' . implode(', ', self::DNS_TYPES) . '.';
        }

        $name = strtolower(trim((string) (isset($input['name']) ? $input['name'] : '')));
        if ($name === '' || strlen($name) > 255) {
            $errors['name'] = 'A record name is required (max 255 characters). Use @ for the apex.';
        }

        $value = trim((string) (isset($input['value']) ? $input['value'] : ''));
        if ($value === '' || strlen($value) > 1024) {
            $errors['value'] = 'A record value is required (max 1024 characters).';
        } elseif ($type !== '' && !$this->validDnsValue($type, $value)) {
            $errors['value'] = 'That value is not valid for a ' . $type . ' record.';
        }

        $ttl = isset($input['ttl']) && $input['ttl'] !== '' ? (int) $input['ttl'] : 3600;
        if ($ttl < 60 || $ttl > 604800) {
            $errors['ttl'] = 'TTL must be between 60 and 604800 seconds.';
        }

        $priority = null;
        if (in_array($type, ['MX', 'SRV'], true)) {
            $priority = isset($input['priority']) && $input['priority'] !== '' ? (int) $input['priority'] : null;
            if ($priority === null || $priority < 0 || $priority > 65535) {
                $errors['priority'] = 'A priority between 0 and 65535 is required for ' . $type . ' records.';
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return [
            'type'     => $type,
            'name'     => $name,
            'value'    => $value,
            'ttl'      => $ttl,
            'priority' => $priority,
        ];
    }

    /** Type-specific value validation. */
    public function validDnsValue($type, $value)
    {
        switch ($type) {
            case 'A':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            case 'AAAA':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            case 'CNAME':
            case 'NS':
                return DomainName::tryParse($value) !== null;
            case 'MX':
                return DomainName::tryParse($value) !== null;
            case 'TXT':
                return mb_strlen($value, 'UTF-8') <= 255;
            case 'SRV':
                // weight port target — validated as a unit by the caller shape;
                // here we require three numeric-ish leading fields + a target.
                return preg_match('/^\d+\s+\d+\s+\d+\s+[^\s]+$/', $value) === 1
                    && DomainName::tryParse(substr($value, (int) strrpos($value, ' ') + 1)) !== null;
            case 'CAA':
                // flags tag "value"
                return preg_match('/^\d+\s+[a-z]+\s+"[^"]+"$/i', $value) === 1;
            default:
                return false;
        }
    }
}
