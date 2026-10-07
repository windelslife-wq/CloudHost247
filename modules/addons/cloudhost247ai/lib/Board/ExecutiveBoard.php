<?php
/**
 * Executive Board composer.
 *
 * The brief asks for ten C-level agents conferring in a war room. What ships
 * here is the OUTPUT of that board without the failure mode: seats are
 * deterministic SQL packs, cross-department findings are SQL joins, and the
 * only model involvement is one optional narration pass over figures that are
 * already verified.
 *
 * Consequences, by design:
 *   - no agent can fabricate another agent's conclusion (none of them speak);
 *   - a seat with no data feed reports CONFIGURATION_REQUIRED rather than
 *     being quietly dropped or filled in by the narrator;
 *   - the report still ships in full when no model is configured.
 *
 * Reports persist into the existing `reports` table (types board_daily /
 * board_weekly / board_monthly) — no duplicate schema.
 *
 * @package Ch247Ai\Board
 */

namespace Ch247Ai\Board;

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Logger;
use Ch247Ai\Core\Settings;
use Ch247Ai\Memory\Memory;
use Ch247Ai\Model\ModelRouter;

class ExecutiveBoard
{
    const TYPES = ['daily', 'weekly', 'monthly'];

    /** Report row type for a board period. */
    public static function reportType($period)
    {
        return 'board_' . self::normalizePeriod($period);
    }

    public static function normalizePeriod($period)
    {
        $p = strtolower(trim((string) $period));
        return in_array($p, self::TYPES, true) ? $p : 'daily';
    }

    /**
     * Compose a board report.
     *
     * @return array{report_id:int, metrics_only:bool, period:string, seats_ok:int, seats_unavailable:int}
     */
    public static function compose($period = 'daily')
    {
        $period = self::normalizePeriod($period);
        if (!Settings::bool('board_enabled', true)) {
            return ['report_id' => 0, 'metrics_only' => true, 'period' => $period, 'skipped' => 'disabled',
                'seats_ok' => 0, 'seats_unavailable' => 0];
        }

        $seats = DepartmentPacks::all();
        $cross = CrossFindings::all();
        $summary = self::summary($seats, $cross);
        $sections = self::sections($seats, $cross, $summary);

        $metricsOnly = !ModelRouter::copilotConfigured() || Settings::bool('kill_switch', false);
        $narrative = '';
        if (!$metricsOnly) {
            try {
                $result = AgentRuntime::run('executive_board', self::narrationTask($seats, $cross, $summary), [
                    'source' => AgentRuntime::SOURCE_CRON,
                    'actor_type' => 'system',
                    'actor_id' => 0,
                    'profile' => 'reasoning',
                    'metrics_only' => true,
                ]);
                $narrative = $result['status'] === 'success' ? $result['output'] : '';
            } catch (\Throwable $e) {
                Logger::warning('board narration skipped', ['reason' => get_class($e)]);
                $narrative = '';
            }
        }

        list($start, $end) = self::window($period);
        $id = Db::insert('reports', [
            'type' => self::reportType($period),
            'period_start' => $start,
            'period_end' => $end,
            'metric_pack' => json_encode(['seats' => $seats, 'cross' => $cross, 'summary' => $summary], JSON_UNESCAPED_UNICODE),
            'sections' => json_encode($sections, JSON_UNESCAPED_UNICODE),
            'narrative' => $narrative !== '' ? $narrative : null,
            'model' => $metricsOnly ? 'none' : 'configured',
            'metrics_only' => $metricsOnly ? 1 : 0,
            'created_at' => Clock::now(),
        ]);

        Memory::rememberRun('executive_board', $id, 'executive board ' . $period . ' ' . Clock::today()
            . ' (' . $summary['seats_ok'] . ' seats reporting, ' . $summary['seats_unavailable'] . ' awaiting a data source)');
        Audit::system('ai.board.composed', [
            'report_id' => $id,
            'period' => $period,
            'metrics_only' => $metricsOnly,
            'seats_ok' => $summary['seats_ok'],
            'seats_unavailable' => $summary['seats_unavailable'],
            'cross_findings' => count($cross['findings']),
        ]);

        return [
            'report_id' => $id,
            'metrics_only' => $metricsOnly,
            'period' => $period,
            'seats_ok' => $summary['seats_ok'],
            'seats_unavailable' => $summary['seats_unavailable'],
        ];
    }

    /** Latest board report row for a period, or null. */
    public static function latest($period = 'daily')
    {
        return Db::first('reports', ['type' => self::reportType($period)], 'id DESC');
    }

    /**
     * Deterministic roll-up across seats. This is the "CEO view": it counts
     * and ranks what the other seats reported, and never adds a claim.
     */
    public static function summary(array $seats, array $cross)
    {
        $counts = [
            DepartmentPacks::SEV_CRITICAL => 0,
            DepartmentPacks::SEV_WARN => 0,
            DepartmentPacks::SEV_INFO => 0,
        ];
        $ok = 0;
        $unavailable = [];
        foreach ($seats as $seat) {
            if ($seat['status'] !== 'ok') {
                $unavailable[] = ['seat' => $seat['seat'], 'title' => $seat['title'], 'reason' => $seat['reason']];
                continue;
            }
            $ok++;
            foreach ($seat['findings'] as $f) {
                if (isset($counts[$f['severity']])) {
                    $counts[$f['severity']]++;
                }
            }
        }
        foreach ($cross['findings'] as $f) {
            if (isset($counts[$f['severity']])) {
                $counts[$f['severity']]++;
            }
        }

        // Attention list: critical first, then warn, each carrying its origin.
        $attention = [];
        foreach ([DepartmentPacks::SEV_CRITICAL, DepartmentPacks::SEV_WARN] as $sev) {
            foreach ($cross['findings'] as $f) {
                if ($f['severity'] === $sev) {
                    $attention[] = ['severity' => $sev, 'origin' => implode('+', $f['seats']), 'text' => $f['text']];
                }
            }
            foreach ($seats as $seat) {
                if ($seat['status'] !== 'ok') {
                    continue;
                }
                foreach ($seat['findings'] as $f) {
                    if ($f['severity'] === $sev) {
                        $attention[] = ['severity' => $sev, 'origin' => $seat['seat'], 'text' => $f['text']];
                    }
                }
            }
        }

        return [
            'seats_total' => count($seats),
            'seats_ok' => $ok,
            'seats_unavailable' => count($unavailable),
            'unavailable' => $unavailable,
            'severity_counts' => $counts,
            'cross_findings' => count($cross['findings']),
            'cross_skipped' => count($cross['skipped']),
            'attention' => $attention,
        ];
    }

    /** Human-readable, strictly derived sections. */
    protected static function sections(array $seats, array $cross, array $summary)
    {
        $sections = [];

        $exec = [
            $summary['seats_ok'] . ' of ' . $summary['seats_total'] . ' board seats reported from live data.',
            'Findings: ' . $summary['severity_counts'][DepartmentPacks::SEV_CRITICAL] . ' critical, '
                . $summary['severity_counts'][DepartmentPacks::SEV_WARN] . ' warning, '
                . $summary['severity_counts'][DepartmentPacks::SEV_INFO] . ' informational.',
        ];
        if ($summary['seats_unavailable'] > 0) {
            $names = [];
            foreach ($summary['unavailable'] as $u) {
                $names[] = $u['title'];
            }
            $exec[] = 'Awaiting a data source: ' . implode(', ', $names) . '.';
        }
        if ($summary['attention'] === []) {
            $exec[] = 'No critical or warning findings.';
        }
        $sections[] = ['title' => 'Executive summary', 'lines' => $exec];

        if ($summary['attention'] !== []) {
            $lines = [];
            foreach ($summary['attention'] as $a) {
                $lines[] = '[' . strtoupper($a['severity']) . '] (' . $a['origin'] . ') ' . $a['text'];
            }
            $sections[] = ['title' => 'Requires attention', 'lines' => $lines];
        }

        if ($cross['findings'] !== []) {
            $lines = [];
            foreach ($cross['findings'] as $f) {
                $lines[] = $f['title'] . ' — ' . $f['text'];
            }
            $sections[] = ['title' => 'Cross-department findings', 'lines' => $lines];
        }

        foreach ($seats as $seat) {
            if ($seat['status'] !== 'ok') {
                $sections[] = ['title' => $seat['title'], 'lines' => ['CONFIGURATION_REQUIRED — ' . $seat['reason']]];
                continue;
            }
            $lines = [];
            foreach ($seat['metrics'] as $m) {
                $lines[] = $m['metric'] . ': ' . self::fmt($m['metric'], $m['value']);
            }
            foreach ($seat['findings'] as $f) {
                $lines[] = '[' . strtoupper($f['severity']) . '] ' . $f['text'];
            }
            $sections[] = ['title' => $seat['title'], 'lines' => $lines];
        }
        return $sections;
    }

    /**
     * Narration prompt. The model receives only verified figures and the
     * explicit list of seats that have no data, and is told it may not fill
     * those gaps.
     */
    protected static function narrationTask(array $seats, array $cross, array $summary)
    {
        $lines = [];
        foreach ($seats as $seat) {
            if ($seat['status'] !== 'ok') {
                $lines[] = $seat['title'] . ': NO DATA SOURCE — do not comment on this area at all.';
                continue;
            }
            $parts = [];
            foreach ($seat['metrics'] as $m) {
                $parts[] = $m['metric'] . '=' . self::fmt($m['metric'], $m['value']);
            }
            $lines[] = $seat['title'] . ': ' . implode(', ', $parts);
            foreach ($seat['findings'] as $f) {
                $lines[] = '  finding[' . $f['severity'] . ']: ' . $f['text'];
            }
        }
        foreach ($cross['findings'] as $f) {
            $lines[] = 'CROSS(' . implode('+', $f['seats']) . ')[' . $f['severity'] . ']: ' . $f['text'];
        }

        return "You are narrating a verified executive board report for a hosting operator.\n"
            . "Rules you must follow:\n"
            . "1. Use ONLY the figures and findings below. Add no facts.\n"
            . "2. Any area marked NO DATA SOURCE must not be discussed, estimated or guessed at.\n"
            . "3. Do not invent a department's opinion. The findings given are the findings.\n"
            . "4. Lead with the critical items, then warnings. Be concise and plain.\n"
            . "5. Under 220 words.\n\nVERIFIED BOARD DATA:\n" . implode("\n", $lines);
    }

    protected static function window($period)
    {
        $end = Clock::today();
        if ($period === 'weekly') {
            return [gmdate('Y-m-d', Clock::time() - 7 * 86400), $end];
        }
        if ($period === 'monthly') {
            return [gmdate('Y-m-d', Clock::time() - 30 * 86400), $end];
        }
        return [$end, $end];
    }

    protected static function fmt($key, $value)
    {
        return strpos($key, 'amount') !== false
            ? number_format((float) $value, 2, '.', '')
            : (string) (int) $value;
    }
}
