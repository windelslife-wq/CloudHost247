<?php
/**
 * C-3: suspend/terminate cancel discipline + TestConnection.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/eval_entry.php';
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
    function lifeparams(array $over = [])
    {
        return array_merge(['serviceid' => 11, 'serverhostname' => 'panel.example.com', 'serverip' => '', 'serverpassword' => 'KEY'], $over);
    }

    echo "== suspend ==\n";
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '777'];
    $ord = new \SmmProvTest\FakeQuery();
    \Illuminate\Database\Capsule\Manager::$handler = function ($table) use ($ord) {
        return $ord;
    };
    check('suspend returns success', smmprovisioning_SuspendAccount(lifeparams()) === 'success');
    $cancels = array_values(array_filter(\SmmAddon\ApiClient::$constructed, function ($c) {
        return isset($c[0]) && $c[0] === 'cancelOrder';
    }));
    check('panel cancel called with smm id', $cancels === [['cancelOrder', '777']]);
    check('row marked canceled', count($ord->updated) === 1 && $ord->updated[0]['status'] === 'canceled');

    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '777'];
    \SmmAddon\ApiClient::$cancelResult = ['error' => 'already completed'];
    $ord = new \SmmProvTest\FakeQuery();
    \Illuminate\Database\Capsule\Manager::$handler = function ($table) use ($ord) {
        return $ord;
    };
    check('rejected cancel still suspends', smmprovisioning_SuspendAccount(lifeparams()) === 'success');
    check('rejected cancel not marked', $ord->updated === []);
    check('rejection logged', count($GLOBALS['__c3_activity']) === 1 && strpos($GLOBALS['__c3_activity'][0], 'cancel rejected') !== false);

    c3_full_reset();
    \SmmAddon\Helper::$orderByService = null;
    check('suspend without order succeeds silently', smmprovisioning_SuspendAccount(lifeparams()) === 'success');
    check('no panel call without order', \SmmAddon\ApiClient::$constructed === []);

    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '777'];
    check('suspend without endpoint succeeds', smmprovisioning_SuspendAccount(lifeparams(['serverhostname' => '', 'serverip' => ''])) === 'success');
    check('no panel call without endpoint', \SmmAddon\ApiClient::$constructed === []);
    check('missing endpoint logged', count($GLOBALS['__c3_activity']) === 1);

    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '777'];
    \SmmAddon\ApiClient::$throwOn = 'cancelOrder';
    check('panel throw still suspends', smmprovisioning_SuspendAccount(lifeparams()) === 'success');

    echo "== terminate ==\n";
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '888'];
    $ord = new \SmmProvTest\FakeQuery();
    \Illuminate\Database\Capsule\Manager::$handler = function ($table) use ($ord) {
        return $ord;
    };
    check('terminate returns success', smmprovisioning_TerminateAccount(lifeparams()) === 'success');
    check('terminate marks canceled', count($ord->updated) === 1 && $ord->updated[0]['status'] === 'canceled');
    c3_full_reset();
    \SmmAddon\Helper::$orderByService = (object) ['id' => 9, 'smm_order_id' => '888'];
    \SmmAddon\ApiClient::$cancelResult = ['error' => 'nope'];
    $ord = new \SmmProvTest\FakeQuery();
    \Illuminate\Database\Capsule\Manager::$handler = function ($table) use ($ord) {
        return $ord;
    };
    check('terminate rejection not marked', smmprovisioning_TerminateAccount(lifeparams()) === 'success' && $ord->updated === []);

    echo "== test connection ==\n";
    c3_full_reset();
    $res = smmprovisioning_TestConnection(['serverid' => 5, 'serverhostname' => 'panel.example.com', 'serverip' => '', 'serverpassword' => 'KEY']);
    check('good panel reports success', $res === ['success' => true, 'error' => '']);
    check('mapping saved with normalised url', \SmmAddon\Helper::$serverConfigs === [[5, 'https://panel.example.com', 'KEY']]);
    c3_full_reset();
    $res = smmprovisioning_TestConnection(['serverid' => 5, 'serverhostname' => '', 'serverip' => '', 'serverpassword' => '']);
    check('missing endpoint fails', $res === ['success' => false, 'error' => 'API URL and API Key are required.']);
    c3_full_reset();
    \SmmAddon\ApiClient::$balanceResult = ['error' => 'bad key'];
    $res = smmprovisioning_TestConnection(['serverid' => 5, 'serverhostname' => 'panel.example.com', 'serverip' => '', 'serverpassword' => 'KEY']);
    check('bad response fails', $res['success'] === false && strpos($res['error'], 'Invalid API response: ') === 0);
    check('no mapping saved on failure', \SmmAddon\Helper::$serverConfigs === []);
    c3_full_reset();
    \SmmAddon\ApiClient::$throwOn = 'getBalance';
    $res = smmprovisioning_TestConnection(['serverid' => 5, 'serverhostname' => 'panel.example.com', 'serverip' => '', 'serverpassword' => 'KEY']);
    check('panel throw fails', $res === ['success' => false, 'error' => 'Connection failed: panel down']);

    echo "\nlifecycle: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
