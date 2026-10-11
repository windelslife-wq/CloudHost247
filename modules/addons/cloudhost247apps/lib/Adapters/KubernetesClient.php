<?php
/**
 * CloudHost247 App Cloud — worker-only Kubernetes API transport.
 *
 * Talks to one registered cluster's API server over HTTPS with the vault-held
 * kubeconfig. Worker-only, TLS always verified (a pinned cluster CA or the
 * system bundle — `insecure-skip-tls-verify` fails closed), bearer-token and
 * client-certificate users only, and every write is a server-side apply so a
 * retried call converges instead of duplicating.
 *
 * The client never logs or throws credential material: TLS files are written
 * 0600 to the system temp directory for one request and unlinked in a finally
 * block, and Secret-bearing responses are excluded from the HTTP diagnostic
 * recorder.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Adapters;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\HealthCheckException;
use Ch247Apps\Core\KubernetesException;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\RetryableKubernetesException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\Yaml;
use Ch247Apps\Servers\AgentClient;
use Ch247Apps\Servers\CredentialVault;

class KubernetesClient
{
    const POLL_INTERVAL = 5;

    /** @var Actor */
    private $actor;

    /** @var CredentialVault */
    private $vault;

    /** @var callable|null */
    private $sleeper;

    /** @var array server_id => connection */
    private $connections = [];

    /** @var string[] temp files to unlink */
    private $tempFiles = [];

    public function __construct(Actor $actor = null, CredentialVault $vault = null, callable $sleeper = null)
    {
        $this->actor = $actor ?: Actor::system('KubernetesClient');
        $this->vault = $vault ?: new CredentialVault($this->actor);
        $this->sleeper = $sleeper;
    }

    public function __destruct()
    {
        $this->releaseTempFiles();
    }

    /**
     * Resolve a transient cluster connection from trusted DB state.
     *
     * The kubeconfig is revealed from the encrypted vault and parsed on every
     * new server id; callers must never serialize or persist the return value.
     */
    public function connect($serverId)
    {
        AgentClient::assertWorkerContext();
        Rbac::assert($this->actor, Rbac::SERVER_VIEW);
        $serverId = (int) $serverId;
        if (isset($this->connections[$serverId])) {
            return $this->connections[$serverId];
        }
        $server = Db::first('servers', ['id' => $serverId, 'deleted_at' => null]);
        if (!$server) {
            throw new NotFoundException('That server does not exist.');
        }
        if ((string) $server['server_type'] !== 'kubernetes' || empty($server['kubernetes_enabled'])) {
            throw new ConfigurationException('The selected server is not enabled for Kubernetes operations.', [
                'server_id' => $serverId, 'error_code' => 'KUBERNETES_SERVER_DISABLED',
            ]);
        }
        if (in_array((string) $server['status'], ['disabled', 'maintenance'], true)) {
            throw new ConfigurationException('The selected server is not available for Kubernetes operations.', [
                'server_id' => $serverId, 'error_code' => 'KUBERNETES_SERVER_DISABLED',
            ]);
        }
        $credential = Db::first('server_credentials', [
            'server_id' => $serverId,
            'credential_type' => CredentialVault::TYPE_KUBE_CONFIG,
            'name' => 'primary',
            'status' => CredentialVault::STATUS_ACTIVE,
        ]);
        if (!$credential) {
            throw new ConfigurationException('The Kubernetes server has no active primary kubeconfig.', [
                'server_id' => $serverId, 'error_code' => 'KUBERNETES_CREDENTIAL_MISSING',
            ]);
        }
        $connection = $this->parseKubeconfig($this->vault->reveal((int) $credential['id']), $serverId);
        $this->connections[$serverId] = $connection;
        return $connection;
    }

    /* -------------------------------------------------------------- calls */

    public function get(array $connection, $path, array $query = [], array $options = [])
    {
        return $this->request($connection, 'GET', $path, $query, null, $options);
    }

    /** GET that returns null on 404 (existence probes). Everything else throws. */
    public function getOrNull(array $connection, $path, array $query = [], array $options = [])
    {
        try {
            return $this->request($connection, 'GET', $path, $query, null, $options);
        } catch (KubernetesException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            $asArray = $e->toArray();
            $context = isset($asArray['context']) && is_array($asArray['context']) ? $asArray['context'] : [];
            if (isset($context['http_status']) && (int) $context['http_status'] === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function post(array $connection, $path, array $body, array $query = [], array $options = [])
    {
        return $this->request($connection, 'POST', $path, $query, $body, $options);
    }

    public function patch(array $connection, $path, array $body, $contentType, array $query = [],
        array $options = [])
    {
        $options['content_type'] = (string) $contentType;
        return $this->request($connection, 'PATCH', $path, $query, $body, $options);
    }

    public function delete(array $connection, $path, array $query = [], array $options = [])
    {
        return $this->request($connection, 'DELETE', $path, $query, null, $options);
    }

    /**
     * Server-side apply one rendered object. Retried applies converge: the
     * field manager owns exactly the fields the platform renders.
     */
    public function apply(array $connection, array $object, array $options = [])
    {
        $kind = isset($object['kind']) ? (string) $object['kind'] : '';
        $name = isset($object['metadata']['name']) ? (string) $object['metadata']['name'] : '';
        $namespace = isset($object['metadata']['namespace']) ? (string) $object['metadata']['namespace'] : '';
        if (!in_array($kind, KubernetesManifests::KINDS, true) || $name === '') {
            throw new ConfigurationException('Only rendered platform objects may be applied to a cluster.', [
                'error_code' => 'KUBERNETES_KIND_UNSUPPORTED', 'kind' => Str::clip($kind, 80),
            ]);
        }
        $path = $this->objectPath($kind, $namespace, $name);
        return $this->patch($connection, $path, $object, 'application/apply-patch+yaml', [
            'fieldManager' => KubernetesManifests::FIELD_MANAGER, 'force' => 'true',
        ], $options);
    }

    /**
     * Poll a probe until it reports done or the timeout expires.
     *
     * @param callable $probe fn() => ['done' => bool, 'detail' => string]
     */
    public function waitFor($label, $timeoutSeconds, callable $probe)
    {
        $deadline = time() + max(1, (int) $timeoutSeconds);
        $detail = 'no status reported yet';
        do {
            $result = $probe();
            if (!empty($result['done'])) {
                return $result;
            }
            if (isset($result['detail'])) {
                $detail = (string) $result['detail'];
            }
            if (time() >= $deadline) {
                break;
            }
            $this->sleep(min(self::POLL_INTERVAL, max(1, $deadline - time())));
        } while (time() < $deadline);
        throw new HealthCheckException('Timed out waiting for ' . $label . ': ' . Str::clip($detail, 200) . '.', [
            'error_code' => 'KUBERNETES_ROLLOUT_TIMEOUT',
        ]);
    }

    /* ------------------------------------------------------------ transport */

    private function request(array $connection, $method, $path, array $query = [], $body = null,
        array $options = [])
    {
        AgentClient::assertWorkerContext();
        $path = $this->checkedPath($path);
        $url = $connection['server_url'] . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $httpOptions = [
            'headers' => ['Accept' => 'application/json'],
            'verify_tls' => true,
            'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : 30,
            'connect_timeout' => 8,
            'max_bytes' => isset($options['max_bytes']) ? (int) $options['max_bytes'] : 2097152,
        ];
        if ($connection['auth'] === 'bearer') {
            $httpOptions['headers']['Authorization'] = 'Bearer ' . $connection['token'];
        }
        if ($body !== null) {
            // 'body' (not 'json') so the Content-Type stays overridable for
            // server-side apply and strategic merge patches.
            $httpOptions['body'] = Str::jsonEncode($body);
            $httpOptions['headers']['Content-Type'] = isset($options['content_type'])
                ? (string) $options['content_type'] : 'application/json';
        } elseif (isset($options['content_type'])) {
            $httpOptions['headers']['Content-Type'] = (string) $options['content_type'];
        }
        if (strpos($path, '/secrets') !== false) {
            $httpOptions['redact_response'] = true;
        }

        try {
            if ($connection['ca_pem'] !== null) {
                $httpOptions['ca_bundle'] = $this->materialise('ch247k8s-ca-', $connection['ca_pem']);
            }
            if ($connection['auth'] === 'certificate') {
                $httpOptions['client_cert'] = $this->materialise('ch247k8s-cert-', $connection['cert_pem']);
                $httpOptions['client_key'] = $this->materialise('ch247k8s-key-', $connection['key_pem']);
            }
            $response = \Ch247Apps\Core\Http::request($method, $url, $httpOptions);
        } finally {
            $this->releaseTempFiles();
        }

        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableKubernetesException('The Kubernetes API could not complete the request.', [
                'http_status' => $status, 'error_code' => 'KUBERNETES_TRANSPORT_FAILED',
            ]);
        }
        if (!empty($options['raw_body'])) {
            // Text endpoints (pod logs): status-checked, never JSON-decoded.
            if ($status === 401 || $status === 403) {
                throw new KubernetesException('The cluster rejected the configured credentials.', [
                    'http_status' => $status, 'error_code' => 'KUBERNETES_AUTH_FAILED', 'reason' => null,
                ]);
            }
            if ($status < 200 || $status >= 300) {
                throw new KubernetesException('The Kubernetes API rejected the request.', [
                    'http_status' => $status, 'error_code' => 'KUBERNETES_API_REJECTED', 'reason' => null,
                ]);
            }
            return ['status' => $status, 'body' => isset($response['body']) ? (string) $response['body'] : ''];
        }
        $decoded = $this->decoded($response, $status);
        if ($status === 401 || $status === 403) {
            throw new KubernetesException('The cluster rejected the configured credentials.', [
                'http_status' => $status, 'error_code' => 'KUBERNETES_AUTH_FAILED',
                'reason' => $this->statusReason($decoded),
            ]);
        }
        if ($status < 200 || $status >= 300) {
            throw new KubernetesException('The Kubernetes API rejected the request: '
                . Str::clip($this->statusDetail($decoded), 300) . '.', [
                'http_status' => $status, 'error_code' => 'KUBERNETES_API_REJECTED',
                'reason' => $this->statusReason($decoded),
            ]);
        }
        return $decoded;
    }

    private function decoded(array $response, $status)
    {
        $body = isset($response['body']) ? (string) $response['body'] : '';
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new KubernetesException('The Kubernetes API returned an invalid JSON response.', [
                'http_status' => $status, 'error_code' => 'KUBERNETES_RESPONSE_INVALID',
            ]);
        }
        return $decoded;
    }

    private function statusReason(array $decoded)
    {
        return isset($decoded['reason']) && is_string($decoded['reason'])
            ? Str::clip($decoded['reason'], 120) : null;
    }

    private function statusDetail(array $decoded)
    {
        if (isset($decoded['message']) && is_string($decoded['message']) && trim($decoded['message']) !== '') {
            return trim($decoded['message']);
        }
        $reason = $this->statusReason($decoded);
        return $reason !== null ? $reason : 'no reason given';
    }

    private function checkedPath($path)
    {
        $path = '/' . ltrim((string) $path, '/');
        if (strpos($path, '..') !== false || strpos($path, '://') !== false
            || !preg_match('|^[A-Za-z0-9_\-/\.~%]+$|', $path)) {
            throw new ConfigurationException('Refusing to call an invalid Kubernetes API path.', [
                'error_code' => 'KUBERNETES_PATH_INVALID',
            ]);
        }
        return $path;
    }

    private function objectPath($kind, $namespace, $name)
    {
        $namespace = (string) $namespace;
        $name = (string) $name;
        switch ($kind) {
            case 'Namespace':
                return '/api/v1/namespaces/' . $name;
            case 'Secret':
                return '/api/v1/namespaces/' . $namespace . '/secrets/' . $name;
            case 'Service':
                return '/api/v1/namespaces/' . $namespace . '/services/' . $name;
            case 'PersistentVolumeClaim':
                return '/api/v1/namespaces/' . $namespace . '/persistentvolumeclaims/' . $name;
            case 'Deployment':
                return '/apis/apps/v1/namespaces/' . $namespace . '/deployments/' . $name;
            case 'Ingress':
                return '/apis/networking.k8s.io/v1/namespaces/' . $namespace . '/ingresses/' . $name;
            default:
                throw new ConfigurationException('Only rendered platform objects may be applied to a cluster.', [
                    'error_code' => 'KUBERNETES_KIND_UNSUPPORTED',
                ]);
        }
    }

    /* ---------------------------------------------------------- kubeconfig */

    private function parseKubeconfig($text, $serverId)
    {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            throw new ConfigurationException('The Kubernetes server has an empty kubeconfig.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
            ]);
        }
        if (strpos($text, '{') === 0) {
            $config = json_decode($text, true);
            if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
                throw new ConfigurationException('The kubeconfig is not valid JSON.', [
                    'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
                ]);
            }
        } else {
            $config = Yaml::parseStrict($text, 'kubeconfig');
            if (!is_array($config)) {
                throw new ConfigurationException('The kubeconfig is not a YAML mapping.', [
                    'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
                ]);
            }
        }

        $current = isset($config['current-context']) ? (string) $config['current-context'] : '';
        $entry = $this->namedEntry($config, 'contexts', $current);
        if ($entry === null || empty($entry['context']['cluster']) || empty($entry['context']['user'])) {
            throw new ConfigurationException('The kubeconfig current-context is missing or incomplete.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
            ]);
        }
        $cluster = $this->namedEntry($config, 'clusters', (string) $entry['context']['cluster']);
        $user = $this->namedEntry($config, 'users', (string) $entry['context']['user']);
        if ($cluster === null || $user === null) {
            throw new ConfigurationException('The kubeconfig current-context points nowhere.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
            ]);
        }

        $clusterData = isset($cluster['cluster']) && is_array($cluster['cluster']) ? $cluster['cluster'] : [];
        if (!empty($clusterData['insecure-skip-tls-verify'])) {
            throw new ConfigurationException('The kubeconfig disables TLS verification; provide the cluster CA.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INSECURE',
            ]);
        }
        $serverUrl = $this->checkedServerUrl(isset($clusterData['server']) ? $clusterData['server'] : '', $serverId);
        $caPem = null;
        if (isset($clusterData['certificate-authority-data'])) {
            $caPem = $this->checkedPem($clusterData['certificate-authority-data'], 'BEGIN CERTIFICATE', 'cluster CA');
        }

        $userData = isset($user['user']) && is_array($user['user']) ? $user['user'] : [];
        $auth = 'none';
        $token = null;
        $certPem = null;
        $keyPem = null;
        if (isset($userData['token']) && trim((string) $userData['token']) !== '') {
            $token = (string) $userData['token'];
            if (strlen($token) > 8192 || preg_match('/[\\r\\n\\x00]/', $token)) {
                throw new ConfigurationException('The kubeconfig bearer token is malformed.', [
                    'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_INVALID',
                ]);
            }
            $auth = 'bearer';
        } elseif (isset($userData['client-certificate-data']) && isset($userData['client-key-data'])) {
            $certPem = $this->checkedPem($userData['client-certificate-data'], 'BEGIN CERTIFICATE',
                'client certificate');
            // PKCS#8, PKCS#1 and SEC1 armors all contain PRIVATE KEY.
            $keyPem = $this->checkedPem($userData['client-key-data'], 'PRIVATE KEY', 'client key');
            $auth = 'certificate';
        } else {
            throw new ConfigurationException('Only bearer-token and client-certificate kubeconfig users are'
                . ' supported.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_AUTH_UNSUPPORTED',
            ]);
        }

        return [
            'server_id' => (int) $serverId,
            'server_url' => $serverUrl,
            'auth' => $auth,
            'token' => $token,
            'ca_pem' => $caPem,
            'cert_pem' => $certPem,
            'key_pem' => $keyPem,
        ];
    }

    private function namedEntry(array $config, $section, $name)
    {
        if ($name === '' || !isset($config[$section]) || !is_array($config[$section])) {
            return null;
        }
        foreach ($config[$section] as $entry) {
            if (is_array($entry) && isset($entry['name']) && (string) $entry['name'] === $name) {
                return $entry;
            }
        }
        return null;
    }

    private function checkedServerUrl($url, $serverId)
    {
        $parts = is_string($url) ? @parse_url(trim($url)) : false;
        if (!is_array($parts) || !isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException('The kubeconfig server URL must be an https URL without credentials.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_SERVER_INVALID',
            ]);
        }
        $host = strtolower(trim((string) $parts['host']));
        if (strlen($host) > 253
            || (!filter_var($host, FILTER_VALIDATE_IP)
                && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host))) {
            throw new ConfigurationException('The kubeconfig server hostname is invalid.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_SERVER_INVALID',
            ]);
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new ConfigurationException('The kubeconfig server port is invalid.', [
                'server_id' => (int) $serverId, 'error_code' => 'KUBECONFIG_SERVER_INVALID',
            ]);
        }
        $base = 'https://' . $host . ($port === 443 ? '' : ':' . $port);
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $base .= '/' . trim((string) $parts['path'], '/');
        }
        return $base;
    }

    private function checkedPem($value, $mustContain, $label)
    {
        $raw = base64_decode((string) $value, true);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 65536
            || strpos($raw, (string) $mustContain) === false) {
            throw new ConfigurationException('The kubeconfig ' . $label . ' is not valid base64 PEM.', [
                'error_code' => 'KUBECONFIG_INVALID',
            ]);
        }
        return $raw;
    }

    /* -------------------------------------------------------------- helpers */

    private function materialise($prefix, $contents)
    {
        $path = @tempnam(sys_get_temp_dir(), (string) $prefix);
        if (!is_string($path) || $path === '') {
            throw new ConfigurationException('Cluster TLS material could not be staged for the API call.', [
                'error_code' => 'KUBERNETES_TLS_MATERIAL_FAILED',
            ]);
        }
        @chmod($path, 0600);
        if (@file_put_contents($path, (string) $contents) === false) {
            @unlink($path);
            throw new ConfigurationException('Cluster TLS material could not be staged for the API call.', [
                'error_code' => 'KUBERNETES_TLS_MATERIAL_FAILED',
            ]);
        }
        $this->tempFiles[] = $path;
        return $path;
    }

    private function releaseTempFiles()
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    private function sleep($seconds)
    {
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, (int) $seconds);
            return;
        }
        sleep(max(1, (int) $seconds));
    }
}
