<?php
/**
 * C-7: money math — conversion, credit base, display formatting, and the
 * exact Policy inputs the callback/poller rely on. Prints FAIL=<n>.
 */
namespace {
    use Blockonomics\TestBlockonomics;
    use CloudHost247\Blockonomics\Policy;

    require_once __DIR__ . '/bootstrap.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
    }
    function moneyReady()
    {
        bn_reset_all();
        TestBlockonomics::$langPath = __DIR__ . '/../lang/english.php';
        return new TestBlockonomics();
    }

    echo "== credit base ==\n";
    $b = moneyReady();
    check('converted invoice credits its base', $b->convertPercentPaidToInvoiceCurrency(['basecurrencyamount' => 50.00, 'value' => 45.00], 100.0) === 50.0);
    check('plain invoice credits its value', $b->convertPercentPaidToInvoiceCurrency(['basecurrencyamount' => 0, 'value' => 200.00], 50.0) === 100.0);
    check('partial credit rounds to cents', $b->convertPercentPaidToInvoiceCurrency(['basecurrencyamount' => 0, 'value' => 100.00], 100 / 3) === 33.33);
    check('zero percent credits zero', $b->convertPercentPaidToInvoiceCurrency(['basecurrencyamount' => 0, 'value' => 100.00], 0.0) === 0.0);

    echo "== fiat to crypto ==\n";
    $b = moneyReady();
    TestBlockonomics::$priceContents = '{"price":50000}';
    check('btc conversion', $b->convertFiatToBlockonomicsCurrency(100.0, 'USD', 'btc') === 200000);
    check('btc price endpoint', strpos(TestBlockonomics::$calls[0][1], 'www.blockonomics.co/api/price?currency=USD') !== false);
    BnTestState::$gatewayParams['Margin'] = '2';
    $margined = $b->convertFiatToBlockonomicsCurrency(100.0, 'USD', 'btc');
    check('margin widens the ask', abs($margined - 204000) <= 1 && $margined > 200000);
    TestBlockonomics::$priceContents = '{"USD":2.0}';
    BnTestState::$gatewayParams['Margin'] = '0';
    check('usdt uses 6 decimals', $b->convertFiatToBlockonomicsCurrency(100.0, 'USD', 'usdt') === 50000000);
    check('usdt price endpoint', strpos(TestBlockonomics::$calls[2][1], 'min-api.cryptocompare.com/data/price?fsym=usdt&tsyms=USD') !== false);
    TestBlockonomics::$priceContents = '{"price":500}';
    $b->convertFiatToBlockonomicsCurrency(100.0, 'USD', 'bch');
    check('bch routes to its subdomain', strpos(TestBlockonomics::$calls[3][1], 'bch.blockonomics.co/api/price') !== false);

    echo "== display formatting ==\n";
    $b = moneyReady();
    check('dust shows 8 places', $b->fix_displaying_small_values(5000, 'btc') === '0.00005');
    check('normal amounts stay numeric', $b->fix_displaying_small_values(100000, 'btc') === 0.001);
    check('usdt shows 2 places', $b->fix_displaying_small_values(1500000, 'usdt') === '1.50');
    check('rate string has no thousands sep', $b->get_crypto_rate_from_params(100.0, 20000, 'btc') === '500000.00');
    check('payment uri shape', $b->get_payment_uri('bitcoin', 'tb1x', 0.001) === 'bitcoin:tb1x?amount=0.001');

    echo "== amount classification as the callback uses it ==\n";
    $a = Policy::classifyAmount(1000, '1000', 0.0);
    check('exact pays in full', $a['class'] === Policy::AMT_EXACT && $a['percent_paid'] === 100.0);
    $a = Policy::classifyAmount(1000, '990', 2.0);
    check('slack underpayment pays in full by policy', $a['class'] === Policy::AMT_UNDER_SLACK && $a['percent_paid'] === 100.0);
    $a = Policy::classifyAmount(1000, '500', 2.0);
    check('true underpayment stays proportional', $a['class'] === Policy::AMT_UNDER && $a['percent_paid'] === 50.0);
    $a = Policy::classifyAmount(1000, '1500', 0.0);
    check('overpayment credits the excess', $a['class'] === Policy::AMT_OVER && $a['percent_paid'] === 150.0);
    $a = Policy::classifyAmount(0, '100', 0.0);
    check('zero expectation is invalid, never a divide-by-zero', $a['class'] === Policy::AMT_INVALID && $a['percent_paid'] === 0.0);
    $a = Policy::classifyAmount(1000, '-5', 0.0);
    check('negative receipt clamps to zero', $a['percent_paid'] == 0 && $a['class'] === Policy::AMT_UNDER);

    echo "== status and expiry as the callback uses them ==\n";
    check('confirmed maps paid', Policy::mapStatus(2, 2) === Policy::ST_PAID);
    check('expired maps expired', Policy::mapStatus(Policy::ORD_EXPIRED, 2) === Policy::ST_EXPIRED);
    check('cancelled maps cancelled', Policy::mapStatus(Policy::ORD_CANCELLED, 2) === Policy::ST_CANCELLED);
    check('waiting maps pending', Policy::mapStatus(Policy::ORD_WAITING, 2) === Policy::ST_PENDING);
    check('partial maps confirming', Policy::mapStatus(1, 2) === Policy::ST_CONFIRMING);
    check('old waiting order expired', Policy::isExpired(time() - 700, 10) === true);
    check('fresh order live', Policy::isExpired(time() - 100, 10) === false);
    check('credit needs all three clear', Policy::creditAllowed(false, false, false) === true);
    check('recorded tx blocks credit', Policy::creditAllowed(true, false, false) === false);
    check('settled invoice blocks credit', Policy::creditAllowed(false, true, false) === false);
    check('terminal order blocks credit', Policy::creditAllowed(false, false, true) === false);

    echo "== checkout params ==\n";
    $b = moneyReady();
    BnTestState::$gatewayParams['bchEnabled'] = 'on';
    $p = ['invoiceid' => 9, 'amount' => '25.00', 'currency' => 'USD', 'basecurrencyamount' => '25.00'];
    $two = $b->get_order_checkout_params($p);
    check('two currencies offer a picker', isset($two['select_crypto']) && count($two) === 1);
    check('picker hash decrypts to the invoice', $b->getOrderIdByHash($two['select_crypto']) === 9);
    BnTestState::$gatewayParams['bchEnabled'] = '';
    $one = $b->get_order_checkout_params($p);
    check('one currency goes direct', $one['crypto'] === 'btc' && $b->getOrderIdByHash($one['show_order']) === 9);
    BnTestState::$gatewayParams['btcEnabled'] = '';
    check('no currency signals empty', $b->get_order_checkout_params($p) === ['crypto' => 'empty']);

    echo "\nmoney math: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
