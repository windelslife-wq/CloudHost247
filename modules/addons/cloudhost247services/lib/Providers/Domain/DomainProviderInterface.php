<?php
/**
 * Registrar / domain-provider abstraction.
 *
 * CloudHost247 never talks to a registrar directly: services ask the
 * ProviderRegistry for the provider mapped to a TLD and call this interface.
 * Implementations must be honest — an operation the provider cannot perform
 * throws ProviderException (or ProviderNotConfiguredException when the
 * provider has no usable configuration) instead of simulating success.
 *
 * Credentials never cross this boundary: providers receive their sealed
 * configuration at construction and unseal internally.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

interface DomainProviderInterface
{
    /** Stable provider id (mod_chs_domain_providers.id) or 'null' for the null provider. */
    public function providerId();

    /** Human-readable provider name. */
    public function providerName();

    /** Provider type slug: whmcs | http | null. */
    public function providerType();

    /** True when the provider has the configuration it needs to operate. */
    public function isConfigured();

    /**
     * Capability map: operation => bool. Operations not listed are unsupported.
     * Known keys: check_availability, get_pricing, register, transfer, renew,
     * get_domain, update_domain, get_nameservers, update_nameservers,
     * list_dns_records, create_dns_record, update_dns_record, delete_dns_record,
     * whois, transfer_status, cancel_transfer.
     *
     * @return array<string,bool>
     */
    public function capabilities();

    /**
     * Live availability check.
     *
     * @return array{available:?bool, status:string, reason?:string}
     *         available=null means "unknown" — never guess.
     */
    public function checkAvailability($fqdn);

    /**
     * Provider-side pricing for a TLD, in minor units.
     *
     * @return array{register_minor:?int, renew_minor:?int, transfer_minor:?int, currency:string}
     */
    public function getPricing($tld);

    /**
     * Register a domain for $years year(s).
     *
     * @return array{provider_ref:string, expires_at:string}
     */
    public function registerDomain($fqdn, $years, array $contact = []);

    /**
     * Submit an inbound transfer.
     *
     * @return array{provider_ref:string}
     */
    public function transferDomain($fqdn, $eppCode, $years = 1);

    /** @return array{provider_ref:string, expires_at:string} */
    public function renewDomain($fqdn, $years = 1);

    /**
     * Current state of one domain at the provider.
     *
     * @return array{found:bool, status:string, expires_at:?string, nameservers?:array,
     *               privacy?:bool, provider_ref?:string}
     */
    public function getDomain($fqdn);

    /**
     * Update mutable domain settings.
     *
     * @param array{auto_renew?:bool, whois_privacy?:bool} $settings
     */
    public function updateDomain($fqdn, array $settings);

    /** @return array<int,string> */
    public function getNameservers($fqdn);

    /** @param array<int,string> $nameservers */
    public function updateNameservers($fqdn, array $nameservers);

    /**
     * @return array[] records: ['id'=>string,'type'=>string,'name'=>string,'value'=>string,
     *                           'ttl'=>int,'priority'=>?int]
     */
    public function getDnsRecords($fqdn);

    /** @return array{id:string} */
    public function createDnsRecord($fqdn, array $record);

    public function updateDnsRecord($fqdn, $recordId, array $record);

    public function deleteDnsRecord($fqdn, $recordId);

    /**
     * Public registration data (RDAP/WHOIS). Must only return what the
     * registry publishes; redaction stays redaction.
     *
     * @return array{raw:string, parsed:array, server:string}
     */
    public function getWhois($fqdn);

    /**
     * Provider-side transfer status.
     *
     * @return array{status:string, detail?:string}
     */
    public function getTransferStatus($fqdn);

    public function cancelTransfer($fqdn);
}
