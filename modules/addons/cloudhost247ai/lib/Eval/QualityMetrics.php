<?php
/**
 * Deterministic quality metrics over what the platform actually did.
 *
 * Every metric is one SQL aggregate over real rows. No model is involved, so
 * these work on an installation with no provider configured, and two runs
 * over the same window always produce the same numbers.
 *
 * The rule that shapes this whole file: **a rate over zero samples is not a
 * rate.** An agent that ran zero times has not got a 100% success rate; it
 * has no success rate. Every metric therefore carries its sample size, and a
 * metric with `sample_size = 0` reports value `null`. The UI renders that as
 * "no data in this window" rather than a reassuring number, because a
 * dashboard that shows 100% green for a system nobody used is worse than one
 * that admits it knows nothing.
 *
 * Metrics worth naming:
 *
 *   uncited_answers       Successful runs that produced an answer with no
 *                         citation at all. This is the anti-fabrication
 *                         alarm: the runtime is supposed to refuse to answer
 *                         without evidence, so this should be 0 and any
 *                         other value is a bug worth chasing.
 *
 *   human_override_rate   Share of decisions a human REJECTED. This measures
 *                         the agents against human judgement: a rising
 *                         rejection rate means the proposals are getting
 *                         worse, and it is the one quality signal here that
 *                         no amount of self-evaluation could fake.
 *
 *   unverified_writes     Executions where the tool reported success but the
 *                         post-write re-read could not confirm it. Should be
 *                         0; anything else means something is lying.
 */

namespace Ch247Ai\Eval;

use Ch247Ai\Core\Db;

class QualityMetrics
{
    /**
     * @param string $start 'Y-m-d'
     * @param string $end   'Y-m-d'
     * @return array<int,array{metric:string,agent:string,value:?float,sample_size:int,unit:string,note:string}>
     */
    public static function collect($start, $end)
    {
        $out = [];
        foreach (self::runMetrics($start, $end) as $row) {
            $out[] = $row;
        }
        foreach (self::toolMetrics($start, $end) as $row) {
            $out[] = $row;
        }
        foreach (self::decisionMetrics($start, $end) as $row) {
            $out[] = $row;
        }
        foreach (self::costMetrics($start, $end) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    protected static function metric($metric, $agent, $value, $sampleSize, $unit, $note)
    {
        return [
            'metric' => $metric,
            'agent' => $agent,
            // No samples -> no value. Never a flattering default.
            'value' => $sampleSize > 0 ? $value : null,
            'sample_size' => (int) $sampleSize,
            'unit' => $unit,
            'note' => $note,
        ];
    }

    protected static function pct($numerator, $denominator)
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    // -----------------------------------------------------------------

    /** Run outcomes and citation coverage, per agent and platform-wide. */
    public static function runMetrics($start, $end)
    {
        $out = [];
        if (!Db::tableExists('runs')) {
            return $out;
        }
        $rows = Db::query(
            'SELECT agent, status, citations FROM ' . Db::t('runs')
            . ' WHERE started_at >= ? AND started_at < ?',
            [$start . ' 00:00:00', $end . ' 23:59:59']
        );

        $byAgent = [];
        foreach ($rows as $row) {
            $agent = (string) $row['agent'];
            if (!isset($byAgent[$agent])) {
                $byAgent[$agent] = ['total' => 0, 'success' => 0, 'failed' => 0, 'stale' => 0, 'cited' => 0, 'uncited' => 0];
            }
            $byAgent[$agent]['total']++;
            $status = (string) $row['status'];
            if ($status === 'success') {
                $byAgent[$agent]['success']++;
                if (self::hasCitations($row['citations'])) {
                    $byAgent[$agent]['cited']++;
                } else {
                    $byAgent[$agent]['uncited']++;
                }
            } elseif ($status === 'failed') {
                $byAgent[$agent]['failed']++;
            } elseif ($status === 'stale') {
                $byAgent[$agent]['stale']++;
            }
        }

        $totals = ['total' => 0, 'success' => 0, 'failed' => 0, 'stale' => 0, 'cited' => 0, 'uncited' => 0];
        foreach ($byAgent as $agent => $c) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $c[$k];
            }
            $out[] = self::metric('runs', $agent, (float) $c['total'], $c['total'], 'count', 'Runs started in the window.');
            $out[] = self::metric('success_rate', $agent, self::pct($c['success'], $c['total']), $c['total'], '%', 'Runs that completed successfully.');
            $out[] = self::metric('failure_rate', $agent, self::pct($c['failed'], $c['total']), $c['total'], '%', 'Runs that ended in an error.');
            $out[] = self::metric('citation_coverage', $agent, self::pct($c['cited'], $c['success']), $c['success'], '%', 'Successful answers carrying at least one citation.');
            $out[] = self::metric('uncited_answers', $agent, (float) $c['uncited'], $c['success'], 'count', 'Successful answers with NO evidence attached. Should be zero.');
        }

        $out[] = self::metric('runs', '*', (float) $totals['total'], $totals['total'], 'count', 'All runs in the window.');
        $out[] = self::metric('success_rate', '*', self::pct($totals['success'], $totals['total']), $totals['total'], '%', 'Platform-wide run success.');
        $out[] = self::metric('citation_coverage', '*', self::pct($totals['cited'], $totals['success']), $totals['success'], '%', 'Platform-wide evidence coverage.');
        $out[] = self::metric('uncited_answers', '*', (float) $totals['uncited'], $totals['success'], 'count', 'Answers produced with no evidence. Should be zero.');
        return $out;
    }

    protected static function hasCitations($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '[]' || $raw === 'null') {
            return false;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [];
    }

    // -----------------------------------------------------------------

    /** Tool reliability. */
    public static function toolMetrics($start, $end)
    {
        $out = [];
        if (!Db::tableExists('tool_calls')) {
            return $out;
        }
        $rows = Db::query(
            'SELECT tool, status, duration_ms FROM ' . Db::t('tool_calls')
            . ' WHERE created_at >= ? AND created_at < ?',
            [$start . ' 00:00:00', $end . ' 23:59:59']
        );
        $total = 0;
        $refused = 0;
        $durationSum = 0;
        $byTool = [];
        foreach ($rows as $row) {
            $total++;
            $tool = (string) $row['tool'];
            if (!isset($byTool[$tool])) {
                $byTool[$tool] = ['n' => 0, 'refused' => 0];
            }
            $byTool[$tool]['n']++;
            $durationSum += (int) $row['duration_ms'];
            if ((string) $row['status'] !== 'executed') {
                $refused++;
                $byTool[$tool]['refused']++;
            }
        }
        $out[] = self::metric('tool_calls', '*', (float) $total, $total, 'count', 'Tool calls made in the window.');
        $out[] = self::metric('tool_refusal_rate', '*', self::pct($refused, $total), $total, '%', 'Tool calls that were refused or errored.');
        $out[] = self::metric('tool_avg_duration_ms', '*', $total > 0 ? round($durationSum / $total, 1) : null, $total, 'ms', 'Mean tool call duration.');
        return $out;
    }

    // -----------------------------------------------------------------

    /**
     * Human override analysis and write verification (§31).
     *
     * This is the honest measure of agent quality: not what the AI thinks of
     * itself, but how often a human disagreed with it, and how often an
     * action it reported could actually be confirmed.
     */
    public static function decisionMetrics($start, $end)
    {
        $out = [];
        if (!Db::tableExists('approvals')) {
            return $out;
        }
        $rows = Db::query(
            'SELECT agent, status, verified, execution_status FROM ' . Db::t('approvals')
            . ' WHERE created_at >= ? AND created_at < ?',
            [$start . ' 00:00:00', $end . ' 23:59:59']
        );

        $decided = 0;
        $rejected = 0;
        $approved = 0;
        $expired = 0;
        $proposals = 0;
        $executed = 0;
        $verified = 0;
        $unverified = 0;
        $byAgent = [];

        foreach ($rows as $row) {
            $proposals++;
            $agent = (string) $row['agent'];
            if (!isset($byAgent[$agent])) {
                $byAgent[$agent] = ['proposals' => 0, 'decided' => 0, 'rejected' => 0];
            }
            $byAgent[$agent]['proposals']++;

            $status = (string) $row['status'];
            if ($status === 'rejected') {
                $decided++;
                $rejected++;
                $byAgent[$agent]['decided']++;
                $byAgent[$agent]['rejected']++;
            } elseif (in_array($status, ['approved', 'executing', 'executed', 'failed'], true)) {
                $decided++;
                $approved++;
                $byAgent[$agent]['decided']++;
            } elseif ($status === 'expired') {
                $expired++;
            }

            $execStatus = isset($row['execution_status']) ? (string) $row['execution_status'] : 'none';
            if ($execStatus === 'succeeded' || $execStatus === 'failed') {
                $executed++;
                if (!empty($row['verified'])) {
                    $verified++;
                } else {
                    $unverified++;
                }
            }
        }

        foreach ($byAgent as $agent => $c) {
            $out[] = self::metric('proposals', $agent, (float) $c['proposals'], $c['proposals'], 'count', 'Actions this agent proposed.');
            $out[] = self::metric('human_override_rate', $agent, self::pct($c['rejected'], $c['decided']), $c['decided'], '%', 'Share of decided proposals a human rejected.');
        }

        $out[] = self::metric('proposals', '*', (float) $proposals, $proposals, 'count', 'Actions proposed in the window.');
        $out[] = self::metric('human_override_rate', '*', self::pct($rejected, $decided), $decided, '%', 'Share of decided proposals a human rejected.');
        $out[] = self::metric('approval_rate', '*', self::pct($approved, $decided), $decided, '%', 'Share of decided proposals a human approved.');
        $out[] = self::metric('decision_expiry_rate', '*', self::pct($expired, $proposals), $proposals, '%', 'Proposals that expired before anyone decided.');
        $out[] = self::metric('write_verified_rate', '*', self::pct($verified, $executed), $executed, '%', 'Executed actions confirmed by a post-write re-read.');
        $out[] = self::metric('unverified_writes', '*', (float) $unverified, $executed, 'count', 'Executions that could NOT be confirmed. Should be zero.');
        return $out;
    }

    // -----------------------------------------------------------------

    /** Spend, straight from the usage ledger. */
    public static function costMetrics($start, $end)
    {
        $out = [];
        if (!Db::tableExists('usage_daily')) {
            return $out;
        }
        $rows = Db::query(
            'SELECT agent, runs, tokens_in, tokens_out, cost_micros FROM ' . Db::t('usage_daily')
            . ' WHERE day >= ? AND day <= ?',
            [$start, $end]
        );
        $tokensIn = 0;
        $tokensOut = 0;
        $cost = 0;
        $days = 0;
        foreach ($rows as $row) {
            $days++;
            $tokensIn += (int) $row['tokens_in'];
            $tokensOut += (int) $row['tokens_out'];
            $cost += (int) $row['cost_micros'];
        }
        $out[] = self::metric('tokens_in', '*', (float) $tokensIn, $days, 'tokens', 'Prompt tokens billed in the window.');
        $out[] = self::metric('tokens_out', '*', (float) $tokensOut, $days, 'tokens', 'Completion tokens billed in the window.');
        $out[] = self::metric('cost_micros', '*', (float) $cost, $days, 'micros', 'Model spend in millionths of a currency unit.');
        return $out;
    }
}
