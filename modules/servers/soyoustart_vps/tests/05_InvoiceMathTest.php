<?php
/**
 * C-4: invoice amounts are server-derived (price x quantity) via localAPI.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
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
    $lib = 'WHMCS\\Module\\Server\\SoyoustartVps\\SoyoustartServer';
    $o = new $lib();

    echo "== additional ip invoice ==\n";
    c4_reset();
    $GLOBALS['__c4_localapi']['handler'] = function ($cmd, $data) {
        return ['result' => 'success', 'invoiceid' => 4242];
    };
    $res = $o->createAdditionalIpInvoice(7, 3, (object) ['additionalIPprice' => 4.5]);
    check('returns localAPI result', $res === ['result' => 'success', 'invoiceid' => 4242]);
    $calls = $GLOBALS['__c4_localapi']['calls'];
    check('one CreateInvoice call', count($calls) === 1 && $calls[0][0] === 'CreateInvoice');
    $args = $calls[0][1];
    check('userid', $args['userid'] === 7);
    check('unpaid status', $args['status'] === 'Unpaid');
    check('amount is price x qty', $args['itemamount1'] == 13.5);
    check('description shows math', $args['itemdescription1'] === 'Additional ip 4.5*3 = 13.5');

    echo "== generic invoice ==\n";
    c4_reset();
    $res = $o->createInvoice(9, 12.0, 'Ordering automated backup');
    check('default stub success', $res === ['result' => 'success']);
    $args = $GLOBALS['__c4_localapi']['calls'][0][1];
    check('price stringified', $args['itemamount1'] === '12');
    check('description passthrough', $args['itemdescription1'] === 'Ordering automated backup');
    $GLOBALS['__c4_localapi']['handler'] = function () {
        throw new \Exception('api down');
    };
    check('localAPI failure returns null (vendor swallow)', $o->createInvoice(9, 1, 'x') === null);

    echo "\ninvoice math: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
