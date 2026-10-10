<?php
/**
 * Regression test for H-1: the legacy client-IP helper must not trust
 * client-supplied forwarded headers unless REMOTE_ADDR is a configured proxy.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ipchk($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
}

echo "== spoofed headers ignored without trusted proxy ==\n";
putenv('CLOUDHOST247_TRUSTED_PROXIES');
$_SERVER = ['REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.2'];
ipchk('xff ignored', CloudHost247_tools_get_client_ip(), '198.51.100.10');

// Three spoofed requests must map to one client bucket.
$seen = [];
foreach (['203.0.113.5', '203.0.113.6', '203.0.113.7'] as $fake) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $fake;
    $seen[CloudHost247_tools_get_client_ip()] = true;
}
ipchk('spoofing yields one key', count($seen), 1);

echo "== trusted proxy honoured ==\n";
putenv('CLOUDHOST247_TRUSTED_PROXIES=10.0.0.5, 10.0.0.6');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.5'];
ipchk('trusted xff first hop', CloudHost247_tools_get_client_ip(), '203.0.113.9');

echo "== untrusted peer with proxy list configured ==\n";
$_SERVER = ['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
ipchk('untrusted peer', CloudHost247_tools_get_client_ip(), '198.51.100.10');

echo "== malformed values ==\n";
putenv('CLOUDHOST247_TRUSTED_PROXIES=10.0.0.5');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'];
ipchk('bad xff falls back to peer', CloudHost247_tools_get_client_ip(), '10.0.0.5');
$_SERVER = [];
ipchk('no REMOTE_ADDR', CloudHost247_tools_get_client_ip(), '0.0.0.0');

echo "\nPASS=$pass FAIL=$fail\n";
