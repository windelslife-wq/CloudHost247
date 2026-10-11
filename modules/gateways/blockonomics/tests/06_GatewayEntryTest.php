<?php
/**
 * C-7: gateway entry (link/config) and the admin gate. Prints FAIL=<n>.
 */
namespace {
    use Blockonomics\TestBlockonomics;
    use CloudHost247\Blockonomics\Bridge;
    use WHMCS\Authentication\CurrentUser;
    use WHMCS\Database\Capsule;

    require_once __DIR__ . '/bootstrap.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
    }

    // Load the REAL gateway entry file with its requires stripped (same
    // approach as the class file in bootstrap.php).
    $entryFile = __DIR__ . '/../../blockonomics.php';
    $entryCode = (string) file_get_contents($entryFile);
    foreach ([
        "require_once dirname(__FILE__) . '/blockonomics/blockonomics.php';",
        "require_once dirname(__FILE__) . '/blockonomics/cloudhost247/autoload.php';",
    ] as $req) {
        if (substr_count($entryCode, $req) !== 1) {
            echo "FAIL: entry strip anchor changed: $req\nFAIL=1\n";
            exit(1);
        }
        $entryCode = str_replace($req, '', $entryCode);
    }
    $entryCode = (string) preg_replace('/^<\\?php\\s/', '', $entryCode);
    eval($entryCode);

    function linkParams()
    {
        return ['invoiceid' => 9, 'amount' => '25.00', 'currency' => 'USD', 'basecurrencyamount' => '25.00', 'langpaynow' => 'Pay Now'];
    }

    echo "== payment link ==\n";
    bn_reset_all();
    BnTestState::$gatewayParams['bchEnabled'] = 'on';
    $form = blockonomics_link(linkParams());
    check('link posts to the payment page', strpos($form, 'https://shop.test/modules/gateways/blockonomics/payment.php') !== false);
    check('link carries the picker hash', strpos($form, 'name="select_crypto"') !== false && strpos($form, 'Pay Now') !== false);
    check('checkout seeding attempted', Bridge::$seeded === 1);
    bn_reset_all();
    Bridge::$state = [];
    $closed = blockonomics_link(linkParams());
    check('disabled checkout offers no form', strpos($closed, '<form') === false && strpos($closed, 'currently unavailable') !== false);
    bn_reset_all();
    Bridge::$throwOnState = true;
    $failed = blockonomics_link(linkParams());
    check('governance failure fails closed', strpos($failed, 'currently unavailable') !== false);

    echo "== gateway config ==\n";
    bn_reset_all();
    require __DIR__ . '/../lang/english.php';
    $cfg = blockonomics_config();
    check('friendly name', $cfg['FriendlyName']['Value'] === 'Blockonomics');
    check('api key field', $cfg['ApiKey']['Type'] === 'text');
    check('btc default on', $cfg['btcEnabled']['Default'] === true && $cfg['btcEnabled']['Type'] === 'yesno');
    check('time period options', isset($cfg['TimePeriod']['Options']['30']));
    check('config ensures schema', Capsule::$schemaWrites === [['create', 'blockonomics_orders']]);
    check('admin footer hook registered', BnTestState::$hooks !== [] && BnTestState::$hooks[0][0] === 'AdminAreaFooterOutput');

    echo "== admin gate ==\n";
    bn_reset_all();
    $b = new TestBlockonomics();
    global $CONFIG;
    $CONFIG = ['Version' => '8.5'];
    CurrentUser::$isAdmin = true;
    check('modern admin passes', $b->checkAdmin() === true);
    $CONFIG = ['Version' => '7.10'];
    CurrentUser::$isAdmin = false;
    $_SESSION['adminid'] = 3;
    check('legacy session admin passes', $b->checkAdmin() === true);

    echo "\ngateway entry: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
