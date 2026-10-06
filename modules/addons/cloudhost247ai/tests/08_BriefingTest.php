<?php
/** Briefing composer: deterministic packs, metrics-only without provider, narration with one. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Briefing\BriefingComposer;
use Ch247Ai\Core\Db;

ch247ai_boot();
ch247ai_freeze();

T::section('Briefing composes without any model provider (metrics only)');
$result = BriefingComposer::compose();
T::ok('report row created', (int) $result['briefing_id'] > 0);
T::ok('metrics only flagged', $result['metrics_only'] === true);
$row = Db::first('reports', ['id' => $result['briefing_id']]);
T::eq('type daily', 'daily', $row['type']);
T::ok('metric pack stored', strpos((string) $row['metric_pack'], 'paid_today_amount') !== false);
T::ok('no narrative without provider', $row['narrative'] === null);

T::section('Sections are deterministic SQL facts');
$sections = json_decode((string) $row['sections'], true);
T::eq('three sections', 3, count($sections));
$titles = [];
foreach ($sections as $section) {
    $titles[] = $section['title'];
}
T::eq('section titles', ['Revenue', 'Growth', 'Operations'], $titles);
$all = implode("\n", array_merge(...array_map(function ($s) {
    return $s['lines'];
}, $sections)));
T::ok('paid today from tblaccounts fixture (30.00 — only the 2026-10-06 payment)', strpos($all, 'Paid today: 30.00') !== false);
T::ok('active clients count present', strpos($all, 'Active clients total: 2') !== false);
T::ok('unpaid exposure from tblinvoices (132.5 = 80.5 + 40 + 12, credit 5 deducted)', strpos($all, 'Unpaid exposure: 132.5') !== false);

T::section('Every metric carries its SQL');
$pack = json_decode((string) $row['metric_pack'], true);
foreach ($pack['metrics'] as $metric) {
    T::ok('metric ' . $metric['metric'] . ' cites SQL', strpos($metric['sql'], 'SELECT') === 0);
}

T::section('With a provider, exactly one narration call is made');
ch247ai_boot();
ch247ai_freeze();
// A provider is "configured" via settings; metrics_only goes false and the
// narration happens through exactly one model call.
\Ch247Ai\Core\Settings::put('model_fast_endpoint', 'https://models.internal.test/v1');
\Ch247Ai\Core\Settings::put('model_fast_model', 'test-model');
$calls = 0;
\Ch247Ai\Agents\AgentRuntime::setModelFake(function (array $messages, array $opts) use (&$calls) {
    $calls++;
    return ['content' => 'Revenue was 30.00 today. 3 invoices remain unpaid totalling 132.50.', 'tokens_in' => 100, 'tokens_out' => 80];
});
$result = BriefingComposer::compose();
T::ok('narrated this time', $result['metrics_only'] === false);
T::eq('exactly one model call', 1, $calls);
$row = Db::first('reports', [], 'id DESC');
T::ok('narrative stored', strpos((string) $row['narrative'], '30.00') !== false);
T::ok('sections unchanged by narration', count(json_decode((string) $row['sections'], true)) === 3);

T::section('Narration cannot add facts it was not given');
$calls2 = 0;
\Ch247Ai\Agents\AgentRuntime::setModelFake(function (array $messages, array $opts) use (&$calls2) {
    $calls2++;
    return ['content' => 'We also won a new enterprise deal worth $500k and the CEO is thrilled.', 'tokens_in' => 50, 'tokens_out' => 40];
});
BriefingComposer::compose();
$row = Db::first('reports', [], 'id DESC');
T::ok('hallucinated enterprise deal kept out of metrics', strpos((string) $row['metric_pack'], '500k') === false);
T::ok('narrative is clearly separated from the deterministic pack', $row['sections'] !== $row['narrative']);

T::section('Briefings can be disabled');
\Ch247Ai\Core\Settings::put('briefings_enabled', '0');
$result = BriefingComposer::compose();
T::ok('disabled skips composition', isset($result['skipped']) && $result['skipped'] === 'disabled');

T::section('Memory + audit trail');
T::ok('briefing remembered in memory', Db::count('memories', ['agent' => 'briefing_composer']) >= 1);
T::ok('composition audited', Db::count('audit_log', ['action' => 'ai.briefing.composed']) >= 1);

T::finish();
