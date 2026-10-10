<?php
/**
 * CloudHost247 Tools - legacy AJAX path tests.
 *
 * The live tool-execution endpoint is
 * index.php?m=CloudHost247_tools&action=ajax, served by
 * CloudHost247ToolsClient::handleAjax(). assets/js/CloudHost247-tools.js posts
 * there from every tool page, so it is not dead code.
 *
 * These tests pin the two guarantees that matter once that path delegates to
 * CloudHost247ToolsRunner:
 *
 *   1. Tools the catalog registers as exec=client have NO server endpoint.
 *      They promise "your data is never transmitted to CloudHost247", so the
 *      server must refuse to execute them - including when they are addressed
 *      by their legacy handler id, which is what the legacy bundle posts.
 *   2. The response the legacy bundle receives keeps its {success,data} /
 *      {success,message} shape, and sensitive input is redacted before any of
 *      it reaches the activity log.
 */
define('CLOUDHOST247_TOOLS', true);

require __DIR__ . '/../includes/Catalog.php';
require __DIR__ . '/../includes/Security.php';
require __DIR__ . '/../includes/RateLimiter.php';
require __DIR__ . '/../includes/Runner.php';

$pass = 0;
$fail = 0;

function la_ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "  FAIL $label\n"; }
}

function la_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else {
        $fail++;
        echo "  FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n";
    }
}

$moduleRoot = dirname(__DIR__);
$tools = CloudHost247ToolsCatalog::tools(false);

// ---------------------------------------------------------------------
echo "== the server refuses every browser-only tool (by slug) ==\n";
// ---------------------------------------------------------------------
$clientTools = [];
foreach ($tools as $slug => $tool) {
    if (($tool['exec'] ?? '') === 'client') { $clientTools[$slug] = $tool; }
}
la_ok('catalog exposes client tools', count($clientTools) > 0);

$wrongCode = [];
$succeeded = [];
foreach ($clientTools as $slug => $tool) {
    $r = CloudHost247ToolsRunner::run($slug, []);
    if ($r['success'] !== false) { $succeeded[] = $slug; }
    if (($r['error']['code'] ?? '') !== 'client_only') {
        $wrongCode[$slug] = $r['error']['code'] ?? '(none)';
    }
}
la_eq('no client tool executed', count($succeeded), 0);
if ($succeeded) { echo "     executed: " . implode(', ', $succeeded) . "\n"; }
la_eq('every client tool returns client_only', count($wrongCode), 0);
if ($wrongCode) {
    foreach ($wrongCode as $s => $c) { echo "     $s -> $c\n"; }
}

// ---------------------------------------------------------------------
echo "== ... and by the legacy handler id the bundle posts ==\n";
// ---------------------------------------------------------------------
$byHandlerFailed = [];
foreach ($clientTools as $slug => $tool) {
    $r = CloudHost247ToolsRunner::run($tool['handler'], []);
    if ($r['success'] !== false || ($r['error']['code'] ?? '') !== 'client_only') {
        $byHandlerFailed[] = $tool['handler'];
    }
}
la_eq('legacy handler ids also refused', count($byHandlerFailed), 0);
if ($byHandlerFailed) { echo "     " . implode(', ', $byHandlerFailed) . "\n"; }

// ---------------------------------------------------------------------
echo "== the refusal states the privacy guarantee ==\n";
// ---------------------------------------------------------------------
$refusal = CloudHost247ToolsRunner::run('ipv6-compress', ['ip' => '2001:db8::1']);
la_eq('refusal http status', $refusal['http_status'], 400);
la_ok('message mentions the browser', stripos($refusal['error']['message'], 'browser') !== false);
la_ok('message denies transmission', stripos($refusal['error']['message'], 'never transmitted') !== false);
la_ok('no data key leaked', !isset($refusal['data']));

// ---------------------------------------------------------------------
echo "== every browser-only tool ships a browser module ==\n";
// ---------------------------------------------------------------------
$missingModule = [];
foreach ($clientTools as $slug => $tool) {
    if (!is_file($moduleRoot . '/assets/js/tools/' . $slug . '.js')) {
        $missingModule[] = $slug;
    }
}
la_eq('all client tools have a route-split module', count($missingModule), 0);
if ($missingModule) { echo "     missing: " . implode(', ', $missingModule) . "\n"; }

// ---------------------------------------------------------------------
echo "== sensitive input is redacted before logging ==\n";
// ---------------------------------------------------------------------
$red = CloudHost247ToolsRunner::redact([
    'domain'      => 'example.com',
    'password'    => 'hunter2',
    'pass'        => 'hunter2',
    'passphrase'  => 'correct horse',
    'cvv'         => '123',
    'card_number' => '4111111111111111',
    'cc'          => '4111111111111111',
    'private_key' => '-----BEGIN-----',
    'api_key'     => 'abc123',
    'token'       => 't0ken',
    'csrf_token'  => 'sess-token-value',
    'nested'      => ['password' => 'inner', 'ip' => '8.8.8.8'],
    'long'        => str_repeat('a', 400),
]);

foreach (['password', 'pass', 'passphrase', 'cvv', 'card_number', 'cc', 'private_key', 'api_key', 'token'] as $k) {
    la_eq("redacted: $k", $red[$k], '[redacted]');
}
la_eq('harmless key kept: domain', $red['domain'], 'example.com');
la_ok('csrf_token dropped', !array_key_exists('csrf_token', $red));
la_eq('nested password redacted', $red['nested']['password'], '[redacted]');
la_eq('nested harmless kept', $red['nested']['ip'], '8.8.8.8');
la_ok('long value truncated', strlen($red['long']) < 300 && strpos($red['long'], '[truncated]') !== false);
la_ok('plaintext card number absent', strpos(json_encode($red), '4111111111111111') === false);
la_ok('plaintext password absent', strpos(json_encode($red), 'hunter2') === false);

// ---------------------------------------------------------------------
echo "== respondLegacy keeps the bundle's response shape ==\n";
// ---------------------------------------------------------------------
function la_capture($envelope)
{
    ob_start();
    CloudHost247ToolsRunner::respondLegacy($envelope);
    $out = ob_get_clean();
    return json_decode($out, true);
}

$okEnv = [
    'success'     => true,
    'tool'        => 'ipv6-compress',
    'data'        => ['compressed' => '2001:db8::1'],
    'http_status' => 200,
];
$leg = la_capture($okEnv);
la_eq('success flag', $leg['success'], true);
la_eq('data passthrough', $leg['data']['compressed'], '2001:db8::1');
la_ok('no message on success', !array_key_exists('message', $leg));
la_ok('no internal keys on success', !array_key_exists('meta', $leg));

$errEnv = [
    'success'     => false,
    'tool'        => 'ipv6-compress',
    'error'       => ['code' => 'client_only', 'message' => 'Runs in your browser.'],
    'http_status' => 400,
];
$leg = la_capture($errEnv);
la_eq('failure flag', $leg['success'], false);
la_eq('message surfaced', $leg['message'], 'Runs in your browser.');
la_ok('no data on failure', !array_key_exists('data', $leg));

// A server_error envelope must expose only the safe text, never the internals.
$boom = [
    'success'     => false,
    'error'       => [
        'code'    => 'server_error',
        'message' => 'This tool could not complete your request. Please try again.',
    ],
    'http_status' => 500,
];
$leg = la_capture($boom);
la_eq('server_error message', $leg['message'], 'This tool could not complete your request. Please try again.');

// ---------------------------------------------------------------------
echo "== the live endpoint delegates to the Runner ==\n";
// ---------------------------------------------------------------------
$classes = (string) file_get_contents($moduleRoot . '/includes/classes.php');
$at = strpos($classes, 'protected function handleAjax()');
la_ok('handleAjax exists', $at !== false);
$body = $at === false ? '' : substr($classes, $at, 5000);

la_ok('delegates to Runner::run', strpos($body, 'CloudHost247ToolsRunner::run') !== false);
la_ok('uses respondLegacy', strpos($body, 'respondLegacy') !== false);
la_ok('verifies the legacy csrf token', strpos($body, 'CloudHost247_tools_verify_csrf') !== false);
la_ok('passes require_csrf=false (checked above)', strpos($body, "'require_csrf' => false") !== false);
la_ok('passes the resolved client ip', strpos($body, 'CloudHost247_tools_get_client_ip') !== false);

la_ok(
    'admin enable/disable toggle still honoured',
    strpos($body, 'CloudHost247_tools_is_tool_enabled') !== false
);
la_ok('no direct handler invocation', strpos($body, 'call_user_func') === false);
la_ok('no raw exception echo', strpos($body, '$e->getMessage()') === false);
la_ok('no unredacted logging of raw POST', strpos($body, "CloudHost247_tools_log") === false);
la_ok('no flat rate limit (Runner owns quotas)', strpos($body, 'CloudHost247_tools_check_rate_limit') === false);
la_ok('no direct tool-file include', strpos($body, "includes/tools/") === false);

$render = strpos($classes, 'protected function renderToolPage(');
$renderBody = $render === false ? '' : substr($classes, $render, 3000);
la_ok('renderToolPage resolves the catalog record', strpos($renderBody, 'CloudHost247ToolsCatalog::byHandler') !== false);
la_ok('renderToolPage exposes tool_exec', strpos($renderBody, "'tool_exec'") !== false);
la_ok('renderToolPage exposes tool_slug', strpos($renderBody, "'tool_slug'") !== false);

// ---------------------------------------------------------------------
echo "== the tool page runs client tools in the browser ==\n";
// ---------------------------------------------------------------------
$tpl = (string) file_get_contents($moduleRoot . '/templates/client/tool.tpl');
la_ok('tool page loads tools-core.js', strpos($tpl, 'tools-core.js') !== false);
la_ok('tool page exposes exec mode', strpos($tpl, 'CloudHost247ToolExec') !== false);
la_ok('tool page exposes the slug', strpos($tpl, 'CloudHost247ToolSlug') !== false);
la_ok('tool page exposes the assets url', strpos($tpl, 'CloudHost247AssetsUrl') !== false);

$bundle = (string) file_get_contents($moduleRoot . '/assets/js/CloudHost247-tools.js');
$submit = strpos($bundle, 'window.CloudHost247SubmitTool = function');
la_ok('submit handler exists', $submit !== false);
$submitBody = $submit === false ? '' : substr($bundle, $submit, 4000);

$branchAt = strpos($submitBody, "CloudHost247ToolExec === 'client'");
$sendAt   = strpos($submitBody, 'xhr.send(');
la_ok('submit handler branches on exec=client', $branchAt !== false);
la_ok('branch runs before anything is posted', $branchAt !== false && $sendAt !== false && $branchAt < $sendAt);
la_ok('branch calls the local runner', strpos($submitBody, 'CloudHost247RunClientTool') !== false);

la_ok('module loader defined', strpos($bundle, 'window.CloudHost247LoadToolModule') !== false);
la_ok('module loader uses js/tools/<slug>.js', strpos($bundle, "'js/tools/'") !== false);
la_ok('local runner defined', strpos($bundle, 'window.CloudHost247RunClientTool') !== false);
la_ok('local runner stubs ToolPage during load', strpos($bundle, 'CH.ToolPage = function(config)') !== false);
la_ok('local runner restores ToolPage', strpos($bundle, 'CH.ToolPage = realToolPage') !== false);
la_ok('local runner handles async run()', strpos($bundle, 'typeof data.then ===') !== false);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
