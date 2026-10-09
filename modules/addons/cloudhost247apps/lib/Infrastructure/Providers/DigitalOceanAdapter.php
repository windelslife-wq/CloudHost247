<?php
/**
 * DigitalOcean Droplets API v2. Only the narrow, testable VPS lifecycle slice.
 * Fixed HTTPS endpoint; no caller-supplied URL or TLS override. A lost create
 * response is AMBIGUOUS: never automatically retry POST. Operators must
 * reconcile the deterministic tag before manually retrying a failed job.
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

class DigitalOceanAdapter implements InfrastructureProviderInterface
{
    const API_BASE = 'https://api.digitalocean.com/v2';

    public function key() { return 'digitalocean'; }
    public function name() { return 'DigitalOcean'; }

    public function capabilities()
    {
        return [
            'server.create' => true, 'server.get' => true, 'server.delete' => true,
            'server.reboot' => true, 'server.power_on' => true, 'server.power_off' => true,
            'server.rebuild' => false, 'server.resize' => false,
            'snapshot.create' => false, 'snapshot.delete' => false,
            'snapshot.restore' => false, 'server.metrics' => false,
        ];
    }

    public function verifyCredentials(array $credentials, array $accountConfig)
    {
        $token = $this->token($credentials);
        // Verify both identity and the SSH key before marking an account usable.
        $account = $this->request('GET', '/account', $token);
        if ($account['status'] !== 200 || !isset($account['data']['account'])
            || !is_array($account['data']['account'])
            || !isset($account['data']['account']['status'])
            || $account['data']['account']['status'] !== 'active') {
            return false;
        }
        $this->verifySshKey($token, $accountConfig);
        $this->sizeSlug($accountConfig);
        return true;
    }

    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $spec = $this->spec($spec);
        $tag = $this->tag($idempotencyKey);
        $slug = $this->sizeSlug($accountConfig);
        $existing = $this->findByTag($token, $tag);
        if ($existing !== null) {
            $this->assertIdentity($existing, $spec, $slug, $tag);
            return $this->normalise($existing);
        }
        $this->checkSize($token, $slug, $spec);
        $sshKey = $this->verifySshKey($token, $accountConfig);
        $body = [
            'name' => $this->hostname($spec['name']),
            'region' => $spec['region'],
            'size' => $slug,
            'image' => $spec['image'],
            'ssh_keys' => [(int) $sshKey],
            'tags' => [$tag],
            'backups' => false,
            'ipv6' => false,
        ];
        // No DigitalOcean-enforced idempotency key exists for this endpoint.
        // A transport failure, 5xx or malformed success may follow acceptance:
        // fail terminally rather than re-POSTing. The worker will not retry.
        try {
            $result = $this->request('POST', '/droplets', $token, ['json' => $body]);
        } catch (ProviderOperationException $e) {
            throw new ProviderOperationException(
                'DigitalOcean create outcome is uncertain; reconcile the Droplet tag before any manual retry.',
                ['error_code' => 'PROVIDER_CREATE_UNCERTAIN'], $e
            );
        }
        if ($result['status'] !== 202 || !isset($result['data']['droplet'])
            || !is_array($result['data']['droplet'])) {
            throw new ProviderOperationException(
                'DigitalOcean create outcome is uncertain; reconcile the Droplet tag before any manual retry.',
                ['error_code' => 'PROVIDER_CREATE_UNCERTAIN']
            );
        }
        try {
            $this->assertIdentity($result['data']['droplet'], $spec, $slug, $tag);
            return $this->normalise($result['data']['droplet']);
        } catch (ProviderOperationException $e) {
            throw new ProviderOperationException(
                'DigitalOcean create response is incomplete; reconcile the Droplet tag before retrying.',
                ['error_code' => 'PROVIDER_CREATE_UNCERTAIN'], $e);
        }
    }

    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $id = $this->id($providerServerId);
        $result = $this->request('GET', '/droplets/' . $id, $this->token($credentials));
        if ($result['status'] === 404) {
            return $this->deleted($id);
        }
        if ($result['status'] !== 200 || !isset($result['data']['droplet'])
            || !is_array($result['data']['droplet'])) {
            throw $this->invalidResponse();
        }
        $server = $this->normalise($result['data']['droplet']);
        if ($server['id'] !== $id) {
            throw $this->invalidResponse();
        }
        return $server;
    }

    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $id = $this->id($providerServerId);
        $this->tag($idempotencyKey);
        $result = $this->request('DELETE', '/droplets/' . $id, $this->token($credentials));
        if ($result['status'] !== 204 && $result['status'] !== 404) {
            throw $this->invalidResponse();
        }
        // The API's 204 means deletion succeeded; a subsequent 404 is also success.
        return $this->deleted($id);
    }

    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'reboot'); }

    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'power_on'); }

    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'power_off'); }

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
        throw new ProviderUnavailableException('This DigitalOcean operation is not supported by the adapter.',
            ['provider_code' => $this->key(), 'capability' => $capability, 'error_code' => 'PROVIDER_UNAVAILABLE']);
    }

    private function action(array $credentials, $providerServerId, $idempotencyKey, $type)
    {
        $id = $this->id($providerServerId);
        $this->tag($idempotencyKey);
        // Actions are not guaranteed idempotent on ambiguous responses either.
        try {
            $result = $this->request('POST', '/droplets/' . $id . '/actions', $this->token($credentials),
                ['json' => ['type' => $type]]);
        } catch (ProviderOperationException $e) {
            throw new ProviderOperationException('DigitalOcean action outcome is uncertain; check the Droplet before retrying.',
                ['error_code' => 'PROVIDER_ACTION_UNCERTAIN'], $e);
        }
        if ($result['status'] !== 201 || !isset($result['data']['action'])
            || !is_array($result['data']['action'])
            || !isset($result['data']['action']['id'])) {
            throw $this->invalidResponse();
        }
        return ['confirmed' => true, 'operation_id' => $this->id($result['data']['action']['id'])];
    }

    private function findByTag($token, $tag)
    {
        // Explicitly check pagination, uniqueness and the echoed tag. Never
        // follow provider-supplied next links or treat a malformed list as empty.
        $matches = [];
        for ($page = 1; $page <= 20; $page++) {
            $result = $this->request('GET', '/droplets', $token,
                ['query' => ['tag_name' => $tag, 'per_page' => 200, 'page' => $page]]);
            if ($result['status'] !== 200 || !isset($result['data']['droplets'])
                || !is_array($result['data']['droplets']) || !Str::isList($result['data']['droplets'])) {
                throw $this->invalidResponse();
            }
            foreach ($result['data']['droplets'] as $droplet) {
                if (!is_array($droplet) || !isset($droplet['tags']) || !is_array($droplet['tags'])
                    || !in_array($tag, $droplet['tags'], true)) {
                    throw $this->invalidResponse();
                }
                $matches[] = $droplet;
                if (count($matches) > 1) {
                    throw new ProviderOperationException('Multiple Droplets share the same idempotency tag; reconcile manually.',
                        ['error_code' => 'PROVIDER_DUPLICATE_RESOURCE']);
                }
            }
            if (!$this->hasNext($result['data'])) {
                return $matches ? $matches[0] : null;
            }
        }
        throw new ProviderOperationException('DigitalOcean tag lookup exceeded the pagination limit.',
            ['error_code' => 'PROVIDER_RESPONSE_INVALID']);
    }

    private function checkSize($token, $slug, array $spec)
    {
        for ($page = 1; $page <= 20; $page++) {
            $result = $this->request('GET', '/sizes', $token, ['query' => ['per_page' => 200, 'page' => $page]]);
            if ($result['status'] !== 200 || !isset($result['data']['sizes'])
                || !is_array($result['data']['sizes']) || !Str::isList($result['data']['sizes'])) {
                throw $this->invalidResponse();
            }
            foreach ($result['data']['sizes'] as $size) {
                if (!is_array($size) || !isset($size['slug']) || $size['slug'] !== $slug) {
                    continue;
                }
                if (!isset($size['available'], $size['vcpus'], $size['memory'], $size['disk'], $size['regions'])
                    || $size['available'] !== true || !is_int($size['vcpus'])
                    || !is_int($size['memory']) || !is_int($size['disk'])
                    || !is_array($size['regions']) || !in_array($spec['region'], $size['regions'], true)
                    || !empty($size['gpu_info']) // GPU droplets are omitted from tag-filtered listings.
                    || $size['vcpus'] !== $spec['cpu_cores']
                    || $size['memory'] !== $spec['memory_mb'] || $size['disk'] !== $spec['storage_gb']) {
                    throw new ProviderOperationException('The configured DigitalOcean size is unavailable or differs from the product.',
                        ['error_code' => 'SERVER_TYPE_UNAVAILABLE']);
                }
                return;
            }
            if (!$this->hasNext($result['data'])) {
                break;
            }
        }
        throw new ProviderOperationException('The configured DigitalOcean size was not found in the provider catalog.',
            ['error_code' => 'SERVER_TYPE_UNAVAILABLE']);
    }

    private function hasNext(array $data)
    {
        if (!isset($data['links']) || !is_array($data['links'])
            || !isset($data['links']['pages']) || !is_array($data['links']['pages'])) {
            // A missing pagination structure is not proof of a complete list.
            throw $this->invalidResponse();
        }
        $pages = $data['links']['pages'];
        if (!array_key_exists('next', $pages) || $pages['next'] === null) {
            return false;
        }
        if (!is_string($pages['next']) || !Str::startsWith($pages['next'], self::API_BASE . '/')) {
            throw $this->invalidResponse();
        }
        return true; // Next link is a hint only; URL is never used.
    }

    private function verifySshKey($token, array $config)
    {
        $key = isset($config['ssh_key_id']) ? $this->id($config['ssh_key_id']) : null;
        if ($key === null) {
            throw new ConfigurationException('A DigitalOcean account SSH key ID is required.');
        }
        $result = $this->request('GET', '/account/keys/' . $key, $token);
        if ($result['status'] !== 200 || !isset($result['data']['ssh_key']['id'])
            || $this->id($result['data']['ssh_key']['id']) !== $key) {
            throw new ConfigurationException('The configured DigitalOcean SSH key was not confirmed for this account.');
        }
        return $key;
    }

    /** A tag alone is not evidence that a recovered Droplet belongs to this product. */
    private function assertIdentity(array $droplet, array $spec, $slug, $tag)
    {
        if (!isset($droplet['name'], $droplet['size_slug'], $droplet['region']['slug'],
            $droplet['image']['slug'], $droplet['tags'])
            || !is_array($droplet['tags'])
            || $droplet['name'] !== $this->hostname($spec['name'])
            || $droplet['size_slug'] !== $slug
            || $droplet['region']['slug'] !== $spec['region']
            || $droplet['image']['slug'] !== $spec['image']
            || !in_array($tag, $droplet['tags'], true)) {
            throw new ProviderOperationException('The tagged DigitalOcean Droplet does not match this product.',
                ['error_code' => 'PROVIDER_RESOURCE_MISMATCH']);
        }
    }

    private function normalise(array $droplet)
    {
        $id = isset($droplet['id']) ? $this->id($droplet['id']) : null;
        $status = isset($droplet['status']) ? $droplet['status'] : null;
        if ($id === null || !in_array($status, ['new', 'active', 'off', 'archive'], true)) {
            throw $this->invalidResponse();
        }
        $ipv4 = null;
        $ipv6 = null;
        if (isset($droplet['networks'])) {
            if (!is_array($droplet['networks'])) { throw $this->invalidResponse(); }
            foreach (['v4' => FILTER_FLAG_IPV4, 'v6' => FILTER_FLAG_IPV6] as $family => $flag) {
                if (!isset($droplet['networks'][$family])) { continue; }
                if (!is_array($droplet['networks'][$family]) || !Str::isList($droplet['networks'][$family])) {
                    throw $this->invalidResponse();
                }
                foreach ($droplet['networks'][$family] as $network) {
                    if (!is_array($network) || !isset($network['type'])) { throw $this->invalidResponse(); }
                    if ($network['type'] !== 'public') { continue; }
                    $ip = isset($network['ip_address']) ? $network['ip_address'] : null;
                    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, $flag) === false) {
                        throw $this->invalidResponse();
                    }
                    if ($family === 'v4' && $ipv4 === null) { $ipv4 = $ip; }
                    if ($family === 'v6' && $ipv6 === null) { $ipv6 = $ip; }
                }
            }
        }
        return ['id' => $id, 'status' => $status, 'ipv4' => $ipv4,
            'ipv6' => $ipv6, 'operation_id' => null];
    }

    private function deleted($id)
    { return ['id' => $id, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null, 'operation_id' => null]; }

    private function spec(array $spec)
    {
        foreach (['name', 'region', 'image'] as $field) {
            if (!isset($spec[$field]) || !is_string($spec[$field])
                || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$/', $spec[$field])) {
                throw new ProviderOperationException('Invalid DigitalOcean product specification.', ['error_code' => 'SPEC_INVALID']);
            }
        }
        foreach (['cpu_cores', 'memory_mb', 'storage_gb'] as $field) {
            if (!isset($spec[$field]) || !is_int($spec[$field]) || $spec[$field] <= 0) {
                throw new ProviderOperationException('Invalid DigitalOcean product specification.', ['error_code' => 'SPEC_INVALID']);
            }
        }
        return $spec;
    }

    private function hostname($name)
    {
        $name = strtolower($name);
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name)) {
            throw new ProviderOperationException('The product hostname is not valid for DigitalOcean.',
                ['error_code' => 'PROVIDER_NAME_INVALID']);
        }
        return $name;
    }

    private function tag($key)
    {
        if (!is_string($key) || $key === '' || strlen($key) > 1024) {
            throw new ProviderOperationException('A valid idempotency key is required.',
                ['error_code' => 'IDEMPOTENCY_KEY_REQUIRED']);
        }
        return 'ch247-' . substr(hash('sha256', $key), 0, 32);
    }

    private function sizeSlug(array $config)
    {
        $slug = isset($config['size_slug']) ? $config['size_slug'] : null;
        if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)
            || Str::startsWith($slug, 'gpu-')) {
            throw new ConfigurationException('Configure a DigitalOcean size slug for this provider account.');
        }
        return $slug;
    }

    private function token(array $credentials)
    {
        $token = isset($credentials['api_token']) ? $credentials['api_token'] : null;
        if (!is_string($token) || $token === '' || trim($token) !== $token
            || strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new ConfigurationException('A DigitalOcean API token is required.');
        }
        return $token;
    }

    private function id($id)
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string) $id)) {
            throw $this->invalidResponse();
        }
        return (string) $id;
    }

    private function invalidResponse()
    { return new ProviderOperationException('DigitalOcean returned an invalid or incomplete response.',
        ['error_code' => 'PROVIDER_RESPONSE_INVALID']); }

    private function request($method, $path, $token, array $options = [])
    {
        $url = self::API_BASE . $path;
        if (isset($options['query'])) {
            $url .= '?' . http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
            unset($options['query']);
        }
        $options += [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'verify_tls' => true, 'timeout' => 30, 'connect_timeout' => 8,
            'max_bytes' => 1048576, 'redact_response' => true,
        ];
        $response = Http::request($method, $url, $options);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if (!empty($response['error']) || $status === 0 || $status === 429 || $status >= 500) {
            throw new RetryableProviderException('DigitalOcean API request did not complete.',
                ['error_code' => 'SERVICE_UNAVAILABLE', 'http_status' => $status]);
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationException('DigitalOcean rejected the configured token or its permissions.',
                ['error_code' => 'AUTHENTICATION_FAILED', 'http_status' => $status]);
        }
        if ($status === 404) { return ['status' => 404, 'data' => []]; }
        if ($status < 200 || $status >= 300) {
            throw new ProviderOperationException('DigitalOcean rejected the operation.',
                ['error_code' => 'PROVISIONING_FAILED', 'http_status' => $status]);
        }
        if (strlen((string) $response['body']) > 1048576) { throw $this->invalidResponse(); }
        if ($status === 204 && trim((string) $response['body']) === '') {
            return ['status' => 204, 'data' => []];
        }
        $data = json_decode((string) $response['body'], true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) { throw $this->invalidResponse(); }
        return ['status' => $status, 'data' => $data];
    }
}
