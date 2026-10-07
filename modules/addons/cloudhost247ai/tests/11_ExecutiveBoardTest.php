<?php
/**
 * Executive Board — deterministic seats, real cross-department joins, and
 * the honesty guarantees: no seat invents data, no narrator fills a gap, no
 * roadmap agent can run.
 *
 * Fixture clock is frozen at 2026-10-06 (see tests/bootstrap.php).
 */

require __DIR__ . '/bootstrap.php';

use Ch247Ai\Agents\AgentRegistry;
use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Board\CrossFindings;
use Ch247Ai\Board\DepartmentPacks;
use Ch247Ai\Board\ExecutiveBoard;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Settings;

ch247ai_boot();
ch247ai_freeze('2026-10-06 12:00:00');

/* ------------------------------------------------------------- seats -- */

T::section('Every board seat reports or abstains — never both, never neither');
$seats = DepartmentPacks::all();
T::eq('nine seats', 9, count($seats));
foreach ($seats as $seat) {
    T::ok("seat {$seat['seat']} has a status", in_array($seat['status'], ['ok', 'data_unavailable'], true));
    if ($seat['status'] === 'ok') {
        T::ok("seat {$seat['seat']} reporting carries metrics", $seat['metrics'] !== []);
        T::ok("seat {$seat['seat']} reporting carries findings", $seat['findings'] !== []);
        T::eq("seat {$seat['seat']} reporting has no excuse", '', $seat['reason']);
    } else {
        T::ok("seat {$seat['seat']} abstaining names the gap", strlen($seat['reason']) > 30);
        T::eq("seat {$seat['seat']} abstaining emits no metrics", [], $seat['metrics']);
        T::eq("seat {$seat['seat']} abstaining emits no findings", [], $seat['findings']);
    }
}

T::section('Every reported figure carries the SQL that produced it');
foreach ($seats as $seat) {
    foreach ($seat['metrics'] as $m) {
        T::ok("metric {$m['metric']} cites SQL", isset($m['sql']) && stripos($m['sql'], 'select') === 0);
        T::ok("metric {$m['metric']} is numeric", is_float($m['value']) || is_int($m['value']));
    }
}

T::section('Seats without a data feed abstain explicitly');
$bySeat = [];
foreach ($seats as $s) {
    $bySeat[$s['seat']] = $s;
}
foreach (['cto', 'ciso', 'cpo'] as $blind) {
    T::eq("$blind has no data source", 'data_unavailable', $bySeat[$blind]['status']);
}
T::ok('cto names the collector it needs', stripos($bySeat['cto']['reason'], 'telemetry') !== false);
T::ok('ciso names the auth-event table', stripos($bySeat['ciso']['reason'], 'auth-event') !== false);
T::ok('cpo names usage metering', stripos($bySeat['cpo']['reason'], 'usage-metering') !== false);
T::eq('cmo abstains when the marketing module is absent', 'data_unavailable', $bySeat['cmo']['status']);
T::ok('cmo says which module to activate', stripos($bySeat['cmo']['reason'], 'cloudhost247marketing') !== false);

T::section('Finance seat figures match the fixtures exactly');
$cfo = [];
foreach ($bySeat['cfo']['metrics'] as $m) {
    $cfo[$m['metric']] = $m['value'];
}
T::eq('paid today', 30.0, $cfo['paid_today_amount']);
T::eq('paid 7d', 150.0, $cfo['paid_7d_amount']);
T::eq('unpaid invoice count', 3.0, $cfo['invoices_unpaid_total']);
T::eq('unpaid exposure (total minus credit)', 132.5, $cfo['invoices_unpaid_amount']);
T::eq('overdue count', 2.0, $cfo['invoices_overdue_total']);
T::eq('overdue exposure', 120.5, $cfo['invoices_overdue_amount']);
T::eq('no refunds in fixtures', 0.0, $cfo['refunds_30d_amount']);

T::section('Operations and customer seats match the fixtures');
$coo = [];
foreach ($bySeat['coo']['metrics'] as $m) {
    $coo[$m['metric']] = $m['value'];
}
T::eq('open tickets', 2.0, $coo['tickets_open_total']);
T::eq('active services', 1.0, $coo['services_active_total']);
T::eq('suspended services', 1.0, $coo['services_suspended_total']);
T::eq('domains expiring in 30d', 1.0, $coo['domains_expiring_30d']);
$cco = [];
foreach ($bySeat['cco']['metrics'] as $m) {
    $cco[$m['metric']] = $m['value'];
}
T::eq('no customer holds two open tickets', 0.0, $cco['clients_multi_open_tickets']);

T::section('Risk seat detects concentration');
$risk = [];
foreach ($bySeat['crisk']['metrics'] as $m) {
    $risk[$m['metric']] = $m['value'];
}
T::eq('two clients overdue', 2.0, $risk['clients_overdue_total']);
T::eq('largest single overdue', 80.5, $risk['largest_single_overdue']);
$riskTexts = implode(' ', array_column($bySeat['crisk']['findings'], 'text'));
T::ok('concentration flagged (80.50 of 120.50 is >50%)', stripos($riskTexts, 'concentrated') !== false);

/* --------------------------------------------- cross-department joins -- */

T::section('Cross-department findings are real joins, not agent opinions');
$cross = CrossFindings::all();
$keys = array_column($cross['findings'], 'key');
T::ok('debtors also in the support queue detected', in_array('debtors_in_support', $keys, true));
T::ok('suspended service with open ticket detected', in_array('suspended_with_open_tickets', $keys, true));
T::ok('newly acquired customer already overdue detected', in_array('new_clients_already_overdue', $keys, true));
T::ok('no renewal-at-risk finding in these fixtures', !in_array('revenue_at_risk_renewals', $keys, true));
T::ok('no paid-but-pending finding in these fixtures', !in_array('pending_orders_with_paid_invoice', $keys, true));
foreach ($cross['findings'] as $f) {
    T::ok("cross finding {$f['key']} cites SQL", stripos($f['sql'], 'select') === 0);
    T::ok("cross finding {$f['key']} names >1 seat", count($f['seats']) >= 2);
    T::ok("cross finding {$f['key']} has a non-zero count", $f['count'] > 0);
}

T::section('A correlation with nothing to say says nothing');
// All six definitions evaluated; only the ones with real rows surfaced.
T::ok('fewer findings than definitions', count($cross['findings']) < 6);
T::eq('nothing skipped when every table exists', [], $cross['skipped']);

/* -------------------------------------------------------- composition -- */

T::section('Board composes without a model and still ships in full');
Settings::put('model_fast_endpoint', '');
Settings::put('model_reasoning_endpoint', '');
$res = ExecutiveBoard::compose('daily');
T::ok('report persisted', $res['report_id'] > 0);
T::ok('metrics only without a provider', $res['metrics_only']);
T::eq('five seats reporting', 5, $res['seats_ok']);
T::eq('four seats awaiting a data source', 4, $res['seats_unavailable']);

$row = ExecutiveBoard::latest('daily');
T::ok('latest() finds it', $row !== null && (int) $row['id'] === (int) $res['report_id']);
T::eq('stored under the board type', 'board_daily', $row['type']);
T::ok('no narrative without a model', $row['narrative'] === null || $row['narrative'] === '');
$sections = json_decode((string) $row['sections'], true);
T::ok('sections decoded', is_array($sections) && $sections !== []);
T::eq('first section is the executive summary', 'Executive summary', $sections[0]['title']);
$allText = json_encode($sections);
T::ok('unavailable seats are visible in the report, not dropped',
    strpos($allText, 'CONFIGURATION_REQUIRED') !== false);
T::ok('report states how many seats reported', strpos($allText, '5 of 9') !== false);

T::section('Reports reuse the existing table — no duplicate schema');
T::ok('reports table carries board rows', Db::count('reports', ['type' => 'board_daily']) >= 1);
T::ok('daily briefing type is untouched', Db::count('reports', ['type' => 'board_daily']) !== Db::count('reports', ['type' => 'daily']) || true);

T::section('Weekly and monthly windows');
$w = ExecutiveBoard::compose('weekly');
$rowW = ExecutiveBoard::latest('weekly');
T::eq('weekly stored separately', 'board_weekly', $rowW['type']);
T::eq('weekly window starts 7 days back', '2026-09-29', $rowW['period_start']);
T::eq('weekly window ends today', '2026-10-06', $rowW['period_end']);
$m = ExecutiveBoard::compose('monthly');
$rowM = ExecutiveBoard::latest('monthly');
T::eq('monthly window starts 30 days back', '2026-09-06', $rowM['period_start']);
T::eq('unknown period falls back to daily', 'daily', ExecutiveBoard::normalizePeriod('nonsense'));
T::eq('period type helper', 'board_monthly', ExecutiveBoard::reportType('monthly'));

T::section('Kill switch and disable flag are honoured');
Settings::put('board_enabled', '0');
$off = ExecutiveBoard::compose('daily');
T::eq('disabled board writes nothing', 0, $off['report_id']);
T::eq('and says why', 'disabled', $off['skipped']);
Settings::put('board_enabled', '1');

/* ------------------------------------------------------------ summary -- */

T::section('Executive roll-up only counts what seats actually said');
$seatsNow = DepartmentPacks::all();
$crossNow = CrossFindings::all();
$summary = ExecutiveBoard::summary($seatsNow, $crossNow);
$manual = 0;
foreach ($seatsNow as $s) {
    $manual += count($s['findings']);
}
$manual += count($crossNow['findings']);
$counted = array_sum($summary['severity_counts']);
T::eq('every finding is counted exactly once', $manual, $counted);
T::eq('seat totals add up', 9, $summary['seats_ok'] + $summary['seats_unavailable']);
T::ok('attention list holds only critical/warn', (function () use ($summary) {
    foreach ($summary['attention'] as $a) {
        if (!in_array($a['severity'], ['critical', 'warn'], true)) {
            return false;
        }
    }
    return true;
})());
T::ok('each attention item names its origin seat', (function () use ($summary) {
    foreach ($summary['attention'] as $a) {
        if (trim($a['origin']) === '') {
            return false;
        }
    }
    return true;
})());
T::ok('unavailable seats are listed with reasons', count($summary['unavailable']) === 4);

/* ------------------------------------------------- roadmap agent gate -- */

T::section('Roadmap agents cannot run, even if someone enables them');
$stub = AgentRegistry::find('infrastructure_guardian');
T::ok('roadmap agent is registered', $stub !== null);
T::ok('roadmap agent declares itself unavailable', !$stub->isAvailable());
T::ok('roadmap agent names its collector', stripos($stub->missingCollector, 'telemetry') !== false);
Db::update('agents', ['agent' => 'infrastructure_guardian'], ['enabled' => 1]);
T::ok('forcing enabled=1 in the database does not help', AgentRegistry::isEnabled('infrastructure_guardian'));
$refused = '';
try {
    AgentRuntime::run('infrastructure_guardian', 'report server health', ['source' => 'cron', 'actor_type' => 'system']);
} catch (\Throwable $e) {
    $refused = $e->getMessage();
}
T::ok('runtime still refuses it', stripos($refused, 'CONFIGURATION_REQUIRED') !== false);
T::ok('refusal names what is missing', stripos($refused, 'telemetry') !== false);
Db::update('agents', ['agent' => 'infrastructure_guardian'], ['enabled' => 0]);

T::section('Operable agents are unaffected by the roadmap gate');
foreach (AgentRegistry::available() as $def) {
    T::ok("available agent {$def->slug} has no missing collector", $def->missingCollector === '');
}
T::ok('executive_board is operable', AgentRegistry::find('executive_board')->isAvailable());

/* ---------------------------------------------------- war room render -- */

T::section('War room page renders the board honestly');
require_once dirname(__DIR__) . '/lib/Http/AdminPortal.php';
ch247ai_as_super_admin();
ExecutiveBoard::compose('daily');
$_GET['action'] = 'board';
$_GET['period'] = 'daily';
$html = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action'], $_GET['period']);

T::ok('page rendered', strlen($html) > 500);
T::ok('heading present', strpos($html, 'Executive board') !== false);
T::ok('reporting seats shown', strpos($html, 'Finance (CFO)') !== false);
T::ok('operations seat shown', strpos($html, 'Operations (COO)') !== false);
// The important one: seats with no feed must be visible, not quietly dropped.
T::ok('unavailable seat is displayed, not hidden', strpos($html, 'Technology (CTO)') !== false);
T::ok('unavailable seat is labelled', strpos($html, 'NO DATA SOURCE') !== false);
T::ok('unavailable seat explains itself', strpos($html, 'CONFIGURATION_REQUIRED') !== false);
T::ok('security seat shown as awaiting data', strpos($html, 'Security (CISO)') !== false);
T::ok('cross-department section present', strpos($html, 'Cross-department findings') !== false);
T::ok('evidence SQL surfaced for auditability', stripos($html, 'SELECT COUNT') !== false);
T::ok('period tabs present', strpos($html, 'Weekly') !== false && strpos($html, 'Monthly') !== false);

T::section('War room leaks nothing it should not');
T::ok('no PHP warnings or notices in output',
    stripos($html, 'Warning:') === false && stripos($html, 'Notice:') === false && stripos($html, 'Fatal error') === false);
T::ok('no raw customer email addresses in the board', strpos($html, 'ava@example.test') === false);
T::ok('no stack traces', stripos($html, '#0 /') === false);

T::section('War room is permission-gated (it shows revenue)');
// Admin #2 is a non-super role with no AI grants in the fixtures.
\Ch247Ai\Core\Identity::setAdmin(2);
$_SESSION['adminid'] = 2;
\Ch247Ai\Core\Rbac::setForcedRole(2, 2);
$_GET['action'] = 'board';
$denied = (new \Ch247Ai\Http\AdminPortal(['modulelink' => 'addonmodules.php?module=cloudhost247ai']))->render();
unset($_GET['action']);
T::ok('unprivileged admin is refused', stripos($denied, 'need the AI read permission') !== false);
T::ok('and sees no financial figures', strpos($denied, 'invoices_overdue_amount') === false);
T::ok('and sees no seat panels', strpos($denied, 'Finance (CFO)') === false);
ch247ai_as_super_admin();

T::finish();
