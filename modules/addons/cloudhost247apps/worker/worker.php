<?php
/**
 * CloudHost247 App Cloud — deployment worker.
 *
 * The only process that ever talks to a server agent. Run it as a supervised
 * service (systemd, supervisor, a screen on a small node) — one worker per node
 * is enough for a few hundred installations, and more workers scale horizontally
 * because jobs are leased, not locked:
 *
 *   /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247apps/worker/worker.php
 *   /usr/bin/php -q .../worker/worker.php --queue=deployment --batch=5 --runtime=300
 *   /usr/bin/php -q .../worker/worker.php --queue=provisioning --batch=2
 *   /usr/bin/php -q .../worker/worker.php --queue=control-panel --batch=2
 *   /usr/bin/php -q .../worker/worker.php --once          # single pass (cron fallback)
 *
 * Options:
 *   --queue=NAME      job queue to work (default: deployment)
 *   --batch=N         jobs to lease per pass (default: worker_batch_size setting)
 *   --runtime=SECS    stop after this many seconds (default: worker_max_runtime_seconds)
 *   --poll=SECS       sleep between empty passes (default: worker_poll_seconds)
 *   --once            one pass, then exit
 *   --worker-id=NAME  identity recorded on leases (default: hostname-pid)
 *   --json            print a JSON summary instead of log lines
 *
 * The worker holds the SYSTEM actor: it can drive jobs and automated workflow, but
 * it can never publish an application, rotate a credential key or approve a
 * payment. Those need a human.
 *
 * @package Ch247Apps
 */

define('CH247APPS_WORKER', true);

$whmcsInit = dirname(__DIR__, 4) . '/init.php';
if (file_exists($whmcsInit)) {
    require_once $whmcsInit;
}

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Apps\Core\Actor;
use Ch247Apps\Core\Clock;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Settings;
use Ch247Apps\ControlPanels\PanelAccountWorker;
use Ch247Apps\Deployments\JobQueue;
use Ch247Apps\Deployments\Orchestrator;
use Ch247Apps\Infrastructure\JobDispatcher;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderAccountVerifyWorker;
use Ch247Apps\Infrastructure\ServerProvisioningWorker;
use Ch247Apps\Servers\AgentAuthenticator;

if (PHP_SAPI !== 'cli' && !defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/* ------------------------------------------------------------- arguments -- */

$options = [
    'queue' => JobQueue::QUEUE_DEPLOYMENT,
    'batch' => Settings::int('worker_batch_size', 5),
    'runtime' => Settings::int('worker_max_runtime_seconds', 300),
    'poll' => Settings::int('worker_poll_seconds', 5),
    'once' => false,
    'json' => false,
    'worker-id' => gethostname() . '-' . getmypid(),
];
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--') !== 0) {
        continue;
    }
    $argument = substr($argument, 2);
    $parts = explode('=', $argument, 2);
    $name = $parts[0];
    $value = isset($parts[1]) ? $parts[1] : true;
    if (array_key_exists($name, $options)) {
        $options[$name] = $value;
    }
}
$options['batch'] = max(1, min(50, (int) $options['batch']));
$options['runtime'] = max(5, (int) $options['runtime']);
$options['poll'] = max(1, (int) $options['poll']);

if (!Settings::bool('worker_enabled', true)) {
    $message = 'The deployment worker is disabled (worker_enabled = 0). Nothing to do.';
    if ($options['json']) {
        echo json_encode(['ok' => false, 'error' => 'WORKER_DISABLED', 'message' => $message]), "\n";
    } else {
        echo $message, "\n";
    }
    exit(1);
}

ProviderBootstrap::boot();
$actor = Actor::system('Worker ' . $options['worker-id']);
$queue = new JobQueue();
$orchestrator = new Orchestrator($actor, null, $queue);
$serverProvisioningWorker = new ServerProvisioningWorker($actor, $queue);
$providerAccountVerifyWorker = new ProviderAccountVerifyWorker($actor, $queue);
$panelAccountWorker = new PanelAccountWorker($actor, $queue);
$dispatcher = new JobDispatcher($orchestrator, $serverProvisioningWorker,
    $providerAccountVerifyWorker, $queue, $panelAccountWorker);

$started = Clock::timestamp();
$summary = [
    'worker_id' => (string) $options['worker-id'],
    'queue' => (string) $options['queue'],
    'started_at' => Clock::now(),
    'processed' => 0,
    'completed' => 0,
    'failed' => 0,
    'retried' => 0,
    'housekeeping' => [],
];

$say = function ($message) use ($options) {
    if (!$options['json']) {
        echo '[' . Clock::now() . '] ' . $message . "\n";
    }
};

$say('Worker ' . $options['worker-id'] . ' starting on queue "' . $options['queue'] . '".');

/* -------------------------------------------------------------- main loop -- */

$stop = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = function () use (&$stop, $say) {
        $stop = true;
        $say('Stop requested; finishing the current job.');
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$passes = 0;
while (!$stop && (Clock::timestamp() - $started) < $options['runtime']) {
    $passes++;

    try {
        if (!Db::isBound()) {
            $say('No database connection available; waiting.');
            break;
        }
        $jobs = $queue->lease($options['worker-id'], $options['queue'], $options['batch']);
    } catch (\Throwable $e) {
        Logger::error('Worker could not lease jobs.', ['error' => $e->getMessage(), 'source' => 'worker']);
        $say('Lease error: ' . $e->getMessage());
        break;
    }

    if ($jobs === []) {
        if ($options['once']) {
            break;
        }
        // Idle: do the cheap housekeeping that keeps the queue honest.
        if ($passes % 12 === 0) {
            $summary['housekeeping'] = worker_housekeeping($queue, $summary['housekeeping']);
        }
        sleep($options['poll']);
        continue;
    }

    foreach ($jobs as $job) {
        if ($stop) {
            break;
        }
        $summary['processed']++;
        $jobId = (int) $job['id'];
        $say('Job #' . $jobId . ' (' . $job['job_type'] . ') attempt ' . ((int) $job['attempts'] + 1) . '.');
        try {
            $result = $dispatcher->runJob($job);
            if (isset($result['status']) && $result['status'] === 'completed') {
                $summary['completed']++;
                $say('Job #' . $jobId . ' completed.');
            } else {
                $summary['failed']++;
                $say('Job #' . $jobId . ' failed: '
                    . (isset($result['error']['code']) ? $result['error']['code'] : 'unknown') . '.');
            }
        } catch (\Throwable $e) {
            // A retryable failure was already requeued by runJob(); this is the
            // worker keeping going instead of dying on the first bad job.
            $summary['retried']++;
            $say('Job #' . $jobId . ' will be retried: ' . $e->getMessage());
            Logger::warning('Deployment job will be retried.', [
                'job_id' => $jobId, 'job_type' => $job['job_type'], 'error' => $e->getMessage(),
                'source' => 'worker',
            ]);
        }
    }

    if ($options['once']) {
        break;
    }
}

/* ------------------------------------------------------------- shutdown -- */

$summary['finished_at'] = Clock::now();
$summary['runtime_seconds'] = Clock::timestamp() - $started;
$summary['stopped_by_signal'] = $stop;
$summary['housekeeping'] = worker_housekeeping($queue, $summary['housekeeping']);

if ($options['json']) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $say(sprintf('Finished: %d processed, %d completed, %d failed, %d retried in %ds.',
        $summary['processed'], $summary['completed'], $summary['failed'], $summary['retried'],
        $summary['runtime_seconds']));
}

// Always exit 0: a failed job is data for the dead-letter queue, not a reason for
// a supervisor to restart-loop the worker.
exit(0);

/**
 * Cheap maintenance that keeps the queue and the agent protocol honest.
 *
 * @return array accumulated counts
 */
function worker_housekeeping(JobQueue $queue, array $counts)
{
    $tasks = [
        'leases_released' => function () use ($queue) {
            return $queue->releaseExpiredLeases();
        },
        'nonces_pruned' => function () {
            return AgentAuthenticator::pruneNonces();
        },
    ];
    foreach ($tasks as $name => $task) {
        try {
            $result = (int) $task();
            $counts[$name] = (isset($counts[$name]) ? (int) $counts[$name] : 0) + $result;
        } catch (\Throwable $e) {
            Logger::error('Worker housekeeping task failed.', [
                'task' => $name, 'error' => $e->getMessage(), 'source' => 'worker',
            ]);
        }
    }
    return $counts;
}
