<?php
/**
 * C-1: Renew + TestConnection shapes.
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

    echo "== renew ==\n";
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$renewResult = ['result' => (object) ['message' => 'renewed']];
    check('renewal confirmed', RDP_Renew(['customfields' => ['serviceid' => 'S1']]) === 'success');
    check('renewal hits the panel id', \WHMCS\Module\Server\RDP\Helper::$calls === [['renewService', 'S1']]);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$renewResult = ['result' => (object) ['error' => 'expired']];
    check('renewal error surfaces', RDP_Renew(['customfields' => ['serviceid' => 'S1']]) === 'expired');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$renewResult = null;
    check('renewal outage yields a message', RDP_Renew(['customfields' => ['serviceid' => 'S1']]) === 'Service renewal failed: no confirmation returned by the panel.');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$renewResult = ['result' => (object) ['message' => '']];
    check('empty confirmation is not success', RDP_Renew(['customfields' => ['serviceid' => 'S1']]) === 'Service renewal failed: no confirmation returned by the panel.');
    rdp_full_reset();
    check('missing service id fails fast', RDP_Renew(['customfields' => []]) === 'Service ID is not set for this service.');
    check('fail-fast calls no panel', \WHMCS\Module\Server\RDP\Helper::$calls === []);

    echo "== test connection ==\n";
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$testResult = ['httpcode' => 200, 'result' => []];
    check('good panel reports success', RDP_TestConnection([]) === ['success' => true, 'error' => '']);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$testResult = ['httpcode' => 500, 'result' => null];
    $res = RDP_TestConnection([]);
    check('bad http fails', $res['success'] === false && $res['error'] === 'Connection failed: the panel did not return HTTP 200.');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$testResult = null;
    $res = RDP_TestConnection([]);
    check('outage fails closed', $res['success'] === false && $res['error'] === 'Connection failed: the panel did not return HTTP 200.');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$throwOn = 'testConnection';
    $res = RDP_TestConnection([]);
    check('exception fails with message', $res === ['success' => false, 'error' => 'panel down']);

    echo "\nlifecycle: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
