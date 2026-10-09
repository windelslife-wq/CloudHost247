<?php
/**
 * Domain provider registry.
 *
 * Owns the mod_chs_domain_providers / domain_provider_mappings tables and
 * answers the one question services ask: "which provider handles this TLD?".
 *
 * Resolution order for a TLD:
 *   1. explicit per-TLD mapping (domain_provider_mappings)
 *   2. the provider flagged is_default
 *   3. when no providers are configured at all → the WHMCS registrar chain
 *      (the platform's own lookup pipeline — always available)
 *   4. otherwise → NullDomainProvider (fails closed, never fabricates)
 *
 * Credentials are sealed with Secrets on write and unsealed only inside the
 * provider instance; list/detail views expose a masked placeholder only.
 *
 * @package Chs\Providers\Domain
 */

namespace Chs\Providers\Domain;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\Secrets;
use Chs\Core\Str;
use Chs\Core\ValidationException;

class ProviderRegistry
{
    /** @var array<string,DomainProviderInterface> per-request instance cache */
    private $instances = [];

    /** Provider for one TLD (honest null provider when nothing is mapped). */
    public function forTld($tld)
    {
        $tld = ltrim(strtolower(trim((string) $tld)), '.');
        if (!Db::tableExists('domain_provider_mappings')) {
            return $this->platformDefault();
        }
        $mapping = Db::first('domain_provider_mappings', ['tld' => $tld]);
        if ($mapping) {
            return $this->instance((int) $mapping['provider_id']);
        }
        return $this->default();
    }

    /** The default provider: flagged default, platform chain, or null. */
    public function default()
    {
        if (!Db::tableExists('domain_providers')) {
            return $this->platformDefault();
        }
        if (Db::count('domain_providers') === 0) {
            return $this->platformDefault();
        }
        $row = Db::first('domain_providers', ['is_default' => 1, 'is_enabled' => 1]);
        if (!$row) {
            $row = Db::first('domain_providers', ['is_enabled' => 1], 'id ASC');
        }
        if (!$row) {
            return new NullDomainProvider();
        }
        return $this->instance((int) $row['id']);
    }

    /** The platform chain provider (WHMCS' own registrar/lookup pipeline). */
    public function platformDefault()
    {
        if (!Db::tableExists('domain_providers')) {
            return new WhmcsRegistrarProvider();
        }
        $row = Db::first('domain_providers', ['type' => 'whmcs', 'is_enabled' => 1], 'id ASC');
        if ($row) {
            return $this->instance((int) $row['id']);
        }
        return new WhmcsRegistrarProvider();
    }

    /** Instantiate (and cache) the provider for a stored row id. */
    public function instance($providerId)
    {
        $providerId = (int) $providerId;
        if (isset($this->instances[$providerId])) {
            return $this->instances[$providerId];
        }
        $row = Db::first('domain_providers', ['id' => $providerId]);
        if (!$row) {
            return $this->instances[$providerId] = new NullDomainProvider();
        }
        $config = json_decode((string) $row['config'], true);
        $config = is_array($config) ? $config : [];
        $type = (string) $row['type'];
        if ($type === 'http') {
            $provider = new HttpRegistrarProvider($providerId, (string) $row['name'], $config, (string) $row['credentials_enc']);
        } elseif ($type === 'whmcs') {
            $provider = new WhmcsRegistrarProvider($providerId, (string) $row['name'], $config);
        } else {
            $provider = new NullDomainProvider();
        }
        return $this->instances[$providerId] = $provider;
    }

    /* ---------------------------------------------------------- admin CRUD -- */

    /**
     * @return array[] provider rows for the admin grid; credentials masked
     */
    public function all()
    {
        if (!Db::tableExists('domain_providers')) {
            return [];
        }
        $rows = Db::all('domain_providers', [], 'is_default DESC, id ASC');
        foreach ($rows as &$row) {
            $row['config'] = json_decode((string) $row['config'], true) ?: [];
            $row['has_credentials'] = trim((string) $row['credentials_enc']) !== '';
            unset($row['credentials_enc']);
        }
        unset($row);
        return $rows;
    }

    /** @return array|null single provider row (masked), or null */
    public function find($providerId)
    {
        foreach ($this->all() as $row) {
            if ((int) $row['id'] === (int) $providerId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Create or update a provider. $credentialsPlain is sealed when non-empty;
     * an empty value keeps the stored credentials untouched.
     *
     * @return int provider id
     */
    public function save($providerId, array $data, $credentialsPlain = '')
    {
        $errors = [];
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($name === '' || strlen($name) > 96) {
            $errors['name'] = 'Provider name is required (max 96 characters).';
        }
        $type = (string) (isset($data['type']) ? $data['type'] : 'http');
        if (!in_array($type, ['whmcs', 'http'], true)) {
            $errors['type'] = 'Provider type must be whmcs or http.';
        }
        $baseUrl = trim((string) (isset($data['base_url']) ? $data['base_url'] : ''));
        if ($type === 'http' && $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors['base_url'] = 'Base URL must be a valid http(s) URL.';
        }
        $endpoints = isset($data['endpoints']) ? $data['endpoints'] : [];
        if (is_string($endpoints)) {
            $endpoints = json_decode($endpoints, true) ?: [];
        }
        if (!is_array($endpoints)) {
            $errors['endpoints'] = 'Endpoint map must be a JSON object of operation → "METHOD /path".';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $config = [
            'base_url'     => $baseUrl,
            'endpoints'    => $endpoints,
            'auth_header'  => trim((string) (isset($data['auth_header']) ? $data['auth_header'] : 'Authorization')),
            'auth_prefix'  => (string) (isset($data['auth_prefix']) ? $data['auth_prefix'] : 'Bearer '),
            'token_field'  => trim((string) (isset($data['token_field']) ? $data['token_field'] : 'api_key')),
        ];

        $now = Clock::now();
        $row = [
            'name'         => $name,
            'type'         => $type,
            'base_url'     => $baseUrl,
            'is_enabled'   => !empty($data['is_enabled']) ? 1 : 0,
            'is_default'   => !empty($data['is_default']) ? 1 : 0,
            'health_status' => 'unknown',
            'config'       => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at'   => $now,
        ];

        $credentialsPlain = (string) $credentialsPlain;
        if ($credentialsPlain !== '') {
            $row['credentials_enc'] = Secrets::encrypt($credentialsPlain);
        }

        if ($providerId) {
            $existing = Db::first('domain_providers', ['id' => (int) $providerId]);
            if (!$existing) {
                throw new ValidationException(['provider' => 'Provider not found.']);
            }
            if ($credentialsPlain === '') {
                unset($row['credentials_enc']);
            }
            Db::update('domain_providers', ['id' => (int) $providerId], $row);
            $id = (int) $providerId;
        } else {
            $slug = Str::slug($name);
            if (Db::first('domain_providers', ['slug' => $slug])) {
                $slug .= '-' . substr(sha1($name . microtime(true)), 0, 4);
            }
            $row['slug'] = $slug;
            $row['created_at'] = $now;
            if ($credentialsPlain === '') {
                unset($row['credentials_enc']);
            }
            $id = Db::insert('domain_providers', $row);
        }

        $this->enforceSingleDefault($id, !empty($data['is_default']));
        unset($this->instances[$id]);
        return $id;
    }

    public function delete($providerId)
    {
        $providerId = (int) $providerId;
        if (!Db::first('domain_providers', ['id' => $providerId])) {
            return false;
        }
        Db::delete('domain_providers', ['id' => $providerId]);
        Db::exec('DELETE FROM ' . Db::t('domain_provider_mappings') . ' WHERE provider_id = ?', [$providerId]);
        unset($this->instances[$providerId]);
        return true;
    }

    /* ------------------------------------------------------------ mappings -- */

    /** @return array<string,int> tld => provider_id */
    public function mappings()
    {
        $out = [];
        if (!Db::tableExists('domain_provider_mappings')) {
            return $out;
        }
        foreach (Db::all('domain_provider_mappings', [], 'tld ASC') as $row) {
            $out[ltrim((string) $row['tld'], '.')] = (int) $row['provider_id'];
        }
        return $out;
    }

    public function setMapping($tld, $providerId)
    {
        $tld = ltrim(strtolower(trim((string) $tld)), '.');
        if ($tld === '' || !preg_match('/^[a-z0-9.-]{1,63}$/', $tld)) {
            throw new ValidationException(['tld' => 'A valid extension is required (e.g. com, co.uk).']);
        }
        $providerId = (int) $providerId;
        if (!Db::first('domain_providers', ['id' => $providerId])) {
            throw new ValidationException(['provider' => 'Provider not found.']);
        }
        if (Db::first('domain_provider_mappings', ['tld' => $tld])) {
            Db::update('domain_provider_mappings', ['tld' => $tld], [
                'provider_id' => $providerId,
            ]);
        } else {
            Db::insert('domain_provider_mappings', [
                'tld'         => $tld,
                'provider_id' => $providerId,
                'created_at'  => Clock::now(),
            ]);
        }
        unset($this->instances);
    }

    public function clearMapping($tld)
    {
        $tld = ltrim(strtolower(trim((string) $tld)), '.');
        Db::delete('domain_provider_mappings', ['tld' => $tld]);
        unset($this->instances);
    }

    /* ------------------------------------------------------------- health -- */

    /**
     * Refresh the stored health status for one provider. Configuration-based
     * (no network): 'ok' when the provider reports configured, otherwise the
     * concrete missing piece. A live reachability ping is a separate admin
     * action (testProvider) so the cron never blocks on a slow registrar.
     *
     * @return array{status:string, detail:string}
     */
    public function healthCheck($providerId)
    {
        $providerId = (int) $providerId;
        $row = Db::first('domain_providers', ['id' => $providerId]);
        if (!$row) {
            return ['status' => 'unknown', 'detail' => 'Provider not found.'];
        }
        $provider = $this->instance($providerId);
        $configured = $provider->isConfigured();
        $status = 'ok';
        $detail = 'Configured.';
        if (!$configured) {
            $status = 'not_configured';
            $type = (string) $row['type'];
            if ($type === 'http') {
                $config = json_decode((string) $row['config'], true) ?: [];
                $missing = [];
                if (trim((string) (isset($config['base_url']) ? $config['base_url'] : '')) === '') {
                    $missing[] = 'base URL';
                }
                if (trim((string) $row['credentials_enc']) === '') {
                    $missing[] = 'credentials (set CHS_CREDENTIALS_KEY and re-save)';
                }
                $detail = 'Not configured — missing: ' . implode(', ', $missing) . '.';
            } else {
                $detail = 'Platform provider disabled or misconfigured.';
            }
        }
        Db::update('domain_providers', ['id' => $providerId], [
            'health_status'  => $status,
            'last_error'     => $status === 'ok' ? '' : $detail,
            'last_checked_at' => Clock::now(),
        ]);
        unset($this->instances[$providerId]);
        return ['status' => $status, 'detail' => $detail];
    }

    /** Refresh health for every stored provider. @return array<int,array> */
    public function healthCheckAll()
    {
        $out = [];
        foreach ($this->all() as $row) {
            $out[(int) $row['id']] = $this->healthCheck((int) $row['id']);
        }
        return $out;
    }

    /* ------------------------------------------------------------ internals -- */

    private function enforceSingleDefault($keepId, $makeDefault)
    {
        if (!$makeDefault) {
            return;
        }
        Db::exec(
            'UPDATE ' . Db::t('domain_providers') . ' SET is_default = 0 WHERE id != ?',
            [(int) $keepId]
        );
    }
}
