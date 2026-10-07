<?php
namespace CloudHost247\Cloudflare\Service;

use CloudHost247\Cloudflare\Core\Audit;
use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\CloudflareException;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Repository\AccountRepository;
use CloudHost247\Cloudflare\Repository\PackageRepository;
use CloudHost247\Cloudflare\Repository\ServiceRepository;

/** WHMCS service lifecycle adapter; does not create or mutate billing records. */
class ProvisioningService
{
    private $accounts; private $packages; private $services; private $dns;
    public function __construct(AccountRepository $accounts = null, PackageRepository $packages = null, ServiceRepository $services = null, DnsRecordService $dns = null)
    {
        $this->accounts = $accounts ?: new AccountRepository(); $this->packages = $packages ?: new PackageRepository();
        $this->services = $services ?: new ServiceRepository(); $this->dns = $dns ?: new DnsRecordService($this->services, $this->accounts);
    }

    public function provisionHosting($whmcsServiceId)
    {
        IntegrationStatus::requireEnabled();
        $hosting = BillingGuard::paidHostingService((int) $whmcsServiceId);
        $mapping = $this->packages->forProduct((int) $hosting['packageid']);
        if (!$mapping) throw new ConfigurationException('This WHMCS product has no enabled Cloudflare package mapping.');
        $domain = DomainName::normalize($hosting['domain']);
        $account = $this->accountForMapping($mapping);
        $domainId = $this->domainId((int) $hosting['userid'], $domain);
        $service = $this->ensureService([
            'whmcs_service_id' => (int) $hosting['id'], 'whmcs_addon_id' => null, 'origin_hosting_id' => null,
            'customer_id' => (int) $hosting['userid'], 'whmcs_product_id' => (int) $hosting['packageid'],
            'package_mapping_id' => (int) $mapping['id'], 'domain_id' => $domainId,
            'account_id' => (int) $account['id'], 'zone_name' => $domain,
        ], $mapping);
        $origin = $hosting;
        return $this->provision($service, $mapping, $account, $origin, 'system');
    }

    /** Native tblhostingaddons integration. This is queued by InvoicePaid, never from cart submission. */
    public function provisionAddon($addonInstanceId)
    {
        IntegrationStatus::requireEnabled();
        $addon = BillingGuard::activeAddon((int) $addonInstanceId);
        $mapping = $this->packages->forAddon((int) $addon['addonid']);
        if (!$mapping) throw new ConfigurationException('This WHMCS addon has no enabled Cloudflare package mapping.');
        $domain = DomainName::normalize($addon['domain']);
        $account = $this->accountForMapping($mapping);
        $domainId = $this->domainId((int) $addon['userid'], $domain);
        $service = $this->ensureService([
            'whmcs_service_id' => null, 'whmcs_addon_id' => (int) $addon['id'], 'origin_hosting_id' => (int) $addon['hostingid'],
            'customer_id' => (int) $addon['userid'], 'whmcs_product_id' => null,
            'package_mapping_id' => (int) $mapping['id'], 'domain_id' => $domainId,
            'account_id' => (int) $account['id'], 'zone_name' => $domain,
        ], $mapping);
        return $this->provision($service, $mapping, $account, $addon, 'system');
    }

    public function retry($internalServiceId, $actorType = 'admin', $actorId = 0)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare service was not found.');
        if (!empty($service['whmcs_service_id'])) $result = $this->provisionHosting((int) $service['whmcs_service_id']);
        elseif (!empty($service['whmcs_addon_id'])) $result = $this->provisionAddon((int) $service['whmcs_addon_id']);
        else throw new ConfigurationException('The Cloudflare service has no linked WHMCS service.');
        Audit::record($actorType, $actorId, 'PROVISIONING_RETRIED', 'cloudflare_service', $internalServiceId, $service['customer_id'], $internalServiceId, true);
        return $result;
    }

    public function sync($internalServiceId, $actorType = 'admin', $actorId = 0)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service || empty($service['zone_id'])) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare zone was not found.');
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $zone = $api->getZone($service['zone_id']);
        if (strtolower((string) ($zone['name'] ?? '')) !== strtolower((string) $service['zone_name'])) throw new CloudflareException('CLOUDFLARE_ZONE_NOT_FOUND', 'The Cloudflare zone does not match this service.');
        $dnsRecords = $this->dns->syncRecords($service, $api);
        $state = ZoneState::fromProvider($zone);
        $nameservers = $this->nameservers($zone, $this->accounts->find((int) $service['account_id']));
        $this->services->update((int) $service['id'], [
            'status' => $state['status'], 'activation_status' => $state['activation_status'],
            'provisioning_status' => 'complete', 'nameservers_json' => $nameservers ? json_encode($nameservers) : null,
            'last_synced_at' => Clock::now(), 'last_error_code' => null, 'last_error_message' => null,
        ]);
        Audit::record($actorType, $actorId, 'CLOUDFLARE_SERVICE_SYNCED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['zone_id' => $service['zone_id'], 'dns_records' => count($dnsRecords), 'zone_status' => $state['status']]);
        return ['service' => $this->services->find($service['id']), 'zone' => $zone, 'dns_records' => $dnsRecords];
    }

    public function syncOriginIp($internalServiceId, $actorType = 'admin', $actorId = 0)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service || empty($service['origin_hosting_id'])) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Linked hosting service was not found.');
        $hosting = Db::firstQuery('SELECT id,userid,packageid,domain,domainstatus,dedicatedip,serverip FROM tblhosting WHERE id=? AND userid=? LIMIT 1', [(int) $service['origin_hosting_id'], (int) $service['customer_id']]);
        if (!$hosting || strtolower((string) $hosting['domainstatus']) !== 'active') throw new \CloudHost247\Cloudflare\Core\AuthorizationException('The linked hosting service is not active.');
        $ip = filter_var((string) ($hosting['dedicatedip'] ?? ''), FILTER_VALIDATE_IP) ? $hosting['dedicatedip'] : $hosting['serverip'];
        $result = $this->dns->synchronizeOrigin($service, $ip);
        Audit::record($actorType, $actorId, 'HOSTING_DNS_SYNCHRONIZED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['records_updated' => $result['updated'], 'records_skipped' => $result['skipped']]);
        return $result;
    }

    public function changePlan($whmcsServiceId, $newProductId = null, $actorType = 'system', $actorId = 0)
    {
        $hosting = BillingGuard::paidHostingService((int) $whmcsServiceId);
        $service = $this->services->findByHostingId((int) $whmcsServiceId);
        if (!$service || empty($service['zone_id'])) throw new ConfigurationException('Cloudflare service has not been provisioned.');
        Features::require($service, 'plan.change');
        $productId = (int) ($newProductId ?: $hosting['packageid']);
        $mapping = $this->packages->forProduct($productId);
        if (!$mapping) throw new ConfigurationException('The target WHMCS product has no enabled Cloudflare plan mapping.');
        $account = $this->accountForMapping($mapping);
        if ((int) $account['id'] !== (int) $service['account_id']) throw new ConfigurationException('Changing the Cloudflare account is not supported as part of a plan upgrade. Contact support.');
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        // Called by WHMCS ChangePackage, after WHMCS has generated and confirmed the upgrade invoice.
        $api->changePlan($service['zone_id'], $mapping['provider_plan_id']);
        $this->services->update((int) $service['id'], [
            'whmcs_product_id' => $productId, 'package_mapping_id' => (int) $mapping['id'],
            'provider_plan_id' => $mapping['provider_plan_id'], 'plan_label' => $mapping['plan_label'],
            'features_json' => json_encode($mapping['features'], JSON_UNESCAPED_SLASHES),
            'proxy_default' => isset($mapping['proxy_default']) ? (int) $mapping['proxy_default'] : (int) $service['proxy_default'],
            'ssl_mode' => $mapping['ssl_mode'] ?: $service['ssl_mode'], 'last_error_code' => null, 'last_error_message' => null,
        ]);
        Audit::record($actorType, $actorId, 'PLAN_CHANGED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['from' => $service['provider_plan_id'], 'to' => $mapping['provider_plan_id']]);
        return $this->services->find($service['id']);
    }

    public function suspend($internalServiceId, $actorType, $actorId)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare service was not found.');
        if (strtoupper((string) $service['status']) === 'SUSPENDED') return $service;
        if (!empty($service['zone_id'])) {
            list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
            $api->updateZonePaused($service['zone_id'], true);
        }
        $this->services->update((int) $service['id'], ['status' => 'SUSPENDED', 'provisioning_status' => 'suspended', 'suspended_at' => Clock::now()]);
        Audit::record($actorType, $actorId, 'CLOUDFLARE_SERVICE_SUSPENDED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['zone_id' => $service['zone_id']]);
        return $this->services->find($service['id']);
    }
    public function unsuspend($internalServiceId, $actorType, $actorId)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service || empty($service['zone_id'])) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare zone was not found.');
        list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
        $zone = $api->updateZonePaused($service['zone_id'], false);
        $state = ZoneState::fromProvider($zone);
        $this->services->update((int) $service['id'], ['status' => $state['status'], 'provisioning_status' => 'complete', 'suspended_at' => null]);
        Audit::record($actorType, $actorId, 'CLOUDFLARE_SERVICE_UNSUSPENDED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['zone_id' => $service['zone_id']]);
        return $this->services->find($service['id']);
    }
    public function terminate($internalServiceId, $actorType, $actorId)
    {
        $service = $this->services->find((int) $internalServiceId);
        if (!$service) throw new \CloudHost247\Cloudflare\Core\NotFoundException('Cloudflare service was not found.');
        $this->services->update((int) $service['id'], ['status' => 'TERMINATING', 'provisioning_status' => 'terminating']);
        $account = $this->accounts->find((int) $service['account_id']);
        if ($account && !empty($account['delete_zone_on_terminate']) && !empty($service['zone_id'])) {
            list($api) = $this->accounts->api((int) $service['account_id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
            try { $api->deleteZone($service['zone_id']); }
            catch (CloudflareException $e) { if ($e->errorCode() !== 'CLOUDFLARE_ZONE_NOT_FOUND') throw $e; }
        }
        $this->services->update((int) $service['id'], ['status' => 'TERMINATED', 'provisioning_status' => 'terminated', 'terminated_at' => Clock::now()]);
        Audit::record($actorType, $actorId, 'CLOUDFLARE_SERVICE_TERMINATED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['zone_deleted' => $account && !empty($account['delete_zone_on_terminate'])]);
        return $this->services->find($service['id']);
    }

    private function provision(array $service, array $mapping, array $account, array $hosting, $actorType)
    {
        try {
            $service = $this->services->find((int) $service['id']);
            if (!empty($service['zone_id']) && in_array(strtoupper((string) $service['status']), ['ACTIVE','PENDING_NAMESERVER_UPDATE'], true)) {
                // A repeated module callback is idempotent; sync is the repair path.
                return $service;
            }
            $this->services->update((int) $service['id'], ['status' => 'PROVISIONING', 'provisioning_status' => 'provisioning', 'last_error_code' => null, 'last_error_message' => null]);
            list($api) = $this->accounts->api((int) $account['id'], ['service_id' => (int) $service['id'], 'customer_id' => (int) $service['customer_id']]);
            $zone = null;
            if (!empty($service['zone_id'])) {
                try { $zone = $api->getZone($service['zone_id']); }
                catch (CloudflareException $e) { if ($e->errorCode() !== 'CLOUDFLARE_ZONE_NOT_FOUND') throw $e; }
            }
            if (!$zone) $zone = $api->findZone($service['zone_name']);
            if (!$zone) {
                try { $zone = $api->createZone($service['zone_name'], $mapping['zone_mode'] ?: $account['zone_mode'], !empty($mapping['dns_discovery'])); }
                catch (CloudflareException $e) {
                    // Race-safe create: another retry/admin request may have created it.
                    $zone = $api->findZone($service['zone_name']);
                    if (!$zone) throw $e;
                }
            }
            if (empty($zone['id']) || strtolower(rtrim((string) ($zone['name'] ?? ''), '.')) !== strtolower((string) $service['zone_name'])) throw new CloudflareException('CLOUDFLARE_API_ERROR', 'Cloudflare returned a zone that did not match the requested domain.');
            $cloudflareAccount = (string) ($zone['account']['id'] ?? '');
            if ($cloudflareAccount !== '' && !hash_equals((string) $account['account_id'], $cloudflareAccount)) throw new CloudflareException('CLOUDFLARE_PERMISSION_DENIED', 'The zone belongs to a different Cloudflare account.', 403);
            $service['zone_id'] = (string) $zone['id'];
            $service['zone_name'] = strtolower(rtrim((string) $zone['name'], '.'));
            $this->services->update((int) $service['id'], ['zone_id' => $service['zone_id'], 'zone_name' => $service['zone_name'], 'provider_plan_id' => $mapping['provider_plan_id'], 'plan_label' => $mapping['plan_label']]);
            $ip = $this->originIp($hosting);
            if ($ip !== '') $this->dns->synchronizeOrigin($service, $ip);
            $sslMode = $mapping['ssl_mode'] ?: $account['ssl_mode'];
            if (!empty($mapping['features']['ssl.manage'])) $api->setSetting($service['zone_id'], 'ssl', $sslMode);
            if (!empty($mapping['cache_level'])) $api->setSetting($service['zone_id'], 'cache_level', $mapping['cache_level']);
            if (!empty($mapping['browser_cache_ttl'])) $api->setSetting($service['zone_id'], 'browser_cache_ttl', (int) $mapping['browser_cache_ttl']);
            $zone = $api->getZone($service['zone_id']);
            $state = ZoneState::fromProvider($zone);
            $nameservers = $this->nameservers($zone, $account);
            $this->services->update((int) $service['id'], [
                'status' => $state['status'], 'activation_status' => $state['activation_status'], 'provisioning_status' => 'complete',
                'nameservers_json' => $nameservers ? json_encode($nameservers) : null, 'last_synced_at' => Clock::now(),
                'last_error_code' => null, 'last_error_message' => null,
            ]);
            Audit::record($actorType, 0, 'CLOUDFLARE_ZONE_PROVISIONED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], true, ['zone_id' => $service['zone_id'], 'domain' => $service['zone_name'], 'status' => $state['status']]);
            return $this->services->find((int) $service['id']);
        } catch (\Throwable $e) {
            $code = $e instanceof CloudflareException ? $e->errorCode() : 'CLOUDFLARE_API_ERROR';
            $message = $e instanceof CloudflareException ? $e->getMessage() : 'Cloudflare provisioning failed. Check the Cloudflare integration logs.';
            $this->services->update((int) $service['id'], ['status' => 'PROVISIONING_FAILED', 'provisioning_status' => 'failed', 'last_error_code' => $code, 'last_error_message' => substr($message, 0, 500)]);
            Audit::record($actorType, 0, 'CLOUDFLARE_PROVISIONING_FAILED', 'cloudflare_service', $service['id'], $service['customer_id'], $service['id'], false, ['zone_id' => $service['zone_id'] ?? null], $code);
            if (in_array($code, ['CLOUDFLARE_TIMEOUT','CLOUDFLARE_RATE_LIMITED','SERVICE_UNAVAILABLE'], true)) JobQueue::enqueue('provision_service', (int) $service['id'], [], 'provision:' . (int) $service['id']);
            throw $e;
        }
    }

    private function ensureService(array $seed, array $mapping)
    {
        $existing = !empty($seed['whmcs_service_id']) ? $this->services->findByHostingId($seed['whmcs_service_id']) : $this->services->findByAddonId($seed['whmcs_addon_id']);
        $data = $seed + [
            'zone_id' => null, 'provider_plan_id' => (string) $mapping['provider_plan_id'], 'plan_label' => (string) $mapping['plan_label'],
            'status' => 'PENDING', 'activation_status' => 'PENDING', 'provisioning_status' => 'pending', 'nameservers_json' => null,
            'features_json' => json_encode($mapping['features'], JSON_UNESCAPED_SLASHES), 'proxy_default' => isset($mapping['proxy_default']) ? (int) $mapping['proxy_default'] : 1,
            'ssl_mode' => $mapping['ssl_mode'] ?: 'full', 'development_mode' => 0, 'development_mode_changed_at' => null,
            'last_synced_at' => null, 'last_error_code' => null, 'last_error_message' => null, 'suspended_at' => null, 'terminated_at' => null,
        ];
        if ($existing) {
            if (!empty($existing['zone_id']) && (int) $existing['account_id'] !== (int) $seed['account_id']) throw new ConfigurationException('A Cloudflare service cannot be reassigned to another account after its zone exists.');
            if (!empty($existing['zone_id']) && in_array(strtoupper((string) $existing['status']), ['ACTIVE','PENDING_NAMESERVER_UPDATE'], true)) return $existing;
            $data['zone_id'] = $existing['zone_id'];
            $data['nameservers_json'] = $existing['nameservers_json'];
            $data['last_synced_at'] = $existing['last_synced_at'];
            $data['updated_at'] = Clock::now();
            $this->services->update((int) $existing['id'], $data);
            return $this->services->find((int) $existing['id']);
        }
        $data['created_at'] = Clock::now(); $data['updated_at'] = Clock::now();
        try { $id = Db::insert('services', $data); }
        catch (\Throwable $e) {
            $existing = !empty($seed['whmcs_service_id']) ? $this->services->findByHostingId($seed['whmcs_service_id']) : $this->services->findByAddonId($seed['whmcs_addon_id']);
            if (!$existing) throw $e;
            return $existing;
        }
        return $this->services->find($id);
    }

    private function accountForMapping(array $mapping)
    {
        $account = !empty($mapping['account_id']) ? $this->accounts->find((int) $mapping['account_id']) : Db::first('accounts', ['enabled' => 1], 'id ASC');
        if (!$account || empty($account['enabled'])) throw new ConfigurationException('No enabled Cloudflare account is assigned to this product plan.');
        return $account;
    }
    private function originIp(array $hosting)
    {
        foreach (['dedicatedip','serverip'] as $field) {
            if (!empty($hosting[$field]) && filter_var(trim((string) $hosting[$field]), FILTER_VALIDATE_IP)) return trim((string) $hosting[$field]);
        }
        return '';
    }
    private function nameservers(array $zone, array $account)
    {
        $nameservers = isset($zone['name_servers']) && is_array($zone['name_servers']) ? array_values(array_filter(array_map('strval', $zone['name_servers']))) : [];
        if (!$nameservers && !empty($account['default_nameservers'])) {
            $fallback = json_decode((string) $account['default_nameservers'], true);
            if (is_array($fallback)) $nameservers = array_values(array_filter(array_map('strval', $fallback)));
        }
        return $nameservers;
    }
    private function domainId($customerId, $domain)
    {
        $row = Db::firstQuery('SELECT id,userid FROM tbldomains WHERE LOWER(domain)=? LIMIT 1', [(string) $domain]);
        if ($row && (int) $row['userid'] !== (int) $customerId) throw new \CloudHost247\Cloudflare\Core\AuthorizationException('The requested domain belongs to another customer.');
        return $row ? (int) $row['id'] : null;
    }
}
