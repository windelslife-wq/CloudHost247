<?php
/**
 * Marketing cron — the ONLY place messages are actually sent.
 *
 *   1. Release stale worker locks (a crashed run must not strand its batch).
 *   2. Promote scheduled campaigns whose send time has arrived.
 *   3. Drain the delivery queue, respecting rate limits and a wall-clock budget.
 *   4. Advance automations (enrol, step waits, send steps).
 *   5. Refresh segment counts.
 *   6. Housekeeping: prune finished queue rows, old events, rate-limit windows.
 *
 * Run every 5 minutes:
 *   * / 5 * * * * php /path/to/whmcs/modules/addons/cloudhost247marketing/cron/cloudhost247marketing.php --quiet
 *
 * Flags: --quiet (no stdout)  --force (ignore the sending_enabled switch for
 * maintenance phases only; it never overrides the kill switch)  --once
 * (single queue batch instead of draining for the full budget).
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

$init = dirname(__DIR__, 4) . '/init.php';
if (is_file($init)) {
    require_once $init;
}
require_once dirname(__DIR__) . '/autoload.php';

use Ch247Mkt\Audience\SegmentService;
use Ch247Mkt\Automation\AutomationService;
use Ch247Mkt\Campaign\CampaignService;
use Ch247Mkt\Core\Clock;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\RateLimiter;
use Ch247Mkt\Core\Settings;
use Ch247Mkt\Delivery\AnalyticsService;
use Ch247Mkt\Delivery\DeliveryService;
use Ch247Mkt\Delivery\QueueService;

$argv = $argv ?? [];
$quiet = in_array('--quiet', $argv, true);
$force = in_array('--force', $argv, true);
$once  = in_array('--once', $argv, true);

$log = function ($message) use ($quiet) {
    if (!$quiet) {
        echo '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . "\n";
    }
    Logger::info('[cron] ' . $message);
};

$startedAt = microtime(true);
$exit = 0;

if (!Settings::bool('service_enabled', true)) {
    $log('module disabled — nothing to do');
    exit(0);
}

$workerId = DeliveryService::workerId();
$log('worker ' . $workerId);

try {
    /* 1 ------------------------------------------------- stale locks */
    $released = QueueService::releaseStaleLocks();
    if ($released > 0) {
        $log('released ' . $released . ' stale lock(s) from a previous run');
    }

    /* 2 --------------------------------------- promote scheduled sends */
    $due = CampaignService::dueForSending();
    foreach ($due as $campaign) {
        try {
            $result = CampaignService::send((int) $campaign['id'], 0);
            $log('scheduled campaign #' . (int) $campaign['id'] . ' "' . $campaign['name'] . '" released: ' . $result['queued'] . ' queued');
        } catch (\Throwable $e) {
            $log('scheduled campaign #' . (int) $campaign['id'] . ' could not start: ' . $e->getMessage());
            CampaignService::markFailedToStart((int) $campaign['id'], $e->getMessage());
            $exit = 1;
        }
    }

    /* 3 --------------------------------------------------- send queue */
    $totals = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0, 'skipped' => 0];
    $budget = Settings::int('queue_wall_clock_seconds', 50);
    $stopReason = '';

    do {
        $summary = DeliveryService::processBatch(null, $workerId);
        foreach (array_keys($totals) as $key) {
            $totals[$key] += (int) $summary[$key];
        }
        $stopReason = (string) $summary['stopped'];
        $elapsed = microtime(true) - $startedAt;
        $keepGoing = !$once
            && $stopReason === ''
            && (int) $summary['claimed'] > 0
            && $elapsed < $budget;
    } while ($keepGoing);

    if ($totals['claimed'] > 0 || $stopReason !== '') {
        $log('queue: ' . $totals['sent'] . ' sent, ' . $totals['failed'] . ' failed, '
            . $totals['retried'] . ' retrying, ' . $totals['skipped'] . ' skipped'
            . ($stopReason !== '' ? ' (stopped: ' . str_replace('_', ' ', $stopReason) . ')' : ''));
    } else {
        $log('queue: empty');
    }

    /* 4 -------------------------------------------------- automations */
    if (class_exists(AutomationService::class)) {
        try {
            $auto = AutomationService::tick();
            if ($auto['enrolled'] + $auto['advanced'] + $auto['completed'] > 0) {
                $log('automations: ' . $auto['enrolled'] . ' enrolled, ' . $auto['advanced'] . ' advanced, ' . $auto['completed'] . ' completed');
            }
        } catch (\Throwable $e) {
            $log('automations failed: ' . $e->getMessage());
            $exit = 1;
        }
    }

    /* 5 --------------------------------------------- segment refresh */
    // Hourly is plenty; counts are advisory and recomputed at send time anyway.
    if ((int) gmdate('i', Clock::time()) < 5 || $force) {
        $refreshed = SegmentService::refreshAll();
        if ($refreshed > 0) {
            $log('refreshed ' . $refreshed . ' segment count(s)');
        }
    }

    /* 6 ----------------------------------------------- housekeeping */
    if ((int) gmdate('G', Clock::time()) === 3 || $force) {
        $purgedQueue = QueueService::purge();
        $purgedEvents = AnalyticsService::purgeEvents();
        $purgedLimits = RateLimiter::purge();
        $log('housekeeping: ' . $purgedQueue . ' queue row(s), ' . $purgedEvents . ' event(s), ' . $purgedLimits . ' rate-limit window(s) removed');
    }
} catch (\Throwable $e) {
    $log('FATAL: ' . $e->getMessage());
    Logger::error('cron failed', ['reason' => get_class($e), 'message' => $e->getMessage()]);
    $exit = 1;
} finally {
    try {
        \Ch247Mkt\Transport\TransportFactory::release();
    } catch (\Throwable $e) {
        // nothing useful to do while shutting down
    }
}

$log('done in ' . number_format(microtime(true) - $startedAt, 2) . 's');
exit($exit);
