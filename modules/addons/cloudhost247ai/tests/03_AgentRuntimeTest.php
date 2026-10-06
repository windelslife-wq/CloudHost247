<?php
/** Agent runtime: lifecycle guarantees, budgets, anti-fabrication, observability. */

require_once __DIR__ . '/bootstrap.php';

use Ch247Ai\Agents\AgentRuntime;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Settings;

ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();

T::section('Hard rule: no model call inside a web-request hook');
T::throws('source=hook refused outright', function () {
    AgentRuntime::run('admin_copilot', 'anything', ['source' => AgentRuntime::SOURCE_HOOK]);
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Fail closed without a provider (CONFIGURATION_REQUIRED)');
T::throws('unconfigured provider throws ProviderNotConfigured', function () {
    AgentRuntime::run('admin_copilot', 'show unpaid invoices');
}, \Ch247Ai\Core\ProviderNotConfiguredException::class);
$message = '';
try {
    AgentRuntime::run('admin_copilot', 'show unpaid invoices');
} catch (\Throwable $e) {
    $message = $e->getMessage();
}
T::ok('message names CONFIGURATION_REQUIRED', strpos($message, 'CONFIGURATION_REQUIRED') !== false);
T::ok('message explains how to fix it', strpos($message, 'CloudHost247 AI') !== false && strpos($message, 'CH247AI_API_KEY') !== false);
$failed = Db::first('runs', [], 'id DESC');
T::eq('failed run recorded', 'failed', $failed['status']);
T::ok('failure reason stored without leaking secrets', strpos((string) $failed['output'], 'CONFIGURATION_REQUIRED') !== false);

T::section('Anti-fabrication: no evidence collected, no answer');
AgentRuntime::setModelFake(ch247ai_scripted_model([
    ['content' => 'Based on my knowledge, you have 14 unpaid invoices totalling $3,205.90.'], // fabricated!
]));
$result = AgentRuntime::run('admin_copilot', 'how many unpaid invoices do we have?', ['source' => AgentRuntime::SOURCE_INTERACTIVE]);
T::eq('run refused (not success)', 'refused', $result['status']);
T::ok('fabricated numbers are discarded', strpos($result['output'], '3,205.90') === false);
T::ok('cannot-verify template returned', strpos($result['output'], 'cannot verify') !== false || strpos($result['output'], "can't verify") !== false);

T::section('Grounded answer with tool evidence passes');
AgentRuntime::setModelFake(ch247ai_scripted_model([
    ['content' => ch247ai_tool_call('read_invoices', ['status' => 'Unpaid', 'limit' => 5])],
    ['content' => 'There are 3 unpaid invoices (102: 80.50, 103: 45.00, 104: 12.00) totalling 137.50. Source: read_invoices over tblinvoices.'],
]));
$result = AgentRuntime::run('admin_copilot', 'how many unpaid invoices?', ['source' => AgentRuntime::SOURCE_INTERACTIVE]);
T::eq('run succeeds with evidence', 'success', $result['status']);
T::eq('one tool call made', 1, $result['tool_calls']);
T::ok('citation captured', strpos(json_encode($result['citations']), 'tblinvoices') !== false);
$run = Db::first('runs', ['id' => $result['run_id']]);
T::eq('run row marked success', 'success', $run['status']);
T::ok('output stored', strlen((string) $run['output']) > 10);
T::ok('tool_calls row recorded', Db::count('tool_calls', ['run_id' => $result['run_id']]) === 1);
$call = Db::first('tool_calls', ['run_id' => $result['run_id']]);
T::eq('permission_checked flag set', 1, (int) $call['permission_checked']);
T::ok('result digest stored', strlen((string) $call['result_digest']) === 64);
T::ok('run steps recorded (model + tool + model)', Db::count('run_steps', ['run_id' => $result['run_id']]) === 3);
T::ok('usage recorded', Db::count('usage_daily', ['agent' => 'admin_copilot']) === 1);
T::ok('memory appended', Db::count('memories', ['agent' => 'admin_copilot', 'scope' => 'short']) >= 1);

T::section('Tool errors are reported, never papered over');
AgentRuntime::setModelFake(ch247ai_scripted_model([
    ['content' => ch247ai_tool_call('read_client_details', ['client_id' => 9999])],
    ['content' => 'Client 9999 exists with 4 services.'], // fabrication after a failed tool
]));
$result = AgentRuntime::run('admin_copilot', 'tell me about client 9999');
T::eq('run refused after tool failure', 'refused', $result['status']);
$call = Db::first('tool_calls', [], 'id DESC');
T::eq('failed tool call recorded as refused', 'refused', $call['status']);

T::section('Bounded loop: tool-call budget');
Settings::put('max_tool_calls_per_run', '2');
AgentRuntime::setModelFake(function (array $messages, array $opts) {
    return ['content' => ch247ai_tool_call('read_metrics', []), 'tokens_in' => 5, 'tokens_out' => 5]; // always wants another call
});
$result = AgentRuntime::run('admin_copilot', 'drill into metrics forever');
T::eq('budget clamps tool calls', 2, $result['tool_calls']);
T::ok('bounded run ends in stale with explanation', in_array($result['status'], ['stale', 'success', 'refused'], true));

T::section('Bounded loop: wall clock');
Settings::put('max_tool_calls_per_run', '5');
Settings::put('run_wall_clock_seconds', '5');
\Ch247Ai\Core\Clock::freeze(strtotime('2026-10-06 12:00:00 UTC'));
AgentRuntime::setModelFake(function (array $messages, array $opts) {
    \Ch247Ai\Core\Clock::freeze(strtotime('2026-10-06 12:01:00 UTC')); // jump past the deadline
    return ['content' => ch247ai_tool_call('read_metrics', []), 'tokens_in' => 5, 'tokens_out' => 5];
});
$result = AgentRuntime::run('admin_copilot', 'slow tool loop');
T::eq('deadline stops the run', 'stale', $result['status']);
T::ok('bound template explains the stop', strpos($result['output'], 'bound') !== false);

T::section('Daily token budget');
ch247ai_boot();
ch247ai_freeze();
ch247ai_as_super_admin();
Settings::put('daily_tokens_per_agent', '50');
AgentRuntime::setModelFake(ch247ai_scripted_model([
    ['content' => ch247ai_tool_call('read_metrics', [])],
    ['content' => 'Metrics say 3 active clients.'],
    ['content' => ch247ai_tool_call('read_metrics', [])],
    ['content' => 'Still 3.'],
]));
$first = AgentRuntime::run('admin_copilot', 'metrics once');
T::eq('first run ok', 'success', $first['status']);
$budgetHit = false;
try {
    AgentRuntime::run('admin_copilot', 'metrics again');
} catch (\Ch247Ai\Core\BudgetExceededException $e) {
    $budgetHit = true;
}
T::ok('budget exceeded stops the second run', $budgetHit);
$run = Db::first('runs', [], 'id DESC');
T::eq('budget failure recorded', 'failed', $run['status']);

T::section('Scheduled agents run from cron context');
AgentRuntime::setModelFake(ch247ai_scripted_model([
    ['content' => ch247ai_tool_call('read_domains', ['limit' => 2])],
    ['content' => 'Two domains tracked, nearest expiry avariver.com 2026-10-20.'],
]));
$result = AgentRuntime::run('ssl_guardian', 'check expiring certificates', ['source' => AgentRuntime::SOURCE_CRON, 'actor_type' => 'system', 'actor_id' => 0]);
T::eq('scheduled agent runs from cron', 'success', $result['status']);
T::ok('task row references the run', Db::count('runs', ['agent' => 'ssl_guardian']) === 1);

T::section('Disabled agent refuses');
Db::update('agents', ['agent' => 'dns_domain_agent'], ['enabled' => 0]);
T::throws('disabled agent refuses', function () {
    AgentRuntime::run('dns_domain_agent', 'check dns');
}, \Ch247Ai\Core\ForbiddenException::class);

T::section('Disabled service refuses before any model call');
Settings::put('service_enabled', '0');
T::throws('service off refuses', function () {
    AgentRuntime::run('admin_copilot', 'anything');
}, \Ch247Ai\Core\ForbiddenException::class);
Settings::put('service_enabled', '1');

T::finish();
