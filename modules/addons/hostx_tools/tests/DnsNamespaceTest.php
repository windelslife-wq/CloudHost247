<?php
/** Local and special-use DNS names must be rejected before resolver lookups. */
define('WHMCS', true);
require __DIR__ . '/../includes/SecurityManager.php';
use WHMCS\Module\Addon\HostXTools\SecurityManager;

$pass = 0;
$fail = 0;
function dns_namespace_check($label, $ok)
{
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL {$label}\n"; }
}

foreach ([
    'localhost', 'metadata.internal', 'printer.local', 'service.lan', 'host.home.arpa',
    'mail.example', 'missing.invalid', 'service.onion', 'metadata.goog', '127.0.0.1',
] as $domain) {
    dns_namespace_check("rejects {$domain}", SecurityManager::sanitizeDomain($domain) === false);
}
dns_namespace_check('ordinary public-looking domain remains accepted',
    SecurityManager::sanitizeDomain('example.com') === 'example.com');
dns_namespace_check('public IP is not accepted as a general DNS domain',
    SecurityManager::sanitizeDomain('8.8.8.8') === false);
dns_namespace_check('public IPv4 PTR input becomes its reverse owner',
    SecurityManager::sanitizeDomain('8.8.4.1', true) === '1.4.8.8.in-addr.arpa');
dns_namespace_check('private IPv4 PTR input is rejected',
    SecurityManager::sanitizeDomain('10.0.0.1', true) === false);
dns_namespace_check('reserved IPv4 PTR input is rejected',
    SecurityManager::sanitizeDomain('192.0.2.1', true) === false);
dns_namespace_check('private IPv4 reverse owner is rejected',
    SecurityManager::sanitizeDomain('1.0.0.10.in-addr.arpa', true) === false);
dns_namespace_check('public IPv4 reverse owner is preserved for PTR',
    SecurityManager::sanitizeDomain('8.8.8.8.in-addr.arpa', true) === '8.8.8.8.in-addr.arpa');
dns_namespace_check('reverse owner is rejected for non-PTR DNS queries',
    SecurityManager::sanitizeDomain('8.8.8.8.in-addr.arpa') === false);
dns_namespace_check('private reverse owner is rejected for non-PTR DNS queries',
    SecurityManager::sanitizeDomain('1.0.0.10.in-addr.arpa') === false);
dns_namespace_check('partial IPv4 reverse zone is rejected',
    SecurityManager::sanitizeDomain('10.in-addr.arpa', true) === false);
dns_namespace_check('public IPv6 PTR input becomes its reverse owner',
    SecurityManager::sanitizeDomain('2001:4860:4860::8888', true)
        === '8.8.8.8.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.6.8.4.0.6.8.4.1.0.0.2.ip6.arpa');
$publicIpv6Owner = SecurityManager::sanitizeDomain('2001:4860:4860::8888', true);
dns_namespace_check('public IPv6 reverse owner is accepted',
    $publicIpv6Owner !== false && SecurityManager::sanitizeDomain($publicIpv6Owner, true) === $publicIpv6Owner);
dns_namespace_check('IPv6 loopback PTR input is rejected',
    SecurityManager::sanitizeDomain('::1', true) === false);
dns_namespace_check('IPv6 unique-local PTR input is rejected',
    SecurityManager::sanitizeDomain('fc00::1', true) === false);
dns_namespace_check('IPv6 loopback reverse owner is rejected',
    SecurityManager::sanitizeDomain('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa', true) === false);
dns_namespace_check('malformed reverse owner is rejected',
    SecurityManager::sanitizeDomain('1.0.0.in-addr.arpa', true) === false);
dns_namespace_check('public host remains eligible for PTR query',
    SecurityManager::sanitizeDomain('example.com', true) === 'example.com');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail ? 1 : 0);
