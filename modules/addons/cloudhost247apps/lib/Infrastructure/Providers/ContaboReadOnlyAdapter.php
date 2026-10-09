<?php
/** Contabo v1 existing-instance inventory. No purchases, mutations or cancellation. */
namespace Ch247Apps\Infrastructure\Providers;

use Ch247Apps\Core\ConfigurationException;
use Ch247Apps\Core\Http;
use Ch247Apps\Core\ProviderAuthenticationException;
use Ch247Apps\Core\ProviderOperationException;
use Ch247Apps\Core\ProviderUnavailableException;
use Ch247Apps\Core\RetryableProviderException;
use Ch247Apps\Core\Str;
use Ch247Apps\Infrastructure\InfrastructureProviderInterface;

class ContaboReadOnlyAdapter implements InfrastructureProviderInterface
{
    const API_BASE = 'https://api.contabo.com/v1';
    const TOKEN_URL = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token';

    public function key() { return 'contabo'; }
    public function name() { return 'Contabo (existing instances only)'; }
    public function capabilities()
    {
        return [
            'server.create' => false, 'server.get' => true, 'server.delete' => false,
            'server.reboot' => false, 'server.power_on' => false, 'server.power_off' => false,
            'server.rebuild' => false, 'server.resize' => false,
            'snapshot.create' => false, 'snapshot.delete' => false,
            'snapshot.restore' => false, 'server.metrics' => false,
        ];
    }

    public function verifyCredentials(array $credentials, array $accountConfig)
    {
        $this->accountIds($accountConfig);
        $token = $this->accessToken($credentials);
        $result = $this->api('GET', '/compute/instances', $token, ['query' => ['page' => 1, 'size' => 1]]);
        if ($result['status'] !== 200 || !isset($result['data']['data'])
            || !is_array($result['data']['data']) || !Str::isList($result['data']['data'])
            || !isset($result['data']['_pagination']) || !is_array($result['data']['_pagination'])) {
            throw $this->invalidResponse();
        }
        // This confirms the read permission. Instance ownership is checked
        // independently against tenant/customer ids on each adoption read.
        return true;
    }

    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $id = $this->id($providerServerId);
        $ids = $this->accountIds($accountConfig);
        $result = $this->api('GET', '/compute/instances/' . $id, $this->accessToken($credentials));
        if ($result['status'] === 404) {
            return ['id' => $id, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null,
                'operation_id' => null];
        }
        if ($result['status'] !== 200 || !isset($result['data']['data'])
            || !is_array($result['data']['data']) || !Str::isList($result['data']['data'])
            || count($result['data']['data']) !== 1 || !is_array($result['data']['data'][0])) {
            throw $this->invalidResponse();
        }
        $item = $result['data']['data'][0];
        if (!isset($item['instanceId']) || (string) $item['instanceId'] !== $id
            || !isset($item['tenantId'], $item['customerId'])
            || $item['tenantId'] !== $ids['tenant_id']
            || $item['customerId'] !== $ids['customer_id']) {
            throw new ProviderOperationException('The Contabo instance does not belong to the configured account.',
                ['error_code' => 'PROVIDER_RESOURCE_MISMATCH']);
        }
        $status = isset($item['status']) ? $item['status'] : null;
        if ($status === 'running') { $state = 'active'; }
        elseif ($status === 'stopped') { $state = 'off'; }
        elseif (in_array($status, ['provisioning', 'installing', 'verification_required', 'pending_payment'], true)) {
            $state = 'initializing';
        } elseif ($status === 'error') { $state = 'failed'; }
        else { throw $this->invalidResponse(); }
        if (!isset($item['region'], $item['imageId'], $item['cpuCores'], $item['ramMb'], $item['diskMb'],
            $item['osType'], $item['productId']) || $item['osType'] !== 'Linux'
            || !is_string($item['region']) || !is_string($item['imageId'])
            || !is_int($item['cpuCores']) || $item['cpuCores'] < 1
            || !preg_match('/^[1-9][0-9]*$/D', (string) $item['ramMb'])
            || !preg_match('/^[1-9][0-9]*$/D', (string) $item['diskMb'])
            || ((int) $item['diskMb']) % 1024 !== 0) {
            throw $this->invalidResponse();
        }
        $v4 = isset($item['ipConfig']['v4']['ip']) ? $item['ipConfig']['v4']['ip'] : null;
        $v6 = isset($item['ipConfig']['v6']['ip']) ? $item['ipConfig']['v6']['ip'] : null;
        $ipv4 = $this->ip($v4, FILTER_FLAG_IPV4);
        $ipv6 = $this->ip($v6, FILTER_FLAG_IPV6);
        return [
            'id' => $id, 'status' => $state, 'ipv4' => $ipv4, 'ipv6' => $ipv6,
            'operation_id' => null,
            // Only provider-sourced, non-secret data needed to check an operator
            // adoption request; callers must never persist the raw response.
            'spec' => [
                'region' => $item['region'], 'image' => $item['imageId'],
                'cpu_cores' => $item['cpuCores'], 'memory_mb' => (int) $item['ramMb'],
                'storage_gb' => (int) ((int) $item['diskMb'] / 1024),
            ],
            'product_id' => $item['productId'],
        ];
    }

    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    { $this->unsupported('server.create'); }
    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { $this->unsupported('server.delete'); }
    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { $this->unsupported('server.reboot'); }
    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { $this->unsupported('server.power_on'); }
    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { $this->unsupported('server.power_off'); }
    public function rebuildServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    { $this->unsupported('server.rebuild'); }
    public function resizeServer(array $credentials, array $accountConfig, $providerServerId, array $spec, $idempotencyKey)
    { $this->unsupported('server.resize'); }
    public function createSnapshot(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { $this->unsupported('snapshot.create'); }
    public function deleteSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    { $this->unsupported('snapshot.delete'); }
    public function restoreSnapshot(array $credentials, array $accountConfig, $providerServerId, $snapshotId, $idempotencyKey)
    { $this->unsupported('snapshot.restore'); }
    public function getMetrics(array $credentials, array $accountConfig, $providerServerId)
    { $this->unsupported('server.metrics'); }

    private function unsupported($capability)
    {
        throw new ProviderUnavailableException('Contabo adoption never purchases or mutates provider contracts.',
            ['provider_code' => 'contabo', 'capability' => $capability, 'error_code' => 'PROVIDER_UNAVAILABLE']);
    }

    private function accessToken(array $credentials)
    {
        $fields = [];
        foreach (['client_id', 'client_secret', 'username', 'password'] as $field) {
            $value = isset($credentials[$field]) ? $credentials[$field] : null;
            if (!is_string($value) || $value === '' || strlen($value) > 4096
                || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new ConfigurationException('The Contabo OAuth credentials are incomplete.');
            }
            $fields[$field] = $value;
        }
        $response = Http::request('POST', self::TOKEN_URL, [
            'form' => $fields + ['grant_type' => 'password'],
            'verify_tls' => true, 'timeout' => 20, 'connect_timeout' => 8,
            'max_bytes' => 65536, 'redact_response' => true,
        ]);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableProviderException('The Contabo authentication service is unavailable.');
        }
        if (in_array($status, [400, 401, 403], true)) {
            throw new ProviderAuthenticationException('Contabo rejected the configured OAuth credentials.');
        }
        if ($status !== 200 || strlen((string) $response['body']) > 65536) { throw $this->invalidResponse(); }
        $data = json_decode((string) $response['body'], true);
        $token = is_array($data) && isset($data['access_token']) ? $data['access_token'] : null;
        if (!is_string($token) || $token === '' || strlen($token) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $token)) { throw $this->invalidResponse(); }
        return $token;
    }

    private function api($method, $path, $token, array $options = [])
    {
        $url = self::API_BASE . $path;
        if (isset($options['query'])) {
            $url .= '?' . http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
            unset($options['query']);
        }
        $options += [
            'headers' => ['Authorization' => 'Bearer ' . $token,
                'x-request-id' => Str::uuid4(), 'Accept' => 'application/json'],
            'verify_tls' => true, 'timeout' => 30, 'connect_timeout' => 8,
            'max_bytes' => 1048576, 'redact_response' => true,
        ];
        $response = Http::request($method, $url, $options);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableProviderException('Contabo instance read did not complete.');
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationException('Contabo rejected the token or read permission.');
        }
        if ($status === 404) { return ['status' => 404, 'data' => []]; }
        if ($status !== 200 || strlen((string) $response['body']) > 1048576) { throw $this->invalidResponse(); }
        $data = json_decode((string) $response['body'], true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) { throw $this->invalidResponse(); }
        return ['status' => 200, 'data' => $data];
    }

    private function accountIds(array $config)
    {
        $customer = isset($config['customer_id']) ? $config['customer_id'] : null;
        $tenant = isset($config['tenant_id']) ? $config['tenant_id'] : null;
        if (!is_string($customer) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $customer)
            || !is_string($tenant) || !preg_match('/^[A-Za-z0-9-]{1,32}$/D', $tenant)) {
            throw new ConfigurationException('Configure the Contabo customer and tenant IDs for adoption.');
        }
        return ['customer_id' => $customer, 'tenant_id' => $tenant];
    }

    private function id($id)
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)) {
            throw $this->invalidResponse();
        }
        return (string) $id;
    }

    private function ip($value, $flag)
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            throw $this->invalidResponse();
        }
        return $value;
    }

    private function invalidResponse()
    { return new ProviderOperationException('Contabo returned an incomplete or invalid read response.',
        ['error_code' => 'PROVIDER_RESPONSE_INVALID']); }
}
