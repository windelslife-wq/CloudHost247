<?php
/**
 * C-7: Test Setup diagnostics, callback-URL management, gateway params.
 * Prints FAIL=<n>.
 */
namespace {
    use Blockonomics\TestBlockonomics;
    use CloudHost247\Blockonomics\Bridge;
    use WHMCS\Database\Capsule;

    require_once __DIR__ . '/bootstrap.php';
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
    }
    function diagReady()
    {
        bn_reset_all();
        TestBlockonomics::$langPath = __DIR__ . '/../lang/english.php';
        return new TestBlockonomics();
    }
    function httpResp($code, $data)
    {
        return (object) ['response_code' => $code, 'data' => $data];
    }

    echo "== test setup ==\n";
    $b = diagReady();
    BnTestState::$gatewayParams['bchEnabled'] = 'on';
    BnTestState::$gatewayParams['usdtEnabled'] = 'on';
    $cbUrl = $b->getCallbackUrl($b->getCallbackSecret());
    TestBlockonomics::$curlResponses = [
        'get_callback=true' => httpResp(200, [(object) ['callback' => $cbUrl, 'address' => 'xpub1']]),
        'update_callback' => httpResp(200, (object) []),
    ];
    TestBlockonomics::$newAddressResponse = (object) ['response_code' => 200, 'address' => 'tb1x', 'message' => ''];
    $res = $b->testSetup();
    check('usdt skipped (no blockonomics api surface)', array_keys($res) === ['btc', 'bch']);
    check('healthy currencies report no error', $res === ['btc' => false, 'bch' => false]);
    $addrCurrencies = array_map(function ($c) { return $c[1]; }, TestBlockonomics::$calls);
    check('no address probe for usdt', !in_array('usdt', $addrCurrencies, true));

    echo "== per-currency test ==\n";
    $b = diagReady();
    BnTestState::$gatewayParams['ApiKey'] = '';
    require TestBlockonomics::$langPath;
    check('empty key diagnosed', $b->test_one_currency('btc') === $_BLOCKLANG['testSetup']['emptyApi']);
    $b = diagReady();
    TestBlockonomics::$curlResponses = ['get_callback=true' => httpResp(401, null)];
    check('401 diagnosed', $b->test_one_currency('btc') === $_BLOCKLANG['testSetup']['incorrectApi']);
    TestBlockonomics::$curlResponses = ['get_callback=true' => httpResp(500, 'Server says no')];
    check('unexpected status surfaces provider body', $b->test_one_currency('btc') === 'Server says no');
    TestBlockonomics::$curlResponses = ['get_callback=true' => (object) []];
    check('missing status diagnosed', $b->test_one_currency('btc') === $_BLOCKLANG['testSetup']['blockedHttps']);
    TestBlockonomics::$curlResponses = ['get_callback=true' => httpResp(200, [])];
    check('no store diagnosed', $b->test_one_currency('btc') === $_BLOCKLANG['testSetup']['addStore']);
    TestBlockonomics::$curlResponses = ['get_callback=true' => httpResp(200, 'garbage-string')];
    check('non-countable body cannot fatal (BN-9)', $b->test_one_currency('btc') === $_BLOCKLANG['testSetup']['addStore']);

    echo "== callback url management ==\n";
    $b = diagReady();
    $cbUrl = $b->getCallbackUrl($b->getCallbackSecret());
    $exact = httpResp(200, [(object) ['callback' => $cbUrl, 'address' => 'xpub1']]);
    check('exact match needs no update', $b->examine_server_callback_urls($exact, 'btc') === '' && TestBlockonomics::$curlCalls === []);
    TestBlockonomics::$curlResponses = ['update_callback' => httpResp(200, (object) [])];
    $partial = httpResp(200, [(object) ['callback' => $cbUrl . '&stale=1', 'address' => 'xpub2']]);
    check('partial match updated', $b->examine_server_callback_urls($partial, 'btc') === '');
    $posted = json_decode((string) TestBlockonomics::$curlCalls[0][1], true);
    check('update posts json callback plus xpub (BN-8)', $posted === ['callback' => $cbUrl, 'xpub' => 'xpub2']);
    check('update hits the btc endpoint', strpos(TestBlockonomics::$curlCalls[0][0], 'www.blockonomics.co/api/update_callback') !== false);
    TestBlockonomics::$curlCalls = [];
    TestBlockonomics::$curlResponses = ['update_callback' => httpResp(200, (object) [])];
    $free = httpResp(200, [(object) ['address' => 'xpub9']]);
    check('xpub without callback adopted', $b->examine_server_callback_urls($free, 'bch') === '');
    check('bch update hits the bch endpoint', strpos(TestBlockonomics::$curlCalls[0][0], 'bch.blockonomics.co/api/update_callback') !== false);
    TestBlockonomics::$curlResponses = ['update_callback' => httpResp(500, null)];
    $partial2 = httpResp(200, [(object) ['callback' => $cbUrl . '&stale=1', 'address' => 'xpub2']]);
    check('failed update reported, not swallowed (BN-6b)', $b->examine_server_callback_urls($partial2, 'btc') === 'Failed to update callback URL (HTTP 500).');
    $other = httpResp(200, [(object) ['callback' => 'https://other.test/cb?secret=z', 'address' => 'xpub3']]);
    check('foreign store diagnosed', $b->examine_server_callback_urls($other, 'btc') === $_BLOCKLANG['testSetup']['addStore']);

    echo "== update payload quoting (BN-8) ==\n";
    $b = diagReady();
    App::$systemUrl = 'https://x.test/"quoted"/';
    $cbUrl = $b->getCallbackUrl($b->getCallbackSecret());
    TestBlockonomics::$curlResponses = ['update_callback' => httpResp(200, (object) [])];
    $b->update_callback($cbUrl, 'btc', 'xpub"1');
    $posted = json_decode((string) TestBlockonomics::$curlCalls[0][1], true);
    check('quotes survive as data, not syntax', $posted === ['callback' => $cbUrl, 'xpub' => 'xpub"1']);
    App::reset();

    echo "== endpoint routing and address probe ==\n";
    $b = diagReady();
    TestBlockonomics::$curlResponses = ['api/address' => httpResp(200, [])];
    $b->get_callbacks('btc');
    $b->get_callbacks('bch');
    check('btc callbacks route to www', strpos(TestBlockonomics::$curlCalls[0][0], 'www.blockonomics.co') !== false);
    check('bch callbacks route to bch', strpos(TestBlockonomics::$curlCalls[1][0], 'bch.blockonomics.co') !== false);
    TestBlockonomics::$newAddressResponse = (object) ['response_code' => 200, 'address' => 'a', 'message' => ''];
    check('address probe ok', $b->test_new_address_gen('btc') === '');
    TestBlockonomics::$newAddressResponse = (object) ['response_code' => 500, 'message' => 'boom'];
    check('address probe failure surfaces', $b->test_new_address_gen('btc') === 'boom');

    echo "== gateway params ==\n";
    $b = diagReady();
    $active = $b->getActiveCurrencies();
    check('active needs checkbox and governance', array_keys($active) === ['btc']);
    BnTestState::$gatewayParams['bchEnabled'] = 'on';
    Bridge::$matrix = ['btc' => true, 'bch' => false, 'usdt' => false];
    check('governed-off currency excluded', array_keys($b->getActiveCurrencies()) === ['btc']);
    Bridge::$apiKey = 'vault-key';
    check('api key vault first', $b->getApiKey() === 'vault-key');
    Bridge::$apiKey = '';
    BnTestState::$gatewayParams['ApiKey'] = '  legacy  ';
    check('api key legacy trimmed fallback', $b->getApiKey() === 'legacy');
    Bridge::$state['usdt_network'] = 'sepolia';
    check('network governed first', $b->getTokenNetwork() === 'sepolia');
    Bridge::$state['usdt_network'] = '';
    check('network legacy fallback', $b->getTokenNetwork() === 'ethereum');
    check('confirmations passthrough', $b->getConfirmations() === '2');
    unset(BnTestState::$gatewayParams['Confirmations']);
    check('confirmations default', $b->getConfirmations() === 2);
    bn_reset_all();
    $b = new TestBlockonomics();
    check('secret configured', $b->getCallbackSecret() === 'testsecret0123456789abcdef0123456789abcd');
    BnTestState::$gatewayParams['CallbackSecret'] = '';
    BnTestState::$gatewayParams['ApiSecret'] = 'https://shop.test/modules/gateways/callback/blockonomics.php?secret=' . str_repeat('c', 40);
    check('legacy secret tail parsed', $b->getCallbackSecret() === str_repeat('c', 40));
    BnTestState::$gatewayParams['ApiSecret'] = '';
    $gen = $b->getCallbackSecret();
    check('generated secret is 40 hex', (bool) preg_match('/^[0-9a-f]{40}$/', (string) $gen));
    $eth = $b->getTokenNetworkDetails('ethereum');
    check('ethereum details', $eth['chainId'] === 1 && $eth['tokens']['usdt'] === '0xdAC17F958D2ee523a2206206994597C13D831ec7');
    $sep = $b->getTokenNetworkDetails('sepolia');
    check('sepolia details', $sep['chainId'] === 11155111 && $sep['tokens']['usdt'] === '0x419Fe9f14Ff3aA22e46ff1d03a73EdF3b70A62ED');

    echo "\ndiagnostics: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
