<?php
namespace CloudHost247\Cloudflare\Repository;

use CloudHost247\Cloudflare\Core\Clock;
use CloudHost247\Cloudflare\Core\Crypto;
use CloudHost247\Cloudflare\Core\Db;
use CloudHost247\Cloudflare\Core\ConfigurationException;
use CloudHost247\Cloudflare\Core\ValidationException;
use CloudHost247\Cloudflare\Provider\CloudflareApi;
use CloudHost247\Cloudflare\Provider\CloudflareClient;

class AccountRepository
{
    private $transport;
    public function __construct(\CloudHost247\Cloudflare\Provider\TransportInterface $transport = null) { $this->transport = $transport; }
    public function listAll() { return Db::all('accounts', [], 'id DESC', 200); }
    public function find($id) { return Db::first('accounts', ['id' => (int) $id]); }
    public function save(array $input)
    {
        $id = (int) ($input['id'] ?? 0);
        $label = trim((string) ($input['label'] ?? ''));
        $accountId = strtolower(trim((string) ($input['account_id'] ?? '')));
        $base = trim((string) ($input['api_base_url'] ?? 'https://api.cloudflare.com/client/v4'));
        if ($label === '' || strlen($label) > 120) throw new ValidationException('Enter a name for this Cloudflare account (up to 120 characters).');
        if (!preg_match('/^[a-f0-9]{32}$/', $accountId)) throw new ValidationException('Cloudflare Account ID must be 32 hexadecimal characters.');
        $this->validateApiBase($base);
        $existing = $id ? $this->find($id) : null;
        if ($id && !$existing) throw new ValidationException('Cloudflare account was not found.');
        $token = trim((string) ($input['api_token'] ?? ''));
        if ($token === '' && !$existing) throw new ValidationException('Enter a Cloudflare API token.');
        if ($token !== '' && strlen($token) > 4096) throw new ValidationException('API token is too long.');
        if ($token !== '' && !Crypto::hasKey()) throw new ConfigurationException('Credential encryption key is missing; token was not saved. Configure CLOUDFLARE_ENCRYPTION_KEY or the WHMCS encryption hash.');
        $mode = in_array(($input['zone_mode'] ?? 'full'), ['full', 'partial'], true) ? $input['zone_mode'] : 'full';
        $ssl = strtolower(trim((string) ($input['ssl_mode'] ?? 'full')));
        if (!in_array($ssl, ['off', 'flexible', 'full', 'strict'], true)) throw new ValidationException('Select a supported default SSL mode.');
        $cache = strtolower(trim((string) ($input['cache_level'] ?? 'standard')));
        if (!in_array($cache, ['basic', 'standard', 'aggressive'], true)) throw new ValidationException('Select a supported default cache level.');
        $nameservers = $this->parseNameservers((string) ($input['default_nameservers'] ?? ''));
        $data = [
            'label' => $label, 'account_id' => $accountId, 'api_base_url' => $base,
            'enabled' => !empty($input['enabled']) ? 1 : 0, 'zone_mode' => $mode,
            'default_nameservers' => $nameservers ? json_encode($nameservers) : null, 'ssl_mode' => $ssl,
            'proxy_default' => !empty($input['proxy_default']) ? 1 : 0, 'cache_level' => $cache,
            'browser_cache_ttl' => max(0, min(31536000, (int) ($input['browser_cache_ttl'] ?? 14400))),
            'delete_zone_on_terminate' => !empty($input['delete_zone_on_terminate']) ? 1 : 0,
            'updated_at' => Clock::now(),
        ];
        if ($token !== '') $data['encrypted_api_token'] = Crypto::seal($token);
        if (!$existing) {
            if (empty($data['encrypted_api_token'])) throw new ValidationException('An encrypted API token is required.');
            $data['connection_status'] = 'not_tested'; $data['created_at'] = Clock::now();
            return Db::insert('accounts', $data);
        }
        Db::update('accounts', ['id' => $id], $data);
        return $id;
    }
    public function delete($id)
    {
        $id = (int) $id;
        if (Db::count('services', ['account_id' => $id]) > 0) throw new ValidationException('This account is still linked to Cloudflare services and cannot be removed.');
        return Db::delete('accounts', ['id' => $id]);
    }
    public function api($accountId, array $context = [], $allowDisabled = false)
    {
        $account = $this->find($accountId);
        if (!$account || (empty($account['enabled']) && !$allowDisabled)) throw new ConfigurationException('Cloudflare account is disabled or missing.');
        if (empty($account['encrypted_api_token'])) throw new ConfigurationException();
        $token = Crypto::open($account['encrypted_api_token']);
        $client = new CloudflareClient($account['api_base_url'], $token, (int) $account['id'], $context, $this->transport);
        return [new CloudflareApi($client, $account['account_id']), $account];
    }
    public function test($id)
    {
        $account = $this->find($id);
        if (!$account || empty($account['encrypted_api_token'])) throw new ConfigurationException();
        list($api) = $this->api($id, [], true);
        $start = microtime(true);
        try {
            $result = $api->testConnection();
            $result['response_time_ms'] = (int) round((microtime(true) - $start) * 1000);
            $result['status'] = 'Connected'; $result['tested_at'] = Clock::now();
            Db::update('accounts', ['id' => (int) $id], ['connection_status' => 'connected', 'connection_tested_at' => Clock::now(), 'last_success_at' => Clock::now(), 'last_failure_at' => null, 'last_error_code' => null, 'last_error_message' => null]);
            return $result;
        } catch (\Throwable $e) {
            $code = $e instanceof \CloudHost247\Cloudflare\Core\CloudflareException ? $e->errorCode() : 'CLOUDFLARE_API_ERROR';
            $message = $e instanceof \CloudHost247\Cloudflare\Core\CloudflareException ? $e->getMessage() : 'Cloudflare connection test failed.';
            Db::update('accounts', ['id' => (int) $id], ['connection_status' => 'failed', 'connection_tested_at' => Clock::now(), 'last_failure_at' => Clock::now(), 'last_error_code' => $code, 'last_error_message' => substr($message, 0, 500)]);
            throw $e;
        }
    }
    private function validateApiBase($base)
    {
        $parts = parse_url($base);
        if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'api.cloudflare.com' || rtrim((string) ($parts['path'] ?? ''), '/') !== '/client/v4' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) throw new ValidationException('Use the official Cloudflare API endpoint: https://api.cloudflare.com/client/v4');
    }
    private function parseNameservers($value)
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $value) as $ns) {
            $ns = strtolower(rtrim(trim($ns), '.'));
            if ($ns === '') continue;
            if (strlen($ns) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $ns)) throw new ValidationException('One of the default nameservers is invalid.');
            $out[] = $ns;
        }
        return array_values(array_unique($out));
    }
}
