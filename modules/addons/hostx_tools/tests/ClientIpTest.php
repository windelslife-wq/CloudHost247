<?php
/**
 * H-1 regression: the rate-limit client IP must not come from spoofable headers
 * unless REMOTE_ADDR is a configured trusted proxy.
 */
define('WHMCS', true);
require __DIR__ . '/../includes/SecurityManager.php';
use WHMCS\Module\Addon\HostXTools\SecurityManager;

$pass = 0; $fail = 0;
function ipchk($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
}

putenv('CLOUDHOST247_TRUSTED_PROXIES');
echo "== spoofed headers ignored without trusted proxy ==\n";
$_SERVER = ['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.2', 'HTTP_CLIENT_IP' => '203.0.113.3'];
ipchk('headers ignored', SecurityManager::getClientIp(), '198.51.100.10');
$seen = [];
foreach (['203.0.113.5', '203.0.113.6', '203.0.113.7'] as $fake) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $fake;
    $seen[SecurityManager::getClientIp()] = true;
}
ipchk('spoofing yields one key', count($seen), 1);

echo "== trusted proxy honoured ==\n";
putenv('CLOUDHOST247_TRUSTED_PROXIES=10.0.0.5');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.5'];
ipchk('trusted forwarded first hop', SecurityManager::getClientIp(), '203.0.113.9');
$_SERVER = ['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'];
ipchk('untrusted peer keeps own address', SecurityManager::getClientIp(), '198.51.100.10');

echo "== fallbacks ==\n";
$_SERVER = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'junk'];
ipchk('bad forwarded value falls back to peer', SecurityManager::getClientIp(), '10.0.0.5');
$_SERVER = [];
ipchk('no REMOTE_ADDR gives loopback fallback', SecurityManager::getClientIp(), '127.0.0.1');

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
