<?php
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Security.php';
$S = 'CloudHost247ToolsSecurity';

$pass=0; $fail=0;
function chk($label, $got, $want) {
    global $pass,$fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label: got ".var_export($got,true)." want ".var_export($want,true)."\n"; }
}

echo "== blockedIpReason: must BLOCK ==\n";
$mustBlock = [
 '127.0.0.1','127.1.2.3','0.0.0.0','10.0.0.1','10.255.255.254','172.16.0.1','172.31.255.255',
 '192.168.1.1','192.168.0.0','169.254.169.254','169.254.170.2','100.64.0.1','192.0.2.5',
 '198.18.0.1','198.51.100.7','203.0.113.9','224.0.0.1','239.255.255.250','255.255.255.255',
 '240.0.0.1','192.88.99.1','192.0.0.1',
 '::1','::','fe80::1','fc00::1','fd12:3456:789a::1','ff02::1','2001:db8::1','::ffff:127.0.0.1',
 '::ffff:192.168.1.1','64:ff9b::1','100::1',
];
foreach ($mustBlock as $ip) {
    $r = CloudHost247ToolsSecurity::blockedIpReason($ip);
    if ($r === false) { echo "  FAIL not blocked: $ip\n"; $GLOBALS['fail']++; } else { $GLOBALS['pass']++; }
}

echo "== blockedIpReason: must ALLOW ==\n";
$mustAllow = ['8.8.8.8','1.1.1.1','93.184.216.34','172.15.0.1','172.32.0.1','11.0.0.1',
 '100.63.255.255','100.128.0.1','9.255.255.255','126.255.255.255','128.0.0.1',
 '2606:4700:4700::1111','2001:4860:4860::8888','2001:db9::1','2a00:1450:4001::1'];
foreach ($mustAllow as $ip) {
    $r = CloudHost247ToolsSecurity::blockedIpReason($ip);
    if ($r !== false) { echo "  FAIL wrongly blocked: $ip ($r)\n"; $GLOBALS['fail']++; } else { $GLOBALS['pass']++; }
}

echo "== normaliseHostname ==\n";
chk('plain','example.com', CloudHost247ToolsSecurity::normaliseHostname('example.com'));
chk('upper','example.com', CloudHost247ToolsSecurity::normaliseHostname('EXAMPLE.COM'));
chk('trailing dot','example.com', CloudHost247ToolsSecurity::normaliseHostname('example.com.'));
chk('scheme','example.com', CloudHost247ToolsSecurity::normaliseHostname('https://example.com/path?x=1'));
chk('port','example.com', CloudHost247ToolsSecurity::normaliseHostname('example.com:8080'));
chk('userinfo','evil.com', CloudHost247ToolsSecurity::normaliseHostname('http://user:pass@evil.com/'));
chk('sub','a.b.example.co.uk', CloudHost247ToolsSecurity::normaliseHostname('a.b.example.co.uk'));
chk('ipv4','8.8.8.8', CloudHost247ToolsSecurity::normaliseHostname('8.8.8.8'));
chk('ipv6 bracket','2001:db8::1', CloudHost247ToolsSecurity::normaliseHostname('[2001:db8::1]'));
chk('empty',false, CloudHost247ToolsSecurity::normaliseHostname(''));
chk('space',false, CloudHost247ToolsSecurity::normaliseHostname('not a host'));
chk('underscore',false, CloudHost247ToolsSecurity::normaliseHostname('bad_host.com'));
chk('tld only',false, CloudHost247ToolsSecurity::normaliseHostname('com'));
chk('idn','xn--bcher-kva.de', CloudHost247ToolsSecurity::normaliseHostname('bücher.de'));

echo "== assertPublicHost rejects internal names ==\n";
foreach (['localhost','metadata.google.internal','foo.local','box.internal','thing.lan','x.corp','y.onion','app.test'] as $h) {
    try { CloudHost247ToolsSecurity::assertPublicHost($h); echo "  FAIL allowed: $h\n"; $fail++; }
    catch (CloudHost247ToolsSecurityException $e) { $pass++; }
}
echo "== assertPublicHost rejects literal private IPs ==\n";
foreach (['127.0.0.1','169.254.169.254','192.168.1.1','10.1.2.3','::1'] as $h) {
    try { CloudHost247ToolsSecurity::assertPublicHost($h); echo "  FAIL allowed: $h\n"; $fail++; }
    catch (CloudHost247ToolsSecurityException $e) { $pass++; }
}
echo "== assertPublicHost allows public literal IP ==\n";
try { $r = CloudHost247ToolsSecurity::assertPublicHost('8.8.8.8'); chk('8.8.8.8 ips', ['8.8.8.8'], $r['ips']); }
catch (Exception $e) { echo "  FAIL ".$e->getMessage()."\n"; $fail++; }

echo "== assertPublicUrl ==\n";
foreach ([
  'http://127.0.0.1/','https://localhost/','http://169.254.169.254/latest/meta-data/',
  'file:///etc/passwd','gopher://evil.com/','ftp://x.com/','http://user:pw@example.com/',
  'http://example.com:22/','http://[::1]/','dict://localhost:11211/',
] as $u) {
    try { CloudHost247ToolsSecurity::assertPublicUrl($u); echo "  FAIL allowed: $u\n"; $fail++; }
    catch (CloudHost247ToolsSecurityException $e) { $pass++; }
}

echo "== validateProbePort ==\n";
try { chk('80', 80, CloudHost247ToolsSecurity::validateProbePort('80')); } catch (Exception $e){ $fail++; echo "  FAIL 80\n"; }
foreach ([1,7,19,31337,65535,0,70000] as $p) {
    try { CloudHost247ToolsSecurity::validateProbePort($p); echo "  FAIL allowed port $p\n"; $fail++; }
    catch (CloudHost247ToolsSecurityException $e) { $pass++; }
}

echo "== escaping ==\n";
chk('xss', '&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', CloudHost247ToolsSecurity::e("<script>alert('x')</script>"));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
