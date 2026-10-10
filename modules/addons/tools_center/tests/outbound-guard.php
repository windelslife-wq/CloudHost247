<?php
/**
 * SSRF guard tests for external-api/outbound.php (run with php-wasm, see
 * tests/run-php-guard.mjs). Offline: only IP literals and rejected inputs are
 * used, so no DNS lookup or network connection is made.
 */
define('TOOLS_API', true);
require __DIR__ . '/../external-api/outbound.php';

$pass = 0; $fail = 0;
function chk($label, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function throws_msg($fn) {
    try { $fn(); return null; } catch (Exception $e) { return $e->getMessage(); }
}

echo "== tc_is_public_ip ==\n";
foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '::ffff:8.8.8.8'] as $ip) {
    chk("public $ip", tc_is_public_ip($ip) === true);
}
foreach ([
    '127.0.0.1', '127.1.2.3', '10.0.0.1', '172.16.5.5', '172.31.255.255', '192.168.1.1',
    '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '240.0.0.1', '192.0.2.7',
    '198.18.0.1', '::1', '::', 'fe80::1', 'fc00::1', 'fd00::5', '2001:db8::1', 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:10.1.2.3', '64:ff9b::808:808', 'not-an-ip', '',
] as $ip) {
    chk("blocked $ip", tc_is_public_ip($ip) === false);
}

echo "== tc_validate_outbound_url ==\n";
foreach ([
    'file:///etc/passwd', 'gopher://8.8.8.8/', 'ftp://8.8.8.8/', 'http://user:pw@8.8.8.8/',
    'http://8.8.8.8:8080/', 'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/',
    'http://localhost/', 'http://[::1]/', 'http://10.0.0.5/admin', 'http://intranet/',
    'http://bad host/', 'not a url', 'https://192.168.0.1:443/',
] as $u) {
    chk("rejects $u", throws_msg(function () use ($u) { tc_validate_outbound_url($u); }) !== null);
}
$v = tc_validate_outbound_url('https://8.8.8.8/path');
chk('https literal ok, port 443', $v['port'] === 443 && $v['ip'] === '8.8.8.8' && $v['scheme'] === 'https');
$v = tc_validate_outbound_url('http://8.8.8.8/');
chk('http literal ok, port 80', $v['port'] === 80);
$v = tc_validate_outbound_url('http://8.8.8.8:80/');
chk('explicit port 80 ok', $v['port'] === 80);

echo "== tc_resolve_public_ips ==\n";
chk('literal public ok', tc_resolve_public_ips('8.8.8.8') === ['8.8.8.8']);
chk('literal private refused', throws_msg(function () { tc_resolve_public_ips('10.0.0.1'); }) !== null);
chk('localhost refused', throws_msg(function () { tc_resolve_public_ips('localhost'); }) !== null);
chk('single-label host refused', throws_msg(function () { tc_resolve_public_ips('metadata'); }) !== null);

echo "== tc_fetch_url (guard runs before any connection) ==\n";
chk('fetch refuses private target', throws_msg(function () { tc_fetch_url('http://10.0.0.1/'); }) !== null);
chk('fetch refuses file scheme', throws_msg(function () { tc_fetch_url('file:///etc/hosts'); }) !== null);

echo "== tc_open_public_socket ==\n";
chk('socket refuses loopback', throws_msg(function () { tc_open_public_socket('127.0.0.1', 25, 3); }) !== null);
chk('socket refuses bad port', throws_msg(function () { tc_open_public_socket('8.8.8.8', 0, 3); }) !== null);

echo "== tc_resolve_redirect ==\n";
chk('absolute kept', tc_resolve_redirect('https://8.8.8.8/a', 'https://1.1.1.1/b') === 'https://1.1.1.1/b');
chk('root-relative', tc_resolve_redirect('https://8.8.8.8/a/b', '/c') === 'https://8.8.8.8/c');
chk('path-relative', tc_resolve_redirect('https://8.8.8.8/a/b', 'c') === 'https://8.8.8.8/a/c');
chk('protocol-relative', tc_resolve_redirect('https://8.8.8.8/a', '//1.1.1.1/x') === 'https://1.1.1.1/x');
chk('redirect to private is rejected by the next hop', throws_msg(function () {
    tc_validate_outbound_url(tc_resolve_redirect('https://8.8.8.8/a', 'http://169.254.169.254/'));
}) !== null);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
