<?php
/**
 * Phase 4 — evaluation and observability.
 *
 * The thing being tested here is mostly honesty. It is trivial to write a
 * dashboard that shows 100% green; the work is making sure it shows green
 * only when something was actually measured, and red when a guarantee
 * breaks. So the suite checks:
 *
 *   - a metric with zero samples reports NO value, not a flattering one;
 *   - rates are computed over the right denominators;
 *   - the human-override signal reflects real decisions;
 *   - an unverifiable write shows up as unverified;
 *   - safety probes PASS against a healthy platform;
 *   - safety probes FAIL when the guarantee is genuinely broken
 *     (asserted by actually breaking one);
 *   - probes never write a business record;
 *   - the page never renders a number it does not have.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Http/AdminPortal.php';

use Ch247Ai\Approval\ApprovalEngine;
use Ch247Ai\Approval\ApprovalExecutor;
use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Settings;
use Ch247Ai\Core\Whmcs;
use Ch247Ai\Eval\Evaluator;
use Ch247Ai\Eval\QualityMetrics;
use Ch247Ai\Eval\SafetyProbes;
use Ch247Ai\Tools\Bootstrap;
use Ch247Ai\Tools\ToolExecutor;

ch247ai_boot();
ch247ai_freeze();
Bootstrap::register();
ch247ai_as_super_admin();

$today = gmdate('Y-m-d', Clock::time());
$weekAgo = gmdate('Y-m-d', Clock::time() - 6 * 86400);

function ch247ai_find_metric(array $metrics, $metric, $agent = '*')
{
    foreach ($metrics as $row) {
        if ($row['metric'] === $metric && $row['agent'] === $agent) {
            return $row;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
T::section('An empty window reports no data, not a perfect score');

$metrics = QualityMetrics::collect($weekAgo, $today);
$successRate = ch247ai_find_metric($metrics, 'success_rate');
T::ok('success_rate is present even with no runs', $successRate !== null);
T::eq('sample size is zero', 0, $successRate['sample_size']);
T::ok('value is NULL, not 100', $successRate['value'] === null);

$coverage = ch247ai_find_metric($metrics, 'citation_coverage');
T::ok('citation coverage reports null on no samples', $coverage['value'] === null);

$override = ch247ai_find_metric($metrics, 'human_override_rate');
T::ok('override rate reports null with no decisions', $override['value'] === null);

// ---------------------------------------------------------------------------
T::section('Run metrics count what actually happened');

$mkRun = function ($agent, $status, $citations) use ($today) {
    return Db::insert('runs', [
        'task_id' => null, 'agent' => $agent, 'status' => $status, 'source' => 'cron',
        'actor_type' => 'system', 'actor_id' => 0, 'input' => 'q', 'output' => 'a',
        'citations' => $citations, 'tokens_in' => 10, 'tokens_out' => 20,
        'started_at' => $today . ' 08:00:00', 'finished_at' => $today . ' 08:00:05',
    ]);
};
$mkRun('revenue_analyst', 'success', '["SELECT 1"]');
$mkRun('revenue_analyst', 'success', '["SELECT 2"]');
$mkRun('revenue_analyst', 'failed', '');
$mkRun('collections_agent', 'success', '[]');   // answered with NO evidence

$metrics = QualityMetrics::collect($weekAgo, $today);
$runs = ch247ai_find_metric($metrics, 'runs');
T::eq('four runs counted', 4.0, $runs['value']);
$success = ch247ai_find_metric($metrics, 'success_rate');
T::eq('three of four succeeded', 75.0, $success['value']);

$agentRate = ch247ai_find_metric($metrics, 'success_rate', 'revenue_analyst');
T::eq('per-agent success rate', round(2 / 3 * 100, 2), $agentRate['value']);

$coverage = ch247ai_find_metric($metrics, 'citation_coverage');
T::ok('coverage is measured over successes only', $coverage['sample_size'] === 3);
T::eq('two of three successful answers cited evidence', round(2 / 3 * 100, 2), $coverage['value']);

$uncited = ch247ai_find_metric($metrics, 'uncited_answers');
T::eq('the uncited answer is counted', 1.0, $uncited['value']);

// ---------------------------------------------------------------------------
T::section('Human override analysis reflects real decisions');

Settings::put('writes_enabled', '1');
ToolExecutor::allowAgent('resolution_pro');

$a1 = ApprovalEngine::create('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'one'], 'WRITE_LOW', 'r');
ApprovalEngine::decide($a1, 'rejected', 'not needed');
$a2 = ApprovalEngine::create('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'two'], 'WRITE_LOW', 'r');
ApprovalEngine::decide($a2, 'rejected', 'no');
$a3 = ApprovalEngine::create('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'three'], 'WRITE_LOW', 'r');
ApprovalEngine::decide($a3, 'approved', 'yes');
$a4 = ApprovalEngine::create('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'four'], 'WRITE_LOW', 'r');
// left pending on purpose: undecided work must not count as agreement

$metrics = QualityMetrics::collect($weekAgo, $today);
$override = ch247ai_find_metric($metrics, 'human_override_rate');
T::eq('three decisions were made', 3, $override['sample_size']);
T::eq('two of three were rejections', round(2 / 3 * 100, 2), $override['value']);
$approval = ch247ai_find_metric($metrics, 'approval_rate');
T::eq('one of three was an approval', round(1 / 3 * 100, 2), $approval['value']);
$proposals = ch247ai_find_metric($metrics, 'proposals');
T::eq('all four proposals counted', 4.0, $proposals['value']);
T::ok('a pending proposal does not count as agreement', $override['sample_size'] === 3);

$perAgent = ch247ai_find_metric($metrics, 'human_override_rate', 'resolution_pro');
T::ok('override rate is also tracked per agent', $perAgent !== null && $perAgent['value'] !== null);

// ---------------------------------------------------------------------------
T::section('Write verification shows up in the metrics');

// A WHMCS fake that claims success and writes nothing -> unverifiable.
Whmcs::setApiFake(function ($command, array $args) {
    return ['result' => 'success'];
});
$bad = ApprovalEngine::create('resolution_pro', 'write_ticket_note', ['ticket_id' => 401, 'note' => 'ghost'], 'WRITE_LOW', 'r');
ApprovalEngine::decide($bad, 'approved', 'ok');
$outcome = ApprovalExecutor::run($bad);
T::ok('the execution itself was reported as failed', $outcome['ok'] === false);

$metrics = QualityMetrics::collect($weekAgo, $today);
$unverified = ch247ai_find_metric($metrics, 'unverified_writes');
T::ok('an unverifiable write is counted', $unverified['value'] >= 1.0);
$verifiedRate = ch247ai_find_metric($metrics, 'write_verified_rate');
T::ok('verified rate is below 100', $verifiedRate['value'] !== null && $verifiedRate['value'] < 100);
Whmcs::setApiFake(null);

// ---------------------------------------------------------------------------
T::section('Safety probes pass on a healthy platform');

$repliesBefore = (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies')[0]['c'];
$notesBefore = (int) Db::query('SELECT COUNT(*) AS c FROM tblticketnotes')[0]['c'];
$invoicesBefore = (int) Db::query('SELECT COUNT(*) AS c FROM tblinvoices')[0]['c'];

$probes = SafetyProbes::runAll();
$byName = [];
foreach ($probes as $probe) {
    $byName[$probe['probe']] = $probe;
}
foreach (['audit_chain_intact', 'unknown_tool_refused', 'approval_gate_blocks_unapproved_write',
          'client_scope_blocks_writes', 'client_scope_isolates_reads', 'redaction_strips_secrets',
          'audit_carries_no_secrets'] as $name) {
    T::ok("probe {$name} ran", isset($byName[$name]));
    T::ok("probe {$name} did not fail", $byName[$name]['status'] !== SafetyProbes::FAIL);
    T::ok("probe {$name} explains itself", trim((string) $byName[$name]['detail']) !== '');
}
T::eq('gate probe passed', SafetyProbes::PASS, $byName['approval_gate_blocks_unapproved_write']['status']);
T::eq('client write probe passed', SafetyProbes::PASS, $byName['client_scope_blocks_writes']['status']);
T::eq('isolation probe passed', SafetyProbes::PASS, $byName['client_scope_isolates_reads']['status']);
T::ok('isolation probe names the two customers it used',
    strpos($byName['client_scope_isolates_reads']['detail'], 'asked for') !== false);

T::section('Probes mutate nothing');
T::eq('no ticket replies created', $repliesBefore, (int) Db::query('SELECT COUNT(*) AS c FROM tblticketreplies')[0]['c']);
T::eq('no ticket notes created', $notesBefore, (int) Db::query('SELECT COUNT(*) AS c FROM tblticketnotes')[0]['c']);
T::eq('no invoices created', $invoicesBefore, (int) Db::query('SELECT COUNT(*) AS c FROM tblinvoices')[0]['c']);

T::section('Probes restore the session they borrowed');
T::eq('still the super admin afterwards', 1, Identity::adminId());
T::ok('no client session left behind', Identity::clientId() === null);

// ---------------------------------------------------------------------------
T::section('A probe FAILS when the guarantee really breaks');

// Break the audit chain by editing a stored row, exactly as tampering would.
$firstAudit = Db::first('audit_log', [], 'id ASC');
if ($firstAudit !== null) {
    Db::update('audit_log', ['id' => (int) $firstAudit['id']], ['action' => 'tampered.by.test']);
    $broken = SafetyProbes::auditChainIntact();
    T::eq('tampering is detected', SafetyProbes::FAIL, $broken['status']);
    T::ok('and the broken row is identified', strpos($broken['detail'], 'broken at row') !== false);
    // Restore so later assertions run against a clean chain.
    Db::update('audit_log', ['id' => (int) $firstAudit['id']], ['action' => (string) $firstAudit['action']]);
    T::eq('chain is intact again after restoring', SafetyProbes::PASS, SafetyProbes::auditChainIntact()['status']);
}

T::section('And when the approval gate is switched off');
// writes_enabled=0 makes the gate refuse earlier; the probe must still pass
// because refusing is the correct behaviour.
Settings::put('writes_enabled', '0');
T::eq('gate probe still passes when writes are disabled', SafetyProbes::PASS,
    SafetyProbes::approvalGateBlocksUnapprovedWrite()['status']);
Settings::put('writes_enabled', '1');

// ---------------------------------------------------------------------------
T::section('Evaluator persists into the existing evaluations table');

$before = Db::count('evaluations');
$result = Evaluator::run(7);
T::ok('evaluation ran', $result['enabled'] === true);
T::ok('metrics were stored', Db::count('evaluations') > $before);
T::eq('window ends today', $today, $result['window_end']);
T::ok('probes were recorded', $result['probes_passed'] + $result['probes_skipped'] > 0);
T::eq('no probe failed on a healthy platform', 0, $result['probes_failed']);

$probeRows = Db::all('evaluations', ['agent' => Evaluator::PROBE_AGENT]);
T::ok('probe results share the metric store', $probeRows !== []);

$stored = Evaluator::latest();
T::eq('latest window matches', $today, $stored['window_end']);
T::ok('stored rows are readable', $stored['rows'] !== []);

T::section('Alerts fire on the conditions that matter');
$alertText = json_encode($result['alerts']);
T::ok('the uncited answer raised an alert', strpos($alertText, 'no supporting evidence') !== false);
T::ok('the unverifiable write raised an alert', strpos($alertText, 'could not be confirmed') !== false);
foreach ($result['alerts'] as $alert) {
    T::ok('alert has a severity', in_array($alert['severity'], ['critical', 'warn'], true));
    T::ok('alert has text', trim((string) $alert['text']) !== '');
}

T::section('Disabling evaluations is honoured');
Settings::put('evaluations_enabled', '0');
$off = Evaluator::run(7);
T::ok('disabled run reports disabled', $off['enabled'] === false);
T::eq('and stores nothing', 0, $off['metrics']);
Settings::put('evaluations_enabled', '1');

// ---------------------------------------------------------------------------
T::section('The page shows what it knows and admits what it does not');

ch247ai_as_super_admin();
$_GET['action'] = 'evaluations';
$html = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);

T::ok('page rendered', strlen($html) > 500);
T::ok('heading present', strpos($html, 'Evaluation') !== false);
T::ok('safety probes section present', strpos($html, 'Safety probes') !== false);
T::ok('probe names are shown', strpos($html, 'client_scope_isolates_reads') !== false);
T::ok('quality metrics shown', strpos($html, 'human_override_rate') !== false);
T::ok('the override signal is explained', strpos($html, 'reviewer rejected') !== false);
T::ok('no stack traces', stripos($html, 'Stack trace') === false);
T::ok('no PHP warnings', stripos($html, 'Warning:') === false);
T::ok('no secret values', strpos($html, 'sk-') === false);

T::section('A metric with no samples renders as "no data", never a number');
// Fresh window with nothing in it.
Db::exec('DELETE FROM ' . Db::t('evaluations'));
Evaluator::run(1);
Db::exec('DELETE FROM ' . Db::t('runs'));
Db::exec('DELETE FROM ' . Db::t('evaluations'));
$emptyMetrics = QualityMetrics::collect($today, $today);
foreach ($emptyMetrics as $metric) {
    if ($metric['sample_size'] === 0) {
        T::ok("metric {$metric['metric']} ({$metric['agent']}) reports null on zero samples", $metric['value'] === null);
    }
}
Evaluator::run(1);
$_GET['action'] = 'evaluations';
$emptyHtml = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);
T::ok('empty windows say so explicitly', strpos($emptyHtml, 'no data in this window') !== false);
T::ok('and do not claim a perfect success rate', strpos($emptyHtml, '>100<') === false);

T::section('Permission gate');
Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
\Ch247Ai\Core\Rbac::setForcedRole(2, 2);
$_GET['action'] = 'evaluations';
$denied = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);
T::ok('an unprivileged admin is refused', stripos($denied, 'permission group') !== false);
T::ok('and sees no probe detail', strpos($denied, 'client_scope_isolates_reads') === false);
ch247ai_as_super_admin();

T::finish();
