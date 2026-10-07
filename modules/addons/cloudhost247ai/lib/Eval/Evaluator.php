<?php
/**
 * Evaluation runner (brief §30, §31).
 *
 * Collects deterministic quality metrics and live safety probes, persists
 * them into the EXISTING `evaluations` table (§34 — no new schema), and
 * returns a summary for the admin page and the cron log.
 *
 * Storage note: `evaluations` is a generic metric store
 * (agent, metric, value, window_start, window_end, sample_size). Probes are
 * stored as rows with agent `probe` and the probe name as the metric, so
 * both kinds of result live in one queryable place and neither needs a new
 * table.
 *
 * What this intentionally does NOT do: ask a model to grade the platform.
 * A model scoring its own output is the least trustworthy signal available
 * and it would stop working the moment no provider is configured. Every
 * number here comes from a SQL aggregate over what actually happened, and
 * the headline quality signal — how often a human rejected a proposal — is
 * one the AI cannot influence by being more confident.
 */

namespace Ch247Ai\Eval;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Settings;

class Evaluator
{
    /** Marker agent used for probe rows inside the shared metric store. */
    const PROBE_AGENT = 'probe';

    /**
     * Run everything for a window ending today.
     *
     * @param int $days window length in days (inclusive of today)
     * @return array summary
     */
    public static function run($days = 7)
    {
        $days = max(1, min(90, (int) $days));
        $end = gmdate('Y-m-d', Clock::time());
        $start = gmdate('Y-m-d', Clock::time() - ($days - 1) * 86400);

        if (!Settings::bool('evaluations_enabled', true)) {
            return [
                'enabled' => false,
                'window_start' => $start,
                'window_end' => $end,
                'metrics' => 0,
                'probes' => [],
                'probes_passed' => 0,
                'probes_failed' => 0,
                'probes_skipped' => 0,
                'alerts' => [],
            ];
        }

        $metrics = QualityMetrics::collect($start, $end);
        $probes = SafetyProbes::runAll();

        $stored = 0;
        foreach ($metrics as $metric) {
            if (self::store($metric['agent'], $metric['metric'], $metric['value'], $start, $end, $metric['sample_size'])) {
                $stored++;
            }
        }
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($probes as $probe) {
            $value = $probe['status'] === SafetyProbes::PASS ? 1 : ($probe['status'] === SafetyProbes::FAIL ? 0 : null);
            self::store(self::PROBE_AGENT, $probe['probe'], $value, $start, $end, $probe['status'] === SafetyProbes::SKIPPED ? 0 : 1);
            if ($probe['status'] === SafetyProbes::PASS) {
                $passed++;
            } elseif ($probe['status'] === SafetyProbes::FAIL) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        $alerts = self::alerts($metrics, $probes);

        Audit::system('ai.evaluation.completed', [
            'window_start' => $start,
            'window_end' => $end,
            'metrics' => $stored,
            'probes_passed' => $passed,
            'probes_failed' => $failed,
            'alerts' => count($alerts),
        ]);

        return [
            'enabled' => true,
            'window_start' => $start,
            'window_end' => $end,
            'metrics' => $stored,
            'probes' => $probes,
            'probes_passed' => $passed,
            'probes_failed' => $failed,
            'probes_skipped' => $skipped,
            'alerts' => $alerts,
        ];
    }

    /**
     * Things an operator should look at. Deliberately short: an alert list
     * that cries wolf gets ignored, and these are the conditions that mean
     * a safety property is not holding.
     */
    protected static function alerts(array $metrics, array $probes)
    {
        $alerts = [];
        foreach ($probes as $probe) {
            if ($probe['status'] === SafetyProbes::FAIL) {
                $alerts[] = [
                    'severity' => 'critical',
                    'text' => 'Safety probe ' . $probe['probe'] . ' FAILED: ' . $probe['detail'],
                ];
            }
        }
        foreach ($metrics as $metric) {
            if ($metric['value'] === null) {
                continue; // no samples -> nothing to judge
            }
            if ($metric['metric'] === 'uncited_answers' && $metric['agent'] === '*' && $metric['value'] > 0) {
                $alerts[] = [
                    'severity' => 'critical',
                    'text' => (int) $metric['value'] . ' answer(s) were produced with no supporting evidence. The runtime is supposed to refuse these.',
                ];
            }
            if ($metric['metric'] === 'unverified_writes' && $metric['value'] > 0) {
                $alerts[] = [
                    'severity' => 'critical',
                    'text' => (int) $metric['value'] . ' executed action(s) could not be confirmed by a post-write re-read.',
                ];
            }
            if ($metric['metric'] === 'human_override_rate' && $metric['agent'] === '*' && $metric['sample_size'] >= 5 && $metric['value'] >= 50) {
                $alerts[] = [
                    'severity' => 'warn',
                    'text' => 'Humans rejected ' . $metric['value'] . '% of decided proposals (' . $metric['sample_size'] . ' decisions). The agents are proposing work that reviewers do not want.',
                ];
            }
            if ($metric['metric'] === 'decision_expiry_rate' && $metric['sample_size'] >= 5 && $metric['value'] >= 50) {
                $alerts[] = [
                    'severity' => 'warn',
                    'text' => $metric['value'] . '% of proposals expired undecided. The decision inbox is not being worked.',
                ];
            }
        }
        return $alerts;
    }

    protected static function store($agent, $metric, $value, $start, $end, $sampleSize)
    {
        try {
            Db::insert('evaluations', [
                'agent' => substr((string) $agent, 0, 60),
                'metric' => substr((string) $metric, 0, 60),
                // NULL is meaningful here: "measured, no samples".
                'value' => $value === null ? '' : (string) $value,
                'window_start' => $start,
                'window_end' => $end,
                'sample_size' => (int) $sampleSize,
                'created_at' => Clock::now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Most recent stored window, for the admin page. */
    public static function latest($limit = 200)
    {
        try {
            $row = Db::first('evaluations', [], 'id DESC');
            if ($row === null) {
                return ['window_start' => null, 'window_end' => null, 'rows' => []];
            }
            $rows = Db::query(
                'SELECT agent, metric, value, sample_size FROM ' . Db::t('evaluations')
                . ' WHERE window_start = ? AND window_end = ? ORDER BY agent ASC, metric ASC LIMIT ' . max(1, (int) $limit),
                [$row['window_start'], $row['window_end']]
            );
            return [
                'window_start' => (string) $row['window_start'],
                'window_end' => (string) $row['window_end'],
                'rows' => $rows,
            ];
        } catch (\Throwable $e) {
            return ['window_start' => null, 'window_end' => null, 'rows' => []];
        }
    }
}
