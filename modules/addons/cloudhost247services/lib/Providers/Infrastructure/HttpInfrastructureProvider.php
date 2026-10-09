<?php
/**
 * Generic HTTP infrastructure provider.
 *
 * A real, configurable integration path for providers that expose a JSON
 * HTTP API (OVH, Proxmox, Virtualizor, SolusVM, Hetzner, OpenStack or any
 * in-house panel with an API): the administrator configures the base URL,
 * the endpoint map and the credential field names once (admin →
 * Infrastructure → Providers); every operation then performs an actual HTTPS
 * call. Endpoints are declared as "METHOD /path/{server}" templates.
 *
 * Credentials arrive sealed (Secrets) and are unsealed only in-memory for
 * the duration of a request. Anything not configured fails closed — this
 * provider never fabricates a server, an IP or a status.
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

use Chs\Core\Secrets;
use Chs\Core\Settings;

class HttpInfrastructureProvider extends AbstractInfrastructureProvider
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

    /** A capability is supported when its endpoint is mapped. */
    public function capabilities()
    {
        $caps = parent::capabilities();
        $endpoints = $this->endpoints();
        foreach (array_keys($caps) as $op) {
            $caps[$op] = isset($endpoints[$op]) && is_string($endpoints[$op]) && trim($endpoints[$op]) !== '';
        }
        return $caps;
    }

    /* -------------------------------------------------------- operations -- */

    public function healthCheck()
    {
        try {
            $data = $this->call('health', []);
            $status = isset($data['status']) ? strtolower((string) $data['status']) : 'ok';
            return [
                'status' => in_array($status, ['ok', 'healthy', 'up'], true) ? 'ok' : 'degraded',
                'detail' => isset($data['detail']) ? (string) $data['detail'] : 'reachable',
            ];
        } catch (ProviderFailure $e) {
            return ['status' => 'unavailable', 'detail' => $e->machineCode()];
        }
    }

    public function getAvailableImages()
    {
        $data = $this->call('images', []);
        $list = isset($data['images']) ? $data['images'] : (isset($data['data']) ? $data['data'] : $data);
        $out = [];
        foreach ((array) $list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string) $row['id'] : (isset($row['image_id']) ? (string) $row['image_id'] : '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'           => $id,
                'name'         => isset($row['name']) ? (string) $row['name'] : $id,
                'architecture' => isset($row['architecture']) ? (string) $row['architecture'] : '',
                'region'       => isset($row['region']) ? (string) $row['region'] : '',
            ];
        }
        return $out;
    }

    public function getImage($imageId)
    {
        $this->requireCapability('images');
        foreach ($this->getAvailableImages() as $image) {
            if ($image['id'] === (string) $imageId) {
                return $image;
            }
        }
        return null;
    }

    public function createServer(array $spec)
    {
        $data = $this->call('create', [
            'server' => [
                'hostname'     => isset($spec['hostname']) ? (string) $spec['hostname'] : '',
                'image_id'     => isset($spec['image_id']) ? (string) $spec['image_id'] : '',
                'template_id'  => isset($spec['template_id']) ? (string) $spec['template_id'] : '',
                'region'       => isset($spec['region']) ? (string) $spec['region'] : '',
                'plan'         => isset($spec['plan']) ? (string) $spec['plan'] : '',
                'architecture' => isset($spec['architecture']) ? (string) $spec['architecture'] : '',
                'ssh_key'      => isset($spec['ssh_key']) ? (string) $spec['ssh_key'] : '',
            ],
        ]);
        $row = isset($data['server']) && is_array($data['server']) ? $data['server'] : $data;
        $id = isset($row['id']) ? (string) $row['id']
            : (isset($row['server_id']) ? (string) $row['server_id']
            : (isset($row['uuid']) ? (string) $row['uuid'] : ''));
        if ($id === '') {
            throw ProviderFailure::permanent('Provider created no server id in its response.', 'PROVIDER_ERROR');
        }
        $ip = isset($row['ip']) ? (string) $row['ip']
            : (isset($row['ip_address']) ? (string) $row['ip_address'] : '');
        return ['provider_server_id' => $id, 'ip_address' => $ip !== '' ? $ip : null];
    }

    public function startServer($providerServerId)
    {
        $this->call('start', ['server' => (string) $providerServerId]);
    }

    public function stopServer($providerServerId)
    {
        $this->call('stop', ['server' => (string) $providerServerId]);
    }

    public function rebootServer($providerServerId)
    {
        $this->call('reboot', ['server' => (string) $providerServerId]);
    }

    public function shutdownServer($providerServerId)
    {
        $this->call('shutdown', ['server' => (string) $providerServerId]);
    }

    public function deleteServer($providerServerId)
    {
        $this->call('delete', ['server' => (string) $providerServerId]);
    }

    public function getServerStatus($providerServerId)
    {
        $data = $this->call('status', ['server' => (string) $providerServerId]);
        $row = isset($data['server']) && is_array($data['server']) ? $data['server'] : $data;
        return $this->normalizeStatus(isset($row['status']) ? $row['status'] : 'unknown');
    }

    public function getServerIp($providerServerId)
    {
        $data = $this->call('status', ['server' => (string) $providerServerId]);
        $row = isset($data['server']) && is_array($data['server']) ? $data['server'] : $data;
        $ip = isset($row['ip']) ? (string) $row['ip']
            : (isset($row['ip_address']) ? (string) $row['ip_address'] : '');
        return $ip !== '' ? $ip : null;
    }

    public function reinstallServer($providerServerId, $imageId, array $options = [])
    {
        $this->call('reinstall', [
            'server'   => (string) $providerServerId,
            'image_id' => (string) $imageId,
            'options'  => $options,
        ]);
        return ['provider_server_id' => (string) $providerServerId];
    }

    public function configureServer($providerServerId, array $config)
    {
        $this->call('configure', [
            'server'   => (string) $providerServerId,
            'hostname' => isset($config['hostname']) ? (string) $config['hostname'] : '',
            'ssh_key'  => isset($config['ssh_key']) ? (string) $config['ssh_key'] : '',
            'config'   => $config,
        ]);
    }

    public function enterRescueMode($providerServerId)
    {
        $this->call('rescue', ['server' => (string) $providerServerId]);
    }

    public function getConsole($providerServerId)
    {
        $data = $this->call('console', ['server' => (string) $providerServerId]);
        $url = isset($data['url']) ? (string) $data['url'] : '';
        if ($url === '') {
            return null;
        }
        return ['url' => $url, 'type' => isset($data['type']) ? (string) $data['type'] : 'vnc'];
    }

    public function getServerMetrics($providerServerId)
    {
        $data = $this->call('metrics', ['server' => (string) $providerServerId]);
        $row = isset($data['metrics']) && is_array($data['metrics']) ? $data['metrics'] : $data;
        return [
            'cpu'        => isset($row['cpu']) ? (float) $row['cpu'] : null,
            'memory'     => isset($row['memory']) ? (float) $row['memory'] : null,
            'disk'       => isset($row['disk']) ? (float) $row['disk'] : null,
            'bandwidth'  => isset($row['bandwidth']) ? (float) $row['bandwidth'] : null,
            'raw'        => $row,
        ];
    }

    /* ---------------------------------------------------------- transport -- */

    /** @return array<string,string> operation => "METHOD /path/{server}" */
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
     * Perform one HTTPS call against the configured provider API.
     *
     * @param string $operation capability key (must exist in the endpoint map)
     * @param array  $params    template variables; scalar values replace {key}
     * @return array decoded response payload (the "data" member when present)
     */
    protected function call($operation, array $params)
    {
        if (!$this->isConfigured()) {
            throw ProviderFailure::notConfigured('provider "' . $this->name . '" needs a base URL and sealed credentials');
        }
        $endpoints = $this->endpoints();
        if (!isset($endpoints[$operation]) || !is_string($endpoints[$operation]) || trim($endpoints[$operation]) === '') {
            throw ProviderFailure::unsupported($operation . ' (no endpoint mapped)');
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
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if (in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28], true)) {
                throw ProviderFailure::timeout($operation);
            }
            throw ProviderFailure::transient(
                'PROVIDER_UNREACHABLE: ' . $operation . ' failed (' . $error . ').',
                'PROVIDER_UNREACHABLE'
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            if ($status >= 400) {
                throw ProviderFailure::fromHttpStatus($status, (string) $response);
            }
            throw ProviderFailure::permanent(
                'PROVIDER_ERROR: provider returned a non-JSON response for ' . $operation . '.',
                'PROVIDER_ERROR'
            );
        }
        if ($status >= 400) {
            $message = isset($decoded['message']) ? (string) $decoded['message']
                : (isset($decoded['error']) ? (string) $decoded['error'] : '');
            throw ProviderFailure::fromHttpStatus($status, $message);
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }
        return $decoded;
    }
}
