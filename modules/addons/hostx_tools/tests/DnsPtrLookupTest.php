<?php
namespace WHMCS\Module\Addon\HostXTools;

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

$GLOBALS['hostx_test_dns_calls'] = [];
function dns_get_record($domain, $type)
{
    $GLOBALS['hostx_test_dns_calls'][] = [$domain, $type];
    return [];
}

class CacheManager
{
    public function get($key) { return false; }
    public function set($key, $value) { return true; }
}

require __DIR__ . '/../includes/SecurityManager.php';
require __DIR__ . '/../includes/DnsTool.php';

$pass = 0;
$fail = 0;
function ptr_lookup_check($label, $ok)
{
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL {$label}\n"; }
}

$tool = new DnsTool();
$publicResult = $tool->lookup('8.8.4.1', 'PTR');
ptr_lookup_check('public IPv4 PTR lookup succeeds with a normalized reverse owner',
    !empty($publicResult['success']) && $publicResult['domain'] === '1.4.8.8.in-addr.arpa');
ptr_lookup_check('resolver receives only the normalized public PTR owner',
    count($GLOBALS['hostx_test_dns_calls']) === 1
    && $GLOBALS['hostx_test_dns_calls'][0][0] === '1.4.8.8.in-addr.arpa'
    && $GLOBALS['hostx_test_dns_calls'][0][1] === DNS_PTR);

$privateResult = $tool->lookup('10.0.0.1', 'PTR');
ptr_lookup_check('private PTR input fails validation before resolver access',
    empty($privateResult['success']) && $privateResult['source'] === 'validation'
    && count($GLOBALS['hostx_test_dns_calls']) === 1);

$privateReverseResult = $tool->lookup('1.0.0.10.in-addr.arpa', 'ALL');
ptr_lookup_check('private reverse owner with ALL fails before resolver access',
    empty($privateReverseResult['success']) && $privateReverseResult['source'] === 'validation'
    && count($GLOBALS['hostx_test_dns_calls']) === 1);

$publicReverseAllResult = $tool->lookup('8.8.8.8.in-addr.arpa', 'ALL');
ptr_lookup_check('reverse owners are limited to explicit PTR requests',
    empty($publicReverseAllResult['success']) && $publicReverseAllResult['source'] === 'validation'
    && count($GLOBALS['hostx_test_dns_calls']) === 1);

$ipAsDomain = $tool->lookup('8.8.8.8', 'A');
ptr_lookup_check('public IP literal remains invalid for non-PTR lookups',
    empty($ipAsDomain['success']) && $ipAsDomain['source'] === 'validation'
    && count($GLOBALS['hostx_test_dns_calls']) === 1);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail ? 1 : 0);
