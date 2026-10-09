<?php
/**
 * Infrastructure provider registry.
 *
 * Owns mod_chs_infrastructure_providers and mod_chs_infrastructure_regions
 * and answers the one question services ask: "which provider handles this
 * server?". Resolution order:
 *
 *   1. an explicitly requested provider id (must exist and be enabled)
 *   2. the provider flagged is_default
 *   3. the first enabled provider
 *   4. NullInfrastructureProvider (fails closed, never fabricates)
 *
 * Credentials are sealed with Secrets on write (the save fails closed when
 * CHS_CREDENTIALS_KEY is unset) and unsealed only inside the provider
 * instance; list/detail views expose a masked placeholder only.
 *
 * @package Chs\Providers\Infrastructure
 */

namespace Chs\Providers\Infrastructure;

use Chs\Core\Clock;
use Chs\Core\Db;
use Chs\Core\DuplicateOperationException;
use Chs\Core\Secrets;
use Chs\Core\Str;
use Chs\Core\ValidationException;

class InfraProviderRegistry
{
    /** All capability keys the admin UI may toggle. */
    public const CAPABILITY_KEYS = [
        'create', 'start', 'stop', 'reboot', 'shutdown', 'delete',
        'status', 'ip', 'reinstall', 'rescue', 'images', 'console', 'metrics', 'configure',
    ];

    /** @var array<string,InfrastructureProviderInterface> per-request cache */
    private $instances = [];

    /** @var array<int,InfrastructureProviderInterface>|null test seam: provider id => instance */
    public static $instanceOverride;

    /** Resolve a provider: explicit id → default → first enabled → null. */
    public function resolve($providerId = null)
    {
        if ($providerId) {
            $row = Db::first('infrastructure_providers', ['id' => (int) $providerId, 'is_enabled' => 1]);
            if ($row) {
                return $this->instance((int) $row['id']);
            }
            return new NullInfrastructureProvider();
        }
        return $this->default();
    }

    /** The default provider: flagged default, first enabled, or null. */
    public function default()
    {
        $row = Db::first('infrastructure_providers', ['is_default' => 1, 'is_enabled' => 1]);
        if (!$row) {
            $row = Db::first('infrastructure_providers', ['is_enabled' => 1], 'id ASC');
        }
        if (!$row) {
            return new NullInfrastructureProvider();
        }
        return $this->instance((int) $row['id']);
    }

    /** Instantiate (and cache) the provider for a stored row id. */
    public function instance($providerId)
    {
        $providerId = (int) $providerId;
        if (self::$instanceOverride !== null && isset(self::$instanceOverride[$providerId])) {
            return self::$instanceOverride[$providerId];
        }
        if (isset($this->instances[$providerId])) {
            return $this->instances[$providerId];
        }
        $row = Db::first('infrastructure_providers', ['id' => $providerId]);
        if (!$row || (string) $row['type'] !== 'http') {
            return $this->instances[$providerId] = new NullInfrastructureProvider();
        }
        $config = [
            'base_url'     => (string) $row['base_url'],
            'endpoints'    => (string) $row['endpoints'],
            'auth_header'  => (string) $row['auth_header'],
            'auth_prefix'  => (string) $row['auth_prefix'],
            'token_field'  => (string) $row['token_field'],
        ];
        return $this->instances[$providerId] = new HttpInfrastructureProvider(
            $providerId,
            (string) $row['name'],
            $config,
            (string) $row['credentials_enc']
        );
    }

    /** @return array<string,bool> declared capabilities for a provider row */
    public function capabilitiesOf($providerId)
    {
        $row = Db::first('infrastructure_providers', ['id' => (int) $providerId]);
        if (!$row) {
            return [];
        }
        $caps = json_decode((string) $row['capabilities'], true);
        return is_array($caps) ? $caps : [];
    }

    /* ---------------------------------------------------------- admin CRUD -- */

    /**
     * @return array[] provider rows for the admin grid; credentials masked
     */
    public function all()
    {
        $rows = Db::all('infrastructure_providers', [], 'is_default DESC, id ASC');
        foreach ($rows as &$row) {
            $row['endpoints'] = json_decode((string) $row['endpoints'], true) ?: [];
            $row['capabilities'] = json_decode((string) $row['capabilities'], true) ?: [];
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
     * an empty value keeps the stored credentials untouched. Sealing fails
     * closed when CHS_CREDENTIALS_KEY is not configured.
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
        if (!in_array($type, ['http'], true)) {
            $errors['type'] = 'Provider type must be http.';
        }
        $baseUrl = trim((string) (isset($data['base_url']) ? $data['base_url'] : ''));
        if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors['base_url'] = 'Base URL must be a valid http(s) URL.';
        }
        $endpoints = isset($data['endpoints']) ? $data['endpoints'] : [];
        if (is_string($endpoints)) {
            $endpoints = json_decode($endpoints, true) ?: [];
        }
        if (!is_array($endpoints)) {
            $errors['endpoints'] = 'Endpoint map must be a JSON object of operation → "METHOD /path".';
        }
        $capsIn = isset($data['capabilities']) ? (array) $data['capabilities'] : [];
        $caps = [];
        foreach (self::CAPABILITY_KEYS as $key) {
            $caps[$key] = !empty($capsIn[$key]);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }

        $now = Clock::now();
        $row = [
            'name'         => $name,
            'type'         => $type,
            'base_url'     => $baseUrl,
            'endpoints'    => json_encode($endpoints, JSON_UNESCAPED_SLASHES),
            'auth_header'  => trim((string) (isset($data['auth_header']) ? $data['auth_header'] : 'Authorization')),
            'auth_prefix'  => (string) (isset($data['auth_prefix']) ? $data['auth_prefix'] : 'Bearer '),
            'token_field'  => trim((string) (isset($data['token_field']) ? $data['token_field'] : 'api_key')),
            'capabilities' => json_encode($caps),
            'is_enabled'   => !empty($data['is_enabled']) ? 1 : 0,
            'is_default'   => !empty($data['is_default']) ? 1 : 0,
            'health_status' => 'unknown',
            'updated_at'   => $now,
        ];

        $credentialsPlain = (string) $credentialsPlain;
        if ($credentialsPlain !== '') {
            // Fails closed when CHS_CREDENTIALS_KEY is unset — credentials
            // are never stored in plaintext.
            $row['credentials_enc'] = Secrets::encrypt($credentialsPlain);
        }

        if ($providerId) {
            $existing = Db::first('infrastructure_providers', ['id' => (int) $providerId]);
            if (!$existing) {
                throw new ValidationException(['provider' => 'Provider not found.']);
            }
            if ($credentialsPlain === '') {
                unset($row['credentials_enc']);
            }
            Db::update('infrastructure_providers', ['id' => (int) $providerId], $row);
            $id = (int) $providerId;
        } else {
            $slug = Str::slug($name);
            if (Db::first('infrastructure_providers', ['slug' => $slug])) {
                $slug .= '-' . substr(sha1($name . microtime(true)), 0, 4);
            }
            $row['slug'] = $slug;
            $row['created_at'] = $now;
            if ($credentialsPlain === '') {
                unset($row['credentials_enc']);
            }
            $id = Db::insert('infrastructure_providers', $row);
        }

        $this->enforceSingleDefault($id, !empty($data['is_default']));
        unset($this->instances[$id]);
        return $id;
    }

    /**
     * Delete a provider where safe: refused while OS images or regions still
     * reference it (those mappings would silently break provisioning).
     */
    public function delete($providerId)
    {
        $providerId = (int) $providerId;
        if (!$this->find($providerId)) {
            throw new ValidationException(['provider' => 'Provider not found.']);
        }
        if (Db::count('server_os_images', ['provider_id' => $providerId]) > 0) {
            throw new DuplicateOperationException('Provider still has OS image mappings — delete them first.');
        }
        if (Db::count('infrastructure_regions', ['provider_id' => $providerId]) > 0) {
            throw new DuplicateOperationException('Provider still has regions — delete them first.');
        }
        unset($this->instances[$providerId]);
        return Db::delete('infrastructure_providers', ['id' => $providerId]) > 0;
    }

    /** Live health check; the outcome is persisted on the provider row. */
    public function healthCheck($providerId)
    {
        $provider = $this->instance($providerId);
        $result = $provider->healthCheck();
        Db::update('infrastructure_providers', ['id' => (int) $providerId], [
            'health_status'   => $result['status'],
            'last_error'      => $result['status'] === 'ok' ? '' : substr((string) $result['detail'], 0, 255),
            'last_checked_at' => Clock::now(),
            'updated_at'      => Clock::now(),
        ]);
        return $result;
    }

    /** Check every enabled provider (cron sweep). */
    public function healthCheckAll()
    {
        $out = [];
        foreach ($this->all() as $row) {
            if (empty($row['is_enabled'])) {
                continue;
            }
            $out[(int) $row['id']] = $this->healthCheck((int) $row['id']);
        }
        return $out;
    }

    /* ------------------------------------------------------------- regions -- */

    /** @return array[] regions for a provider (active first, then by sort) */
    public function regions($providerId, $activeOnly = false)
    {
        $where = ['provider_id' => (int) $providerId];
        $rows = Db::all('infrastructure_regions', $where, 'is_active DESC, sort_order ASC, id ASC');
        if ($activeOnly) {
            $rows = array_values(array_filter($rows, function ($r) {
                return (int) $r['is_active'] === 1;
            }));
        }
        return $rows;
    }

    /** All active regions across enabled providers (for the order form). */
    public function activeRegions()
    {
        $regions = Db::query(
            'SELECT r.*, p.name AS provider_name, p.slug AS provider_slug
             FROM ' . Db::t('infrastructure_regions') . ' r
             JOIN ' . Db::t('infrastructure_providers') . ' p ON p.id = r.provider_id
             WHERE r.is_active = 1 AND p.is_enabled = 1
             ORDER BY p.is_default DESC, r.sort_order ASC, r.id ASC'
        );
        return $regions ?: [];
    }

    /** @return int region id */
    public function saveRegion($regionId, $providerId, array $data)
    {
        $errors = [];
        $providerId = (int) $providerId;
        if (!$this->find($providerId)) {
            $errors['provider_id'] = 'Provider not found.';
        }
        $code = Str::slug((string) (isset($data['code']) ? $data['code'] : ''));
        if ($code === '') {
            $errors['code'] = 'Region code is required.';
        }
        $name = trim((string) (isset($data['name']) ? $data['name'] : ''));
        if ($name === '') {
            $errors['name'] = 'Region name is required.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $existing = Db::first('infrastructure_regions', ['provider_id' => $providerId, 'code' => $code]);
        if ($existing && (int) $existing['id'] !== (int) $regionId) {
            throw new DuplicateOperationException('This provider already has a region with that code.');
        }
        $now = Clock::now();
        $row = [
            'provider_id' => $providerId,
            'code'        => $code,
            'name'        => $name,
            'datacenter'  => trim((string) (isset($data['datacenter']) ? $data['datacenter'] : '')),
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
            'sort_order'  => max(0, (int) (isset($data['sort_order']) ? $data['sort_order'] : 0)),
            'updated_at'  => $now,
        ];
        if ($regionId && Db::first('infrastructure_regions', ['id' => (int) $regionId])) {
            Db::update('infrastructure_regions', ['id' => (int) $regionId], $row);
            return (int) $regionId;
        }
        $row['created_at'] = $now;
        return Db::insert('infrastructure_regions', $row);
    }

    /** Refused while image mappings reference the region. */
    public function deleteRegion($regionId)
    {
        $regionId = (int) $regionId;
        if (!Db::first('infrastructure_regions', ['id' => $regionId])) {
            throw new ValidationException(['region' => 'Region not found.']);
        }
        if (Db::count('server_os_images', ['region_id' => $regionId]) > 0) {
            throw new DuplicateOperationException('Region still has OS image mappings — delete them first.');
        }
        return Db::delete('infrastructure_regions', ['id' => $regionId]) > 0;
    }

    /* ------------------------------------------------------------ internals -- */

    private function enforceSingleDefault($id, $isDefault)
    {
        if (!$isDefault) {
            return;
        }
        Db::exec(
            'UPDATE ' . Db::t('infrastructure_providers') . ' SET is_default = 0 WHERE id != ?',
            [(int) $id]
        );
    }
}
