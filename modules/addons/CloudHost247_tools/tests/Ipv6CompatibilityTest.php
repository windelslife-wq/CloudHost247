<?php
/** Deterministic tests for IPv6 DNS observations and the forced-family HTTPS probe. */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Security.php';
require __DIR__ . '/../includes/tools/ip_tools.php';

$pass = 0;
$fail = 0;
function ipv6_compat_chk($label, $ok)
{
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function ipv6_compat_dns($mode, $host, $type)
{
    if ($host === 'example.com' && $type === DNS_AAAA) {
        return $mode === 'no-apex-aaaa' ? [] : [['ipv6' => '2606:4700:4700::1111']];
    }
    if ($host === 'www.example.com' && $type === DNS_AAAA) {
        return $mode === 'partial' ? [] : [['ipv6' => '2606:4700:4700::1001']];
    }
    if ($host === 'www.example.com' && $type === (DNS_A | DNS_CNAME)) {
        return [['type' => 'A', 'ip' => '93.184.216.34']];
    }
    if ($host === 'example.com' && $type === DNS_NS) {
        return [['target' => 'ns1.example.com'], ['target' => 'ns2.example.com']];
    }
    if ($host === 'ns1.example.com' && $type === DNS_AAAA) {
        return [['ipv6' => '2001:4860:4860::1']];
    }
    if ($host === 'ns2.example.com' && $type === DNS_AAAA) {
        return $mode === 'partial' ? [] : [['ipv6' => '2001:4860:4860::2']];
    }
    if ($host === 'example.com' && $type === DNS_MX) {
        return $mode === 'partial'
            ? [['target' => 'mx1.example.com'], ['target' => 'mx2.example.com']]
            : [['target' => 'mx1.example.com']];
    }
    if ($host === 'mx1.example.com' && $type === DNS_AAAA) {
        return [['ipv6' => '2001:4860:4860::10']];
    }
    if ($host === 'mx2.example.com' && $type === DNS_AAAA) {
        return $mode === 'partial' ? [] : [['ipv6' => '2001:4860:4860::11']];
    }
    return [];
}

$seenUrl = null;
$seenOptions = null;
$dnsAll = function ($host, $type) { return ipv6_compat_dns('complete', $host, $type); };
$fetchIpv6 = function ($url, $options) use (&$seenUrl, &$seenOptions) {
    $seenUrl = $url;
    $seenOptions = $options;
    return ['status' => 200, 'connected_ip' => '2606:4700:4700::1111'];
};
$complete = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $dnsAll, $fetchIpv6);
ipv6_compat_chk('a verified IPv6 HTTPS response plus complete DNS observations scores 100',
    $complete['score'] === 100 && $complete['verdict'] === 'IPv6 web connection confirmed');
ipv6_compat_chk('the probe is HTTPS and explicitly requests IPv6',
    $seenUrl === 'https://example.com/' && ($seenOptions['ip_version'] ?? null) === 6);
ipv6_compat_chk('the response reports the connected IPv6 address',
    $complete['http_connected_ip'] === '2606:4700:4700::1111'
    && $complete['checks'][4]['status'] === 'pass');
ipv6_compat_chk('nameserver and mail checks describe record presence, not reachability',
    strpos($complete['checks'][2]['name'], 'reach') === false
    && strpos($complete['checks'][3]['name'], 'reach') === false
    && strpos($complete['note'], 'do not test DNS or SMTP connectivity') !== false);

$dnsPartial = function ($host, $type) { return ipv6_compat_dns('partial', $host, $type); };
$partial = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $dnsPartial, $fetchIpv6);
ipv6_compat_chk('partial DNS record coverage is reported as partial despite the HTTPS pass',
    $partial['checks'][1]['status'] === 'fail'
    && $partial['checks'][2]['status'] === 'warn'
    && $partial['checks'][3]['status'] === 'warn'
    && $partial['verdict'] === 'IPv6 web connection confirmed; DNS coverage is partial');

$fetchIpv4 = function ($url, $options) {
    return ['status' => 200, 'connected_ip' => '93.184.216.34'];
};
$wrongFamily = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $dnsAll, $fetchIpv4);
ipv6_compat_chk('an IPv4 connection cannot satisfy the IPv6 HTTPS check',
    $wrongFamily['checks'][4]['status'] === 'fail'
    && $wrongFamily['verdict'] === 'IPv6 HTTPS connection not confirmed');

$fetchUnavailable = function ($url, $options) { throw new CloudHost247ToolsSecurityException('internal socket diagnostic'); };
$unverified = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $dnsAll, $fetchUnavailable);
ipv6_compat_chk('failed IPv6 probing is unverified and does not expose socket details',
    $unverified['checks'][4]['status'] === 'warn'
    && $unverified['verdict'] === 'IPv6 addresses published; web connection unverified'
    && strpos($unverified['checks'][4]['detail'], 'internal socket diagnostic') === false);

$noApexDns = function ($host, $type) { return ipv6_compat_dns('no-apex-aaaa', $host, $type); };
$fetchCalls = 0;
$fetchShouldNotRun = function ($url, $options) use (&$fetchCalls) { $fetchCalls++; return []; };
$noApex = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $noApexDns, $fetchShouldNotRun);
ipv6_compat_chk('missing apex AAAA skips the HTTP probe and cannot claim IPv6 readiness',
    $fetchCalls === 0 && $noApex['checks'][4]['status'] === 'na'
    && $noApex['verdict'] === 'No IPv6 address published');

$dnsHosts = [];
$dnsWithLocalTargets = function ($host, $type) use (&$dnsHosts) {
    $dnsHosts[] = $host;
    if ($host === 'example.com' && $type === DNS_AAAA) {
        return [['ipv6' => '2606:4700:4700::1111']];
    }
    if ($host === 'example.com' && $type === DNS_NS) {
        return [['target' => 'ns.internal.'], ['target' => 'ns.local.']];
    }
    if ($host === 'example.com' && $type === DNS_MX) {
        return [['target' => 'mail.internal.']];
    }
    return [];
};
$localTargets = CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], $dnsWithLocalTargets, $fetchIpv6);
ipv6_compat_chk('DNS-derived local NS/MX targets are not passed to the resolver callback',
    !in_array('ns.internal.', $dnsHosts, true)
    && !in_array('ns.local.', $dnsHosts, true)
    && !in_array('mail.internal.', $dnsHosts, true)
    && $localTargets['checks'][2]['status'] === 'fail'
    && $localTargets['checks'][3]['status'] === 'fail');

$dnsCalls = 0;
$badDomain = CloudHost247_tool_ipv6_compatibility(['domain' => 'localhost'], function ($host, $type) use (&$dnsCalls) {
    $dnsCalls++;
    return [];
}, $fetchShouldNotRun);
ipv6_compat_chk('invalid/internal host is rejected before DNS callbacks', isset($badDomain['error']) && $dnsCalls === 0);
ipv6_compat_chk('non-string domain input is rejected without casting arrays',
    isset(CloudHost247_tool_ipv6_compatibility(['domain' => ['example.com']], $dnsAll, $fetchIpv6)['error']));
ipv6_compat_chk('invalid test transport callbacks are rejected',
    isset(CloudHost247_tool_ipv6_compatibility(['domain' => 'example.com'], 'not-callable')['error']));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
