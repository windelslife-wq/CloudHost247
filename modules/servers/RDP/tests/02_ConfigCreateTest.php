<?php
/**
 * C-1: ConfigOptions dropdown + CreateAccount (idempotency, mail, storage).
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
    function mkorder($over = [])
    {
        return (object) array_merge(['serviceid' => 'S1', 'ipaddress' => '1.2.3.4', 'username' => 'u1', 'password' => base64_encode('pw1')], $over);
    }

    echo "== config options ==\n";
    rdp_full_reset();
    $_REQUEST['id'] = 6;
    \WHMCS\Module\Server\RDP\Helper::$stocksResult = ['result' => [
        (object) ['name ' => 'Pro', 'id' => 5, 'price ' => '9.99', 'inStockQuantity ' => '12'],
        (object) ['name ' => 'Mini', 'id' => 6, 'price ' => '4.99', 'inStockQuantity ' => '0'],
    ]];
    $opts = RDP_ConfigOptions([]);
    check('dropdown options from stock', $opts['Products']['Options'] === ['5|12' => 'Pro ($9.99 USD)', '6|0' => 'Mini ($4.99 USD)']);
    check('custom fields created for pid', \WHMCS\Module\Server\RDP\Helper::$customFieldPids === [6]);
    check('mail template ensured', \WHMCS\Module\Server\RDP\Helper::$templatesCreated === 1);
    rdp_full_reset();
    $_REQUEST['id'] = 6;
    \WHMCS\Module\Server\RDP\Helper::$stocksResult = null;
    $opts = RDP_ConfigOptions([]);
    check('panel outage yields empty dropdown', $opts['Products']['Options'] === []);
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$stocksResult = ['result' => []];
    $opts = RDP_ConfigOptions([]);
    check('missing request id skips field creation', \WHMCS\Module\Server\RDP\Helper::$customFieldPids === [] && $opts['Products']['Options'] === []);

    echo "== create ==\n";
    $mkparams = function (array $over = []) {
        return array_merge(['pid' => 2, 'serviceid' => 3, 'userid' => 4, 'configoption1' => '7|12', 'customfields' => []], $over);
    };
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => [mkorder()]];
    $hosting = new \RdpTest\FakeQuery();
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hosting) {
        return $hosting;
    };
    check('happy path returns success', RDP_CreateAccount($mkparams()) === 'success');
    check('order placed for plan id', \WHMCS\Module\Server\RDP\Helper::$calls === [['createOrder', '7']]);
    check('service id stored', \WHMCS\Module\Server\RDP\Helper::$savedValues === [[2, 3, 'S1']]);
    check('credentials mail queued', \WHMCS\Module\Server\RDP\Helper::$emails[0]['messagename'] === 'Welcome RDP Credentials Email' && \WHMCS\Module\Server\RDP\Helper::$emails[0]['id'] === 4);
    $customvars = unserialize(base64_decode(\WHMCS\Module\Server\RDP\Helper::$emails[0]['customvars']));
    check('mail carries decoded credentials', $customvars === ['RDP_ip' => '1.2.3.4', 'RDP_username' => 'u1', 'RDP_password' => 'pw1']);
    check('hosting credentials encrypted', $hosting->updated === [['username' => 'u1', 'password' => 'ENC:pw1']]);

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => [mkorder()]];
    check('existing service id short-circuits', RDP_CreateAccount($mkparams(['customfields' => ['serviceid' => 'S9']])) === 'success');
    check('short-circuit places no order', \WHMCS\Module\Server\RDP\Helper::$calls === []);
    check('short-circuit touches no tables', \WHMCS\Database\Capsule::$tables === []);

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => (object) ['error' => 'out of stock']];
    check('panel error surfaces', RDP_CreateAccount($mkparams()) === 'out of stock');
    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = null;
    check('transport failure yields a message', RDP_CreateAccount($mkparams()) === 'Order placement failed: no service id returned by the panel.');

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => [mkorder()]];
    \WHMCS\Module\Server\RDP\Helper::$emailResult = ['result' => 'error'];
    $hosting = new \RdpTest\FakeQuery();
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hosting) {
        return $hosting;
    };
    check('failed mail fails creation', RDP_CreateAccount($mkparams()) === 'error');
    check('failed mail stores nothing', $hosting->updated === []);

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => [mkorder()]];
    \WHMCS\Module\Server\RDP\Helper::$saveResult = false;
    check('failed field save fails creation', RDP_CreateAccount($mkparams()) === 'error');
    check('failed save sends no mail', \WHMCS\Module\Server\RDP\Helper::$emails === []);

    rdp_full_reset();
    \WHMCS\Module\Server\RDP\Helper::$orderResult = ['result' => (object) ['error' => 'bad plan']];
    $p = $mkparams();
    unset($p['configoption1']);
    check('missing plan option cannot crash', RDP_CreateAccount($p) === 'bad plan');

    echo "\nconfigcreate: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
