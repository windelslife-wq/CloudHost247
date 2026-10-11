<?php
/**
 * C-7: static pins on the entry scripts (callback, poller, payment page,
 * gateway entry, upgrade) and checkout templates, which cannot be
 * included offline. Prints FAIL=<n>.
 */
namespace {
    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
    }

    $root = __DIR__ . '/../..';
    $cb = (string) file_get_contents($root . '/callback/blockonomics.php');
    $pol = (string) file_get_contents(__DIR__ . '/../pollTransactionStatus.php');
    $pay = (string) file_get_contents(__DIR__ . '/../payment.php');
    $entry = (string) file_get_contents($root . '/blockonomics.php');
    $up = (string) file_get_contents(__DIR__ . '/../upgrade.php');
    $cls = (string) file_get_contents(__DIR__ . '/../blockonomics.php');
    $tpl = (string) file_get_contents(__DIR__ . '/../assets/templates/checkout.tpl');
    $w3 = (string) file_get_contents(__DIR__ . '/../assets/templates/web3_checkout.tpl');

    echo "== callback ==\n";
    check('secret compared constant-time', strpos($cb, 'hash_equals((string) $secret_value, $secret)') !== false);
    check('malformed is 400', strpos($cb, "exit('Malformed callback.');") !== false);
    check('unknown address is 404', strpos($cb, "exit('Unknown order.');") !== false && strpos($cb, 'http_response_code(404)') !== false);
    $logWindows = array_slice(explode('logTransaction(', $cb), 1);
    $sanitised = count($logWindows) === 3;
    foreach ($logWindows as $w) {
        $head = substr($w, 0, 500);
        if (strpos($head, '$_GET') !== false || preg_match('/\$secret\b/', $head) === 1) $sanitised = false;
    }
    check('all three log calls sanitised (no secret, no raw request)', $sanitised);
    check('logged txids truncated', substr_count($cb, 'substr($txid, 0, 24)') === 2);
    check('no property-on-array name read', strpos($cb, '->name') === false);
    check('currency name via array with fallback', strpos($cb, '$blockonomics_currency_name') !== false && strpos($cb, ': $blockonomics_currency_code;') !== false);
    check('note escapes query-derived txid', strpos($cb, 'htmlspecialchars($txid, ENT_QUOTES') !== false);
    check('note escapes query-derived addr', strpos($cb, 'htmlspecialchars($addr, ENT_QUOTES') !== false);
    check('replay trio present', strpos($cb, 'checkIfTransactionExists($fullTransId)') !== false
        && strpos($cb, 'Policy::creditAllowed(false, $invoiceAlreadyPaid, $orderTerminal)') !== false
        && strpos($cb, "tblinvoices')->where('id', \$invoiceId)->first(['status'])") !== false);
    check('settled check reads PAID', strpos($cb, "=== 'PAID'") !== false);
    check('expiry expires instead of crediting', strpos($cb, 'Policy::isExpired(') !== false && strpos($cb, 'Policy::ORD_EXPIRED') !== false);
    check('expiry path exits before any credit', strpos($cb, 'Policy::ORD_EXPIRED') < strpos($cb, 'addInvoicePayment('));
    check('amount flows through the classifier', strpos($cb, 'Policy::classifyAmount(') !== false);
    check('credit converts percent to invoice currency', strpos($cb, 'convertPercentPaidToInvoiceCurrency($order, $percentPaid)') !== false);
    check('provider test txid documented inline', strpos($cb, 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction') !== false);
    check('transid suffixed per address', strpos($cb, "\$fullTransId = \$blockonomics_currency_code . ' - ' . \$txid;") !== false);
    check('invoice validated before credit', strpos($cb, 'checkCbInvoiceID($invoiceId, $gatewayParams[') !== false);

    echo "== poller daemon ==\n";
    check('cli-only guard', strpos($pol, "PHP_SAPI !== 'cli'") !== false && strpos($pol, "exit('Forbidden.');") !== false);
    check('single-flight lock', strpos($pol, 'blockonomics_usdt_poll.lock') !== false && strpos($pol, 'LOCK_EX | LOCK_NB') !== false);
    check('lock fallback logs and continues', strpos($pol, 'lock file unavailable, continuing unlocked') !== false);
    check('poll http has timeouts', strpos($pol, 'CURLOPT_TIMEOUT, 30') !== false && strpos($pol, 'CURLOPT_CONNECTTIMEOUT, 10') !== false);
    check('daemon survives poison orders', strpos($pol, 'catch (\\Throwable $e)') !== false);
    check('dead log line removed', strpos($pol, 'transaction ended') === false);
    check('confirmations gate before credit', strpos($pol, 'fetchConfirmations($result[') !== false && strpos($pol, '$confirmations < $chsRequiredConfirmations') !== false);
    check('erc20 selector pinned', strpos($pol, '0xa9059cbb') !== false);
    check('amount offset pinned', strpos($pol, 'substr($inputData, 74, 64)') !== false);
    check('recipient offset pinned', strpos($pol, "substr(\$inputData, 34, 40)") !== false);
    check('contract address enforced', strpos($pol, 'strtolower($toAddress) != strtolower($contract_address)') !== false);
    check('tx-exists guard precedes credit', strpos($pol, 'checkIfTransactionExists(') < strpos($pol, 'addInvoicePayment('));
    check('sepolia routes to its api', strpos($pol, 'api-sepolia') !== false);
    check('poller only reads unconfirmed usdt', strpos($pol, 'getUnconfirmedUSDTOrders()') !== false);

    echo "== payment page ==\n";
    check('master switch gated', strpos($pay, 'Bridge::isGatewayEnabled') !== false);
    check('empty picker impossible', strpos($pay, 'Policy::anyCurrencyAvailable') !== false && strpos($pay, "'crypto_unavailable'") !== false);
    check('reported txid shape enforced', strpos($pay, '/^0x[a-fA-F0-9]{64}$/') !== false);
    check('explicit crypto double-gated', substr_count($pay, '$chsGuardCurrency($crypto)') === 2 && strpos($pay, "\$chsGuardCurrency('usdt')") !== false);
    check('report path passes the validated txid', strpos($pay, 'process_token_order($finish_order, $crypto, $reportedTxid)') !== false);

    echo "== gateway entry and upgrade ==\n";
    check('link fails closed on governance', strpos($entry, 'Bridge::isCheckoutAvailable()') !== false && strpos($entry, 'catch (\\Throwable $governanceError)') !== false);
    check('link posts to the payment page', strpos($entry, 'modules/gateways/blockonomics/payment.php') !== false);
    check('link guards empty params', strpos($entry, 'Missing or invalid $params') !== false);
    check('config ensures the orders table', strpos($entry, 'createOrderTableIfNotExist()') !== false);
    check('upgrade is admin-only', strpos($up, 'checkAdmin()') !== false);
    check('upgrade deletes files, never rows', stripos($up, 'delete from') === false && stripos($up, 'drop table') === false && stripos($up, 'truncate') === false);

    echo "== class-file pins ==\n";
    check('zero property-on-array reads', strpos($cls, '->name') === false);
    check('encrypt macs the ciphertext', strpos($cls, 'hash_hmac($hashing_algorith, $cipherText, $secret)') !== false);
    check('decrypt verifies before opening', strpos($cls, 'hash_equals(hash_hmac(') !== false);
    check('invalid-hash seam exits 400', strpos($cls, "exit('Invalid payment reference.');") !== false);
    check('unavailable-currency seam exits 403', strpos($cls, "exit('This payment method is currently unavailable.');") !== false);
    check('usdt pseudo-address randomised', strpos($cls, 'random_bytes(4)') !== false);
    check('test setup skips usdt', strpos($cls, "if (\$code === 'usdt')") !== false);
    check('api calls time out', substr_count($cls, 'CURLOPT_TIMEOUT') === 3 && strpos($cls, 'CURLOPT_CONNECTTIMEOUT') !== false);
    check('callback update posts real json', strpos($cls, "json_encode(['callback' => \$callback_url, 'xpub' => \$xpub])") !== false);
    check('provider bodies counted safely', strpos($cls, 'is_countable($response->data)') !== false);
    check('failed callback update reported', strpos($cls, 'Failed to update callback URL (HTTP ') !== false);
    check('token report keys off the resolved row', strpos($cls, 'updateOrderInDb($order->addr, $txid, 0, 0)') !== false);
    check('re-report check precedes order work', strpos($cls, 'blockonomicsTransactionExists($txid)') < strpos($cls, 'decryptHash($finish_order)'));

    echo "== templates ==\n";
    check('checkout currency escaped twice', substr_count($tpl, '{$order->currency|escape}') === 2);
    check('checkout address attr escaped', substr_count($tpl, '{$order->addr|escape}') === 1);
    check('checkout payment hrefs escaped', substr_count($tpl, '{$payment_uri|escape}') === 2);
    check('checkout js strings escaped', substr_count($tpl, "|escape:'javascript'") === 2);
    check('no bare order currency/addr/payment uri', preg_match('/\{\$order->(?:currency|addr)\}/', $tpl) === 0 && preg_match('/\{\$payment_uri\}/', $tpl) === 0);
    check('web3 currency escaped', substr_count($w3, '{$order->currency|escape}') === 1);
    check('web3 receiving address escaped', substr_count($w3, '{$usdt_address|escape}') === 1);
    check('web3 js string escaped', substr_count($w3, "|escape:'javascript'") === 1);

    echo "\nscript statics: PASS=$pass FAIL=$fail\nFAIL=$fail\n";
    exit($fail > 0 ? 1 : 0);
}
