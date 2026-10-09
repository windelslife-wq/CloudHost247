<?php
/**
 * Test doubles for the domain provider layer (never referenced by production
 * code): a configurable fake provider, a registry double, and a fake WHOIS
 * provider so no test touches the network.
 *
 * @package Chs
 */

use Chs\Core\ProviderException;
use Chs\Providers\Domain\AbstractDomainProvider;
use Chs\Providers\Domain\ProviderRegistry;
use Chs\Providers\Whois\WhoisProviderInterface;

class FakeDomainProvider extends AbstractDomainProvider
{
    /** @var array<string,array{available:?bool,status:string}> */
    public $availability = [];
    /** @var array<string,array> */
    public $domains = [];
    /** @var array<string,array<int,string>> */
    public $nameservers = [];
    /** @var array<string,array<int,array>> */
    public $dnsRecords = [];
    /** @var array<string,array> */
    public $transfers = [];
    /** @var array<string,array> */
    public $renewals = [];
    /** @var array<string,array> */
    public $registrations = [];
    /** @var array<string,bool> capability overrides */
    public $capOverrides = [];
    /** @var array<int,array{0:string,1:array}> recorded calls */
    public $calls = [];
    /** @var bool simulate an unconfigured provider */
    public $configured = true;
    /** @var bool throw once on the next createDnsRecord (simulates a provider hiccup) */
    public $failNextCreate = false;
    /** @var string */
    public $name;
    /** @var int next provider record id */
    private $nextRecordId = 500;

    public function __construct($name = 'Fake Registrar', array $config = [])
    {
        parent::__construct($config);
        $this->name = $name;
    }

    public function providerId() { return 'fake'; }
    public function providerName() { return $this->name; }
    public function providerType() { return 'http'; }
    public function isConfigured() { return $this->configured; }

    public function capabilities()
    {
        $caps = parent::capabilities();
        foreach ([
            'check_availability', 'get_pricing', 'register', 'transfer', 'renew', 'get_domain',
            'update_domain', 'get_nameservers', 'update_nameservers', 'list_dns_records',
            'create_dns_record', 'update_dns_record', 'delete_dns_record', 'whois',
            'transfer_status', 'cancel_transfer',
        ] as $op) {
            $caps[$op] = true;
        }
        foreach ($this->capOverrides as $op => $on) {
            $caps[$op] = (bool) $on;
        }
        return $caps;
    }

    private function record($op, array $args)
    {
        $this->calls[] = [$op, $args];
    }

    public function callsTo($op)
    {
        return array_values(array_filter($this->calls, function ($c) use ($op) {
            return $c[0] === $op;
        }));
    }

    public function checkAvailability($fqdn)
    {
        $this->record('check_availability', ['domain' => $fqdn]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED: fake provider has no credentials.');
        }
        $fqdn = strtolower((string) $fqdn);
        if (isset($this->availability[$fqdn])) {
            return $this->availability[$fqdn];
        }
        return ['available' => true, 'status' => 'available'];
    }

    public function getPricing($tld)
    {
        return [
            'register_minor' => 1299, 'renew_minor' => 1599, 'transfer_minor' => 999,
            'currency' => 'USD',
        ];
    }

    public function registerDomain($fqdn, $years, array $contact = [])
    {
        $this->record('register', ['domain' => $fqdn, 'years' => $years]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $this->registrations[strtolower($fqdn)] = ['years' => $years, 'at' => \Chs\Core\Clock::now()];
        return [
            'provider_ref' => 'FAKE-REG-' . strtoupper(substr(md5($fqdn), 0, 8)),
            'expires_at'   => \Chs\Core\Clock::in($years * 365 * 86400),
        ];
    }

    public function transferDomain($fqdn, $eppCode, $years = 1)
    {
        $this->record('transfer', ['domain' => $fqdn, 'years' => $years]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $this->transfers[strtolower($fqdn)] = ['years' => $years, 'at' => \Chs\Core\Clock::now()];
        return ['provider_ref' => 'FAKE-TR-' . strtoupper(substr(md5($fqdn), 0, 8))];
    }

    public function renewDomain($fqdn, $years = 1)
    {
        $this->record('renew', ['domain' => $fqdn, 'years' => $years]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $this->renewals[strtolower($fqdn)] = ['years' => $years, 'at' => \Chs\Core\Clock::now()];
        return [
            'provider_ref' => 'FAKE-REN-' . strtoupper(substr(md5($fqdn), 0, 8)),
            'expires_at'   => \Chs\Core\Clock::in($years * 365 * 86400),
        ];
    }

    public function getDomain($fqdn)
    {
        $fqdn = strtolower((string) $fqdn);
        if (!isset($this->domains[$fqdn])) {
            return ['found' => false, 'status' => 'unknown'];
        }
        return $this->domains[$fqdn];
    }

    public function updateDomain($fqdn, array $settings)
    {
        $this->record('update_domain', ['domain' => $fqdn, 'settings' => $settings]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
    }

    public function getNameservers($fqdn)
    {
        $fqdn = strtolower((string) $fqdn);
        return isset($this->nameservers[$fqdn]) ? $this->nameservers[$fqdn] : [];
    }

    public function updateNameservers($fqdn, array $nameservers)
    {
        $this->record('update_nameservers', ['domain' => $fqdn, 'nameservers' => $nameservers]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $this->nameservers[strtolower((string) $fqdn)] = array_values($nameservers);
    }

    public function getDnsRecords($fqdn)
    {
        $fqdn = strtolower((string) $fqdn);
        return isset($this->dnsRecords[$fqdn]) ? $this->dnsRecords[$fqdn] : [];
    }

    public function createDnsRecord($fqdn, array $record)
    {
        $this->record('create_dns_record', ['domain' => $fqdn, 'record' => $record]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        if ($this->failNextCreate) {
            $this->failNextCreate = false;
            throw new ProviderException('simulated provider hiccup');
        }
        $id = 'rec-' . $this->nextRecordId++;
        $record['id'] = $id;
        $this->dnsRecords[strtolower((string) $fqdn)][] = $record;
        return ['id' => $id];
    }

    public function updateDnsRecord($fqdn, $recordId, array $record)
    {
        $this->record('update_dns_record', ['domain' => $fqdn, 'record_id' => $recordId, 'record' => $record]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $fqdn = strtolower((string) $fqdn);
        foreach ($this->dnsRecords[$fqdn] ?? [] as &$existing) {
            if ($existing['id'] === $recordId) {
                $existing = array_merge($existing, $record);
            }
        }
        unset($existing);
    }

    public function deleteDnsRecord($fqdn, $recordId)
    {
        $this->record('delete_dns_record', ['domain' => $fqdn, 'record_id' => $recordId]);
        if (!$this->configured) {
            throw new \Chs\Core\ProviderNotConfiguredException('DOMAIN_PROVIDER_NOT_CONFIGURED');
        }
        $fqdn = strtolower((string) $fqdn);
        $this->dnsRecords[$fqdn] = array_values(array_filter(
            isset($this->dnsRecords[$fqdn]) ? $this->dnsRecords[$fqdn] : [],
            function ($r) use ($recordId) {
                return $r['id'] !== $recordId;
            }
        ));
    }

    public function getWhois($fqdn)
    {
        return ['raw' => '', 'parsed' => [], 'server' => 'whois.test:f43'];
    }

    public function getTransferStatus($fqdn)
    {
        return isset($this->transfers[strtolower((string) $fqdn)])
            ? ['status' => 'processing']
            : ['status' => 'unknown'];
    }

    public function cancelTransfer($fqdn)
    {
        $this->record('cancel_transfer', ['domain' => $fqdn]);
    }
}

/** Registry double: serves the fake provider regardless of TLD. */
class FakeProviderRegistry extends ProviderRegistry
{
    /** @var FakeDomainProvider */
    public $fake;
    /** @var array<string,FakeDomainProvider> tld => provider */
    public $byTld = [];

    public function __construct(FakeDomainProvider $fake = null)
    {
        $this->fake = $fake ?: new FakeDomainProvider();
    }

    public function forTld($tld)
    {
        $tld = ltrim(strtolower((string) $tld), '.');
        return isset($this->byTld[$tld]) ? $this->byTld[$tld] : $this->fake;
    }

    public function default()
    {
        return $this->fake;
    }

    public function platformDefault()
    {
        return $this->fake;
    }
}

/** WHOIS provider double: canned raw responses, no sockets. */
class FakeWhoisProvider implements WhoisProviderInterface
{
    /** @var array<string,string> fqdn => raw whois text */
    public $raw = [];
    /** @var bool */
    public $fail = false;

    public function lookup($domain, $tld)
    {
        unset($tld);
        if ($this->fail) {
            throw new ProviderException('whois.test unreachable (simulated)');
        }
        $domain = strtolower((string) $domain);
        return [
            'server' => 'whois.test:43',
            'raw'    => isset($this->raw[$domain]) ? $this->raw[$domain] : "Domain Name: " . strtoupper($domain) . "\nRegistrar: Test Registrar LLC\n",
        ];
    }
}
