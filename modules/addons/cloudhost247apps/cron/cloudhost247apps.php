<?php
/**
 * CloudHost247 App Cloud — scheduled maintenance.
 *
 * Run every 5 minutes:
 *   /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247apps/cron/cloudhost247apps.php
 *   ... --json      machine-readable summary
 *   ... --task=mark_stale,reap_stalled    run a subset
 *
 * This is the platform's janitor and its honesty check. It marks servers that
 * stopped reporting as offline, reaps deployments no worker is running, prunes
 * the tables that grow with traffic, verifies the audit chain, flags credentials
 * that need rotation, and records which installations have an update available.
 *
 * Everything runs as the SYSTEM actor: it may observe and maintain, never approve,
 * publish or refund.
 *
 * @package Ch247Apps
 */

define('CH247APPS_CRON', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Audit;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Events;
use Ch247Apps\Core\Idempotency;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\RateLimiter;
use Ch247Apps\Core\Settings;
use Ch247Apps\Catalog\VersionService;
use Ch247Apps\Core\Str;
use Ch247Apps\Deployments\DeploymentService;
use Ch247Apps\Deployments\InstallationState;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Servers\AgentAuthenticator;
use Ch247Apps\Servers\CredentialVault;
use Ch247Apps\Servers\ServerService;
use Ch247Apps\Servers\UptimeRetention;

if (PHP_SAPI !== 'cli' && !defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$json = in_array('--json', $argv, true);
$only = [];
foreach ($argv as $argument) {
    if (strpos($argument, '--task=') === 0) {
        $only = array_filter(array_map('trim', explode(',', substr($argument, 7))));
    }
}

$actor = Actor::system('Cron');
$results = [];
$errors = [];

/** Run one task without letting a failure stop the rest. */
$task = function ($name, callable $fn) use (&$results, &$errors, $only) {
    if ($only !== [] && !in_array($name, $only, true)) {
        return;
    }
    try {
        $results[$name] = $fn();
    } catch (\Throwable $e) {
        $results[$name] = 'error';
        $errors[$name] = $e->getMessage();
        Logger::error('App Cloud cron task failed.', [
            'task' => $name, 'message' => $e->getMessage(), 'source' => 'cron',
        ]);
    }
};

try {
    // WHMCS binds Capsule lazily; isBound() alone rejects a valid fresh cron.
    Db::pdo();
} catch (\Throwable $e) {
    $message = 'No database connection is available; the App Cloud cron did nothing.';
    Logger::error($message, ['source' => 'cron']);
    echo $json ? json_encode(['ok' => false, 'error' => $message]) . "\n" : $message . "\n";
    exit(1);
}

/* ------------------------------------------------------- queue hygiene -- */

$queue = new JobQueue();
$task('ratelimits_pruned', function () {
    return RateLimiter::prune();
});
$task('idempotency_pruned', function () {
    return Idempotency::prune();
});
$task('events_pruned', function () {
    return Events::prune();
});
$task('leases_released', function () use ($queue) {
    return $queue->releaseExpiredLeases();
});
$task('jobs_pruned', function () use ($queue) {
    return $queue->prune();
});
$task('nonces_pruned', function () {
    return AgentAuthenticator::pruneNonces();
});
$task('agent_uptime_pruned', function () use ($actor) {
    return (new UptimeRetention($actor))->prune();
});
$task('deployment_logs_pruned', function () use ($actor) {
    return (new DeploymentService($actor))->pruneLogs();
});

/* --------------------------------------------------- infrastructure truth -- */

$task('servers_marked_stale', function () use ($actor) {
    return (new ServerService($actor))->markStale();
});
$task('deployments_reaped', function () use ($actor) {
    $reaped = (new Orchestrator($actor))->reapStalled();
    return $reaped['timed_out'] + $reaped['orphaned'];
});
$task('credentials_needing_attention', function () use ($actor) {
    $attention = (new CredentialVault($actor))->attention();
    if ($attention !== []) {
        Events::emit('security.credentials_attention', [
            'count' => count($attention),
            'types' => array_values(array_unique(array_map(function ($item) {
                return $item['credential_type'];
            }, $attention))),
        ], []);
        Logger::warning('Server credentials need attention.', [
            'count' => count($attention), 'source' => 'cron',
        ]);
    }
    return count($attention);
});

/* ---------------------------------------------------------- catalog truth -- */

$task('updates_available', function () use ($actor) {
    // The catalog owns the definition of "newer": published, latest, same channel.
    $versions = new VersionService($actor);
    $flagged = 0;
    foreach (Db::fetch('installations', ['deleted_at' => null,
        'status' => ['in', [InstallationState::HEALTHY, InstallationState::UNHEALTHY,
            InstallationState::STOPPED, InstallationState::FAILED]]]) as $installation) {
        $current = Db::first('application_versions',
            ['id' => (int) $installation['application_version_id']]);
        if (!$current) {
            continue;
        }
        $newer = $versions->updateAvailable($current);
        $available = $newer ? (string) $newer['version'] : null;
        if ($available === (isset($installation['available_version']) ? $installation['available_version'] : null)) {
            continue;
        }
        Db::update('installations', ['available_version' => $available, 'updated_at' => Clock::now()],
            ['id' => (int) $installation['id']]);
        if ($available !== null) {
            $flagged++;
            Events::emit(Events::APP_UPDATE_AVAILABLE, [
                'reference' => $installation['reference'], 'version' => $available,
                'current' => $installation['current_version'],
            ], ['installation_id' => (int) $installation['id'],
                'client_id' => (int) $installation['customer_id']]);
        }
    }
    return $flagged;
});

/* ------------------------------------------------------- integrity check -- */

$task('audit_chain_valid', function () {
    $result = Audit::verifyChain();
    if (empty($result['valid'])) {
        Logger::error('The audit hash chain failed verification.', [
            'broken_at' => isset($result['broken_at']) ? $result['broken_at'] : null,
            'checked' => isset($result['checked']) ? $result['checked'] : 0,
            'source' => 'cron',
        ]);
        Events::emit('security.audit_chain_broken', ['broken_at' => $result['broken_at']], []);
        return false;
    }
    return true;
});

$task('leaked_resources', function () use ($actor) {
    $leaked = (new DeploymentService($actor))->leaked(500);
    if ($leaked !== []) {
        Logger::error('Resources were left behind by failed deployments.', [
            'count' => count($leaked), 'source' => 'cron',
        ]);
        Events::emit('deployment.leaked_resources', ['count' => count($leaked)], []);
    }
    return count($leaked);
});

/* --------------------------------------------------------------- output -- */

$summary = [
    'ok' => $errors === [],
    'ran_at' => Clock::now(),
    'tasks' => $results,
    'errors' => $errors,
    'queue' => $queue->statistics(),
];

if ($json) {
    echo Str::jsonEncode($summary), "\n";
} else {
    foreach ($results as $name => $value) {
        printf("%-32s %s\n", $name, is_scalar($value) ? var_export($value, true) : json_encode($value));
    }
    if ($errors !== []) {
        echo "\nErrors:\n";
        foreach ($errors as $name => $message) {
            printf("  %s: %s\n", $name, $message);
        }
    }
}

exit($errors === [] ? 0 : 1);
