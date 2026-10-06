<?php
/**
 * CloudHost247 Tools - IPv6 tool handler tests.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Security.php';
require __DIR__ . '/../includes/tools/ip_tools.php';

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL $label\n"; }
}
function eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n"; }
}

// ---------- ipv6_range_to_cidr ----------
$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::', 'end' => '2001:db8::ffff']);
eq('single /112 block', array_column($r['cidrs'], 'cidr'), ['2001:db8::/112']);

$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::', 'end' => '2001:db8::']);
eq('single address -> /128', array_column($r['cidrs'], 'cidr'), ['2001:db8::/128']);

$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::1', 'end' => '2001:db8::2']);
eq('unaligned pair', array_column($r['cidrs'], 'cidr'), ['2001:db8::1/128', '2001:db8::2/128']);

$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::', 'end' => '2001:db8::2']);
eq('three addresses', array_column($r['cidrs'], 'cidr'), ['2001:db8::/127', '2001:db8::2/128']);

$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '::', 'end' => 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff']);
eq('whole space -> ::/0', array_column($r['cidrs'], 'cidr'), ['::/0']);

$r = CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::1', 'end' => '2001:db8::ffff']);
ok('unaligned start produces multiple blocks', count($r['cidrs']) === 16);
// Verify coverage is exact and contiguous.
$prevEnd = null;
$contig = true;
foreach ($r['cidrs'] as $c) {
    if ($prevEnd !== null) {
        $expect = inet_ntop(CloudHost247_tools_ip_inc(inet_pton($prevEnd)));
        if ($expect !== $c['first']) { $contig = false; }
    }
    $prevEnd = $c['last'];
}
ok('blocks are contiguous', $contig);
eq('coverage starts at range start', $r['cidrs'][0]['first'], '2001:db8::1');
eq('coverage ends at range end', $prevEnd, '2001:db8::ffff');

// Errors
ok('start > end rejected', isset(CloudHost247_tool_ipv6_range_to_cidr(['start' => '2001:db8::5', 'end' => '2001:db8::1'])['error']));
ok('ipv4 rejected', isset(CloudHost247_tool_ipv6_range_to_cidr(['start' => '1.2.3.4', 'end' => '1.2.3.5'])['error']));
ok('empty rejected', isset(CloudHost247_tool_ipv6_range_to_cidr([])['error']));
ok('garbage rejected', isset(CloudHost247_tool_ipv6_range_to_cidr(['start' => 'zz::', 'end' => '2001:db8::'])['error']));

// ---------- ipv6_expand ----------
$r = CloudHost247_tool_ipv6_expand(['ip' => '2001:db8::1']);
eq('expanded', $r['expanded'], '2001:0db8:0000:0000:0000:0000:0000:0001');
eq('compressed', $r['compressed'], '2001:db8::1');
eq('short', $r['short'], '2001:db8:0:0:0:0:0:1');
eq('groups count', count($r['groups']), 8);
ok('arpa suffix', substr($r['arpa'], -9) === '.ip6.arpa');
eq('arpa', $r['arpa'], '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa');
ok('db8 is documentation (not public)', $r['is_public'] === false);
$r = CloudHost247_tool_ipv6_expand(['ip' => '2606:4700:4700::1111']);
ok('cloudflare is public', $r['is_public'] === true);
eq('binary group count', count(explode(' ', $r['binary'])), 16);
$r = CloudHost247_tool_ipv6_expand(['ip' => '[2001:db8::1]']);
eq('brackets stripped', $r['compressed'], '2001:db8::1');
ok('ipv4 rejected', isset(CloudHost247_tool_ipv6_expand(['ip' => '8.8.8.8'])['error']));
ok('empty rejected', isset(CloudHost247_tool_ipv6_expand([])['error']));

// ---------- ipv6_to_ipv4 ----------
$r = CloudHost247_tool_ipv6_to_ipv4(['ip' => '::ffff:192.168.1.1']);
eq('mapped ipv4', $r['ipv4'], '192.168.1.1');
ok('mapped mechanism', strpos($r['mechanism'], 'IPv4-mapped') === 0);

$r = CloudHost247_tool_ipv6_to_ipv4(['ip' => '2002:c000:0204::1']);
eq('6to4 ipv4', $r['ipv4'], '192.0.2.4');
ok('6to4 mechanism', strpos($r['mechanism'], '6to4') === 0);

$r = CloudHost247_tool_ipv6_to_ipv4(['ip' => '64:ff9b::808:808']);
eq('nat64 ipv4', $r['ipv4'], '8.8.8.8');

$r = CloudHost247_tool_ipv6_to_ipv4(['ip' => '2001:0:4136:e378:8000:63bf:3fff:fdd2']);
eq('teredo client ipv4', $r['ipv4'], '192.0.2.45');
eq('teredo server ipv4', $r['teredo']['server_ipv4'], '65.54.227.120');
eq('teredo port', $r['teredo']['client_port'], 40000);

$r = CloudHost247_tool_ipv6_to_ipv4(['ip' => '2606:4700:4700::1111']);
eq('native has no ipv4', $r['ipv4'], null);
ok('native explained', strlen($r['explanation']) > 40);
ok('ipv4 input rejected', isset(CloudHost247_tool_ipv6_to_ipv4(['ip' => '1.2.3.4'])['error']));

// ---------- helpers ----------
eq('trailing zeros ::', CloudHost247_tools_ip_trailing_zeros(inet_pton('::')), 128);
eq('trailing zeros ::1', CloudHost247_tools_ip_trailing_zeros(inet_pton('::1')), 0);
eq('trailing zeros ::2', CloudHost247_tools_ip_trailing_zeros(inet_pton('::2')), 1);
// 0x..0db8 ends in 1011 1000 -> 3 trailing zero bits, plus 12 zero bytes = 99.
eq('trailing zeros 2001:db8::', CloudHost247_tools_ip_trailing_zeros(inet_pton('2001:db8::')), 99);
eq('mask /64', inet_ntop(CloudHost247_tools_ip_mask(inet_pton('2001:db8::dead:beef'), 64)), '2001:db8::');
eq('prefix end /112', inet_ntop(CloudHost247_tools_ip_prefix_end(inet_pton('2001:db8::'), 112)), '2001:db8::ffff');
eq('inc', inet_ntop(CloudHost247_tools_ip_inc(inet_pton('2001:db8::ff'))), '2001:db8::100');
eq('inc rollover', inet_ntop(CloudHost247_tools_ip_inc(inet_pton('2001:db8::ffff:ffff'))), '2001:db8::1:0:0');

echo "\nPASS=$pass FAIL=$fail\n";
