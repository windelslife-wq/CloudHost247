<?php
/**
 * Narrow Vultr API v2 VPS adapter. No caller URL, TLS override, guessed plan,
 * default password persistence, or automatic retry of ambiguous billable POSTs.
 * Provider tags offer recovery, not atomic create idempotency.
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

class VultrAdapter implements InfrastructureProviderInterface
{
    const API_BASE = 'https://api.vultr.com/v2';

    public function key() { return 'vultr'; }
    public function name() { return 'Vultr'; }

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
        $account = $this->request('GET', '/account', $token);
        if ($account['status'] !== 200 || !isset($account['data']['account']['acls'])
            || !is_array($account['data']['account']['acls'])
            || !in_array('provisioning', $account['data']['account']['acls'], true)) {
            return false;
        }
        $this->planId($accountConfig);
        $this->verifySshKey($token, $accountConfig);
        return true;
    }

    public function createServer(array $credentials, array $accountConfig, array $spec, $idempotencyKey)
    {
        $token = $this->token($credentials);
        $spec = $this->spec($spec);
        $tag = $this->tag($idempotencyKey);
        $plan = $this->planId($accountConfig);
        $existing = $this->findByTag($token, $tag);
        if ($existing !== null) {
            $this->assertIdentity($existing, $spec, $plan, $tag);
            return $this->normalise($existing);
        }
        $this->checkPlan($token, $plan, $spec);
        $this->checkOs($token, (int) $spec['image']);
        $sshKey = $this->verifySshKey($token, $accountConfig);
        $body = [
            'region' => $spec['region'], 'plan' => $plan,
            'os_id' => (int) $spec['image'], 'hostname' => $this->hostname($spec['name']),
            'label' => $spec['name'], 'tags' => [$tag], 'sshkey_id' => [$sshKey],
            'backups' => 'disabled', 'enable_ipv6' => false, 'ddos_protection' => false,
            'activation_email' => false,
        ];
        // 201/202 can be billable even if the body is lost. No second POST.
        try {
            $result = $this->request('POST', '/instances', $token, ['json' => $body]);
            if (!in_array($result['status'], [201, 202], true) || !isset($result['data']['instance'])
                || !is_array($result['data']['instance'])) {
                throw $this->invalidResponse();
            }
            $instance = $result['data']['instance'];
            $this->assertIdentity($instance, $spec, $plan, $tag);
            return $this->normalise($instance);
        } catch (ProviderOperationException $e) {
            throw new ProviderOperationException(
                'Vultr create outcome is uncertain; reconcile the instance tag before any manual retry.',
                ['error_code' => 'PROVIDER_CREATE_UNCERTAIN'], $e);
        }
    }

    public function getServer(array $credentials, array $accountConfig, $providerServerId)
    {
        $id = $this->id($providerServerId);
        $result = $this->request('GET', '/instances/' . $id, $this->token($credentials));
        if ($result['status'] === 404) { return $this->deleted($id); }
        if ($result['status'] !== 200 || !isset($result['data']['instance'])
            || !is_array($result['data']['instance'])) { throw $this->invalidResponse(); }
        $instance = $this->normalise($result['data']['instance']);
        if ($instance['id'] !== $id) { throw $this->invalidResponse(); }
        return $instance;
    }

    public function deleteServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    {
        $id = $this->id($providerServerId);
        $this->tag($idempotencyKey);
        $result = $this->request('DELETE', '/instances/' . $id, $this->token($credentials));
        if ($result['status'] !== 204 && $result['status'] !== 404) { throw $this->invalidResponse(); }
        return $this->deleted($id);
    }

    public function rebootServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'reboot'); }
    public function powerOnServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'start'); }
    public function powerOffServer(array $credentials, array $accountConfig, $providerServerId, $idempotencyKey)
    { return $this->action($credentials, $providerServerId, $idempotencyKey, 'halt'); }

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
        throw new ProviderUnavailableException('This Vultr operation is not supported by the adapter.',
            ['provider_code' => $this->key(), 'capability' => $capability,
                'error_code' => 'PROVIDER_UNAVAILABLE']);
    }

    private function action(array $credentials, $providerServerId, $idempotencyKey, $action)
    {
        $id = $this->id($providerServerId);
        $this->tag($idempotencyKey);
        try {
            $result = $this->request('POST', '/instances/' . $id . '/' . $action, $this->token($credentials));
            if ($result['status'] !== 204) { throw $this->invalidResponse(); }
        } catch (ProviderOperationException $e) {
            throw new ProviderOperationException('Vultr action outcome is uncertain; inspect the instance before retrying.',
                ['error_code' => 'PROVIDER_ACTION_UNCERTAIN'], $e);
        }
        return ['confirmed' => true, 'operation_id' => null];
    }

    private function findByTag($token, $tag)
    {
        $found = null;
        $this->pages('instances', '/instances', $token, ['tag' => $tag], function ($instance) use ($tag, &$found) {
            if (!is_array($instance) || !$this->hasTag($instance, $tag)) {
                throw $this->invalidResponse();
            }
            if ($found !== null) {
                throw new ProviderOperationException('Multiple Vultr instances share the idempotency tag.',
                    ['error_code' => 'PROVIDER_DUPLICATE_RESOURCE']);
            }
            $found = $instance;
            return false; // Always check all cursor pages before trusting uniqueness.
        });
        return $found;
    }

    private function checkPlan($token, $id, array $spec)
    {
        $found = false;
        $this->pages('plans', '/plans', $token, ['type' => 'vc2'], function ($plan) use ($id, $spec, &$found) {
            if (!is_array($plan) || !isset($plan['id']) || $plan['id'] !== $id) { return false; }
            if ($found || !isset($plan['type'], $plan['vcpu_count'], $plan['ram'],
                $plan['disk'], $plan['locations']) || $plan['type'] !== 'vc2'
                || !is_int($plan['vcpu_count']) || !is_int($plan['ram']) || !is_int($plan['disk'])
                || !is_array($plan['locations']) || !in_array($spec['region'], $plan['locations'], true)
                || $plan['vcpu_count'] !== $spec['cpu_cores']
                || $plan['ram'] !== $spec['memory_mb'] || $plan['disk'] !== $spec['storage_gb']
                || (isset($plan['disk_count']) && $plan['disk_count'] !== 1)) {
                throw new ProviderOperationException('The configured Vultr plan differs from the approved product.',
                    ['error_code' => 'SERVER_TYPE_UNAVAILABLE']);
            }
            $found = true;
            return false;
        });
        if (!$found) {
            throw new ProviderOperationException('The configured Vultr plan is absent from the provider catalog.',
                ['error_code' => 'SERVER_TYPE_UNAVAILABLE']);
        }
        $availability = $this->request('GET', '/regions/' . $spec['region'] . '/availability', $token,
            ['query' => ['type' => 'vc2']]);
        if ($availability['status'] !== 200 || !isset($availability['data']['available_plans'])
            || !is_array($availability['data']['available_plans'])) { throw $this->invalidResponse(); }
        if (!in_array($id, $availability['data']['available_plans'], true)) {
            throw new ProviderOperationException('The Vultr plan is not available in the approved region.',
                ['error_code' => 'SERVER_TYPE_UNAVAILABLE']);
        }
    }

    private function checkOs($token, $osId)
    {
        $found = false;
        $this->pages('os', '/os', $token, [], function ($os) use ($osId, &$found) {
            if (!is_array($os) || !isset($os['id']) || $os['id'] !== $osId) { return false; }
            if ($found || !isset($os['family'], $os['arch']) || !is_string($os['family'])
                || !in_array(strtolower($os['family']), ['ubuntu', 'debian'], true)
                || $os['arch'] !== 'x64') {
                throw new ProviderOperationException('The Vultr OS ID is unsupported or inconsistent.',
                    ['error_code' => 'IMAGE_UNAVAILABLE']);
            }
            $found = true;
            return false;
        });
        if (!$found) {
            throw new ProviderOperationException('The approved Vultr OS ID is absent from the provider catalog.',
                ['error_code' => 'IMAGE_UNAVAILABLE']);
        }
    }

    /** Cursor tokens are data for a fixed path, never URLs or instructions to redirect. */
    private function pages($field, $path, $token, array $filter, callable $visit)
    {
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < 20; $page++) {
            $query = $filter + ['per_page' => 500];
            if ($cursor !== null) { $query['cursor'] = $cursor; }
            $result = $this->request('GET', $path, $token, ['query' => $query]);
            if ($result['status'] !== 200 || !isset($result['data'][$field])
                || !is_array($result['data'][$field]) || !Str::isList($result['data'][$field])
                || !isset($result['data']['meta']['links'])
                || !is_array($result['data']['meta']['links'])
                || !array_key_exists('next', $result['data']['meta']['links'])) {
                throw $this->invalidResponse();
            }
            foreach ($result['data'][$field] as $item) { $visit($item); }
            $next = $result['data']['meta']['links']['next'];
            if ($next === '' || $next === null) { return; }
            if (!is_string($next) || !preg_match('/^[A-Za-z0-9_=-]{1,512}$/D', $next)
                || isset($seen[$next])) { throw $this->invalidResponse(); }
            $seen[$next] = true;
            $cursor = $next;
        }
        throw new ProviderOperationException('Vultr catalog lookup exceeded the cursor limit.',
            ['error_code' => 'PROVIDER_RESPONSE_INVALID']);
    }

    private function verifySshKey($token, array $config)
    {
        if (!isset($config['ssh_key_id']) || !is_string($config['ssh_key_id'])
            || !$this->uuidValid($config['ssh_key_id'])) {
            throw new ConfigurationException('Configure a Vultr account SSH key UUID.');
        }
        $id = strtolower($config['ssh_key_id']);
        $result = $this->request('GET', '/ssh-keys/' . $id, $token);
        if ($result['status'] !== 200 || !isset($result['data']['ssh_key']['id'])
            || !is_string($result['data']['ssh_key']['id'])
            || strtolower($result['data']['ssh_key']['id']) !== $id) {
            throw new ConfigurationException('The configured Vultr SSH key was not confirmed for this account.');
        }
        // The response contains public key material; it is never returned or logged.
        return $id;
    }

    private function hasTag(array $instance, $tag)
    {
        // Current official clients use tags[]; the older API schema also
        // documents a singular tag. In either case require an exact echo.
        if (isset($instance['tags']) && is_array($instance['tags'])) {
            return Str::isList($instance['tags']) && in_array($tag, $instance['tags'], true);
        }
        return isset($instance['tag']) && is_string($instance['tag']) && $instance['tag'] === $tag;
    }

    private function assertIdentity(array $instance, array $spec, $plan, $tag)
    {
        if (!isset($instance['label'], $instance['hostname'], $instance['plan'],
            $instance['region'], $instance['os_id']) || !$this->hasTag($instance, $tag)
            || $instance['label'] !== $spec['name']
            || $instance['hostname'] !== $this->hostname($spec['name'])
            || $instance['plan'] !== $plan || $instance['region'] !== $spec['region']
            || $instance['os_id'] !== (int) $spec['image']) {
            throw new ProviderOperationException('The tagged Vultr instance does not match the approved product.',
                ['error_code' => 'PROVIDER_RESOURCE_MISMATCH']);
        }
    }

    private function normalise(array $instance)
    {
        if (!isset($instance['id']) || !is_string($instance['id'])) { throw $this->invalidResponse(); }
        $id = $this->id($instance['id']);
        if (!isset($instance['status']) || !is_string($instance['status'])) {
            throw $this->invalidResponse();
        }
        $status = $instance['status'];
        if ($status === 'active') {
            if (!isset($instance['power_status'], $instance['server_status'])
                || !is_string($instance['power_status']) || !is_string($instance['server_status'])) {
                throw $this->invalidResponse();
            }
            if ($instance['power_status'] !== 'running') {
                $status = 'off';
            } elseif ($instance['server_status'] === 'ok') {
                $status = 'active';
            } else {
                $status = 'initializing';
            }
        } elseif ($status === 'halted') {
            $status = 'off';
        } elseif (in_array($status, ['pending', 'rebooting', 'resizing'], true)) {
            $status = 'initializing';
        } else {
            throw $this->invalidResponse();
        }
        $ipv4 = $this->ip(isset($instance['main_ip']) ? $instance['main_ip'] : null, FILTER_FLAG_IPV4,
            ['0.0.0.0']);
        $ipv6 = $this->ip(isset($instance['v6_main_ip']) ? $instance['v6_main_ip'] : null, FILTER_FLAG_IPV6,
            ['::']);
        if (isset($instance['v6_networks'])) {
            if (!is_array($instance['v6_networks']) || !Str::isList($instance['v6_networks'])) {
                throw $this->invalidResponse();
            }
            foreach ($instance['v6_networks'] as $network) {
                if (!is_array($network)) { throw $this->invalidResponse(); }
                if ($ipv6 === null && isset($network['main_ip'])) {
                    $ipv6 = $this->ip($network['main_ip'], FILTER_FLAG_IPV6, ['::']);
                }
            }
        }
        // Never expose default_password, internal_ip, KVM URL or raw instance.
        return ['id' => $id, 'status' => $status, 'ipv4' => $ipv4,
            'ipv6' => $ipv6, 'operation_id' => null];
    }

    private function ip($value, $flag, array $sentinels)
    {
        if ($value === null || $value === '' || in_array($value, $sentinels, true)) { return null; }
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            throw $this->invalidResponse();
        }
        return $value;
    }

    private function spec(array $spec)
    {
        foreach (['name', 'region'] as $field) {
            if (!isset($spec[$field]) || !is_string($spec[$field])
                || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $spec[$field])) {
                throw new ProviderOperationException('Invalid Vultr product specification.',
                    ['error_code' => 'SPEC_INVALID']);
            }
        }
        if (!isset($spec['image']) || !is_string($spec['image'])
            || !preg_match('/^[1-9][0-9]{0,8}$/D', $spec['image'])) {
            throw new ProviderOperationException('The Vultr image must be a provider OS ID.',
                ['error_code' => 'SPEC_INVALID']);
        }
        foreach (['cpu_cores', 'memory_mb', 'storage_gb'] as $field) {
            if (!isset($spec[$field]) || !is_int($spec[$field]) || $spec[$field] <= 0) {
                throw new ProviderOperationException('Invalid Vultr product specification.',
                    ['error_code' => 'SPEC_INVALID']);
            }
        }
        return $spec;
    }

    private function hostname($name)
    { return strtolower($name); } // spec() already validates a single DNS label.

    private function planId(array $config)
    {
        $id = isset($config['plan_id']) ? $config['plan_id'] : null;
        if (!is_string($id) || !preg_match('/^vc2-[a-z0-9-]{1,60}$/D', $id)) {
            throw new ConfigurationException('Configure a Vultr Cloud Compute plan ID.');
        }
        return $id;
    }

    private function token(array $credentials)
    {
        $token = isset($credentials['api_token']) ? $credentials['api_token'] : null;
        if (!is_string($token) || $token === '' || trim($token) !== $token
            || strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new ConfigurationException('A Vultr API token is required.');
        }
        return $token;
    }

    private function tag($key)
    {
        if (!is_string($key) || $key === '' || strlen($key) > 1024) {
            throw new ProviderOperationException('A valid idempotency key is required.',
                ['error_code' => 'IDEMPOTENCY_KEY_REQUIRED']);
        }
        return 'ch247-' . substr(hash('sha256', $key), 0, 32);
    }

    private function uuidValid($id)
    { return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id); }

    private function id($id)
    {
        if (!is_string($id) || !$this->uuidValid($id)) { throw $this->invalidResponse(); }
        return strtolower($id);
    }

    private function deleted($id)
    { return ['id' => $id, 'status' => 'deleted', 'ipv4' => null, 'ipv6' => null, 'operation_id' => null]; }

    private function invalidResponse()
    { return new ProviderOperationException('Vultr returned an invalid or incomplete response.',
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
            throw new RetryableProviderException('Vultr API request did not complete.',
                ['error_code' => 'SERVICE_UNAVAILABLE', 'http_status' => $status]);
        }
        if ($status === 401 || $status === 403) {
            throw new ProviderAuthenticationException('Vultr rejected the configured API token or its permissions.',
                ['error_code' => 'AUTHENTICATION_FAILED', 'http_status' => $status]);
        }
        if ($status === 404) { return ['status' => 404, 'data' => []]; }
        if ($status < 200 || $status >= 300) {
            throw new ProviderOperationException('Vultr rejected the operation.',
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
