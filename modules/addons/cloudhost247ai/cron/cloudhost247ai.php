<?php
/**
 * AI cron — the ONLY place scheduled work happens.
 *
 *  1. Drain captured events (web hooks only ever INSERT).
 *  2. Scheduled agent passes (SSL Guardian, DNS & Domain, Billing
 *     Reconciliation, Collections evidence gathering).
 *  3. Daily briefing (deterministic metrics + optional single narration).
 *  4. Housekeeping: expire approvals, prune events/memories/runs, rate limits.
 *
 * Run: php cron/cloudhost247ai.php  (add to crontab every 5 minutes; the
 * scheduled passes only fire at their configured hour).
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once dirname(__DIR__) . '/autoload.php';

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Briefing\BriefingComposer;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Logger;
use Ch247Ai\Core\Settings;
use Ch247Ai\Event\EventBus;
use Ch247Ai\Memory\Memory;
use Ch247Ai\Tools\Bootstrap as ToolBootstrap;

ToolBootstrap::register();

$quiet = in_array('--quiet', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);
$log = function ($message) use ($quiet) {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    }
    Logger::info($message);
};

$exit = 0;
try {
    // 1. Event drain — pending events become agent memories (no model calls).
    $drained = EventBus::drain(100);
    $log('event drain: ' . $drained['processed'] . ' processed, ' . $drained['failed'] . ' failed/dead');

    // 2. Scheduled agent passes at the configured briefing hour (UTC).
    $hour = Settings::int('briefing_hour', 6);
    $isHour = (int) gmdate('G', Clock::time()) === $hour;
    if ($isHour || $force) {
        foreach (['ssl_guardian', 'dns_domain_agent', 'billing_reconciliation', 'collections_agent'] as $slug) {
            if (!AgentRegistry::isEnabled($slug)) {
                $log($slug . ': disabled, skipped');
                continue;
            }
            try {
                $task = Ch247AiScheduledTask::create($slug, 'Scheduled evidence pass');
                $result = AgentRuntime::run($slug, Ch247AiScheduledTask::prompt($slug), [
                    'source' => AgentRuntime::SOURCE_CRON,
                    'actor_type' => 'system',
                    'actor_id' => 0,
                    'task_id' => $task,
                    'profile' => 'fast',
                ]);
                $log($slug . ': run ' . $result['run_id'] . ' status=' . $result['status'] . ' tools=' . $result['tool_calls']);
            } catch (\Ch247Ai\Core\ProviderNotConfiguredException $e) {
                $log($slug . ': CONFIGURATION_REQUIRED (no model provider) — evidence-only skip');
            } catch (\Throwable $e) {
                $log($slug . ': failed — ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        // 3. Daily briefing — works without a provider (metrics only).
        try {
            $briefing = BriefingComposer::compose();
            $log('briefing #' . $briefing['briefing_id'] . ' (' . ($briefing['metrics_only'] ? 'metrics only' : 'narrated') . ')');
        } catch (\Throwable $e) {
            $log('briefing failed — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    // 4. Housekeeping.
    $expired = ApprovalEngine::expireStale();
    if ($expired) {
        $log('approvals expired: ' . $expired);
    }
    EventBus::prune();
    Memory::prune(Settings::int('retention_days_runs', 180));
    $runDays = Settings::int('retention_days_runs', 180);
    if ($runDays > 0) {
        Db::exec('DELETE FROM ' . Db::t('runs') . " WHERE status IN ('success','refused','stale','failed') AND started_at < ?", [Clock::ago($runDays * 86400)]);
    }
    \Ch247Ai\Core\RateLimiter::purge(86400);
    $log('housekeeping complete');
} catch (\Throwable $e) {
    $exit = 1;
    $log('FATAL ' . get_class($e) . ': ' . $e->getMessage());
}

exit($exit);

/**
 * Small helpers for the scheduled passes. Tasks are queued with a subject and
 * input so runs reference them (plan §7 tasks table).
 */
class Ch247AiScheduledTask
{
    public static function create($agentSlug, $subject)
    {
        return Db::insert('tasks', [
            'agent' => $agentSlug,
            'origin' => 'schedule',
            'requested_by_admin_id' => 0,
            'client_id' => null,
            'subject' => $subject,
            'input' => json_encode(['kind' => 'scheduled_pass', 'date' => Clock::today()]),
            'status' => 'pending',
            'priority' => 'normal',
            'created_at' => Clock::now(),
            'finished_at' => null,
        ]);
    }

    public static function prompt($slug)
    {
        $map = [
            'ssl_guardian' => 'Check the TLS certificates of domains expiring soon using the ssl_checker tool for up to 5 domains from read_domains (filter by nearest expirydate). Report expiry findings exactly as measured.',
            'dns_domain_agent' => 'Review domains via read_domains (limit 5, nearest expiry first) and run dns_health on up to 3 of them. Report findings exactly as the tools returned.',
            'billing_reconciliation' => 'Use read_metrics and read_invoices (Unpaid, limit 20) to summarise the current billing exposure. Report record ids and amounts exactly.',
            'collections_agent' => 'Use read_invoices (status Unpaid, limit 20, order by oldest) to list overdue exposure per record id. Do not draft any communication.',
        ];
        return isset($map[$slug]) ? $map[$slug] : 'Scheduled pass: collect evidence with your tools and report findings.';
    }
}
