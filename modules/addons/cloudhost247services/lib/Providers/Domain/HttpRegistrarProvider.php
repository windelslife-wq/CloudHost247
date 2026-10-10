<?php
/**
 * Generic HTTP registrar provider.
 *
 * A real, configurable integration path for registrars that expose a JSON
 * HTTP API: the administrator configures the base URL, the endpoint map and
 * the credential field names once (admin → Domains → Providers); every
 * operation then performs an actual HTTPS call. Endpoints are declared as
 * "METHOD /path/{domain}" templates; responses are read through the
 * configured response map. Anything not configured fails closed — this
 * provider never fabricates a registrar answer.
 *
 * Credentials arrive sealed (Secrets) and are unsealed only in-memory for the
 * duration of a request.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

use Chs\Core\ProviderException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\Secrets;
use Chs\Core\Settings;

class HttpRegistrarProvider extends AbstractDomainProvider
{
    /** @var int */
    private $id;
    /** @var string */
    private $name;
    /** @var string sealed credentials envelope */
    private $credentialsEnc;
    /** @var array|null unsealed credentials (request-scoped) */
    private $credentials;

    public function __construct($id, $name, array $config = [], $credentialsEnc = '')
    {
        parent::__construct($config);
        $this->id = (int) $id;
        $this->name = (string) $name;
        $this->credentialsEnc = (string) $credentialsEnc;
    }

    public function providerId()
    {
        return (string) $this->id;
    }

    public function providerName()
    {
        return $this->name;
    }

    public function providerType()
    {
        return 'http';
    }

    public function isConfigured()
    {
        $base = trim((string) (isset($this->config['base_url']) ? $this->config['base_url'] : ''));
        return $base !== '' && $this->credentials() !== null;
    }

    public function capabilities()
    {
        $caps = parent::capabilities();
        $endpoints = $this->endpoints();
        foreach (array_keys($caps) as $op) {
            $caps[$op] = isset($endpoints[$op]);
        }
        return $caps;
    }

    /* -------------------------------------------------------- operations -- */

    public function checkAvailability($fqdn)
    {
        $data = $this->call('check_availability', ['domain' => $fqdn]);
        $available = isset($data['available']) ? $data['available'] : null;
        return [
            'available' => $available === null ? null : (bool) $available,
            'status'    => isset($data['status']) ? (string) $data['status'] : ($available ? 'available' : 'taken'),
        ];
    }

    public function getPricing($tld)
    {
        $data = $this->call('get_pricing', ['tld' => $tld]);
        return [
            'register_minor'  => isset($data['register_minor']) ? (int) $data['register_minor'] : null,
            'renew_minor'     => isset($data['renew_minor']) ? (int) $data['renew_minor'] : null,
            'transfer_minor'  => isset($data['transfer_minor']) ? (int) $data['transfer_minor'] : null,
            'currency'        => isset($data['currency']) ? strtoupper((string) $data['currency']) : 'USD',
        ];
    }

    public function registerDomain($fqdn, $years, array $contact = [])
    {
        $data = $this->call('register', ['domain' => $fqdn, 'years' => (int) $years, 'contact' => $contact]);
        return [
            'provider_ref' => isset($data['provider_ref']) ? (string) $data['provider_ref'] : '',
            'expires_at'   => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        ];
    }

    public function transferDomain($fqdn, $eppCode, $years = 1)
    {
        $data = $this->call('transfer', ['domain' => $fqdn, 'epp' => $eppCode, 'years' => (int) $years]);
        return ['provider_ref' => isset($data['provider_ref']) ? (string) $data['provider_ref'] : ''];
    }

    public function renewDomain($fqdn, $years = 1)
    {
        $data = $this->call('renew', ['domain' => $fqdn, 'years' => (int) $years]);
        return [
            'provider_ref' => isset($data['provider_ref']) ? (string) $data['provider_ref'] : '',
            'expires_at'   => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
        ];
    }

    public function getDomain($fqdn)
    {
        $data = $this->call('get_domain', ['domain' => $fqdn]);
        return [
            'found'         => !empty($data['found']),
            'status'        => isset($data['status']) ? (string) $data['status'] : 'unknown',
            'expires_at'    => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'nameservers'   => isset($data['nameservers']) ? (array) $data['nameservers'] : [],
            'privacy'       => isset($data['privacy']) ? (bool) $data['privacy'] : null,
            'provider_ref'  => isset($data['provider_ref']) ? (string) $data['provider_ref'] : '',
        ];
    }

    public function updateDomain($fqdn, array $settings)
    {
        $this->call('update_domain', ['domain' => $fqdn, 'settings' => $settings]);
    }

    public function getNameservers($fqdn)
    {
        $data = $this->call('get_nameservers', ['domain' => $fqdn]);
        return isset($data['nameservers']) ? array_values((array) $data['nameservers']) : [];
    }

    public function updateNameservers($fqdn, array $nameservers)
    {
        $this->call('update_nameservers', ['domain' => $fqdn, 'nameservers' => array_values($nameservers)]);
    }

    public function getDnsRecords($fqdn)
    {
        $data = $this->call('list_dns_records', ['domain' => $fqdn]);
        return isset($data['records']) ? array_values((array) $data['records']) : [];
    }

    public function createDnsRecord($fqdn, array $record)
    {
        $data = $this->call('create_dns_record', ['domain' => $fqdn, 'record' => $record]);
        return ['id' => isset($data['id']) ? (string) $data['id'] : ''];
    }

    public function updateDnsRecord($fqdn, $recordId, array $record)
    {
        $this->call('update_dns_record', ['domain' => $fqdn, 'record_id' => (string) $recordId, 'record' => $record]);
    }

    public function deleteDnsRecord($fqdn, $recordId)
    {
        $this->call('delete_dns_record', ['domain' => $fqdn, 'record_id' => (string) $recordId]);
    }

    public function getWhois($fqdn)
    {
        $data = $this->call('whois', ['domain' => $fqdn]);
        return [
            'raw'    => isset($data['raw']) ? (string) $data['raw'] : '',
            'parsed' => isset($data['parsed']) ? (array) $data['parsed'] : [],
            'server' => isset($data['server']) ? (string) $data['server'] : '',
        ];
    }

    public function getTransferStatus($fqdn)
    {
        $data = $this->call('transfer_status', ['domain' => $fqdn]);
        return [
            'status' => isset($data['status']) ? (string) $data['status'] : 'unknown',
            'detail' => isset($data['detail']) ? (string) $data['detail'] : null,
        ];
    }

    public function cancelTransfer($fqdn)
    {
        $this->call('cancel_transfer', ['domain' => $fqdn]);
    }

    /* ---------------------------------------------------------- transport -- */

    /** @return array<string,string> operation => "METHOD /path/{domain}" */
    protected function endpoints()
    {
        $raw = isset($this->config['endpoints']) ? $this->config['endpoints'] : [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string,mixed>|null */
    protected function credentials()
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        $plain = Secrets::decrypt($this->credentialsEnc);
        if ($plain === null || $plain === '') {
            return null;
        }
        $decoded = json_decode($plain, true);
        $this->credentials = is_array($decoded) ? $decoded : null;
        return $this->credentials;
    }

    /**
     * Perform one HTTPS call against the configured registrar API.
     *
     * @param string $operation capability key (must exist in the endpoint map)
     * @param array  $params    template variables; {domain} is replaced inline
     * @return array decoded response payload (the "data" member when present)
     */
    protected function call($operation, array $params)
    {
        if (!$this->isConfigured()) {
            throw new ProviderNotConfiguredException(
                'DOMAIN_PROVIDER_NOT_CONFIGURED: provider "' . $this->name . '" needs a base URL and sealed credentials.'
            );
        }
        $endpoints = $this->endpoints();
        if (!isset($endpoints[$operation]) || !is_string($endpoints[$operation])) {
            $this->unsupported($operation, isset($params['domain']) ? $params['domain'] : '');
        }

        $template = $endpoints[$operation];
        $parts = preg_split('/\s+/', trim($template), 2);
        $method = strtoupper(isset($parts[0]) ? $parts[0] : 'GET');
        $path = isset($parts[1]) ? $parts[1] : '';
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
            }
        }
        $url = rtrim((string) $this->config['base_url'], '/') . '/' . ltrim($path, '/');

        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? json_encode($params) : null;

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $authHeader = trim((string) (isset($this->config['auth_header']) ? $this->config['auth_header'] : 'Authorization'));
        $authPrefix = (string) (isset($this->config['auth_prefix']) ? $this->config['auth_prefix'] : 'Bearer ');
        $tokenField = (string) (isset($this->config['token_field']) ? $this->config['token_field'] : 'api_key');
        $creds = $this->credentials();
        if ($authHeader !== '' && isset($creds[$tokenField]) && $creds[$tokenField] !== '') {
            $headers[] = $authHeader . ': ' . $authPrefix . $creds[$tokenField];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(5, Settings::int('provider_http_timeout_seconds', 15)),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            // C-5: only http(s) — never file://, gopher://, etc., including on redirect.
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new ProviderException('The registrar API could not be reached: ' . ($err !== '' ? $err : 'transport error'));
        }
        if ($code < 200 || $code >= 300) {
            throw new ProviderException('The registrar API answered HTTP ' . $code . '.');
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new ProviderException('The registrar API returned an unreadable payload.');
        }
        if (isset($decoded['ok']) && !$decoded['ok']) {
            throw new ProviderException(
                'The registrar API refused the request: '
                . (isset($decoded['error']) ? (string) (is_array($decoded['error']) ? json_encode($decoded['error']) : $decoded['error']) : 'unknown error')
            );
        }
        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }
}
