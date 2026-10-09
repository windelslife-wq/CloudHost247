<?php
/**
 * CloudHost247 App Cloud — server registry.
 *
 * A server is a deployment target: a VPS, a dedicated machine, a cPanel/WHM host,
 * a Kubernetes cluster or a shared node. This service owns the registry, the
 * agent lifecycle, the capacity accounting that makes scheduling honest, and the
 * metrics that come from real agent reports.
 *
 * Two rules shape every method here:
 *   • status is never invented. A server that has not reported inside the stale
 *     window is `offline`, and a server that has never reported has no metrics —
 *     consumers render UNKNOWN rather than a plausible-looking number.
 *   • capacity is reserved atomically. Two concurrent deployments cannot both be
 *     told the same free core exists.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Servers;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\AuthorizationException;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Crypto;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\ResourceInsufficientException;
use Ch247Apps\Core\ServerUnavailableException;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\ValidationException;
use Ch247Apps\Core\Validator;

class ServerService
{
    const TYPE_VPS        = 'vps';
    const TYPE_DEDICATED  = 'dedicated';
    const TYPE_CPANEL     = 'cpanel';
    const TYPE_KUBERNETES = 'kubernetes';
    const TYPE_SHARED     = 'shared';

    const TYPES = [self::TYPE_VPS, self::TYPE_DEDICATED, self::TYPE_CPANEL, self::TYPE_KUBERNETES, self::TYPE_SHARED];

    const STATUS_PENDING     = 'pending';
    const STATUS_ONLINE      = 'online';
    const STATUS_DEGRADED    = 'degraded';
    const STATUS_OFFLINE     = 'offline';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_DISABLED    = 'disabled';

    // Only this precisely validated metric source is eligible for uptime pruning.
    const METRIC_SOURCE_AGENT_UPTIME = 'agent_linux_uptime';

    const STATUSES = [
        self::STATUS_PENDING, self::STATUS_ONLINE, self::STATUS_DEGRADED,
        self::STATUS_OFFLINE, self::STATUS_MAINTENANCE, self::STATUS_DISABLED,
    ];

    /** Which capability a server type implies for the deployment engine. */
    const CAPABILITY_BY_TYPE = [
        self::TYPE_VPS        => 'docker_enabled',
        self::TYPE_DEDICATED  => 'docker_enabled',
        self::TYPE_CPANEL     => 'cpanel_enabled',
        self::TYPE_KUBERNETES => 'kubernetes_enabled',
        self::TYPE_SHARED     => 'cpanel_enabled',
    ];

    /** @var Actor */
    private $actor;

    /** @var CredentialVault */
    private $vault;

    public function __construct(Actor $actor = null, CredentialVault $vault = null)
    {
        $this->actor = $actor ?: Actor::system('ServerService');
        $this->vault = $vault ?: new CredentialVault($this->actor);
    }

    /* ------------------------------------------------------------ registry */

    /**
     * Register a deployment target.
     *
     * @param array $input name, hostname, ip_address, server_type, provider, region,
     *                     cpu_cores, memory_mb, storage_mb, docker_enabled,
     *                     kubernetes_enabled, cpanel_enabled, accepts_new_installs,
     *                     ssh_port, tags, notes, credentials[], agent_endpoint,
     *                     agent_secret, whmcs_server_id
     * @return array the server row (never contains a secret)
     */
    public function register(array $input)
    {
        Rbac::assert($this->actor, Rbac::SERVER_MANAGE);

        $validator = Validator::make($input)
            ->required('name')->string('name', 160)
            ->required('hostname')
            ->matches('hostname', '/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/', 'Hostname',
                'Use a hostname or FQDN (letters, numbers, dots and dashes).')
            ->required('ip_address')->ip('ip_address')
            ->required('server_type')->in('server_type', self::TYPES)
            ->optional('provider')->string('provider', 80)
            ->optional('region')->string('region', 80)
            ->optional('datacenter')->string('datacenter', 120)
            ->optional('operating_system')->string('operating_system', 120)
            ->optional('ipv6_address')->ip('ipv6_address')
            ->optional('cpu_cores', 1)->integer('cpu_cores', 1, 4096)
            ->optional('memory_mb', 1024)->integer('memory_mb', 256, 8388608)
            ->optional('storage_mb', 10240)->integer('storage_mb', 1024, 104857600)
            ->optional('ssh_port', 22)->integer('ssh_port', 1, 65535)
            ->optional('weight', 100)->integer('weight', 0, 1000)
            ->optional('max_installations', 0)->integer('max_installations', 0, 100000)
            ->optional('notes')->string('notes', 2000)
            ->optional('agent_endpoint')->url('agent_endpoint')
            ->optional('whmcs_server_id')->integer('whmcs_server_id', 0)
            ->validate();

        $type = (string) $validator['server_type'];
        $hostname = strtolower((string) $validator['hostname']);

        if (Db::first('servers', ['hostname' => $hostname])) {
            throw new ValidationException('A server with that hostname is already registered.', [
                'errors' => ['hostname' => 'Already registered'],
            ]);
        }

        $capability = self::CAPABILITY_BY_TYPE[$type];
        $flags = [
            'docker_enabled' => $this->flag($input, 'docker_enabled', $capability === 'docker_enabled'),
            'kubernetes_enabled' => $this->flag($input, 'kubernetes_enabled', $capability === 'kubernetes_enabled'),
            'cpanel_enabled' => $this->flag($input, 'cpanel_enabled', $capability === 'cpanel_enabled'),
        ];
        if (!$flags[$capability]) {
            throw new ValidationException(
                'A ' . $type . ' server must have ' . str_replace('_', ' ', $capability) . ' enabled.',
                ['errors' => [$capability => 'Required for this server type']]
            );
        }
        if ($type === self::TYPE_KUBERNETES && !\Ch247Apps\Core\Settings::bool('kubernetes_enabled')) {
            throw new StateException('Kubernetes deployment targets are not enabled on this platform yet.', [
                'error_code' => 'KUBERNETES_DISABLED',
            ]);
        }

        $now = Clock::now();
        $serverId = Db::insert('servers', [
            'name' => Str::clip($validator['name'], 160),
            'hostname' => $hostname,
            'ip_address' => (string) $validator['ip_address'],
            'ipv6_address' => isset($validator['ipv6_address']) ? $validator['ipv6_address'] : null,
            'server_type' => $type,
            'provider' => isset($validator['provider']) ? $validator['provider'] : null,
            'region' => isset($validator['region']) ? $validator['region'] : null,
            'datacenter' => isset($validator['datacenter']) ? $validator['datacenter'] : null,
            'operating_system' => isset($validator['operating_system']) ? $validator['operating_system'] : null,
            // A server is `pending` until its agent checks in: the platform does
            // not claim a machine is online because a form was submitted.
            'status' => self::STATUS_PENDING,
            'cpu_cores' => (int) $validator['cpu_cores'],
            'memory_mb' => (int) $validator['memory_mb'],
            'storage_mb' => (int) $validator['storage_mb'],
            'cpu_millicores_allocated' => 0,
            'memory_mb_allocated' => 0,
            'storage_mb_allocated' => 0,
            'max_installations' => (int) $validator['max_installations'],
            'docker_enabled' => $flags['docker_enabled'] ? 1 : 0,
            'kubernetes_enabled' => $flags['kubernetes_enabled'] ? 1 : 0,
            'cpanel_enabled' => $flags['cpanel_enabled'] ? 1 : 0,
            'monitoring_enabled' => $this->flag($input, 'monitoring_enabled', true) ? 1 : 0,
            'accepts_new_installs' => $this->flag($input, 'accepts_new_installs', true) ? 1 : 0,
            'weight' => (int) $validator['weight'],
            'ssh_port' => (int) $validator['ssh_port'],
            'tags' => isset($input['tags']) ? Str::jsonEncode(array_values((array) $input['tags'])) : null,
            'notes' => isset($validator['notes']) ? $validator['notes'] : null,
            'whmcs_server_id' => !empty($validator['whmcs_server_id']) ? (int) $validator['whmcs_server_id'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $credentials = isset($input['credentials']) && is_array($input['credentials']) ? $input['credentials'] : [];
        foreach ($this->normaliseCredentials($credentials, $input) as $credential) {
            $this->vault->store($serverId, $credential['type'], $credential['secret'], [
                'username' => isset($credential['username']) ? $credential['username'] : null,
                'name' => isset($credential['name']) ? $credential['name'] : 'primary',
                'expires_at' => isset($credential['expires_at']) ? $credential['expires_at'] : null,
            ]);
        }

        $agentId = null;
        if (!empty($input['agent_secret']) || !empty($input['agent_endpoint'])) {
            $agent = $this->registerAgent($serverId, [
                'name' => isset($input['agent_name']) ? $input['agent_name'] : $validator['name'] . ' agent',
                'endpoint' => isset($validator['agent_endpoint']) ? $validator['agent_endpoint'] : null,
                'secret' => isset($input['agent_secret']) ? $input['agent_secret'] : Crypto::randomToken(32),
                'agent_uuid' => isset($input['agent_uuid']) ? $input['agent_uuid'] : null,
            ]);
            $agentId = (int) $agent['id'];
            Db::update('servers', ['agent_id' => $agentId], ['id' => $serverId]);
        }

        Audit::record($this->actor, Audit::SERVER_ADDED, [
            'resource_type' => 'server', 'resource_id' => $serverId,
            'metadata' => [
                'hostname' => $hostname, 'server_type' => $type, 'region' => $validator['region'],
                'cpu_cores' => (int) $validator['cpu_cores'], 'memory_mb' => (int) $validator['memory_mb'],
                'credentials' => count($credentials), 'agent_id' => $agentId,
            ],
        ]);
        Events::emit(Events::SERVER_REGISTERED, [
            'hostname' => $hostname, 'server_type' => $type, 'status' => self::STATUS_PENDING,
        ], ['server_id' => $serverId]);
        Logger::info('Server registered.', [
            'server_id' => $serverId, 'hostname' => $hostname, 'type' => $type, 'source' => 'servers',
        ]);

        return $this->present($serverId);
    }

    /** Accept both `credentials => [...]` and flat `ssh_key`/`whm_api_token` input. */
    private function normaliseCredentials(array $credentials, array $input)
    {
        $out = [];
        foreach ($credentials as $key => $credential) {
            if (is_string($credential)) {
                $credential = ['type' => $key, 'secret' => $credential];
            }
            if (!is_array($credential) || empty($credential['secret'])) {
                continue;
            }
            $type = isset($credential['type']) ? $credential['type'] : $key;
            if (!in_array($type, CredentialVault::TYPES, true)) {
                throw new ValidationException('Unknown credential type "' . $type . '".', [
                    'errors' => ['credentials' => 'Unknown type ' . $type],
                ]);
            }
            $out[] = [
                'type' => $type,
                'secret' => (string) $credential['secret'],
                'username' => isset($credential['username']) ? $credential['username'] : null,
                'name' => isset($credential['name']) ? $credential['name'] : 'primary',
                'expires_at' => isset($credential['expires_at']) ? $credential['expires_at'] : null,
            ];
        }
        foreach ([CredentialVault::TYPE_SSH_KEY, CredentialVault::TYPE_SSH_PASSWORD,
            CredentialVault::TYPE_WHM_API_TOKEN, CredentialVault::TYPE_CPANEL_TOKEN,
            CredentialVault::TYPE_KUBE_TOKEN, CredentialVault::TYPE_KUBE_CONFIG] as $flat) {
            if (!empty($input[$flat])) {
                $out[] = ['type' => $flat, 'secret' => (string) $input[$flat],
                    'username' => isset($input['ssh_username']) ? $input['ssh_username'] : null,
                    'name' => 'primary'];
            }
        }
        return $out;
    }

    private function flag(array $input, $key, $default)
    {
        if (!array_key_exists($key, $input)) {
            return (bool) $default;
        }
        $value = $input[$key];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Update registry details. Capacity flags may not drop below what is allocated. */
    public function update($serverId, array $input)
    {
        Rbac::assert($this->actor, Rbac::SERVER_MANAGE);
        $row = $this->row($serverId);

        $fields = [];
        foreach (['name' => 160, 'provider' => 80, 'region' => 80, 'datacenter' => 120,
            'operating_system' => 120, 'notes' => 2000] as $key => $length) {
            if (array_key_exists($key, $input)) {
                $fields[$key] = $input[$key] === null ? null : Str::clip((string) $input[$key], $length);
            }
        }
        if (array_key_exists('ip_address', $input)) {
            $fields['ip_address'] = Validator::make($input)->required('ip_address')->ip('ip_address')->validate()['ip_address'];
        }
        if (array_key_exists('ipv6_address', $input)) {
            $fields['ipv6_address'] = $input['ipv6_address'] === null ? null
                : Validator::make($input)->required('ipv6_address')->ip('ipv6_address')->validate()['ipv6_address'];
        }
        if (array_key_exists('ssh_port', $input)) {
            $fields['ssh_port'] = max(1, min(65535, (int) $input['ssh_port']));
        }
        if (array_key_exists('weight', $input)) {
            $fields['weight'] = max(0, min(1000, (int) $input['weight']));
        }
        if (array_key_exists('max_installations', $input)) {
            $fields['max_installations'] = max(0, (int) $input['max_installations']);
        }
        if (array_key_exists('tags', $input)) {
            $fields['tags'] = Str::jsonEncode(array_values((array) $input['tags']));
        }
        foreach (['accepts_new_installs', 'monitoring_enabled', 'docker_enabled',
            'kubernetes_enabled', 'cpanel_enabled'] as $boolean) {
            if (array_key_exists($boolean, $input)) {
                $fields[$boolean] = $this->flag($input, $boolean, (bool) $row[$boolean]) ? 1 : 0;
            }
        }
        if (array_key_exists('server_type', $input) && (string) $input['server_type'] !== (string) $row['server_type']) {
            $type = (string) $input['server_type'];
            if (!in_array($type, self::TYPES, true)) {
                throw new ValidationException('Unknown server type.', ['errors' => ['server_type' => 'Invalid']]);
            }
            $active = Db::count('installations', [
                'server_id' => (int) $row['id'], 'status' => ['notin', ['deleted', 'terminated']],
            ]);
            if ($active > 0) {
                throw new StateException('A server with ' . $active . ' active installation(s) cannot change type. '
                    . 'Migrate them first.', ['active' => $active, 'error_code' => 'SERVER_TYPE_LOCKED']);
            }
            $fields['server_type'] = $type;
        }

        // Shrinking capacity below what is already allocated would make the
        // accounting lie, so it is refused rather than silently clamped.
        foreach (['cpu_cores' => 'cpu_millicores_allocated', 'memory_mb' => 'memory_mb_allocated',
            'storage_mb' => 'storage_mb_allocated'] as $capacity => $allocated) {
            if (!array_key_exists($capacity, $input)) {
                continue;
            }
            $value = max(0, (int) $input[$capacity]);
            $needed = $capacity === 'cpu_cores'
                ? (int) ceil((int) $row[$allocated] / 1000)
                : (int) $row[$allocated];
            if ($value < $needed) {
                throw new StateException(
                    'Cannot reduce ' . str_replace('_', ' ', $capacity) . ' below what is allocated (' . $needed . ').',
                    ['error_code' => 'SERVER_CAPACITY_LOCKED', 'allocated' => $needed, 'requested' => $value]
                );
            }
            $fields[$capacity] = $value;
        }

        if ($fields === []) {
            return $this->present((int) $row['id']);
        }
        $fields['updated_at'] = Clock::now();
        Db::update('servers', $fields, ['id' => (int) $row['id']]);

        Audit::record($this->actor, Audit::SERVER_UPDATED, [
            'resource_type' => 'server', 'resource_id' => (int) $row['id'],
            'metadata' => ['changed' => array_keys($fields), 'hostname' => $row['hostname']],
        ]);
        return $this->present((int) $row['id']);
    }

    public function setStatus($serverId, $status, $reason = '')
    {
        Rbac::assert($this->actor, Rbac::SERVER_MANAGE);
        $row = $this->row($serverId);
        $status = strtolower((string) $status);
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException('Unknown server status "' . $status . '".');
        }
        if ($status === self::STATUS_ONLINE && empty($row['last_heartbeat_at'])) {
            throw new StateException('A server cannot be marked online before its agent has checked in.', [
                'error_code' => 'SERVER_NEVER_REPORTED',
            ]);
        }
        $from = (string) $row['status'];
        Db::update('servers', ['status' => $status, 'updated_at' => Clock::now()], ['id' => (int) $row['id']]);
        if (in_array($status, [self::STATUS_MAINTENANCE, self::STATUS_DISABLED], true)) {
            Db::update('servers', ['accepts_new_installs' => 0], ['id' => (int) $row['id']]);
        }
        Audit::transition($this->actor, Audit::SERVER_UPDATED, 'server', (int) $row['id'], $from, $status, [
            'metadata' => ['reason' => Str::clip($reason, 200)],
            'severity' => $status === self::STATUS_DISABLED ? 'warning' : 'info',
        ]);
        return $this->present((int) $row['id']);
    }

    /** Soft-delete. Refused while installations still live on the server. */
    public function remove($serverId, $reason = '')
    {
        Rbac::assert($this->actor, Rbac::SERVER_MANAGE);
        $row = $this->row($serverId);
        $active = Db::count('installations', [
            'server_id' => (int) $row['id'], 'status' => ['notin', ['deleted', 'terminated']], 'deleted_at' => null,
        ]);
        if ($active > 0) {
            throw new StateException('That server still hosts ' . $active . ' installation(s). '
                . 'Migrate or delete them first.', ['active' => $active, 'error_code' => 'SERVER_IN_USE']);
        }
        Db::update('servers', [
            'deleted_at' => Clock::now(), 'accepts_new_installs' => 0,
            'status' => self::STATUS_DISABLED, 'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        foreach (Db::fetch('agents', ['server_id' => (int) $row['id']]) as $agent) {
            Db::update('agents', ['status' => 'revoked', 'updated_at' => Clock::now()], ['id' => (int) $agent['id']]);
        }
        Audit::record($this->actor, Audit::SERVER_DELETED, [
            'resource_type' => 'server', 'resource_id' => (int) $row['id'],
            'metadata' => ['hostname' => $row['hostname'], 'reason' => Str::clip($reason, 200)],
            'severity' => 'warning',
        ]);
        return true;
    }

    /* ---------------------------------------------------------------- read */

    /** @throws NotFoundException */
    public function row($serverId)
    {
        $row = Db::first('servers', ['id' => (int) $serverId, 'deleted_at' => null]);
        if (!$row) {
            throw new NotFoundException('That server is not registered.');
        }
        return $row;
    }

    public function find($serverId)
    {
        try {
            return $this->row($serverId);
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Admin list with filters: status, server_type, region, q, accepts_new_installs.
     */
    public function listing(array $filters = [])
    {
        Rbac::assert($this->actor, Rbac::SERVER_VIEW);
        $where = ['deleted_at' => null];
        foreach (['status', 'server_type', 'region', 'provider'] as $key) {
            if (!empty($filters[$key])) {
                $where[$key] = (string) $filters[$key];
            }
        }
        if (isset($filters['accepts_new_installs'])) {
            $where['accepts_new_installs'] = $filters['accepts_new_installs'] ? 1 : 0;
        }
        if (!empty($filters['stale'])) {
            $where['heartbeat_stale'] = 1;
        }
        $rows = Db::fetch('servers', $where, [
            'order' => !empty($filters['order']) ? (string) $filters['order'] : 'weight',
            'dir' => !empty($filters['dir']) ? (string) $filters['dir'] : 'desc',
            'order2' => 'name', 'limit' => isset($filters['limit']) ? (int) $filters['limit'] : 200,
        ]);

        $search = isset($filters['q']) ? strtolower(trim((string) $filters['q'])) : '';
        $out = [];
        foreach ($rows as $row) {
            if ($search !== '' && strpos(strtolower(implode(' ', [
                $row['name'], $row['hostname'], $row['ip_address'], (string) $row['region'],
                (string) $row['provider'], (string) $row['datacenter'], (string) $row['server_type'],
            ])), $search) === false) {
                continue;
            }
            $out[] = $this->presentRow($row);
        }
        return $out;
    }

    public function present($serverId)
    {
        return $this->presentRow($this->row($serverId));
    }

    /**
     * Server as an API/UI sees it. Credentials are summarised, never included,
     * and every derived status says where it came from.
     */
    public function presentRow(array $row)
    {
        $capacity = $this->capacityRow($row);
        $stale = $this->isStale($row);
        $status = (string) $row['status'];
        if ($stale && in_array($status, [self::STATUS_ONLINE, self::STATUS_DEGRADED], true)) {
            // The agent stopped reporting: the honest status is offline, not the
            // last state it happened to be in.
            $status = self::STATUS_OFFLINE;
        }
        $latest = $this->latestMetrics((int) $row['id']);

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'hostname' => $row['hostname'],
            'ip_address' => $row['ip_address'],
            'ipv6_address' => isset($row['ipv6_address']) ? $row['ipv6_address'] : null,
            'server_type' => $row['server_type'],
            'provider' => isset($row['provider']) ? $row['provider'] : null,
            'region' => isset($row['region']) ? $row['region'] : null,
            'datacenter' => isset($row['datacenter']) ? $row['datacenter'] : null,
            'operating_system' => isset($row['operating_system']) ? $row['operating_system'] : null,
            'status' => $status,
            'stored_status' => (string) $row['status'],
            'accepts_new_installs' => (bool) $row['accepts_new_installs'],
            'capabilities' => [
                'docker' => (bool) $row['docker_enabled'],
                'kubernetes' => (bool) $row['kubernetes_enabled'],
                'cpanel' => (bool) $row['cpanel_enabled'],
                'monitoring' => (bool) $row['monitoring_enabled'],
            ],
            'agent' => [
                'id' => $row['agent_id'] ? (int) $row['agent_id'] : null,
                'version' => isset($row['agent_version']) ? $row['agent_version'] : null,
                'last_heartbeat_at' => isset($row['last_heartbeat_at']) ? $row['last_heartbeat_at'] : null,
                'stale' => $stale,
                'seconds_since_heartbeat' => empty($row['last_heartbeat_at'])
                    ? null : Clock::diffSeconds($row['last_heartbeat_at'], Clock::now()),
            ],
            'capacity' => $capacity,
            'metrics' => $latest === null ? null : $this->presentMetrics($latest),
            // No sample ever arrived: UNKNOWN, never a fabricated zero.
            'metrics_known' => $latest !== null,
            'installations' => Db::count('installations', [
                'server_id' => (int) $row['id'], 'status' => ['notin', ['deleted', 'terminated']],
            ]),
            'credentials' => $this->actor->can(Rbac::SERVER_VIEW) ? $this->vault->describe((int) $row['id']) : [],
            'tags' => Str::jsonDecode(isset($row['tags']) ? $row['tags'] : null, []),
            'notes' => isset($row['notes']) ? $row['notes'] : null,
            'weight' => (int) $row['weight'],
            'ssh_port' => (int) $row['ssh_port'],
            'whmcs_server_id' => $row['whmcs_server_id'] ? (int) $row['whmcs_server_id'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => isset($row['updated_at']) ? $row['updated_at'] : null,
        ];
    }

    public function detail($serverId)
    {
        Rbac::assert($this->actor, Rbac::SERVER_VIEW);
        $row = $this->row($serverId);
        $detail = $this->presentRow($row);
        $detail['agents'] = $this->agents((int) $row['id']);
        $detail['recent_metrics'] = [];
        foreach ($this->metrics((int) $row['id'], 60) as $sample) {
            $detail['recent_metrics'][] = $this->presentMetrics($sample);
        }
        $detail['recent_deployments'] = Db::fetch('deployments', ['server_id' => (int) $row['id']],
            ['order' => 'id', 'dir' => 'desc', 'limit' => 20]);
        $detail['installations_list'] = Db::fetch('installations', [
            'server_id' => (int) $row['id'], 'status' => ['notin', ['deleted', 'terminated']],
        ], ['order' => 'id', 'dir' => 'desc', 'limit' => 100]);
        return $detail;
    }

    /* ------------------------------------------------------------ capacity */

    public function capacityRow(array $row)
    {
        $cpuTotal = (int) $row['cpu_cores'] * 1000;
        $cpuUsed = (int) $row['cpu_millicores_allocated'];
        $memTotal = (int) $row['memory_mb'];
        $memUsed = (int) $row['memory_mb_allocated'];
        $diskTotal = (int) $row['storage_mb'];
        $diskUsed = (int) $row['storage_mb_allocated'];
        return [
            'cpu_millicores' => ['total' => $cpuTotal, 'allocated' => $cpuUsed, 'free' => max(0, $cpuTotal - $cpuUsed)],
            'memory_mb' => ['total' => $memTotal, 'allocated' => $memUsed, 'free' => max(0, $memTotal - $memUsed)],
            'storage_mb' => ['total' => $diskTotal, 'allocated' => $diskUsed, 'free' => max(0, $diskTotal - $diskUsed)],
            'installations' => [
                'max' => (int) $row['max_installations'],
                'current' => Db::count('installations', [
                    'server_id' => (int) $row['id'], 'status' => ['notin', ['deleted', 'terminated']],
                ]),
            ],
            'percent_used' => [
                'cpu' => $cpuTotal > 0 ? round($cpuUsed / $cpuTotal * 100, 1) : null,
                'memory' => $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : null,
                'storage' => $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null,
            ],
        ];
    }

    /**
     * Reserve capacity for a deployment, atomically.
     *
     * @throws ResourceInsufficientException|ServerUnavailableException
     */
    public function allocate($serverId, array $requirements)
    {
        $row = $this->row($serverId);
        if (!in_array((string) $row['status'], [self::STATUS_ONLINE, self::STATUS_DEGRADED, self::STATUS_PENDING], true)) {
            throw new ServerUnavailableException('Server "' . $row['hostname'] . '" is ' . $row['status'] . '.', [
                'server_id' => (int) $row['id'], 'status' => $row['status'],
            ]);
        }
        if (!(int) $row['accepts_new_installs']) {
            throw new ServerUnavailableException('Server "' . $row['hostname'] . '" is not accepting new installations.', [
                'server_id' => (int) $row['id'], 'error_code' => 'SERVER_NOT_ACCEPTING_INSTALLS',
            ]);
        }
        if ($this->isStale($row)) {
            throw new ServerUnavailableException('Server "' . $row['hostname'] . '" has not reported recently.', [
                'server_id' => (int) $row['id'], 'error_code' => 'SERVER_HEARTBEAT_STALE',
                'last_heartbeat_at' => $row['last_heartbeat_at'],
            ]);
        }

        $cpu = (int) (isset($requirements['cpu_millicores']) ? $requirements['cpu_millicores'] : 0);
        $memory = (int) (isset($requirements['memory_mb']) ? $requirements['memory_mb'] : 0);
        $storage = (int) (isset($requirements['storage_mb']) ? $requirements['storage_mb'] : 0);

        if ($cpu <= 0 && $memory <= 0 && $storage <= 0) {
            // Nothing to reserve; the state checks above are the whole answer.
            return $this->capacity((int) $row['id']);
        }

        return $this->reserve($row, $cpu, $memory, $storage, 0);
    }

    /**
     * One conditional UPDATE, with the previously read counters in the WHERE
     * clause: two workers racing for the same core cannot both win, and the loser
     * retries against fresh numbers instead of over-committing the node.
     */
    private function reserve(array $row, $cpu, $memory, $storage, $attempt)
    {
        $table = Db::quoteIdentifier(Db::t('servers'));
        $installs = '(SELECT COUNT(*) FROM ' . Db::quoteIdentifier(Db::t('installations')) . ' i'
            . ' WHERE i.server_id = ' . $table . '.id'
            . " AND i.status NOT IN ('deleted', 'terminated') AND i.deleted_at IS NULL)";

        $sql = 'UPDATE ' . $table
            . ' SET cpu_millicores_allocated = cpu_millicores_allocated + ?,'
            . ' memory_mb_allocated = memory_mb_allocated + ?,'
            . ' storage_mb_allocated = storage_mb_allocated + ?,'
            . ' updated_at = ?'
            . ' WHERE id = ? AND deleted_at IS NULL AND accepts_new_installs = 1'
            . ' AND cpu_millicores_allocated = ? AND memory_mb_allocated = ? AND storage_mb_allocated = ?'
            . ' AND cpu_millicores_allocated + ? <= cpu_cores * 1000'
            . ' AND memory_mb_allocated + ? <= memory_mb'
            . ' AND storage_mb_allocated + ? <= storage_mb'
            . ' AND (max_installations = 0 OR ' . $installs . ' < max_installations)';

        $affected = Db::run($sql, [
            $cpu, $memory, $storage, Clock::now(),
            (int) $row['id'],
            (int) $row['cpu_millicores_allocated'], (int) $row['memory_mb_allocated'], (int) $row['storage_mb_allocated'],
            $cpu, $memory, $storage,
        ])->rowCount();

        if ($affected > 0) {
            Logger::info('Server capacity reserved.', [
                'server_id' => (int) $row['id'], 'cpu_millicores' => $cpu, 'memory_mb' => $memory,
                'storage_mb' => $storage, 'attempt' => $attempt + 1, 'source' => 'servers',
            ]);
            return $this->capacity((int) $row['id']);
        }

        // Either the numbers moved under us (retry) or the node genuinely has no
        // room (report precisely which resource ran out).
        if ($attempt < 3) {
            $fresh = Db::first('servers', ['id' => (int) $row['id'], 'deleted_at' => null]);
            if ($fresh && $this->allocationChanged($row, $fresh)) {
                // Somebody else moved the counters: re-read and try again.
                return $this->reserve($fresh, $cpu, $memory, $storage, $attempt + 1);
            }
            $row = $fresh ?: $row;
        }

        $capacity = $this->capacityRow($row);
        $short = [];
        if ($cpu > $capacity['cpu_millicores']['free']) {
            $short[] = 'cpu';
        }
        if ($memory > $capacity['memory_mb']['free']) {
            $short[] = 'memory';
        }
        if ($storage > $capacity['storage_mb']['free']) {
            $short[] = 'storage';
        }
        if ($capacity['installations']['max'] > 0
            && $capacity['installations']['current'] >= $capacity['installations']['max']) {
            $short[] = 'installations';
        }
        throw new ResourceInsufficientException(
            'Server "' . $row['hostname'] . '" does not have room for this installation'
            . ($short ? ' (short: ' . implode(', ', $short) . ')' : '') . '.',
            [
                'server_id' => (int) $row['id'],
                'requested' => ['cpu_millicores' => $cpu, 'memory_mb' => $memory, 'storage_mb' => $storage],
                'free' => [
                    'cpu_millicores' => $capacity['cpu_millicores']['free'],
                    'memory_mb' => $capacity['memory_mb']['free'],
                    'storage_mb' => $capacity['storage_mb']['free'],
                ],
                'installations' => $capacity['installations'],
                'short' => $short,
            ]
        );
    }

    private function allocationChanged(array $before, array $after)
    {
        foreach (['cpu_millicores_allocated', 'memory_mb_allocated', 'storage_mb_allocated'] as $column) {
            if ((int) $before[$column] !== (int) $after[$column]) {
                return true;
            }
        }
        return false;
    }

    /** Release capacity when a deployment fails, rolls back or is deleted. */
    public function release($serverId, array $requirements)
    {
        $row = $this->find($serverId);
        if (!$row) {
            return null;
        }
        $cpu = (int) (isset($requirements['cpu_millicores']) ? $requirements['cpu_millicores'] : 0);
        $memory = (int) (isset($requirements['memory_mb']) ? $requirements['memory_mb'] : 0);
        $storage = (int) (isset($requirements['storage_mb']) ? $requirements['storage_mb'] : 0);

        Db::run('UPDATE ' . Db::quoteIdentifier(Db::t('servers'))
            . ' SET cpu_millicores_allocated = CASE WHEN cpu_millicores_allocated - ? < 0 THEN 0'
            . ' ELSE cpu_millicores_allocated - ? END,'
            . ' memory_mb_allocated = CASE WHEN memory_mb_allocated - ? < 0 THEN 0 ELSE memory_mb_allocated - ? END,'
            . ' storage_mb_allocated = CASE WHEN storage_mb_allocated - ? < 0 THEN 0'
            . ' ELSE storage_mb_allocated - ? END,'
            . ' updated_at = ? WHERE id = ?',
            [$cpu, $cpu, $memory, $memory, $storage, $storage, Clock::now(), (int) $row['id']]);

        Logger::info('Server capacity released.', [
            'server_id' => (int) $row['id'], 'cpu_millicores' => $cpu, 'memory_mb' => $memory,
            'storage_mb' => $storage, 'source' => 'servers',
        ]);
        return $this->capacity((int) $row['id']);
    }

    public function capacity($serverId)
    {
        return $this->capacityRow($this->row($serverId));
    }

    /**
     * Servers a given application + plan could be deployed onto.
     *
     * This is the compatibility matrix made concrete: the install wizard shows
     * exactly this list, and an installation request for anything else is refused
     * server-side rather than hidden in the UI.
     *
     * @return array each entry: server presentation + `eligible` + `reasons`
     */
    public function candidatesFor($applicationId, array $requirements = [], $hostingType = null)
    {
        $application = Db::first('applications', ['id' => (int) $applicationId, 'deleted_at' => null]);
        if (!$application) {
            throw new NotFoundException('That application is not in the catalog.');
        }

        $supported = [];
        foreach (Db::fetch('application_compatibility', [
            'application_id' => (int) $applicationId, 'supported' => 1,
        ]) as $row) {
            $supported[] = $row['hosting_type'];
        }
        if ($supported === []) {
            $supported = [self::TYPE_VPS, self::TYPE_DEDICATED];
        }
        if ($hostingType !== null) {
            $supported = array_values(array_intersect($supported, [(string) $hostingType]));
        }

        $engine = (string) $application['deployment_type'];
        $cpu = (int) (isset($requirements['cpu_millicores']) ? $requirements['cpu_millicores'] : 0);
        $memory = (int) (isset($requirements['memory_mb']) ? $requirements['memory_mb'] : 0);
        $storage = (int) (isset($requirements['storage_mb']) ? $requirements['storage_mb'] : 0);

        $out = [];
        foreach (Db::fetch('servers', ['deleted_at' => null], ['order' => 'weight', 'dir' => 'desc', 'order2' => 'name']) as $row) {
            $reasons = [];
            if (!in_array((string) $row['server_type'], $supported, true)) {
                $reasons[] = 'HOSTING_TYPE_UNSUPPORTED';
            }
            $capability = $engine === 'kubernetes' ? 'kubernetes_enabled'
                : ($engine === 'cpanel' ? 'cpanel_enabled' : 'docker_enabled');
            if (!(int) $row[$capability]) {
                $reasons[] = 'CAPABILITY_MISSING';
            }
            if (!in_array((string) $row['status'], [self::STATUS_ONLINE, self::STATUS_DEGRADED], true)) {
                $reasons[] = 'SERVER_' . strtoupper((string) $row['status']);
            }
            if ($this->isStale($row)) {
                $reasons[] = 'HEARTBEAT_STALE';
            }
            if (!(int) $row['accepts_new_installs']) {
                $reasons[] = 'NOT_ACCEPTING_INSTALLS';
            }
            $capacity = $this->capacityRow($row);
            if ($cpu > $capacity['cpu_millicores']['free']) {
                $reasons[] = 'CPU_INSUFFICIENT';
            }
            if ($memory > $capacity['memory_mb']['free']) {
                $reasons[] = 'MEMORY_INSUFFICIENT';
            }
            if ($storage > $capacity['storage_mb']['free']) {
                $reasons[] = 'STORAGE_INSUFFICIENT';
            }
            if ((int) $row['max_installations'] > 0 && $capacity['installations']['current'] >= (int) $row['max_installations']) {
                $reasons[] = 'INSTALLATION_LIMIT_REACHED';
            }

            $entry = $this->presentRow($row);
            $entry['eligible'] = $reasons === [];
            $entry['reasons'] = $reasons;
            // Credentials are irrelevant (and sensitive) in a scheduling list.
            unset($entry['credentials'], $entry['notes']);
            $out[] = $entry;
        }

        // Eligible first, then by free memory so the wizard's default choice is
        // the least loaded machine that can actually take the workload.
        usort($out, function ($a, $b) {
            if ($a['eligible'] !== $b['eligible']) {
                return $a['eligible'] ? -1 : 1;
            }
            $freeA = $a['capacity']['memory_mb']['free'];
            $freeB = $b['capacity']['memory_mb']['free'];
            if ($freeA === $freeB) {
                return strcmp($a['name'], $b['name']);
            }
            return $freeA < $freeB ? 1 : -1;
        });
        return $out;
    }

    /* --------------------------------------------------------------- agent */

    /**
     * Register (or re-register) the agent that runs on a server.
     *
     * The shared secret is sealed; only its fingerprint and the derived agent
     * identity are kept in the clear.
     */
    public function registerAgent($serverId, array $input)
    {
        Rbac::assert($this->actor, Rbac::AGENT_MANAGE);
        $server = $this->row($serverId);
        $secret = isset($input['secret']) ? (string) $input['secret'] : '';
        $uuid = isset($input['agent_uuid']) && $input['agent_uuid'] !== ''
            ? Str::clip((string) $input['agent_uuid'], 36)
            : Str::uuid4();

        $existing = Db::first('agents', ['agent_uuid' => $uuid]);
        $now = Clock::now();
        $sealed = $secret !== '' ? Crypto::seal($secret, 'agents.shared_secret|' . $uuid) : null;

        $fields = [
            'name' => Str::clip(isset($input['name']) && $input['name'] !== ''
                ? $input['name'] : $server['name'] . ' agent', 160),
            'agent_uuid' => $uuid,
            'server_id' => (int) $server['id'],
            'endpoint' => isset($input['endpoint']) ? Str::clip($input['endpoint'], 255) : null,
            'capabilities' => isset($input['capabilities'])
                ? Str::jsonEncode(array_values((array) $input['capabilities'])) : null,
            'updated_at' => $now,
        ];
        if ($sealed) {
            $fields['encrypted_shared_secret'] = $sealed['ciphertext'];
            $fields['key_version'] = (int) $sealed['key_version'];
            $fields['secret_rotated_at'] = $now;
            $fields['rotated_by'] = $this->actor->identity();
        }

        if ($existing) {
            if ((int) $existing['server_id'] !== (int) $server['id']) {
                throw new StateException('That agent UUID is registered to another server.', [
                    'error_code' => 'AGENT_UUID_CONFLICT',
                ]);
            }
            Db::update('agents', $fields, ['id' => (int) $existing['id']]);
            $agentId = (int) $existing['id'];
            $action = Audit::AGENT_KEY_ROTATED;
        } else {
            $fields['status'] = 'pending';
            $fields['request_count'] = 0;
            $fields['rejected_count'] = 0;
            $fields['created_at'] = $now;
            $agentId = Db::insert('agents', $fields);
            $action = Audit::AGENT_REGISTERED;
        }

        Db::update('servers', ['agent_id' => $agentId, 'updated_at' => $now], ['id' => (int) $server['id']]);

        Audit::record($this->actor, $action, [
            'resource_type' => 'agent', 'resource_id' => $agentId, 'server_id' => (int) $server['id'],
            'metadata' => ['agent_uuid' => $uuid, 'secret_rotated' => $sealed !== null],
            'severity' => 'warning',
        ]);

        return [
            'id' => $agentId,
            'agent_uuid' => $uuid,
            'server_id' => (int) $server['id'],
            'status' => $existing ? $existing['status'] : 'pending',
            // Returned once, at registration, over an authenticated admin call.
            'shared_secret' => $secret !== '' ? $secret : null,
            'signing_hint' => 'HMAC-SHA256 over METHOD\\npath\\ntimestamp\\nnonce\\nsha256(body)',
        ];
    }

    /** Rotate an agent's shared secret without re-registering it. */
    public function rotateAgentSecret($agentId)
    {
        Rbac::assert($this->actor, Rbac::SERVER_CREDENTIAL_ROTATE);
        $agent = Db::first('agents', ['id' => (int) $agentId]);
        if (!$agent) {
            throw new NotFoundException('That agent is not registered.');
        }
        $secret = Crypto::randomToken(32);
        $sealed = Crypto::seal($secret, 'agents.shared_secret|' . $agent['agent_uuid']);
        $now = Clock::now();
        Db::update('agents', [
            'encrypted_shared_secret' => $sealed['ciphertext'],
            'key_version' => (int) $sealed['key_version'],
            'secret_rotated_at' => $now,
            'rotated_by' => $this->actor->identity(),
            'updated_at' => $now,
        ], ['id' => (int) $agent['id']]);
        // Nonces signed with the old secret must not be replayable against the new one.
        Db::delete('agent_nonces', ['agent_id' => (int) $agent['id']]);

        Audit::record($this->actor, Audit::AGENT_KEY_ROTATED, [
            'resource_type' => 'agent', 'resource_id' => (int) $agent['id'],
            'server_id' => (int) $agent['server_id'], 'severity' => 'warning',
            'metadata' => ['key_version' => (int) $sealed['key_version']],
        ]);
        return ['agent_id' => (int) $agent['id'], 'shared_secret' => $secret, 'rotated_at' => $now];
    }

    public function setAgentStatus($agentId, $status)
    {
        Rbac::assert($this->actor, Rbac::AGENT_MANAGE);
        $agent = Db::first('agents', ['id' => (int) $agentId]);
        if (!$agent) {
            throw new NotFoundException('That agent is not registered.');
        }
        if (!in_array($status, ['pending', 'active', 'suspended', 'revoked'], true)) {
            throw new ValidationException('Unknown agent status.');
        }
        Db::update('agents', ['status' => $status, 'updated_at' => Clock::now()], ['id' => (int) $agent['id']]);
        Audit::transition($this->actor, Audit::AGENT_REGISTERED, 'agent', (int) $agent['id'],
            $agent['status'], $status);
        return Db::first('agents', ['id' => (int) $agent['id']]);
    }

    public function agents($serverId = null)
    {
        Rbac::assert($this->actor, Rbac::AGENT_VIEW);
        $where = ['deleted_at' => null];
        if ($serverId !== null) {
            $where['server_id'] = (int) $serverId;
        }
        $out = [];
        foreach (Db::fetch('agents', $where, ['order' => 'id', 'dir' => 'desc']) as $row) {
            $out[] = $this->presentAgent($row);
        }
        return $out;
    }

    public function presentAgent(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'agent_uuid' => $row['agent_uuid'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'version' => isset($row['version']) ? $row['version'] : null,
            'endpoint' => isset($row['endpoint']) ? $row['endpoint'] : null,
            'status' => $row['status'],
            'capabilities' => Str::jsonDecode(isset($row['capabilities']) ? $row['capabilities'] : null, []),
            'last_seen_at' => isset($row['last_seen_at']) ? $row['last_seen_at'] : null,
            'last_seen_ip' => isset($row['last_seen_ip']) ? $row['last_seen_ip'] : null,
            'request_count' => (int) $row['request_count'],
            'rejected_count' => (int) $row['rejected_count'],
            'key_version' => (int) $row['key_version'],
            'needs_rotation' => Crypto::needsRotation((int) $row['key_version']),
            'secret_rotated_at' => isset($row['secret_rotated_at']) ? $row['secret_rotated_at'] : null,
            // The shared secret is never presented; only that one exists.
            'has_secret' => !empty($row['encrypted_shared_secret']),
        ];
    }

    /* ---------------------------------------------------- heartbeat/metrics */

    /**
     * Record an agent heartbeat.
     *
     * The agent is the only source of truth for liveness, version, capabilities
     * and (optionally) capacity. Nothing here infers a state the agent did not
     * report.
     *
     * @param array $payload agent_version, status, capabilities, capacity
     *                       {cpu_cores,memory_mb,storage_mb}, metrics
     */
    public function heartbeat($agentUuid, array $payload = [])
    {
        $agent = Db::first('agents', ['agent_uuid' => (string) $agentUuid]);
        if (!$agent) {
            throw new NotFoundException('That agent is not registered.');
        }
        if ($agent['status'] === 'revoked' || $agent['status'] === 'suspended') {
            throw new StateException('That agent is ' . $agent['status'] . '.', [
                'error_code' => 'AGENT_' . strtoupper((string) $agent['status']),
            ]);
        }
        $now = Clock::now();
        $serverId = (int) $agent['server_id'];

        $agentFields = [
            'status' => 'active',
            'last_seen_at' => $now,
            'last_seen_ip' => isset($payload['ip']) ? Str::clip((string) $payload['ip'], 45) : null,
            'updated_at' => $now,
        ];
        if (!empty($payload['agent_version'])) {
            $agentFields['version'] = Str::clip((string) $payload['agent_version'], 40);
        }
        if (isset($payload['capabilities']) && is_array($payload['capabilities'])) {
            $agentFields['capabilities'] = Str::jsonEncode(array_values($payload['capabilities']));
        }
        Db::update('agents', $agentFields, ['id' => (int) $agent['id']]);

        $serverFields = [
            'last_heartbeat_at' => $now,
            'heartbeat_stale' => 0,
            'updated_at' => $now,
        ];
        if (!empty($agentFields['version'])) {
            $serverFields['agent_version'] = $agentFields['version'];
        }
        $server = $this->find($serverId);
        if ($server && in_array((string) $server['status'],
            [self::STATUS_PENDING, self::STATUS_OFFLINE, self::STATUS_DEGRADED], true)) {
            $serverFields['status'] = isset($payload['status'])
                && in_array((string) $payload['status'], [self::STATUS_DEGRADED], true)
                ? self::STATUS_DEGRADED : self::STATUS_ONLINE;
        } elseif (isset($payload['status']) && (string) $payload['status'] === self::STATUS_DEGRADED && $server
            && (string) $server['status'] === self::STATUS_ONLINE) {
            $serverFields['status'] = self::STATUS_DEGRADED;
        }

        // Real reported capacity updates the registry; an unreported field is left
        // alone rather than zeroed.
        if (isset($payload['capacity']) && is_array($payload['capacity'])) {
            foreach (['cpu_cores', 'memory_mb', 'storage_mb'] as $key) {
                if (isset($payload['capacity'][$key]) && (int) $payload['capacity'][$key] > 0) {
                    $serverFields[$key] = (int) $payload['capacity'][$key];
                }
            }
        }
        if ($server) {
            Db::update('servers', $serverFields, ['id' => $serverId]);
        }

        if (isset($payload['metrics']) && is_array($payload['metrics'])) {
            $this->recordMetrics($serverId, $payload['metrics']);
        }

        Events::emit(Events::SERVER_HEARTBEAT, [
            'agent_version' => isset($agentFields['version']) ? $agentFields['version'] : null,
            'status' => isset($serverFields['status']) ? $serverFields['status'] : ($server ? $server['status'] : null),
        ], ['server_id' => $serverId]);

        return [
            'server_id' => $serverId,
            'agent_id' => (int) $agent['id'],
            'status' => isset($serverFields['status']) ? $serverFields['status'] : ($server ? $server['status'] : null),
            'recorded_at' => $now,
        ];
    }

    /** Store the sole agent-uptime field, labelled for narrow retention. */
    public function recordNodeUptime($serverId, $seconds)
    {
        if (!$this->actor->isAgent() || (int) $this->actor->serverId !== (int) $serverId) {
            throw new AuthorizationException('An assigned agent is required for node uptime.');
        }
        if (!is_int($seconds) || $seconds < 0 || $seconds > 2147483647) {
            throw new ValidationException('Invalid kernel uptime in seconds.');
        }
        return $this->recordMetrics($serverId, ['uptime_seconds' => $seconds], null,
            'server', self::METRIC_SOURCE_AGENT_UPTIME);
    }

    /** Store one real metrics sample. Untagged legacy records are never pruned here. */
    public function recordMetrics($serverId, array $metrics, $installationId = null, $scope = 'server', $source = null)
    {
        if ($source !== null && ($source !== self::METRIC_SOURCE_AGENT_UPTIME
            || $installationId !== null || array_keys($metrics) !== ['uptime_seconds'])) {
            throw new ValidationException('Invalid source-labelled metrics sample.');
        }
        $now = isset($metrics['sampled_at']) ? (string) $metrics['sampled_at'] : Clock::now();
        $row = [
            'source' => $source,
            'server_id' => (int) $serverId ?: null,
            'installation_id' => $installationId === null ? null : (int) $installationId,
            'scope' => $installationId === null ? 'server' : (string) $scope,
            'cpu_percent' => isset($metrics['cpu_percent']) ? round((float) $metrics['cpu_percent'], 2) : null,
            'cpu_millicores' => isset($metrics['cpu_millicores']) ? (int) $metrics['cpu_millicores'] : null,
            'memory_used_mb' => isset($metrics['memory_used_mb']) ? (int) $metrics['memory_used_mb'] : null,
            'memory_percent' => isset($metrics['memory_percent']) ? round((float) $metrics['memory_percent'], 2) : null,
            'storage_used_mb' => isset($metrics['storage_used_mb']) ? (int) $metrics['storage_used_mb'] : null,
            'storage_percent' => isset($metrics['storage_percent']) ? round((float) $metrics['storage_percent'], 2) : null,
            'network_in_kb' => isset($metrics['network_in_kb']) ? (int) $metrics['network_in_kb'] : null,
            'network_out_kb' => isset($metrics['network_out_kb']) ? (int) $metrics['network_out_kb'] : null,
            'load_average' => isset($metrics['load_average']) ? round((float) $metrics['load_average'], 2) : null,
            'uptime_seconds' => isset($metrics['uptime_seconds']) ? (int) $metrics['uptime_seconds'] : null,
            'container_count' => isset($metrics['container_count']) ? (int) $metrics['container_count'] : null,
            'restart_count' => isset($metrics['restart_count']) ? (int) $metrics['restart_count'] : null,
            'health' => isset($metrics['health']) && in_array($metrics['health'], ['healthy', 'unhealthy', 'unknown'], true)
                ? $metrics['health'] : null,
            'extra' => isset($metrics['containers']) ? Str::jsonEncode($metrics['containers']) : null,
            'sampled_at' => $now,
        ];
        $id = Db::insert('metrics', $row);
        Events::emit($installationId === null ? Events::SERVER_METRICS : Events::INSTALLATION_METRICS, [
            'cpu_percent' => $row['cpu_percent'], 'memory_percent' => $row['memory_percent'],
            'storage_percent' => $row['storage_percent'], 'health' => $row['health'],
        ], ['server_id' => (int) $serverId, 'installation_id' => $installationId === null ? null : (int) $installationId]);
        return $id;
    }

    /** Latest samples, newest first. Empty when nothing has ever reported. */
    public function metrics($serverId, $limit = 60, $installationId = null)
    {
        $where = ['server_id' => (int) $serverId];
        if ($installationId !== null) {
            $where['installation_id'] = (int) $installationId;
        } else {
            $where['scope'] = 'server';
        }
        return Db::fetch('metrics', $where, ['order' => 'sampled_at', 'dir' => 'desc', 'limit' => (int) $limit]);
    }

    public function latestMetrics($serverId, $installationId = null)
    {
        $where = ['server_id' => (int) $serverId];
        if ($installationId !== null) {
            $where['installation_id'] = (int) $installationId;
        } else {
            $where['scope'] = 'server';
        }
        return Db::first('metrics', $where, ['order' => 'sampled_at', 'dir' => 'desc']);
    }

    public function presentMetrics(array $row)
    {
        return [
            'sampled_at' => $row['sampled_at'],
            'age_seconds' => Clock::diffSeconds($row['sampled_at'], Clock::now()),
            'cpu_percent' => $row['cpu_percent'] === null ? null : (float) $row['cpu_percent'],
            'cpu_millicores' => $row['cpu_millicores'] === null ? null : (int) $row['cpu_millicores'],
            'memory_used_mb' => $row['memory_used_mb'] === null ? null : (int) $row['memory_used_mb'],
            'memory_percent' => $row['memory_percent'] === null ? null : (float) $row['memory_percent'],
            'storage_used_mb' => $row['storage_used_mb'] === null ? null : (int) $row['storage_used_mb'],
            'storage_percent' => $row['storage_percent'] === null ? null : (float) $row['storage_percent'],
            'network_in_kb' => $row['network_in_kb'] === null ? null : (int) $row['network_in_kb'],
            'network_out_kb' => $row['network_out_kb'] === null ? null : (int) $row['network_out_kb'],
            'load_average' => $row['load_average'] === null ? null : (float) $row['load_average'],
            'uptime_seconds' => $row['uptime_seconds'] === null ? null : (int) $row['uptime_seconds'],
            'container_count' => $row['container_count'] === null ? null : (int) $row['container_count'],
            'restart_count' => $row['restart_count'] === null ? null : (int) $row['restart_count'],
            'health' => $row['health'] === null || $row['health'] === '' ? 'unknown' : $row['health'],
            'containers' => Str::jsonDecode(isset($row['extra']) ? $row['extra'] : null, []),
        ];
    }

    /**
     * Health summary for a server. `unknown` whenever there is no evidence —
     * the platform never guesses a health state.
     */
    public function health($serverId)
    {
        $row = $this->row($serverId);
        $latest = $this->latestMetrics((int) $row['id']);
        $stale = $this->isStale($row);
        $status = (string) $row['status'];

        if ($latest === null) {
            $state = 'unknown';
            $reason = 'This server has never reported metrics.';
        } elseif ($stale) {
            $state = 'unknown';
            $reason = 'The last report is older than the stale window; the current state cannot be verified.';
        } elseif ($latest['health'] === 'unhealthy') {
            $state = 'unhealthy';
            $reason = 'The agent reported an unhealthy state.';
        } elseif ($status === self::STATUS_DEGRADED) {
            $state = 'degraded';
            $reason = 'The agent reported degraded operation.';
        } elseif ($latest['health'] === 'healthy') {
            $state = 'healthy';
            $reason = 'The agent reported a healthy state.';
        } else {
            $state = 'unknown';
            $reason = 'The agent reported no health verdict.';
        }

        return [
            'server_id' => (int) $row['id'],
            'status' => $stale && in_array($status, [self::STATUS_ONLINE, self::STATUS_DEGRADED], true)
                ? self::STATUS_OFFLINE : $status,
            'health' => $state,
            'reason' => $reason,
            'last_heartbeat_at' => isset($row['last_heartbeat_at']) ? $row['last_heartbeat_at'] : null,
            'heartbeat_stale' => $stale,
            'last_sample' => $latest ? $this->presentMetrics($latest) : null,
            'checked_at' => Clock::now(),
        ];
    }

    public function isStale(array $row)
    {
        if (empty($row['last_heartbeat_at'])) {
            // Never checked in: not stale (there is nothing to be stale about) but
            // definitely not verifiable either. Callers treat pending separately.
            return (string) $row['status'] !== self::STATUS_PENDING;
        }
        $minutes = \Ch247Apps\Core\Settings::int('agent_heartbeat_stale_min', 10);
        return Clock::diffSeconds($row['last_heartbeat_at'], Clock::now()) > $minutes * 60;
    }

    /**
     * Mark servers whose agent has gone quiet. Run from cron; emits an event per
     * server so monitoring can alert instead of a human noticing later.
     *
     * @return int number of servers marked stale
     */
    public function markStale()
    {
        $minutes = \Ch247Apps\Core\Settings::int('agent_heartbeat_stale_min', 10);
        $cutoff = Clock::at(-$minutes * 60);
        $marked = 0;
        foreach (Db::fetch('servers', [
            'deleted_at' => null, 'heartbeat_stale' => 0,
            'status' => ['in', [self::STATUS_ONLINE, self::STATUS_DEGRADED]],
        ]) as $row) {
            if (!empty($row['last_heartbeat_at']) && $row['last_heartbeat_at'] > $cutoff) {
                continue;
            }
            Db::update('servers', [
                'heartbeat_stale' => 1, 'status' => self::STATUS_OFFLINE, 'updated_at' => Clock::now(),
            ], ['id' => (int) $row['id']]);
            Events::emit(Events::SERVER_STALE, [
                'hostname' => $row['hostname'], 'last_heartbeat_at' => $row['last_heartbeat_at'],
                'stale_after_minutes' => $minutes,
            ], ['server_id' => (int) $row['id']]);
            Logger::warning('Server stopped reporting.', [
                'server_id' => (int) $row['id'], 'hostname' => $row['hostname'],
                'last_heartbeat_at' => $row['last_heartbeat_at'], 'source' => 'servers',
            ]);
            $marked++;
        }
        return $marked;
    }

    /**
     * The server an installation should be placed on when the customer did not
     * choose one: eligible, accepting installs, most free memory, highest weight.
     *
     * @throws ServerUnavailableException when nothing can host it
     */
    public function selectFor($applicationId, array $requirements = [], $hostingType = null)
    {
        $candidates = $this->candidatesFor($applicationId, $requirements, $hostingType);
        foreach ($candidates as $candidate) {
            if ($candidate['eligible']) {
                return (int) $candidate['id'];
            }
        }
        $reasons = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate['reasons'] as $reason) {
                $reasons[$reason] = isset($reasons[$reason]) ? $reasons[$reason] + 1 : 1;
            }
        }
        throw new ServerUnavailableException(
            'No registered server can host this application right now.',
            ['error_code' => 'NO_ELIGIBLE_SERVER', 'candidates' => count($candidates), 'reasons' => $reasons]
        );
    }

    /** Registry summary for the admin dashboard. */
    public function statistics()
    {
        $byStatus = [];
        $byType = [];
        foreach (Db::fetch('servers', ['deleted_at' => null]) as $row) {
            $status = $this->isStale($row) && in_array((string) $row['status'], [self::STATUS_ONLINE, self::STATUS_DEGRADED], true)
                ? self::STATUS_OFFLINE : (string) $row['status'];
            $byStatus[$status] = isset($byStatus[$status]) ? $byStatus[$status] + 1 : 1;
            $byType[$row['server_type']] = isset($byType[$row['server_type']]) ? $byType[$row['server_type']] + 1 : 1;
        }
        return [
            'total' => Db::count('servers', ['deleted_at' => null]),
            'online' => isset($byStatus[self::STATUS_ONLINE]) ? $byStatus[self::STATUS_ONLINE] : 0,
            'offline' => isset($byStatus[self::STATUS_OFFLINE]) ? $byStatus[self::STATUS_OFFLINE] : 0,
            'pending' => isset($byStatus[self::STATUS_PENDING]) ? $byStatus[self::STATUS_PENDING] : 0,
            'maintenance' => isset($byStatus[self::STATUS_MAINTENANCE]) ? $byStatus[self::STATUS_MAINTENANCE] : 0,
            'accepting_installs' => Db::count('servers', ['deleted_at' => null, 'accepts_new_installs' => 1]),
            'by_status' => $byStatus,
            'by_type' => $byType,
            'agents_active' => Db::count('agents', ['status' => 'active']),
            'credentials_needing_attention' => count($this->vault->attention()),
        ];
    }
}
