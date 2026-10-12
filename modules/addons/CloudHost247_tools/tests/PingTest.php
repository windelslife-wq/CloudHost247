<?php
/**
 * ICMP ping parsing and command wiring tests. No network or system ping command is used.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/tools/ip_tools.php';

$pass = 0;
$fail = 0;
function ping_ok($label, $condition)
{
    global $pass, $fail;
    if ($condition) { $pass++; }
    else { $fail++; echo "  FAIL $label\n"; }
}
function ping_eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else {
        $fail++;
        echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
    }
}

$ipv4Output = "PING 8.8.8.8 (8.8.8.8) 56(84) bytes of data.\n"
    . "64 bytes from 8.8.8.8: icmp_seq=1 ttl=117 time=1.25 ms\n"
    . "64 bytes from 8.8.8.8: icmp_seq=2 ttl=117 time=2.50 ms\n"
    . "2 packets transmitted, 2 received, 0% packet loss, time 1001ms\n"
    . "rtt min/avg/max/mdev = 1.250/1.875/2.500/0.625 ms\n";
$r = CloudHost247_tools_parse_ping_output($ipv4Output, 2);
ping_eq('Linux ping summary parses sent count', $r['packets_sent'] ?? null, 2);
ping_eq('Linux ping summary parses received count', $r['packets_received'] ?? null, 2);
ping_eq('Linux ping summary parses loss', $r['packet_loss'] ?? null, 0.0);
ping_eq('echo min is calculated from parsed replies', $r['min_time'] ?? null, 1.25);
ping_eq('echo max is calculated from parsed replies', $r['max_time'] ?? null, 2.5);
ping_eq('echo average is calculated from parsed replies', $r['avg_time'] ?? null, 1.88);
ping_eq('reply details are marked available', $r['reply_details_available'] ?? null, true);
ping_eq('successful echo sequence is retained', $r['results'][1]['status'] ?? null, 'success');

$ipv6Output = "PING 2001:4860:4860::8888(2001:4860:4860::8888) 56 data bytes\n"
    . "64 bytes from 2001:4860:4860::8888: icmp_seq=1 ttl=118 time<1 ms\n"
    . "2 packets transmitted, 1 received, 50% packet loss, time 1001ms\n";
$r = CloudHost247_tools_parse_ping_output($ipv6Output, 2);
ping_eq('IPv6 ping summary parses', $r['packets_received'] ?? null, 1);
ping_eq('lossy ping summary parses', $r['packet_loss'] ?? null, 50.0);
ping_eq('missing echo sequence is marked timeout', $r['results'][1]['status'] ?? null, 'timeout');
ping_eq('sub-millisecond output preserves display text', $r['results'][0]['time_display'] ?? null, '<1 ms');
ping_eq('sub-millisecond timing is not fabricated as a number', $r['results'][0]['time'] ?? null, null);
ping_eq('per-reply table remains available for matching sequence counts', $r['reply_details_available'] ?? null, true);

$noReplyOutput = "4 packets transmitted, 0 received, 100% packet loss, time 3060ms\n";
$r = CloudHost247_tools_parse_ping_output($noReplyOutput, 4);
ping_eq('all-loss summary remains a measured result', $r['packet_loss'] ?? null, 100.0);
ping_ok('all-loss summary has no invented latency', array_key_exists('avg_time', $r) && $r['avg_time'] === null);
ping_eq('all-loss summary contains four timeout rows', count($r['results'] ?? []), 4);

$r = CloudHost247_tools_parse_ping_output("2 packets transmitted, 1 received\n", 2);
ping_eq('packet-loss percentage is derived only from packet counts', $r['packet_loss'] ?? null, 50.0);
$r = CloudHost247_tools_parse_ping_output("4 packets transmitted, 3 received, 25% packet loss\n64 bytes from x: icmp_seq=1 time=1 ms\n", 4);
ping_eq('mismatched reply details are not presented as per-packet facts', $r['reply_details_available'] ?? null, false);
ping_eq('mismatched reply details are omitted', $r['results'] ?? null, []);
ping_ok('summary explains omitted reply details', strpos($r['reply_details_note'] ?? '', 'could not be parsed') !== false);
ping_eq('unrecognized system output does not create results', CloudHost247_tools_parse_ping_output('permission denied', 4), null);
ping_eq('sent count cannot exceed the requested bound', CloudHost247_tools_parse_ping_output('5 packets transmitted, 5 received, 0% packet loss', 4), null);
ping_eq('received count cannot exceed sent count', CloudHost247_tools_parse_ping_output('2 packets transmitted, 3 received, 0% packet loss', 4), null);
ping_eq('inconsistent packet-loss percentage is rejected', CloudHost247_tools_parse_ping_output('2 packets transmitted, 1 received, 0% packet loss', 4), null);

$calls = [];
$runner = function ($binary, $args, $timeout) use (&$calls, $ipv4Output) {
    $calls[] = [$binary, $args, $timeout];
    return ['available' => true, 'output' => $ipv4Output, 'code' => 0];
};
$r = CloudHost247_tools_ping('8.8.8.8', false, 2, 2, $runner);
ping_eq('IPv4 ping uses the allowlisted system ping binary', $calls[0][0] ?? null, 'ping');
ping_ok('IPv4 ping command disables name lookup and bounds count/timeout',
    ($calls[0][1] ?? []) === ['-n', '-c', '2', '-W', '2', '8.8.8.8'] && ($calls[0][2] ?? 0) <= 20);
ping_eq('IPv4 helper reports actual ICMP echo measurement', $r['measurement'] ?? null, 'ICMPv4 echo using the system ping utility.');
ping_eq('IPv4 helper preserves the pinned target', $r['ip'] ?? null, '8.8.8.8');

$ipv6Runner = function ($binary, $args, $timeout) use (&$calls, $ipv4Output) {
    $calls[] = [$binary, $args, $timeout];
    return ['available' => true, 'output' => $ipv4Output, 'code' => 0];
};
$r6 = CloudHost247_tools_ping('2001:4860:4860::8888', true, 2, 2, $ipv6Runner);
ping_ok('IPv6 ping requests the IPv6 mode and pins the IPv6 target',
    ($calls[1][1] ?? []) === ['-n', '-6', '-c', '2', '-W', '2', '2001:4860:4860::8888']);
ping_eq('IPv6 helper reports ICMPv6', $r6['measurement'] ?? null, 'ICMPv6 echo using the system ping utility.');

$blockedCalls = 0;
$neverRun = function () use (&$blockedCalls) { $blockedCalls++; return ['available' => true, 'output' => '']; };
$r = CloudHost247_tools_ping('127.0.0.1', false, 2, 2, $neverRun);
ping_ok('private targets are blocked before invoking ping', isset($r['error']) && $blockedCalls === 0);
$r = CloudHost247_tools_ping('8.8.8.8', true, 2, 2, $neverRun);
ping_ok('IPv4 targets are rejected by IPv6 ping before invoking ping', isset($r['error']) && $blockedCalls === 0);
$r = CloudHost247_tools_ping('8.8.8.8', false, 2, 2, function () { return ['available' => false]; });
ping_ok('missing/disabled ping utility returns an honest error', isset($r['error']) && strpos($r['error'], 'unavailable') !== false);
$r = CloudHost247_tools_ping('8.8.8.8', false, 2, 2, function () { return ['available' => true, 'output' => 'command failed']; });
ping_ok('unrecognized ping output returns no fabricated result', isset($r['error']) && !isset($r['packet_loss']));
ping_ok('invalid test adapter is rejected', isset(CloudHost247_tools_ping('8.8.8.8', false, 2, 2, 'not-callable')['error']));

printf("\nPASS=%d FAIL=%d\n", $pass, $fail);
exit($fail ? 1 : 0);
