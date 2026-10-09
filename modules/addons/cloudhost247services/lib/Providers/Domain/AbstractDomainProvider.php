<?php
/**
 * Base class for domain providers: capability reporting plus honest
 * "unsupported operation" failures. Concrete providers override what they can
 * actually do; everything else fails closed with a machine-readable code.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

use Chs\Core\ProviderException;

abstract class AbstractDomainProvider implements DomainProviderInterface
{
    /** @var array<string,mixed> non-secret provider configuration */
    protected $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /** @return array<string,bool> */
    public function capabilities()
    {
        // Conservative default: availability + pricing only. Concrete
        // providers declare the operations they genuinely implement.
        return [
            'check_availability'  => false,
            'get_pricing'         => false,
            'register'            => false,
            'transfer'            => false,
            'renew'               => false,
            'get_domain'          => false,
            'update_domain'       => false,
            'get_nameservers'     => false,
            'update_nameservers'  => false,
            'list_dns_records'    => false,
            'create_dns_record'   => false,
            'update_dns_record'   => false,
            'delete_dns_record'   => false,
            'whois'               => false,
            'transfer_status'     => false,
            'cancel_transfer'     => false,
        ];
    }

    /** @return array{available:?bool,status:string,reason?:string} */
    public function checkAvailability($fqdn)
    {
        $this->unsupported('check_availability', $fqdn);
    }

    public function getPricing($tld)
    {
        $this->unsupported('get_pricing', $tld);
    }

    public function registerDomain($fqdn, $years, array $contact = [])
    {
        $this->unsupported('register', $fqdn);
    }

    public function transferDomain($fqdn, $eppCode, $years = 1)
    {
        $this->unsupported('transfer', $fqdn);
    }

    public function renewDomain($fqdn, $years = 1)
    {
        $this->unsupported('renew', $fqdn);
    }

    public function getDomain($fqdn)
    {
        $this->unsupported('get_domain', $fqdn);
    }

    public function updateDomain($fqdn, array $settings)
    {
        $this->unsupported('update_domain', $fqdn);
    }

    public function getNameservers($fqdn)
    {
        $this->unsupported('get_nameservers', $fqdn);
    }

    public function updateNameservers($fqdn, array $nameservers)
    {
        $this->unsupported('update_nameservers', $fqdn);
    }

    public function getDnsRecords($fqdn)
    {
        $this->unsupported('list_dns_records', $fqdn);
    }

    public function createDnsRecord($fqdn, array $record)
    {
        $this->unsupported('create_dns_record', $fqdn);
    }

    public function updateDnsRecord($fqdn, $recordId, array $record)
    {
        $this->unsupported('update_dns_record', $fqdn);
    }

    public function deleteDnsRecord($fqdn, $recordId)
    {
        $this->unsupported('delete_dns_record', $fqdn);
    }

    public function getWhois($fqdn)
    {
        $this->unsupported('whois', $fqdn);
    }

    public function getTransferStatus($fqdn)
    {
        $this->unsupported('transfer_status', $fqdn);
    }

    public function cancelTransfer($fqdn)
    {
        $this->unsupported('cancel_transfer', $fqdn);
    }

    /** Throw the standard honest "this provider cannot do that" error. */
    protected function unsupported($operation, $subject)
    {
        throw new ProviderException(
            'PROVIDER_OPERATION_UNSUPPORTED: ' . get_class($this)
            . ' does not support ' . $operation . ' (subject: ' . $subject . ').'
        );
    }
}
