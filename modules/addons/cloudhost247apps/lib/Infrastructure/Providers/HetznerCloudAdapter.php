<?php
/**
 * Hetzner Cloud provider adapter (api.hetzner.cloud/v1).
 *
 * Real API, fail-closed response validation, TLS always verified, and no
 * credentials in exceptions or logs. The endpoint is fixed; callers cannot
 * supply a URL.
 *
 * Hetzner has no idempotency-key header, so create/delete idempotency is
 * recovered through a deterministic server label
 * (`ch247-idempotency=<sha256(key)[:32]>`): an existing server carrying the
 * label is returned instead of creating a duplicate, and a create race is
 * recovered by re-reading the label after a rejected create.
 *
 * Size mapping is provider-sourced: create/resize select a server type from
 * the provider's own `GET /server_types` catalog that exactly matches the
 * requested cpu/memory/disk. The adapter never invents capacity and never
 * claims a size the provider did not confirm.
 *
 * Hetzner exposes no per-server metrics endpoint, so `server.metrics` is not
 * advertised and getMetrics() fails closed.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Infrastructure\Providers;

use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Core\Str;
use Ch247Apps\Infrastructure\InfrastructureProviderInterface;

class HetznerCloudAdapter implements InfrastructureProviderInterface
{
    const API_BASE = 'https://api.hetzner.cloud/v1';
    const IDEMPOTENCY_LABEL = 'ch247-idempotency';

    public function key()
    {
        return 'hetzner';
    }

    public function name()
    {
        return 'Hetzner Cloud';
    }

    public function capabilities()
    {
        return [
            'server.create' => true,
            'server.get' => true,
            'server.delete' => true,
            'server.reboot' => true,
            'server.power_on' => true,
            'server.power_off' => true,
            'server.rebuild' => true,
            'server.resize' => true,
            'snapshot.create' => true,
            'snapshot.delete' => true,
            'snapshot.restore' => true,
            // Hetzner exposes no per-server metrics endpoint.
            'server.metrics' => false,
        ];
    }

    /**
     * True only on an authenticated 200. A definitive rejection (401/403)
     * throws ProviderAuthenticationException; transport failures and 5xx throw
     * RetryableProviderException so verification retries instead of failing.
     */
    public function verifyCredentials(array $credentials, array $accountConfig)
    {
        $token = $this->token($credentials);
        $result = $this->request('GET', '/servers', $token, ['query' => ['per_page' => 1]]);
        if ((int) $result['status'] === 200) {
            return true;
        }
        if ((int) $result['status'] === 401 || (int) $result['status'] === 403) {
            return false;
        }
        return false;
    }

    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $spec = $this->spec($spec);
        $label = $this->idempotencyLabel($idempotencyKey);

        $existing = $this->findByLabel($token, $label);
        if ($existing !== null) {
            return $this->normaliseServer($existing);
        }

        $type = $this->selectServerType($token, $spec);
        $body = [
            'name' => $this->providerName($spec['name']),
            'server_type' => (string) $type['name'],
            'image' => (string) $spec['image'],
            'location' => (string) $spec['region'],
            'start_after_create' => true,
            'labels' => [self::IDEMPOTENCY_LABEL => $label],
        ];
        try {
            $result = $this->request('POST', '/servers', $token, ['json' => $body]);
        } catch (ProviderOperationException $e) {
            // A concurrent create may have won the race; recover by label.
            $existing = $this->findByLabel($token, $label);
            if ($existing !== null) {
                return $this->normaliseServer($existing);
            }
            throw $e;
        }
        if (!isset($result['decoded']['server']) || !is_array($result['decoded']['server'])) {
            throw new ProviderOperationException('The Hetzner Cloud API returned no server for the create call.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return $this->normaliseServer($result['decoded']['server']);
    }

    /** Absence is represented explicitly as `deleted`. */
    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $result = $this->request('GET', '/servers/' . $id, $token);
        if ((int) $result['status'] === 404) {
            return ['id' => $id, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null, 'operation_id' => null];
        }
        if (!isset($result['decoded']['server']) || !is_array($result['decoded']['server'])) {
            throw new ProviderOperationException('The Hetzner Cloud API returned no server for the read call.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return $this->normaliseServer($result['decoded']['server']);
    }

    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $result = $this->request('DELETE', '/servers/' . $id, $token);
        if ((int) $result['status'] === 404) {
            // Already gone: deletion is idempotent.
            return ['id' => $id, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null, 'operation_id' => null];
        }
        return ['id' => $id, 'status' => 'deleting', 'ipv4' => null, 'ipv6' => null,
            'operation_id' => $this->optionalActionId($result['decoded'])];
    }

    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return $this->simpleAction($credentials, $providerServerId, 'reboot');
    }

    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return $this->simpleAction($credentials, $providerServerId, 'poweron');
    }

    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        return $this->simpleAction($credentials, $providerServerId, 'poweroff');
    }

    public function rebuildServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $spec = $this->spec($spec);
        list($actionId) = $this->postAction($token, $id, 'rebuild', ['image' => (string) $spec['image']]);
        return ['id' => $id, 'status' => 'rebuilding', 'ipv4' => null, 'ipv6' => null, 'operation_id' => $actionId];
    }

    public function resizeServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $spec = $this->spec($spec);
        $type = $this->selectServerType($token, $spec);
        list($actionId) = $this->postAction($token, $id, 'change_type', [
            'server_type' => (string) $type['name'],
            'upgrade_disk' => true,
        ]);
        return ['id' => $id, 'status' => 'resizing', 'ipv4' => null, 'ipv6' => null, 'operation_id' => $actionId];
    }

    public function createSnapshot(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $description = 'ch247-' . substr(hash('sha256', (string) $idempotencyKey), 0, 32);
        list($actionId, $decoded) = $this->postAction($token, $id, 'create_snapshot', [
            'description' => $description,
        ]);
        if (!isset($decoded['snapshot']) || !is_array($decoded['snapshot'])) {
            throw new ProviderOperationException('The Hetzner Cloud API returned no snapshot for the create call.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        $snapshotId = isset($decoded['snapshot']['id']) ? $decoded['snapshot']['id'] : null;
        if (!is_int($snapshotId) && !(is_string($snapshotId) && preg_match('/^[0-9]+$/', $snapshotId))) {
            throw new ProviderOperationException('The Hetzner Cloud API returned a snapshot without a valid id.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return ['id' => (string) $snapshotId, 'status' => 'creating', 'ipv4' => null, 'ipv6' => null,
            'operation_id' => $actionId];
    }

    public function deleteSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $snapshotId = $this->providerId($snapshotId);
        $result = $this->request('DELETE', '/snapshots/' . $snapshotId, $token);
        if ((int) $result['status'] === 404) {
            return ['id' => $snapshotId, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null, 'operation_id' => null];
        }
        return ['id' => $snapshotId, 'status' => 'deleting', 'ipv4' => null, 'ipv6' => null,
            'operation_id' => $this->optionalActionId($result['decoded'])];
    }

    public function restoreSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        $snapshotId = $this->providerId($snapshotId);
        list($actionId) = $this->postAction($token, $id, 'restore_snapshot', ['snapshot' => (int) $snapshotId]);
        return ['id' => $id, 'status' => 'restoring', 'ipv4' => null, 'ipv6' => null, 'operation_id' => $actionId];
    }

    /** Not advertised; Hetzner exposes no per-server metrics endpoint. */
    public function getMetrics(array $credentials, array $accountConfig, $providerServerId)
    {
        throw new ProviderUnavailableException('The Hetzner Cloud adapter does not expose provider metrics.', [
            'provider_code' => $this->key(),
            'capability' => 'server.metrics',
            'error_code' => 'PROVIDER_UNAVAILABLE',
        ]);
    }

    /* ------------------------------------------------------------ internals */

    private function simpleAction(array $credentials, $providerServerId, $action)
    {
        $token = $this->token($credentials);
        $id = $this->providerId($providerServerId);
        list($actionId) = $this->postAction($token, $id, $action);
        return ['confirmed' => true, 'operation_id' => $actionId];
    }

    /** @return array [actionId, decoded] */
    private function postAction($token, $providerServerId, $action, array $body = [])
    {
        $id = $this->providerId($providerServerId);
        $result = $this->request('POST', '/servers/' . $id . '/actions/' . $action, $token, ['json' => $body]);
        return $this->actionResult($result['decoded']);
    }

    /** @return array [actionId, decoded] */
    private function actionResult(array $decoded)
    {
        if (!isset($decoded['action']) || !is_array($decoded['action'])) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an action without details.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        $actionId = isset($decoded['action']['id']) ? $decoded['action']['id'] : null;
        if (!is_int($actionId) && !(is_string($actionId) && preg_match('/^[0-9]+$/', $actionId))) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an action without a valid id.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return [(string) $actionId, $decoded];
    }

    /** Action id when the provider returns one; null for empty 204-style responses. */
    private function optionalActionId(array $decoded)
    {
        if (!isset($decoded['action']) || !is_array($decoded['action'])) {
            return null;
        }
        $actionId = isset($decoded['action']['id']) ? $decoded['action']['id'] : null;
        if (!is_int($actionId) && !(is_string($actionId) && preg_match('/^[0-9]+$/', $actionId))) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an action without a valid id.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return (string) $actionId;
    }

    /** @return array|null the first server carrying the idempotency label */
    private function findByLabel($token, $label)
    {
        $result = $this->request('GET', '/servers', $token, [
            'query' => ['label_selector' => self::IDEMPOTENCY_LABEL . '=' . $label],
        ]);
        $servers = isset($result['decoded']['servers']) && is_array($result['decoded']['servers'])
            ? $result['decoded']['servers'] : [];
        foreach ($servers as $server) {
            if (is_array($server)) {
                return $server;
            }
        }
        return null;
    }

    /** Select the provider-catalog server type exactly matching the requested size. */
    private function selectServerType($token, array $spec)
    {
        $result = $this->request('GET', '/server_types', $token);
        $types = isset($result['decoded']['server_types']) && is_array($result['decoded']['server_types'])
            ? $result['decoded']['server_types'] : [];
        $matches = [];
        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            $name = isset($type['name']) ? (string) $type['name'] : '';
            if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $name)) {
                continue;
            }
            $cores = isset($type['cores']) ? (int) $type['cores'] : 0;
            $memoryMb = isset($type['memory']) ? (int) round(((float) $type['memory']) * 1024) : 0;
            $disk = isset($type['disk']) ? (int) $type['disk'] : 0;
            if ($cores === (int) $spec['cpu_cores'] && $memoryMb === (int) $spec['memory_mb']
                && $disk === (int) $spec['storage_gb']) {
                $matches[] = $type;
            }
        }
        if (!$matches) {
            throw new ProviderOperationException('No Hetzner Cloud server type matches the requested size.', [
                'error_code' => 'SERVER_TYPE_UNAVAILABLE',
                'cpu_cores' => (int) $spec['cpu_cores'],
                'memory_mb' => (int) $spec['memory_mb'],
                'storage_gb' => (int) $spec['storage_gb'],
            ]);
        }
        usort($matches, function ($a, $b) {
            return (int) (isset($a['id']) ? $a['id'] : 0) <=> (int) (isset($b['id']) ? $b['id'] : 0);
        });
        return $matches[0];
    }

    /** @return array normalized provider resource (ProviderResource-compatible) */
    private function normaliseServer(array $server)
    {
        $id = isset($server['id']) ? $server['id'] : null;
        if (!is_int($id) && !(is_string($id) && preg_match('/^[0-9]+$/', $id))) {
            throw new ProviderOperationException('The Hetzner Cloud API returned a server without a valid id.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        $status = isset($server['status']) ? strtolower(trim((string) $server['status'])) : '';
        if ($status === '' || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $status)) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an invalid server status.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        $ipv4 = null;
        $ipv6 = null;
        if (isset($server['public_net']) && is_array($server['public_net'])) {
            $net = $server['public_net'];
            $ipv4 = $this->ip(isset($net['ipv4']['ip']) ? $net['ipv4']['ip'] : null, FILTER_FLAG_IPV4);
            $ipv6 = $this->ipv6(isset($net['ipv6']['ip']) ? $net['ipv6']['ip'] : null);
        }
        return [
            'id' => (string) $id,
            'status' => $status,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'operation_id' => null,
        ];
    }

    private function ip($value, $flag)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an invalid IP address.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return $value;
    }

    /** Hetzner returns the IPv6 network with its prefix length; keep the address only. */
    private function ipv6($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        $prefix = strpos($value, '/');
        if ($prefix !== false) {
            $value = substr($value, 0, $prefix);
        }
        return $this->ip($value, FILTER_FLAG_IPV6);
    }

    /** Map the customer-facing name to a valid provider hostname. */
    private function providerName($name)
    {
        $slug = strtolower(trim((string) $name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(substr($slug, 0, 63), '-');
        if ($slug === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug)) {
            throw new ProviderOperationException('The server name cannot be mapped to a valid provider hostname.', [
                'error_code' => 'PROVIDER_NAME_INVALID',
            ]);
        }
        return $slug;
    }

    private function idempotencyLabel($idempotencyKey)
    {
        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            throw new ProviderOperationException('An idempotency key is required for provider operations.', [
                'error_code' => 'IDEMPOTENCY_KEY_REQUIRED',
            ]);
        }
        return substr(hash('sha256', $key), 0, 32);
    }

    private function spec(array $spec)
    {
        $required = ['name', 'region', 'image', 'cpu_cores', 'memory_mb', 'storage_gb'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $spec)) {
                throw new ProviderOperationException('The server specification is incomplete.', [
                    'error_code' => 'SPEC_INVALID', 'field' => $field,
                ]);
            }
        }
        foreach (['cpu_cores', 'memory_mb', 'storage_gb'] as $field) {
            if (!is_int($spec[$field]) && !(is_string($spec[$field]) && preg_match('/^[0-9]+$/', $spec[$field]))) {
                throw new ProviderOperationException('The server specification is incomplete.', [
                    'error_code' => 'SPEC_INVALID', 'field' => $field,
                ]);
            }
            $spec[$field] = (int) $spec[$field];
        }
        return $spec;
    }

    private function token(array $credentials)
    {
        $token = isset($credentials['api_token']) ? (string) $credentials['api_token'] : '';
        if (trim($token) === '' || trim($token) !== $token || strlen($token) > 4096
            || preg_match('/[\r\n\x00]/', $token)) {
            throw new ConfigurationException('A Hetzner Cloud API token is required.', [
                'error_code' => 'CONFIGURATION_REQUIRED',
            ]);
        }
        return $token;
    }

    private function providerId($providerServerId)
    {
        $id = trim((string) $providerServerId);
        if ($id === '' || !preg_match('/^[0-9]{1,19}$/', $id)) {
            throw new ProviderOperationException('The provider server identifier is invalid.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return $id;
    }

    /**
     * One Hetzner Cloud API call. Transport failures, 429 and 5xx are
     * retryable; 401/403 are authentication failures; 404 is returned to the
     * caller for explicit-absence handling; other non-2xx fail closed with the
     * provider's own (clipped) error message. Credentials never appear in
     * exceptions.
     *
     * @return array ['status' => int, 'decoded' => array|null]
     */
    private function request($method, $path, $token, array $options = [])
    {
        $url = self::API_BASE . $path;
        if (!empty($options['query']) && is_array($options['query'])) {
            $url .= (strpos($path, '?') === false ? '?' : '&')
                . http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
            unset($options['query']);
        }
        $options += [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'verify_tls' => true,
            'timeout' => 30,
            'connect_timeout' => 8,
            'max_bytes' => 1048576,
            'redact_response' => true,
        ];
        $response = Http::request($method, $url, $options);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableProviderException('The Hetzner Cloud API could not complete the request.', [
                'http_status' => $status,
                'error_code' => 'SERVICE_UNAVAILABLE',
            ]);
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationException('Hetzner Cloud rejected the configured API token.', [
                'http_status' => $status,
                'error_code' => 'AUTHENTICATION_FAILED',
            ]);
        }
        if ($status === 404) {
            return ['status' => 404, 'decoded' => null];
        }
        if ($status < 200 || $status >= 300) {
            $message = 'The Hetzner Cloud API returned an unsuccessful HTTP status.';
            $decoded = json_decode(isset($response['body']) ? (string) $response['body'] : '', true);
            if (is_array($decoded) && isset($decoded['error']['message'])
                && is_string($decoded['error']['message'])) {
                $message = Str::clip($decoded['error']['message'], 300);
            }
            throw new ProviderOperationException($message, [
                'http_status' => $status,
                'error_code' => 'PROVISIONING_FAILED',
            ]);
        }
        $body = isset($response['body']) ? (string) $response['body'] : '';
        if (trim($body) === '') {
            // 204 No Content and other empty 2xx responses are valid.
            return ['status' => $status, 'decoded' => []];
        }
        $decoded = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ProviderOperationException('The Hetzner Cloud API returned an invalid JSON response.', [
                'error_code' => 'PROVIDER_RESPONSE_INVALID',
            ]);
        }
        return ['status' => $status, 'decoded' => $decoded];
    }
}
