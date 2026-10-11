<?php
/**
 * C-1: ClientArea vars + admin-tab HTML (escaping, guards, catch shapes).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/doubles.php';
    require_once dirname(__DIR__) . '/RDP.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) {
            $pass++;
        } else {
            $fail++;
            echo "FAIL: $label\n";
        }
    }
    function mkdetail($over = [])
    {
        return ['httpcode' => 200, 'result' => (object) array_merge(
            ['password' => 'ZW5j', 'hostname' => 'h1', 'username' => 'u1', 'ipaddress' => '1.2.3.4', 'signupdate' => '2026-01-01', 'duedate' => '2027-01-01'],
            $over
        )];
    }

    echo "== client area ==\n";
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = mkdetail();
    $res = RDP_ClientArea(['customfields' => ['serviceid' => 'S1']]);
    check('templatefile', $res['templatefile'] === 'templates/overview.tpl');
    check('vars mapped', $res['vars']['hostname'] === 'h1' && $res['vars']['username'] === 'u1' && $res['vars']['encodedPassword'] === 'ZW5j');
    check('ipaddress reads the ip field', $res['vars']['ipaddress'] === '1.2.3.4');
    check('dates mapped', $res['vars']['signupdate'] === '2026-01-01' && $res['vars']['duedate'] === '2027-01-01');
    check('status + serviceid', $res['vars']['status'] === 200 && $res['vars']['serviceid'] === 'S1');
    check('assets link', $res['vars']['assets_link'] === 'https://shop.test/modules/servers/RDP/assets/');

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = ['httpcode' => 500, 'result' => null];
    $res = RDP_ClientArea(['customfields' => ['serviceid' => 'S1']]);
    check('non-200 yields empty vars', $res['vars']['hostname'] === '' && $res['vars']['status'] === '' && $res['vars']['serviceid'] === 'S1');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = null;
    $res = RDP_ClientArea(['customfields' => ['serviceid' => 'S1']]);
    check('outage yields empty vars', $res['templatefile'] === 'templates/overview.tpl' && $res['vars']['hostname'] === '');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = ['httpcode' => 200, 'result' => ['not' => 'object']];
    $res = RDP_ClientArea(['customfields' => ['serviceid' => 'S1']]);
    check('non-object result yields empty vars', $res['vars']['hostname'] === '' && $res['vars']['status'] === '');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = mkdetail();
    $res = RDP_ClientArea(['customfields' => []]);
    check('missing service id still renders', $res['vars']['serviceid'] === '' && \WHMCS\Module\Server\RDP\Helper::$calls === [['getServiceDetail', '']]);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$throwOn = 'getServiceDetail';
    $res = RDP_ClientArea(['customfields' => ['serviceid' => 'S1']]);
    check('exception falls back to overview', $res['templatefile'] === 'templates/overview.tpl' && $res['vars']['status'] === 0 && $res['vars']['serviceid'] === '');
    check('fallback shape is renderable', isset($res['vars']['hostname'], $res['vars']['assets_link'], $res['vars']['encodedPassword']));

    echo "== admin tab ==\n";
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = mkdetail(['hostname' => '<script>alert(1)</script>', 'username' => '<b>u</b>']);
    $res = RDP_AdminServicesTabFields(['customfields' => ['serviceid' => 'S1']]);
    $html = $res['RDP Information'];
    check('tab key', isset($res['RDP Information']));
    check('panel markup escaped', strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, '&lt;b&gt;u&lt;/b&gt;') !== false);
    check('no raw panel markup', strpos($html, '<script>alert(1)</script>') === false);
    check('password stays an attribute toggle', strpos($html, 'data-password="ZW5j"') !== false);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = mkdetail();
    $res = RDP_AdminServicesTabFields(['customfields' => ['serviceid' => '']]);
    check('empty service id warns', strpos($res['RDP Information'], 'Please provide service id') !== false);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = ['httpcode' => 500, 'result' => null];
    $res = RDP_AdminServicesTabFields(['customfields' => ['serviceid' => 'S1']]);
    check('non-200 warns', strpos($res['RDP Information'], 'Something went wrong') !== false);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$detailResult = null;
    $res = RDP_AdminServicesTabFields(['customfields' => ['serviceid' => 'S1']]);
    check('outage warns', strpos($res['RDP Information'], 'Something went wrong') !== false);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$throwOn = 'getServiceDetail';
    $res = RDP_AdminServicesTabFields(['customfields' => ['serviceid' => 'S1']]);
    check('exception keeps tab shape', isset($res['RDP Information']) && strpos($res['RDP Information'], 'Something went wrong') !== false);
    check('exception is logged', count($GLOBALS['__rdp_modulelog']) === 1);

    echo "\nclientarea: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
