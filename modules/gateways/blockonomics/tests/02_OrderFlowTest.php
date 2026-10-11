<?php
/**
 * C-7: order lifecycle, USDT report flow, checkout context, schema helper.
 * Prints FAIL=<n>.
 */
namespace {
    use Blockonomics\FakeCa;
    use Blockonomics\TestBlockonomics;
    use Blockonomics\TestRejectException;
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
    function lastWrite($type, $table)
    {
        foreach (array_reverse(Capsule::$writes) as $w) {
            if ($w[0] === $type && $w[1] === $table) return $w;
        }
        return null;
    }
    function orderRow(array $over = [])
    {
        return array_merge([
            'id_order' => 42, 'timestamp' => time(), 'status' => -1,
            'value' => 12.50, 'bits' => 25000, 'bits_payed' => 0,
            'blockonomics_currency' => 'btc', 'txid' => null,
            'basecurrencyamount' => 12.50, 'addr' => 'tb1seedaddr',
        ], $over);
    }
    function freshBtc()
    {
        bn_reset_all();
        TestBlockonomics::$newAddressResponse = (object) ['response_code' => 200, 'address' => 'tb1mockaddr', 'message' => ''];
        TestBlockonomics::$priceContents = '{"price":50000}';
        TestBlockonomics::$langPath = __DIR__ . '/../lang/english.php';
        return new TestBlockonomics();
    }

    echo "== fresh BTC order ==\n";
    $b = freshBtc();
    $hash = $b->getOrderHash(42, '12.50', 'USD', '12.50');
    $order = $b->processOrderHash($hash, 'btc');
    check('creates with provider address', $order->addr === 'tb1mockaddr');
    check('creates waiting with converted bits', $order->status === -1 && $order->bits === 25000);
    check('window is time period in seconds', $order->time_remaining === 600 && abs($order->timestamp - time()) < 5);
    check('one address call plus one price call', TestBlockonomics::$calls[0] === ['getNewAddress', 'btc', false] && count(TestBlockonomics::$calls) === 2);
    $ins = lastWrite('insert', 'blockonomics_orders');
    check('row inserted waiting', $ins && $ins[2]['status'] === -1 && $ins[2]['bits'] === 25000 && $ins[2]['id_order'] === 42);
    check('row carries fiat value and base', $ins[2]['value'] === 12.5 && $ins[2]['basecurrencyamount'] === 12.5);
    check('new-order logged', BnTestState::$transactions === [['blockonomics', ['invoice_id' => 42, 'address' => 'tb1mockaddr', 'crypto' => 'btc'], 'New Order Created']]);

    echo "== pending and waiting reuse ==\n";
    $b = freshBtc();
    Capsule::seed('blockonomics_orders', [orderRow(['status' => 2]), orderRow(['status' => 0, 'addr' => 'tb1pending', 'timestamp' => time() - 10])]);
    $hash = $b->getOrderHash(42, '12.50', 'USD', '12.50');
    $pending = $b->processOrderHash($hash, 'btc');
    check('pending order reused', $pending->addr === 'tb1pending');
    check('reuse burns nothing', TestBlockonomics::$calls === [] && Capsule::$writes === [] && BnTestState::$transactions === []);
    $b = freshBtc();
    Capsule::seed('blockonomics_orders', [orderRow(['status' => -1, 'addr' => 'tb1waiting', 'value' => 10.00, 'bits' => 20000])]);
    $hash = $b->getOrderHash(42, '20.00', 'USD', '20.00');
    $reused = $b->processOrderHash($hash, 'btc');
    check('waiting address reused', $reused->addr === 'tb1waiting');
    $upd = lastWrite('update', 'blockonomics_orders');
    check('waiting row re-armed with new amount', $upd && $upd[2]['value'] === 20.0 && $upd[2]['bits'] === 40000);
    check('waiting reuse inserts nothing', lastWrite('insert', 'blockonomics_orders') === null);
    $b = freshBtc();
    Capsule::seed('blockonomics_orders', [orderRow(['status' => -1, 'addr' => 'tb1waiting'])]);
    $b->processOrderHash($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'bch');
    check('other currency does not steal a waiting address', TestBlockonomics::$calls[0] === ['getNewAddress', 'bch', false]);

    echo "== currency gate ==\n";
    $b = freshBtc();
    Bridge::$deny = ['btc' => true];
    try {
        $b->processOrderHash($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'btc');
        check('disabled currency refused', false);
    } catch (TestRejectException $e) {
        check('disabled currency refused', $e->getMessage() === 'This payment method is currently unavailable.');
    }
    check('refusal does no work', TestBlockonomics::$calls === [] && Capsule::$writes === []);
    $b = freshBtc();
    try {
        $b->processOrderHash($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'doge');
        check('unknown currency refused', false);
    } catch (TestRejectException $e) {
        check('unknown currency refused', true);
    }

    echo "== USDT attempt isolation (BN-7) ==\n";
    $b = freshBtc();
    TestBlockonomics::$priceContents = '{"USD":1.0}';
    BnTestState::$gatewayParams['usdtEnabled'] = 'on';
    $hash = $b->getOrderHash(42, '12.50', 'USD', '12.50');
    $u1 = $b->processOrderHash($hash, 'usdt');
    check('usdt uses no provider address call', TestBlockonomics::$calls === [['fetchPrice', 'https://min-api.cryptocompare.com/data/price?fsym=usdt&tsyms=USD']]);
    check('usdt pseudo-address carries invoice plus nonce', (bool) preg_match('/^0x1111111111111111111111111111111111111111-42-[0-9a-f]{8}$/', $u1->addr));
    Capsule::seed('blockonomics_orders', []); // clear so the second attempt cannot reuse the waiting row
    $u2 = $b->processOrderHash($hash, 'usdt');
    check('repeat usdt attempt gets its own address', $u2->addr !== $u1->addr);
    check('usdt bits use 6 decimals', $u1->bits === 12500000);

    echo "== USDT report flow ==\n";
    $b = freshBtc();
    TestBlockonomics::$priceContents = '{"USD":1.0}';
    $legacyAddr = '0x1111111111111111111111111111111111111111-42';
    Capsule::seed('blockonomics_orders', [orderRow(['status' => -1, 'addr' => $legacyAddr, 'blockonomics_currency' => 'usdt', 'bits' => 12500000])]);
    $txid = '0x' . str_repeat('ab', 32);
    $b->process_token_order($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'usdt', $txid);
    $upd = lastWrite('update', 'blockonomics_orders');
    check('report stores txid as unconfirmed on the waiting row', $upd && $upd[2] === ['txid' => $txid, 'status' => 0, 'bits_payed' => 0]);
    $note = lastWrite('update', 'tblinvoices');
    check('waiting note names the currency (BN-3)', $note && strpos($note[2]['notes'], 'USDT') !== false);
    check('waiting note links the txid', strpos($note[2]['notes'], 'etherscan.io/tx/' . $txid) !== false);
    check('waiting note carries no warning-shaped hole', strpos($note[2]['notes'], '  network') === false);
    check('report inserts no duplicate row', lastWrite('insert', 'blockonomics_orders') === null);
    // Re-report of the same txid is a no-op.
    Capsule::$writes = [];
    $b->process_token_order($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'usdt', $txid);
    check('re-report writes nothing', Capsule::$writes === []);

    echo "== USDT second payment and cross-currency isolation ==\n";
    $b = freshBtc();
    TestBlockonomics::$priceContents = '{"USD":1.0}';
    Capsule::seed('blockonomics_orders', [orderRow(['status' => 0, 'addr' => 'usdt-first-attempt', 'blockonomics_currency' => 'usdt', 'txid' => '0x' . str_repeat('11', 32)])]);
    $second = '0x' . str_repeat('22', 32);
    $b->process_token_order($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'usdt', $second);
    $rows = Capsule::$tables['blockonomics_orders'];
    check('second usdt payment gets its own row', count($rows) === 2);
    check('first usdt txid untouched', $rows[0]['txid'] === '0x' . str_repeat('11', 32));
    check('second txid lands on the new row', $rows[1]['txid'] === $second && $rows[1]['addr'] !== 'usdt-first-attempt');
    $b = freshBtc();
    TestBlockonomics::$priceContents = '{"USD":1.0}';
    Capsule::seed('blockonomics_orders', [orderRow(['status' => 0, 'addr' => 'tb1btcpending', 'txid' => null])]);
    $b->process_token_order($b->getOrderHash(42, '12.50', 'USD', '12.50'), 'usdt', '0x' . str_repeat('33', 32));
    $rows = Capsule::$tables['blockonomics_orders'];
    check('btc-pending plus usdt report keeps separate rows', count($rows) === 2 && $rows[1]['blockonomics_currency'] === 'usdt');
    check('btc row not polluted by the usdt report', $rows[0]['txid'] === null && $rows[0]['blockonomics_currency'] === 'btc');
    $b = freshBtc();
    try {
        $b->process_token_order('garbage', 'usdt', '0x' . str_repeat('44', 32));
        check('report with garbage hash rejected', false);
    } catch (TestRejectException $e) {
        check('report with garbage hash rejected', true);
    }
    check('rejected report writes nothing', Capsule::$writes === []);

    echo "== lookups and helpers ==\n";
    $b = freshBtc();
    Capsule::seed('blockonomics_orders', [orderRow(['addr' => 'tb1lookup', 'txid' => 't1'])]);
    $found = $b->getOrderByAddress('tb1lookup');
    check('lookup maps the row', $found['order_id'] === 42 && $found['txid'] === 't1' && $found['blockonomics_currency'] === 'btc');
    check('unknown address is null', $b->getOrderByAddress('nope') === null);
    check('txid exists', $b->blockonomicsTransactionExists('t1') === true);
    check('txid missing', $b->blockonomicsTransactionExists('t2') === false);
    Capsule::seed('tblaccounts', [['id' => 5, 'gateway' => 'blockonomics', 'transid' => 'btc - t1 - tb1lookup']]);
    check('recorded payment found', $b->checkIfTransactionExists('btc - t1 - tb1lookup') === true);
    check('unknown payment not found', $b->checkIfTransactionExists('btc - t9 - x') === false);
    $now = time();
    Capsule::seed('blockonomics_orders', [
        orderRow(['status' => 0, 'blockonomics_currency' => 'usdt', 'addr' => 'fresh', 'timestamp' => $now - 100]),
        orderRow(['status' => 0, 'blockonomics_currency' => 'usdt', 'addr' => 'stale', 'timestamp' => $now - 90000]),
        orderRow(['status' => 2, 'blockonomics_currency' => 'usdt', 'addr' => 'done', 'timestamp' => $now - 100]),
        orderRow(['status' => 0, 'blockonomics_currency' => 'btc', 'addr' => 'btc0', 'timestamp' => $now - 100]),
    ]);
    $unconfirmed = $b->getUnconfirmedUSDTOrders();
    $ulist = iterator_to_array($unconfirmed);
    check('poller sees only fresh unconfirmed usdt', count($ulist) === 1 && $ulist[0]->addr === 'fresh');
    check('poller collection emptiness', $b->getUnconfirmedUSDTOrders()->isEmpty() === false);
    $picked = $b->getPendingOrder([(object) ['status' => -1], (object) ['status' => 2], (object) ['status' => 1]]);
    check('pending picker skips waiting and settled', $picked->status === 1);
    check('pending picker empty', $b->getPendingOrder([]) === false);
    $b->updateOrderExpected('tb1lookup', 'btc', 1234, 5.0, 1000);
    $upd = lastWrite('update', 'blockonomics_orders');
    check('expected-amount update shape', $upd[2] === ['blockonomics_currency' => 'btc', 'value' => 5.0, 'bits' => 1000, 'timestamp' => 1234]);
    Capsule::seed('blockonomics_orders', [orderRow(['timestamp' => 100, 'addr' => 'old']), orderRow(['timestamp' => 200, 'addr' => 'new'])]);
    $all = $b->getAllOrdersById(42);
    $alist = iterator_to_array($all);
    check('orders newest first', count($alist) === 2 && $alist[0]->addr === 'new');

    echo "== schema helper ==\n";
    $b = freshBtc();
    $b->createOrderTableIfNotExist();
    check('missing table created', Capsule::$schemaWrites === [['create', 'blockonomics_orders']]);
    Capsule::reset();
    Capsule::$schemaTables = ['blockonomics_orders' => ['id_order']];
    $b->createOrderTableIfNotExist();
    check('missing column added', Capsule::$schemaWrites === [['alter', 'blockonomics_orders']]);
    Capsule::reset();
    Capsule::$schemaTables = ['blockonomics_orders' => ['id_order', 'basecurrencyamount']];
    $b->createOrderTableIfNotExist();
    check('healthy schema untouched', Capsule::$schemaWrites === []);

    echo "== checkout context ==\n";
    $b = freshBtc();
    $ca = new FakeCa();
    $hash = $b->getOrderHash(42, '12.50', 'USD', '12.50');
    $b->load_checkout_template($ca, $hash, 'btc');
    check('btc template', $ca->template === '/modules/gateways/blockonomics/assets/templates/checkout.tpl');
    check('btc context order', $ca->assigned['order']->addr === 'tb1mockaddr' && $ca->assigned['order_hash'] === $hash);
    check('btc amount and rate', $ca->assigned['order_amount'] === 0.00025 && $ca->assigned['crypto_rate_str'] === '50000.00');
    check('btc payment uri', $ca->assigned['payment_uri'] === 'bitcoin:tb1mockaddr?amount=0.00025');
    check('btc window', $ca->assigned['time_period'] === '10');
    $b = freshBtc();
    TestBlockonomics::$priceContents = '{"USD":1.0}';
    BnTestState::$gatewayParams['usdtEnabled'] = 'on';
    $ca = new FakeCa();
    $b->load_checkout_template($ca, $b->getOrderHash(42, '12.50', 'USD', '12.50'), 'usdt');
    check('usdt template', $ca->template === '/modules/gateways/blockonomics/assets/templates/web3_checkout.tpl');
    check('usdt chain and contract', $ca->assigned['chain_id'] === 1 && $ca->assigned['contract_address'] === '0xdAC17F958D2ee523a2206206994597C13D831ec7');
    check('usdt network labelled', $ca->assigned['network_display'] === 'USDT — Ethereum' && $ca->assigned['network_is_test'] === false);
    check('usdt receiving address passed through', $ca->assigned['usdt_address'] === '0x1111111111111111111111111111111111111111');
    $b = freshBtc();
    try {
        $b->load_checkout_template(new FakeCa(), $b->getOrderHash(42, '12.50', 'USD', '12.50'), 'doge');
        check('checkout for unknown currency refused', false);
    } catch (TestRejectException $e) {
        check('checkout for unknown currency refused', true);
    }

    echo "\norder flow: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
