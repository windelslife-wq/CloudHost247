<?php
/**
 * CloudHost247 App Cloud — hash-chained audit log.
 *
 * Append-only. Each entry stores the SHA-256 of the previous entry, so a row
 * deleted or edited after the fact breaks the chain and verifyChain() reports
 * exactly where. Every privileged action in the platform (catalog changes,
 * deployments, credential rotation, customer suspension, billing changes, admin
 * logins) is recorded with the resolved actor, IP and user agent.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Audit
{
    /** Actions required by the platform specification (§54) — plus the rest. */
    const ADMIN_LOGIN                 = 'ADMIN_LOGIN';
    const APPLICATION_CREATED         = 'APPLICATION_CREATED';
    const APPLICATION_UPDATED         = 'APPLICATION_UPDATED';
    const APPLICATION_PUBLISHED       = 'APPLICATION_PUBLISHED';
    const APPLICATION_UNPUBLISHED     = 'APPLICATION_UNPUBLISHED';
    const APPLICATION_SUSPENDED       = 'APPLICATION_SUSPENDED';
    const APPLICATION_VERSION_CREATED = 'APPLICATION_VERSION_CREATED';
    const MANIFEST_UPLOADED           = 'MANIFEST_UPLOADED';
    const MANIFEST_VALIDATED          = 'MANIFEST_VALIDATED';
    const APPLICATION_INSTALLED       = 'APPLICATION_INSTALLED';
    const APPLICATION_DELETED         = 'APPLICATION_DELETED';
    const APPLICATION_STOPPED         = 'APPLICATION_STOPPED';
    const APPLICATION_STARTED         = 'APPLICATION_STARTED';
    const APPLICATION_RESTARTED       = 'APPLICATION_RESTARTED';
    const INSTALLATION_STATUS_CHANGED = 'INSTALLATION_STATUS_CHANGED';
    const INSTALLATION_UNHEALTHY      = 'INSTALLATION_UNHEALTHY';
    const INSTALLATION_SUSPENDED      = 'INSTALLATION_SUSPENDED';
    const INSTALLATION_RESTORED       = 'INSTALLATION_RESTORED';
    const INSTALLATION_UPDATED        = 'INSTALLATION_UPDATED';
    const DEPLOYMENT_STARTED          = 'DEPLOYMENT_STARTED';
    const DEPLOYMENT_COMPLETED        = 'DEPLOYMENT_COMPLETED';
    const DEPLOYMENT_FAILED           = 'DEPLOYMENT_FAILED';
    const DEPLOYMENT_ROLLED_BACK      = 'DEPLOYMENT_ROLLED_BACK';
    const DEPLOYMENT_CANCELLED        = 'DEPLOYMENT_CANCELLED';
    const SERVER_ADDED                = 'SERVER_ADDED';
    const SERVER_UPDATED              = 'SERVER_UPDATED';
    const SERVER_DELETED              = 'SERVER_DELETED';
    const SERVER_CREDENTIAL_WRITTEN   = 'SERVER_CREDENTIAL_WRITTEN';
    const SERVER_CREDENTIAL_ROTATED   = 'SERVER_CREDENTIAL_ROTATED';
    const PROVIDER_ACCOUNT_CREATED    = 'PROVIDER_ACCOUNT_CREATED';
    const PROVIDER_ACCOUNT_SUSPENDED  = 'PROVIDER_ACCOUNT_SUSPENDED';
    const PROVIDER_ACCOUNT_VERIFIED   = 'PROVIDER_ACCOUNT_VERIFIED';
    const PROVIDER_ACCOUNT_VERIFY_REQUESTED = 'PROVIDER_ACCOUNT_VERIFY_REQUESTED';
    const PROVIDER_CREDENTIAL_WRITTEN = 'PROVIDER_CREDENTIAL_WRITTEN';
    const PROVIDER_CREDENTIAL_ROTATED = 'PROVIDER_CREDENTIAL_ROTATED';
    const CUSTOMER_SERVER_QUEUED      = 'CUSTOMER_SERVER_QUEUED';
    const CUSTOMER_SERVER_ACTION_QUEUED = 'CUSTOMER_SERVER_ACTION_QUEUED';
    const CUSTOMER_SERVER_STATE_CHANGED = 'CUSTOMER_SERVER_STATE_CHANGED';
    const PANEL_ACCOUNT_LINKED = 'PANEL_ACCOUNT_LINKED';
    const PANEL_ACCOUNT_ACTION_QUEUED = 'PANEL_ACCOUNT_ACTION_QUEUED';
    const PANEL_ACCOUNT_STATE_CHANGED = 'PANEL_ACCOUNT_STATE_CHANGED';
    const PANEL_ACCOUNT_DOMAINS_LISTED = 'PANEL_ACCOUNT_DOMAINS_LISTED';
    const PANEL_ACCOUNT_ALIASES_LISTED = 'PANEL_ACCOUNT_ALIASES_LISTED';
    const PANEL_ACCOUNT_QUOTA_USAGE_READ = 'PANEL_ACCOUNT_QUOTA_USAGE_READ';
    const PANEL_ACCOUNT_BANDWIDTH_USAGE_READ = 'PANEL_ACCOUNT_BANDWIDTH_USAGE_READ';
    const PANEL_ACCOUNT_OPERATION_FAILED = 'PANEL_ACCOUNT_OPERATION_FAILED';
    const AGENT_REGISTERED            = 'AGENT_REGISTERED';
    const AGENT_KEY_ROTATED           = 'AGENT_KEY_ROTATED';
    const AGENT_REQUEST_REJECTED      = 'AGENT_REQUEST_REJECTED';
    const DOMAIN_ADDED                = 'DOMAIN_ADDED';
    const DNS_INVENTORY_READ           = 'DNS_INVENTORY_READ';
    const DOMAIN_CHANGED              = 'DOMAIN_CHANGED';
    const DOMAIN_VERIFIED             = 'DOMAIN_VERIFIED';
    const DOMAIN_DELETED              = 'DOMAIN_DELETED';
    const SSL_REQUESTED               = 'SSL_REQUESTED';
    const SSL_ISSUED                  = 'SSL_ISSUED';
    const SSL_FAILED                  = 'SSL_FAILED';
    const SSL_RENEWED                 = 'SSL_RENEWED';
    const ENVIRONMENT_CHANGED         = 'ENVIRONMENT_CHANGED';
    const BACKUP_CREATED              = 'BACKUP_CREATED';
    const BACKUP_FAILED               = 'BACKUP_FAILED';
    const BACKUP_RESTORED             = 'BACKUP_RESTORED';
    const BACKUP_DELETED              = 'BACKUP_DELETED';
    const BILLING_CHANGED             = 'BILLING_CHANGED';
    const ORDER_CREATED               = 'ORDER_CREATED';
    const BILLING_EVENT_RECEIVED      = 'BILLING_EVENT_RECEIVED';
    const PAYMENT_CONFIRMED           = 'PAYMENT_CONFIRMED';
    const PAYMENT_REJECTED            = 'PAYMENT_REJECTED';
    const WEBHOOK_RECEIVED            = 'WEBHOOK_RECEIVED';
    const SUBSCRIPTION_CHANGED        = 'SUBSCRIPTION_CHANGED';
    const CUSTOMER_SUSPENDED          = 'CUSTOMER_SUSPENDED';
    const CUSTOMER_RESTORED           = 'CUSTOMER_RESTORED';
    const CUSTOMER_TERMINATED         = 'CUSTOMER_TERMINATED';
    const SETTINGS_CHANGED            = 'SETTINGS_CHANGED';
    const RBAC_CHANGED                = 'RBAC_CHANGED';
    const HEALTH_STATE_CHANGED        = 'HEALTH_STATE_CHANGED';
    const RECOVERY_TRIGGERED          = 'RECOVERY_TRIGGERED';
    const CIRCUIT_BREAKER_OPEN        = 'CIRCUIT_BREAKER_OPEN';

    /** @var string|null last hash, memoised within a request */
    private static $head;

    /**
     * Record an audited action.
     *
     * @param array $options resource_type, resource_id, installation_id, server_id,
     *                       deployment_id, metadata (already safe), severity
     */
    public static function record(Actor $actor, $action, array $options = [])
    {
        $now = Clock::now();
        $metadata = Logger::redact(isset($options['metadata']) && is_array($options['metadata'])
            ? $options['metadata']
            : []);

        $row = [
            'actor_type' => Str::clip($actor->type, 20),
            'actor_id' => (int) $actor->actorId(),
            'actor_role' => Str::clip($actor->role, 40),
            'actor_label' => Str::clip($actor->name, 120),
            'auth_method' => Str::clip($actor->authMethod, 30),
            'action' => Str::clip((string) $action, 60),
            'resource_type' => Str::clip(isset($options['resource_type']) ? $options['resource_type'] : '', 60),
            'resource_id' => isset($options['resource_id']) ? Str::clip((string) $options['resource_id'], 64) : null,
            'client_id' => isset($options['client_id']) ? (int) $options['client_id'] : (int) $actor->clientId,
            'installation_id' => isset($options['installation_id']) ? (int) $options['installation_id'] : null,
            'deployment_id' => isset($options['deployment_id']) ? (int) $options['deployment_id'] : null,
            'server_id' => isset($options['server_id']) ? (int) $options['server_id'] : null,
            'severity' => Str::clip(isset($options['severity']) ? $options['severity'] : 'info', 20),
            'ip_address' => Str::clip((string) ($actor->ip ?: Http::clientIp()), 45),
            'user_agent' => Str::clip((string) ($actor->userAgent ?: Http::userAgent()), 255),
            'metadata' => $metadata ? Str::jsonEncode($metadata) : null,
            'created_at' => $now,
        ];

        $row['previous_hash'] = self::headHash();
        $row['entry_hash'] = self::hashEntry($row);

        try {
            $id = Db::insert('audit_logs', $row);
            self::$head = $row['entry_hash'];
            return $id;
        } catch (\Throwable $e) {
            // An audit failure must be visible but must not silently vanish.
            Logger::error('Audit record could not be written.', [
                'action' => $action,
                'message' => $e->getMessage(),
                'source' => 'audit',
            ]);
            return 0;
        }
    }

    /** Record a state transition with both ends for readability. */
    public static function transition(Actor $actor, $action, $resourceType, $resourceId, $from, $to, array $options = [])
    {
        $options['resource_type'] = $resourceType;
        $options['resource_id'] = $resourceId;
        $options['metadata'] = array_merge(
            isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
            ['from' => $from, 'to' => $to]
        );
        return self::record($actor, $action, $options);
    }

    /**
     * The fields an entry hash commits to.
     *
     * Hashing the whole row would break verification, because the row read back
     * from the database carries columns the writer never set (the primary key,
     * driver defaults). Hashing a fixed canonical subset keeps the chain stable
     * across drivers and still commits to every field that matters: who, what,
     * which resource, when, and the previous entry.
     */
    const HASH_FIELDS = [
        'actor_type', 'actor_id', 'actor_role', 'actor_label', 'auth_method',
        'action', 'resource_type', 'resource_id', 'client_id', 'installation_id',
        'deployment_id', 'server_id', 'severity', 'ip_address', 'user_agent',
        'metadata', 'created_at', 'previous_hash',
    ];

    private static function hashEntry(array $row)
    {
        $canonical = [];
        foreach (self::HASH_FIELDS as $field) {
            $value = array_key_exists($field, $row) ? $row[$field] : null;
            $canonical[$field] = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        return hash('sha256', Str::jsonEncode($canonical));
    }

    private static function headHash()
    {
        if (self::$head !== null) {
            return self::$head;
        }
        $last = Db::first('audit_logs', [], ['order' => 'id', 'dir' => 'desc']);
        self::$head = $last && !empty($last['entry_hash']) ? (string) $last['entry_hash'] : str_repeat('0', 64);
        return self::$head;
    }

    public static function resetHead()
    {
        self::$head = null;
    }

    /**
     * Verify chain integrity from the first entry (or a given id).
     *
     * @return array{valid: bool, checked: int, broken_at: int|null}
     */
    public static function verifyChain($fromId = 0)
    {
        $rows = Db::fetch('audit_logs', ['id' => ['>=', (int) $fromId]], ['order' => 'id', 'dir' => 'asc']);
        $expected = str_repeat('0', 64);
        if ($fromId > 0) {
            $previous = Db::first('audit_logs', ['id' => (int) $fromId - 1]);
            if ($previous && !empty($previous['entry_hash'])) {
                $expected = (string) $previous['entry_hash'];
            }
        }
        foreach ($rows as $row) {
            if ((string) $row['previous_hash'] !== $expected) {
                return ['valid' => false, 'checked' => count($rows), 'broken_at' => (int) $row['id']];
            }
            $recomputed = self::hashEntry($row);
            if ($recomputed !== (string) $row['entry_hash']) {
                return ['valid' => false, 'checked' => count($rows), 'broken_at' => (int) $row['id']];
            }
            $expected = (string) $row['entry_hash'];
        }
        self::$head = $expected;
        return ['valid' => true, 'checked' => count($rows), 'broken_at' => null];
    }

    /**
     * Query the log. Filters are ANDed; results are newest first.
     *
     * @param array $filters action, actor_type, client_id, resource_type,
     *                       resource_id, installation_id, server_id,
     *                       deployment_id, severity, from, to, search
     */
    public static function search(array $filters = [], $limit = 100, $offset = 0)
    {
        $where = [];
        foreach (['action', 'actor_type', 'resource_type', 'resource_id', 'severity'] as $key) {
            if (!empty($filters[$key])) {
                $where[$key] = $filters[$key];
            }
        }
        foreach (['client_id', 'installation_id', 'server_id', 'deployment_id', 'actor_id'] as $key) {
            if (!empty($filters[$key])) {
                $where[$key] = (int) $filters[$key];
            }
        }
        // A date range needs two conditions on the same column, which the
        // where-map builder cannot express: use the explicit range operator.
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $where['created_at'] = ['range', $filters['from'], $filters['to']];
        } elseif (!empty($filters['from'])) {
            $where['created_at'] = ['>=', $filters['from']];
        } elseif (!empty($filters['to'])) {
            $where['created_at'] = ['<=', $filters['to']];
        }
        if (!empty($filters['search'])) {
            $where['action'] = ['like', '%' . $filters['search'] . '%'];
        }

        return Db::fetch('audit_logs', $where, [
            'order' => 'id', 'dir' => 'desc', 'limit' => (int) $limit, 'offset' => (int) $offset,
        ]);
    }

    /** Timeline for one resource (an installation, a deployment, a server). */
    public static function timeline($resourceType, $resourceId, $limit = 200)
    {
        return Db::fetch('audit_logs', [
            'resource_type' => (string) $resourceType,
            'resource_id' => (string) $resourceId,
        ], ['order' => 'id', 'dir' => 'desc', 'limit' => (int) $limit]);
    }

    /** Present one row for a UI: metadata decoded, no hashes unless requested. */
    public static function present(array $row, $includeHashes = false)
    {
        $out = [
            'id' => (int) $row['id'],
            'action' => $row['action'],
            'severity' => $row['severity'],
            'actor' => [
                'type' => $row['actor_type'],
                'id' => (int) $row['actor_id'],
                'role' => $row['actor_role'],
                'name' => $row['actor_label'],
                'auth' => $row['auth_method'],
            ],
            'resource_type' => $row['resource_type'],
            'resource_id' => $row['resource_id'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'metadata' => Str::jsonDecode(isset($row['metadata']) ? $row['metadata'] : null, []),
            'created_at' => $row['created_at'],
        ];
        if ($includeHashes) {
            $out['previous_hash'] = $row['previous_hash'];
            $out['entry_hash'] = $row['entry_hash'];
        }
        return $out;
    }
}
