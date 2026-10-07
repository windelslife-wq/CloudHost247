<?php
/**
 * CloudHost247 App Cloud — installation state machine.
 *
 * One place defines which installation status may follow which, so the portal,
 * the worker and the billing hooks cannot disagree about what "stopped" means or
 * let an installation jump from `deleted` back to `healthy`.
 *
 * Every transition is written with its previous status and timestamp, emitted on
 * the event stream (the customer console follows it live) and audited.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Deployments;

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\NotFoundException;
use Ch247Apps\Core\StateException;
use Ch247Apps\Core\Str;

class InstallationState
{
    const PENDING   = 'pending';
    const QUEUED    = 'queued';
    const DEPLOYING = 'deploying';
    const STARTING  = 'starting';
    const HEALTHY   = 'healthy';
    const UNHEALTHY = 'unhealthy';
    const STOPPED   = 'stopped';
    const UPDATING  = 'updating';
    const FAILED    = 'failed';
    const DELETING  = 'deleting';
    const DELETED   = 'deleted';
    const SUSPENDED = 'suspended';
    const TERMINATED = 'terminated';

    const STATUSES = [
        self::PENDING, self::QUEUED, self::DEPLOYING, self::STARTING, self::HEALTHY, self::UNHEALTHY,
        self::STOPPED, self::UPDATING, self::FAILED, self::DELETING, self::DELETED, self::SUSPENDED,
        self::TERMINATED,
    ];

    /** Statuses a customer sees as "my app is up and reachable". */
    const LIVE = [self::HEALTHY, self::UNHEALTHY];

    /** Statuses where the workload still exists on a server. */
    const PROVISIONED = [self::DEPLOYING, self::STARTING, self::HEALTHY, self::UNHEALTHY, self::STOPPED,
        self::UPDATING, self::FAILED, self::SUSPENDED];

    /** Terminal: nothing may follow. */
    const TERMINAL = [self::DELETED, self::TERMINATED];

    const TRANSITIONS = [
        self::PENDING => [self::QUEUED, self::DEPLOYING, self::FAILED, self::DELETED, self::SUSPENDED],
        self::QUEUED => [self::DEPLOYING, self::STARTING, self::FAILED, self::PENDING, self::DELETED, self::SUSPENDED],
        self::DEPLOYING => [self::HEALTHY, self::UNHEALTHY, self::FAILED, self::STOPPED, self::SUSPENDED],
        self::STARTING => [self::HEALTHY, self::UNHEALTHY, self::FAILED, self::STOPPED, self::SUSPENDED],
        self::HEALTHY => [self::STOPPED, self::UPDATING, self::UNHEALTHY, self::SUSPENDED, self::DELETING,
            self::FAILED, self::DEPLOYING],
        self::UNHEALTHY => [self::HEALTHY, self::STOPPED, self::UPDATING, self::DELETING, self::SUSPENDED,
            self::FAILED, self::DEPLOYING],
        self::STOPPED => [self::STARTING, self::DEPLOYING, self::UPDATING, self::DELETING, self::SUSPENDED,
            self::HEALTHY, self::FAILED],
        self::UPDATING => [self::HEALTHY, self::UNHEALTHY, self::FAILED, self::STOPPED, self::SUSPENDED],
        self::FAILED => [self::QUEUED, self::DEPLOYING, self::STARTING, self::DELETING, self::DELETED,
            self::SUSPENDED, self::STOPPED],
        self::DELETING => [self::DELETED, self::FAILED, self::HEALTHY],
        self::SUSPENDED => [self::DEPLOYING, self::STARTING, self::HEALTHY, self::DELETING, self::TERMINATED,
            self::STOPPED],
        self::DELETED => [],
        self::TERMINATED => [],
    ];

    public static function canTransition($from, $to)
    {
        $from = strtolower((string) $from);
        $to = strtolower((string) $to);
        if ($from === $to) {
            return true;
        }
        return isset(self::TRANSITIONS[$from]) && in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function allowedFrom($from)
    {
        $from = strtolower((string) $from);
        return isset(self::TRANSITIONS[$from]) ? self::TRANSITIONS[$from] : [];
    }

    /**
     * Move an installation to a new status.
     *
     * @param array $extra columns to write with the transition (health_status,
     *                     domain, access_url, installed_at, …)
     * @throws StateException when the transition is not allowed
     */
    public static function apply($installationId, $to, array $extra = [], Actor $actor = null, $reason = '')
    {
        $actor = $actor ?: Actor::system('InstallationState');
        $row = Db::first('installations', ['id' => (int) $installationId]);
        if (!$row) {
            throw new NotFoundException('That installation does not exist.');
        }
        $from = (string) $row['status'];
        $to = strtolower((string) $to);
        if (!in_array($to, self::STATUSES, true)) {
            throw new StateException('Unknown installation status "' . $to . '".', [
                'error_code' => 'INSTALLATION_UNKNOWN_STATUS', 'status' => $to,
            ]);
        }
        if ($from === $to) {
            // Re-applying the current status is allowed (health re-checks do it
            // constantly) but writes only the extra columns.
            if ($extra !== []) {
                Db::update('installations', $extra + ['updated_at' => Clock::now()], ['id' => (int) $row['id']]);
            }
            return Db::first('installations', ['id' => (int) $row['id']]);
        }
        if (!self::canTransition($from, $to)) {
            throw new StateException('Cannot move an installation from ' . $from . ' to ' . $to . '.', [
                'error_code' => 'INSTALLATION_INVALID_TRANSITION',
                'from' => $from, 'to' => $to, 'allowed' => self::allowedFrom($from),
            ]);
        }

        $now = Clock::now();
        $fields = array_merge($extra, [
            'status' => $to,
            'previous_status' => $from,
            'status_changed_at' => $now,
            'updated_at' => $now,
        ]);
        if ($to === self::HEALTHY && empty($row['installed_at'])) {
            $fields['installed_at'] = $now;
        }
        if ($to === self::SUSPENDED) {
            $fields['suspended_at'] = $now;
        }
        if ($to === self::TERMINATED) {
            $fields['terminated_at'] = $now;
        }
        if (isset($extra['health_status'])) {
            $fields['health_checked_at'] = $now;
        }
        Db::update('installations', $fields, ['id' => (int) $row['id']]);

        Audit::transition($actor, Audit::INSTALLATION_STATUS_CHANGED, 'installation', (int) $row['id'], $from, $to, [
            'client_id' => (int) $row['customer_id'],
            'installation_id' => (int) $row['id'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null,
            'metadata' => ['reason' => Str::clip($reason, 200), 'reference' => $row['reference']],
            'severity' => in_array($to, [self::FAILED, self::UNHEALTHY, self::TERMINATED], true) ? 'warning' : 'info',
        ]);
        Events::emit(Events::INSTALLATION_STATE, [
            'from' => $from, 'to' => $to, 'reference' => $row['reference'], 'reason' => Str::clip($reason, 200),
        ], ['installation_id' => (int) $row['id'], 'client_id' => (int) $row['customer_id'],
            'server_id' => $row['server_id'] ? (int) $row['server_id'] : null]);

        if ($to === self::HEALTHY) {
            Events::emit(Events::INSTALLATION_HEALTHY, ['reference' => $row['reference']],
                ['installation_id' => (int) $row['id'], 'client_id' => (int) $row['customer_id']]);
        }
        if ($to === self::UNHEALTHY) {
            Events::emit(Events::INSTALLATION_UNHEALTHY, ['reference' => $row['reference'],
                'reason' => Str::clip($reason, 200)],
                ['installation_id' => (int) $row['id'], 'client_id' => (int) $row['customer_id']]);
        }
        if ($to === self::DELETED) {
            Events::emit(Events::INSTALLATION_DELETED, ['reference' => $row['reference']],
                ['installation_id' => (int) $row['id'], 'client_id' => (int) $row['customer_id']]);
        }
        Logger::info('Installation status changed.', [
            'installation_id' => (int) $row['id'], 'from' => $from, 'to' => $to,
            'reason' => Str::clip($reason, 200), 'source' => 'deployments',
        ]);

        return Db::first('installations', ['id' => (int) $row['id']]);
    }

    /** Record a health verdict without moving the lifecycle status. */
    public static function recordHealth($installationId, $state, $message = '', Actor $actor = null)
    {
        $state = in_array((string) $state, ['healthy', 'unhealthy', 'unknown'], true) ? (string) $state : 'unknown';
        $row = Db::first('installations', ['id' => (int) $installationId]);
        if (!$row) {
            throw new NotFoundException('That installation does not exist.');
        }
        $now = Clock::now();
        Db::update('installations', [
            'health_status' => $state,
            'health_checked_at' => $now,
            'health_message' => Str::clip((string) $message, 255),
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        $current = (string) $row['status'];
        // Only a running installation follows its health verdict; a stopped or
        // deleting one keeps its lifecycle status.
        if (in_array($current, [self::HEALTHY, self::UNHEALTHY, self::DEPLOYING, self::STARTING, self::UPDATING], true)) {
            $target = $state === 'healthy' ? self::HEALTHY : ($state === 'unhealthy' ? self::UNHEALTHY : null);
            if ($target !== null && $target !== $current) {
                self::apply((int) $row['id'], $target, ['health_status' => $state,
                    'health_message' => Str::clip((string) $message, 255)], $actor, 'Health probe');
            }
        }
        return Db::first('installations', ['id' => (int) $row['id']]);
    }

    /**
     * Count a restart and decide whether the circuit breaker should open.
     *
     * An application that keeps crashing must not be restarted forever: after the
     * configured number of restarts inside the window the circuit opens, the
     * installation is marked unhealthy and administrators are alerted.
     *
     * @return array{restarts: int, circuit_state: string, opened: bool}
     */
    public static function registerRestart($installationId, $reason = '', Actor $actor = null)
    {
        $row = Db::first('installations', ['id' => (int) $installationId]);
        if (!$row) {
            throw new NotFoundException('That installation does not exist.');
        }
        $now = Clock::now();
        $restarts = (int) $row['restart_count'] + 1;
        $threshold = \Ch247Apps\Core\Settings::int('crash_loop_restart_threshold', 5);
        $windowMinutes = \Ch247Apps\Core\Settings::int('crash_loop_window_minutes', 30);

        // Restarts outside the window start the count again.
        if (!empty($row['last_restart_at'])
            && Clock::diffSeconds($row['last_restart_at'], $now) > $windowMinutes * 60) {
            $restarts = 1;
        }

        $open = $restarts >= $threshold && (string) $row['circuit_state'] !== 'open';
        Db::update('installations', [
            'restart_count' => $restarts,
            'last_restart_at' => $now,
            'circuit_state' => $open ? 'open' : (string) $row['circuit_state'],
            'circuit_opened_at' => $open ? $now : $row['circuit_opened_at'],
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        if ($open) {
            Events::emit(Events::CIRCUIT_BREAKER_OPEN, [
                'reference' => $row['reference'], 'restarts' => $restarts, 'window_minutes' => $windowMinutes,
                'reason' => Str::clip($reason, 200),
            ], ['installation_id' => (int) $row['id'], 'client_id' => (int) $row['customer_id'],
                'server_id' => $row['server_id'] ? (int) $row['server_id'] : null]);
            Audit::record($actor ?: Actor::system('InstallationState'), Audit::INSTALLATION_UNHEALTHY, [
                'resource_type' => 'installation', 'resource_id' => (int) $row['id'],
                'client_id' => (int) $row['customer_id'],
                'metadata' => ['circuit_state' => 'open', 'restarts' => $restarts,
                    'reason' => Str::clip($reason, 200)],
                'severity' => 'error',
            ]);
            Logger::error('Circuit breaker opened after repeated restarts.', [
                'installation_id' => (int) $row['id'], 'restarts' => $restarts, 'source' => 'deployments',
            ]);
            self::apply((int) $row['id'], self::UNHEALTHY, [
                'health_status' => 'unhealthy',
                'health_message' => 'Restarted ' . $restarts . ' times in ' . $windowMinutes . ' minutes.',
            ], $actor, 'Crash loop detected');
        }

        return ['restarts' => $restarts, 'circuit_state' => $open ? 'open' : (string) $row['circuit_state'],
            'opened' => $open];
    }

    /** Close the circuit after a period of healthy operation. */
    public static function closeCircuit($installationId, Actor $actor = null)
    {
        $row = Db::first('installations', ['id' => (int) $installationId]);
        if (!$row || (string) $row['circuit_state'] === 'closed') {
            return false;
        }
        Db::update('installations', [
            'circuit_state' => 'closed', 'circuit_opened_at' => null, 'restart_count' => 0,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);
        Logger::info('Circuit breaker closed after recovery.', [
            'installation_id' => (int) $row['id'], 'source' => 'deployments',
        ]);
        return true;
    }

    /** True when the circuit breaker forbids another automatic restart. */
    public static function circuitOpen(array $row)
    {
        return (string) $row['circuit_state'] === 'open';
    }
}
