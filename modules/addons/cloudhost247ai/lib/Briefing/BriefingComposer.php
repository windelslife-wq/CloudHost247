<?php
/**
 * Briefing Composer — the deterministic replacement for the "AI executive
 * board". No agent-to-agent chatter, no voting, no emergent decisions:
 *  1. Collect metric packs with plain SQL (read_metrics tool code path).
 *  2. Assemble a structured briefing (deterministic, no model).
 *  3. OPTIONALLY narrate it with ONE model call, clearly marked as narrative.
 * When no provider is configured the briefing still ships — metrics only.
 */

namespace Ch247Ai\Briefing;

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Logger;
use Ch247Ai\Core\Settings;
use Ch247Ai\Memory\Memory;

class BriefingComposer
{
    /** @return array{briefing_id: int, metrics_only: bool} */
    public static function compose()
    {
        if (!Settings::bool('briefings_enabled', true)) {
            return ['briefing_id' => 0, 'metrics_only' => true, 'skipped' => 'disabled'];
        }
        require_once dirname(__DIR__) . '/Tools/Readers/MetricsReaders.php';
        $pack = \Ch247Ai\Tools\Readers\ch247ai_platform_metrics(['today', '7d', '30d']);
        $sections = self::sections($pack);
        $metricsOnly = !\Ch247Ai\Model\ModelRouter::copilotConfigured() || Settings::bool('kill_switch', false);
        $narrative = '';

        if (!$metricsOnly) {
            try {
                $result = AgentRuntime::run('briefing_composer', self::narrationTask($pack), [
                    'source' => AgentRuntime::SOURCE_CRON,
                    'actor_type' => 'system',
                    'actor_id' => 0,
                    'profile' => 'fast',
                    'metrics_only' => true,
                ]);
                $narrative = $result['status'] === 'success' ? $result['output'] : '';
            } catch (\Throwable $e) {
                Logger::warning('briefing narration skipped', ['reason' => get_class($e)]);
                $narrative = '';
            }
        }

        $id = Db::insert('reports', [
            'type' => 'daily',
            'period_start' => Clock::today(),
            'period_end' => Clock::today(),
            'metric_pack' => json_encode($pack, JSON_UNESCAPED_UNICODE),
            'sections' => json_encode($sections, JSON_UNESCAPED_UNICODE),
            'narrative' => $narrative !== '' ? $narrative : null,
            'model' => $metricsOnly ? 'none' : 'configured',
            'metrics_only' => $metricsOnly ? 1 : 0,
            'created_at' => Clock::now(),
        ]);
        Memory::rememberRun('briefing_composer', $id, 'daily briefing ' . Clock::today() . ' (' . ($metricsOnly ? 'metrics only' : 'narrated') . ')');
        Audit::system('ai.briefing.composed', ['briefing_id' => $id, 'metrics_only' => $metricsOnly]);
        return ['briefing_id' => $id, 'metrics_only' => $metricsOnly];
    }

    /** Deterministic sections — pure SQL facts, ordered, no prose. */
    protected static function sections(array $pack)
    {
        $sections = [];
        $byName = [];
        foreach ($pack['metrics'] as $metric) {
            $byName[$metric['metric']] = $metric['value'];
        }
        $sections[] = [
            'title' => 'Revenue',
            'lines' => [
                'Paid today: ' . self::fmt($byName, 'paid_today_amount'),
                'Paid last 7 days: ' . self::fmt($byName, 'paid_7d_amount'),
                'Paid last 30 days: ' . self::fmt($byName, 'paid_30d_amount'),
                'Unpaid exposure: ' . self::fmt($byName, 'invoices_unpaid_amount') . ' across ' . self::fmt($byName, 'invoices_unpaid_total') . ' invoices',
            ],
        ];
        $sections[] = [
            'title' => 'Growth',
            'lines' => [
                'New clients today: ' . self::fmt($byName, 'new_clients_today'),
                'New clients last 7 days: ' . self::fmt($byName, 'new_clients_7d'),
                'New clients last 30 days: ' . self::fmt($byName, 'new_clients_30d'),
                'Active clients total: ' . self::fmt($byName, 'clients_active_total'),
            ],
        ];
        $sections[] = [
            'title' => 'Operations',
            'lines' => [
                'Active services: ' . self::fmt($byName, 'services_active_total'),
                'Open tickets: ' . self::fmt($byName, 'tickets_open_total'),
                'Domains expiring within 30 days: ' . self::fmt($byName, 'domains_expiring_30d'),
                'Services due within 7 days: ' . self::fmt($byName, 'services_due_7d'),
            ],
        ];
        return $sections;
    }

    protected static function narrationTask(array $pack)
    {
        $lines = [];
        foreach ($pack['metrics'] as $metric) {
            $lines[] = $metric['metric'] . ' = ' . $metric['value'];
        }
        return "Narrate the following verified metric pack for a hosting operator's daily briefing. Use only these numbers, do not add any facts, do not speculate. Keep it under 180 words. METRIC PACK:\n" . implode("\n", $lines);
    }

    protected static function fmt(array $byName, $key)
    {
        if (!isset($byName[$key])) {
            return 'n/a';
        }
        $value = $byName[$key];
        // Money metrics get two decimals; counts stay integers.
        return strpos($key, 'amount') !== false ? number_format($value, 2, '.', '') : (string) $value;
    }
}
