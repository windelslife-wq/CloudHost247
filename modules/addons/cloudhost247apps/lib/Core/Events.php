<?php
/**
 * CloudHost247 App Cloud — event bus.
 *
 * One append-only event stream feeds three consumers:
 *
 *   • the deployment console (SSE / long-poll: "✓ Pulling image", "● Configuring domain")
 *   • the notification service (deployment completed/failed, SSL expiring, …)
 *   • audit-adjacent operational history queryable per installation/server
 *
 * Events are persisted first and dispatched to in-process listeners second, so a
 * worker crash never loses the record of what happened. Listeners are registered
 * by the bootstrap (Notifications, Monitoring) and never by request code.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Events
{
    /* Event names used across the platform. */
    const DEPLOYMENT_QUEUED       = 'deployment.queued';
    const DEPLOYMENT_STARTED      = 'deployment.started';
    const DEPLOYMENT_STEP_STARTED = 'deployment.step.started';
    const DEPLOYMENT_STEP_DONE    = 'deployment.step.done';
    const DEPLOYMENT_STEP_FAILED  = 'deployment.step.failed';
    const DEPLOYMENT_COMPLETED    = 'deployment.completed';
    const DEPLOYMENT_FAILED       = 'deployment.failed';
    const DEPLOYMENT_ROLLED_BACK  = 'deployment.rolled_back';
    const DEPLOYMENT_CANCELLED    = 'deployment.cancelled';

    const INSTALLATION_CREATED    = 'installation.created';
    const INSTALLATION_STATE      = 'installation.state_changed';
    const INSTALLATION_HEALTHY    = 'installation.healthy';
    const INSTALLATION_UNHEALTHY  = 'installation.unhealthy';
    const INSTALLATION_DELETED    = 'installation.deleted';
    const INSTALLATION_RESTARTED  = 'installation.restarted';
    const INSTALLATION_METRICS    = 'installation.metrics';
    const INSTALLATION_UPDATED    = 'installation.updated';

    const SERVER_REGISTERED       = 'server.registered';
    const SERVER_HEARTBEAT        = 'server.heartbeat';
    const SERVER_STALE            = 'server.stale';
    const SERVER_METRICS          = 'server.metrics';

    const DOMAIN_VERIFIED         = 'domain.verified';
    const DOMAIN_ATTACHED         = 'domain.attached';
    const SSL_REQUESTED           = 'ssl.requested';
    const SSL_ISSUED              = 'ssl.issued';
    const SSL_RENEWED             = 'ssl.renewed';
    const SSL_EXPIRING            = 'ssl.expiring';
    const SSL_FAILED              = 'ssl.failed';

    const BACKUP_STARTED          = 'backup.started';
    const BACKUP_COMPLETED        = 'backup.completed';
    const BACKUP_FAILED           = 'backup.failed';
    const BACKUP_RESTORED         = 'backup.restored';

    const PAYMENT_CONFIRMED       = 'payment.confirmed';
    const PAYMENT_FAILED          = 'payment.failed';
    const ORDER_CREATED           = 'order.created';
    const SUBSCRIPTION_CHANGED    = 'subscription.changed';
    const HOSTING_SUSPENDED       = 'hosting.suspended';
    const HOSTING_RESTORED        = 'hosting.restored';
    const HOSTING_TERMINATED      = 'hosting.terminated';

    const APP_UPDATE_AVAILABLE    = 'application.update_available';
    const CIRCUIT_BREAKER_OPEN    = 'recovery.circuit_breaker_open';

    /** @var array<string, callable[]> */
    private static $listeners = [];

    /** @var bool when false, emit() only persists (used in the worker hot path) */
    private static $dispatchEnabled = true;

    /** @var array in-memory record of emitted events (tests) */
    private static $emitted = [];

    public static function listen($event, callable $listener)
    {
        self::$listeners[(string) $event][] = $listener;
    }

    public static function resetListeners()
    {
        self::$listeners = [];
        self::$emitted = [];
        self::$dispatchEnabled = true;
    }

    public static function setDispatch($enabled)
    {
        self::$dispatchEnabled = (bool) $enabled;
    }

    public static function emitted($event = null)
    {
        if ($event === null) {
            return self::$emitted;
        }
        return array_values(array_filter(self::$emitted, function ($row) use ($event) {
            return $row['event'] === $event;
        }));
    }

    /**
     * Persist and dispatch an event.
     *
     * @param array $payload arbitrary safe data (redacted before storage)
     * @return int the event id (0 when persistence is unavailable)
     */
    public static function emit($event, array $payload = [], array $scope = [])
    {
        $event = (string) $event;
        $payload = Logger::redact($payload);
        $now = Clock::now();

        $id = 0;
        try {
            if (Db::isBound() && Db::tableExists('events')) {
                $id = Db::insert('events', [
                    'event' => Str::clip($event, 80),
                    'installation_id' => isset($scope['installation_id']) ? (int) $scope['installation_id'] : null,
                    'deployment_id' => isset($scope['deployment_id']) ? (int) $scope['deployment_id'] : null,
                    'server_id' => isset($scope['server_id']) ? (int) $scope['server_id'] : null,
                    'client_id' => isset($scope['client_id']) ? (int) $scope['client_id'] : null,
                    'payload' => $payload ? Str::jsonEncode($payload) : null,
                    'source' => Str::clip(isset($scope['source']) ? $scope['source'] : 'platform', 40),
                    'created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('Event could not be persisted.', ['event' => $event, 'message' => $e->getMessage()]);
        }

        $record = [
            'id' => $id,
            'event' => $event,
            'payload' => $payload,
            'scope' => $scope,
            'created_at' => $now,
        ];
        self::$emitted[] = $record;

        if (self::$dispatchEnabled && isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $listener) {
                try {
                    call_user_func($listener, $record);
                } catch (\Throwable $e) {
                    // A failing listener must never break the operation.
                    Logger::error('Event listener failed.', [
                        'event' => $event, 'message' => $e->getMessage(),
                    ]);
                }
            }
        }
        return $id;
    }

    /**
     * Events after a given id, for one scope — the SSE/long-poll source.
     *
     * @param array $scope installation_id | deployment_id | server_id
     */
    public static function since($afterId, array $scope = [], $limit = 200)
    {
        $where = ['id' => ['>', (int) $afterId]];
        foreach (['installation_id', 'deployment_id', 'server_id', 'client_id'] as $key) {
            if (!empty($scope[$key])) {
                $where[$key] = (int) $scope[$key];
            }
        }
        $rows = Db::fetch('events', $where, ['order' => 'id', 'dir' => 'asc', 'limit' => (int) $limit]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'event' => $row['event'],
                'payload' => Str::jsonDecode(isset($row['payload']) ? $row['payload'] : null, []),
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    /** Prune the stream; the scheduler calls this with the configured horizon. */
    public static function prune($olderThanDays = 14)
    {
        if (!Db::tableExists('events')) {
            return 0;
        }
        return Db::delete('events', ['created_at' => ['<', Clock::inDays(-max(1, (int) $olderThanDays))]]);
    }
}
