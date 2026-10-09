<?php
/**
 * The platform-backed domain provider.
 *
 * This is the default provider: it answers through WHMCS itself — the
 * configured registrar/lookup chain (availability, WHOIS) and WHMCS' own
 * domain records (tbldomains). Those are the operations WHMCS exposes to an
 * addon; everything else (register/transfer/renew/DNS writes) is performed by
 * WHMCS' own order pipeline and registrar modules, so this provider reports
 * those capabilities as unsupported rather than pretending to call a registrar
 * API WHMCS does not expose. Operations staff complete such steps in WHMCS or
 * configure a real registrar API provider (HttpRegistrarProvider) — the
 * module then drives it through the same interface.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

use Chs\Core\DomainName;
use Chs\Core\Platform;
use Chs\Core\ProviderException;
use Chs\Services\AvailabilityService;
use Chs\Services\TldCatalogService;
use Chs\Services\WhoisService;

class WhmcsRegistrarProvider extends AbstractDomainProvider
{
    /** @var int|null */
    private $id;
    /** @var string */
    private $name;

    public function __construct($id = null, $name = 'WHMCS registrar chain', array $config = [])
    {
        parent::__construct($config);
        $this->id = $id === null ? null : (int) $id;
        $this->name = $name !== '' ? (string) $name : 'WHMCS registrar chain';
    }

    public function providerId()
    {
        return $this->id === null ? 'whmcs' : (string) $this->id;
    }

    public function providerName()
    {
        return $this->name;
    }

    public function providerType()
    {
        return 'whmcs';
    }

    public function isConfigured()
    {
        // The WHMCS provider is configured whenever WHMCS itself is — it needs
        // no credentials of its own.
        return true;
    }

    public function capabilities()
    {
        $caps = parent::capabilities();
        $caps['check_availability'] = true;
        $caps['get_pricing'] = true;
        $caps['whois'] = true;
        $caps['get_domain'] = true; // read-only, from WHMCS' own domain records
        return $caps;
    }

    public function checkAvailability($fqdn)
    {
        $domain = DomainName::tryParse($fqdn);
        if (!$domain) {
            return ['available' => null, 'status' => 'invalid_domain'];
        }
        $answer = (new AvailabilityService())->check($domain);
        $status = (string) $answer['status'];
        $available = $answer['available'];
        // The platform answers available=false when it could not ask the
        // registry — that is "unknown", never "taken".
        if (in_array($status, ['unknown', 'unavailable_lookup'], true)) {
            $available = null;
        }
        return [
            'available' => $available,
            'status'    => $status,
        ];
    }

    public function getPricing($tld)
    {
        $row = (new TldCatalogService())->detail($tld);
        if (!$row) {
            return [
                'register_minor'  => null,
                'renew_minor'     => null,
                'transfer_minor'  => null,
                'currency'        => Platform::gateway()->defaultCurrency(),
            ];
        }
        return [
            'register_minor'  => $row['register_minor'],
            'renew_minor'     => $row['renew_minor'],
            'transfer_minor'  => $row['transfer_minor'],
            'currency'        => Platform::gateway()->defaultCurrency(),
        ];
    }

    public function getDomain($fqdn)
    {
        $fqdn = strtolower((string) $fqdn);
        $rows = \Chs\Core\Db::query(
            'SELECT id, userid, domain, status, registrar, expirydate, nextduedate, autorenew, donotrenew
             FROM tbldomains WHERE domain = ? LIMIT 1',
            [$fqdn]
        );
        if (!$rows) {
            return ['found' => false, 'status' => 'unknown'];
        }
        $row = $rows[0];
        return [
            'found'         => true,
            'status'        => (string) $row['status'],
            'expires_at'    => $row['expirydate'] ? (string) $row['expirydate'] . ' 00:00:00' : null,
            'whmcs_domain_id' => (int) $row['id'],
            'client_id'     => (int) $row['userid'],
            'registrar'     => (string) $row['registrar'],
            'auto_renew'    => !empty($row['autorenew']) && empty($row['donotrenew']),
        ];
    }

    public function getWhois($fqdn)
    {
        $info = (new WhoisService())->lookup($fqdn);
        return [
            'raw'    => (string) $info['raw'],
            'parsed' => $info['parsed'],
            'server' => (string) $info['server'],
        ];
    }

    public function getTransferStatus($fqdn)
    {
        // WHMCS tracks transfer progress on the domain record itself; a domain
        // that exists and is active means the transfer completed.
        $domain = $this->getDomain($fqdn);
        if (!$domain['found']) {
            return ['status' => 'unknown', 'detail' => 'The platform holds no record for this domain yet.'];
        }
        return [
            'status' => strtolower((string) $domain['status']) === 'active' ? 'completed' : strtolower((string) $domain['status']),
            'detail' => 'WHMCS domain status: ' . $domain['status'],
        ];
    }

    /** Register/transfer/renew/DNS writes go through WHMCS' own pipeline. */
    public function registerDomain($fqdn, $years, array $contact = [])
    {
        throw new ProviderException(
            'PROVIDER_OPERATION_UNSUPPORTED: WHMCS processes domain registrations through its own '
            . 'cart/order pipeline and registrar modules; this provider cannot submit a registration '
            . 'directly for ' . $fqdn . '. Complete the order in WHMCS or configure an HTTP registrar provider.'
        );
    }

    public function transferDomain($fqdn, $eppCode, $years = 1)
    {
        throw new ProviderException(
            'PROVIDER_OPERATION_UNSUPPORTED: WHMCS processes domain transfers through its own '
            . 'cart/order pipeline and registrar modules; this provider cannot submit a transfer '
            . 'directly for ' . $fqdn . '. Complete the transfer in WHMCS or configure an HTTP registrar provider.'
        );
    }

    public function renewDomain($fqdn, $years = 1)
    {
        throw new ProviderException(
            'PROVIDER_OPERATION_UNSUPPORTED: WHMCS renews domains through its own billing cron and '
            . 'registrar modules; this provider cannot submit a renewal directly for ' . $fqdn . '.'
        );
    }
}
