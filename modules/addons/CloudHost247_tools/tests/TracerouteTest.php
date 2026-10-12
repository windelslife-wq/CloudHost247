<?php
/**
 * System traceroute parsing and command wiring tests. No network or system command is used.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/tools/ip_tools.php';

$pass = 0;
$fail = 0;
function trace_ok($label, $condition)
{
    global $pass, $fail;
    if ($condition) { $pass++; }
    else { $fail++; echo "  FAIL $label\n"; }
}
function trace_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else {
        $fail++;
        echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
    }
}

$output = "traceroute to 8.8.8.8 (8.8.8.8), 4 hops max\n"
    . " 1  10.1.0.1  1.234 ms\n"
    . " 2  *\n"
    . " 3  1.1.1.1  <1 ms\n"
    . " 4  8.8.8.8  9.250 ms\n"
    . " 5  203.0.113.8  10.000 ms\n";
$r = CloudHost247_tools_parse_traceroute_output($output, 4, '8.8.8.8');
trace_ok('numbered UDP traceroute output is parsed', is_array($r));
trace_eq('private intermediate router is redacted', $r['hops'][0]['status'] ?? null, 'redacted');
trace_ok('private intermediate router address is omitted', array_key_exists('ip', $r['hops'][0]) && $r['hops'][0]['ip'] === null);
trace_eq('timeout hop is retained', $r['hops'][1]['status'] ?? null, 'timeout');
trace_eq('public intermediate router is retained', $r['hops'][2]['ip'] ?? null, '1.1.1.1');
trace_eq('sub-millisecond hop is displayed without inventing a numeric value', $r['hops'][2]['time_display'] ?? null, '<1 ms');
trace_ok('sub-millisecond time remains null', array_key_exists('time_ms', $r['hops'][2]) && $r['hops'][2]['time_ms'] === null);
trace_eq('destination is detected', $r['reached_destination'] ?? null, true);
trace_eq('hop limit excludes later lines', count($r['hops'] ?? []), 4);
$rWithReservedHop = CloudHost247_tools_parse_traceroute_output($output, 5, '8.8.8.8');
trace_eq('documentation-range hop is redacted', $rWithReservedHop['hops'][4]['status'] ?? null, 'redacted');
trace_ok('documentation-range hop address is omitted', array_key_exists('ip', $rWithReservedHop['hops'][4]) && $rWithReservedHop['hops'][4]['ip'] === null);
trace_eq('unknown output does not create a route', CloudHost247_tools_parse_traceroute_output('permission denied', 20, '8.8.8.8'), null);

$commandOutput = "traceroute to 8.8.8.8 (8.8.8.8), 2 hops max\n 1  *\n 2  8.8.8.8  4.000 ms\n";
$calls = [];
$runner = function ($binary, $args, $timeout) use (&$calls, $commandOutput) {
    $calls[] = [$binary, $args, $timeout];
    return ['available' => true, 'output' => $commandOutput, 'code' => 0];
};
$r = CloudHost247_tools_traceroute('8.8.8.8', 2, 1, $runner);
trace_eq('helper calls the allowlisted traceroute binary', $calls[0][0] ?? null, 'traceroute');
trace_ok('traceroute command pins the vetted IP and bounds hops/probes/wait',
    ($calls[0][1] ?? []) === ['-n', '-m', '2', '-q', '1', '-w', '1', '8.8.8.8']
    && ($calls[0][2] ?? 99) <= 24);
trace_eq('helper returns parsed hop rows', count($r['hops'] ?? []), 2);
trace_eq('helper recognizes destination reached', $r['reached_destination'] ?? null, true);
trace_ok('helper discloses private-hop redaction and UDP traceroute', strpos($r['measurement'] ?? '', 'private') !== false && strpos($r['measurement'] ?? '', 'UDP') !== false);

$called = 0;
$neverRun = function () use (&$called) { $called++; return ['available' => true, 'output' => '']; };
$r = CloudHost247_tools_traceroute('127.0.0.1', 2, 1, $neverRun);
trace_ok('private target is rejected before command execution', isset($r['error']) && $called === 0);
$r = CloudHost247_tools_traceroute('2001:4860:4860::8888', 2, 1, $neverRun);
trace_ok('IPv6 target is rejected before IPv4 traceroute command execution', isset($r['error']) && $called === 0);
$r = CloudHost247_tools_traceroute('8.8.8.8', 2, 1, function () { return ['available' => false]; });
trace_ok('missing traceroute binary is reported honestly', isset($r['error']) && strpos($r['error'], 'unavailable') !== false);
$r = CloudHost247_tools_traceroute('8.8.8.8', 2, 1, function () { return ['available' => true, 'output' => 'no trace']; });
trace_ok('unrecognized traceroute output is not presented as a route', isset($r['error']) && !isset($r['hops']));
trace_ok('invalid command adapter is rejected', isset(CloudHost247_tools_traceroute('8.8.8.8', 2, 1, 'not-callable')['error']));

printf("\nPASS=%d FAIL=%d\n", $pass, $fail);
exit($fail ? 1 : 0);
