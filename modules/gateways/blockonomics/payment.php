<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/blockonomics.php';

use Blockonomics\Blockonomics;
use WHMCS\ClientArea;
use CloudHost247\Blockonomics\Bridge;
use CloudHost247\Blockonomics\PaymentUnavailableException;
use CloudHost247\Blockonomics\Policy;

define('CLIENTAREA', true);

// Init Blockonomics class
$blockonomics = new Blockonomics();
require $blockonomics->getLangFilePath(isset($_GET['language']) ? htmlspecialchars($_GET['language']) : '');

$ca = new ClientArea();

$ca->setPageTitle('Bitcoin Payment');

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('payment.php', 'Bitcoin Payment');

$ca->initPage();

$blockonomics->start_polling_job();

/*
 * SET POST PARAMETERS TO VARIABLES AND CHECK IF THEY EXIST
 */
$show_order = isset($_GET["show_order"]) ? htmlspecialchars($_GET['show_order']) : "";
$crypto = isset($_GET["crypto"]) ? htmlspecialchars($_GET['crypto']) : "";
$select_crypto = isset($_GET["select_crypto"]) ? htmlspecialchars($_GET['select_crypto']) : "";
$finish_order = isset($_GET["finish_order"]) ? htmlspecialchars($_GET['finish_order']) : "";
$get_order = isset($_GET['get_order']) ? htmlspecialchars($_GET['get_order']) : "";

// ── CloudHost247 governance gates (server-side, fail closed) ─────────────
// 1) Master switch: the moment the gateway is disabled, no new crypto
//    payment may be created through ANY path on this page. In-flight
//    historical orders remain viewable via the callback/poller.
try {
    $chsLegacy = getGatewayVariables('blockonomics');
    $chsMatrix = Bridge::availableCurrencies($chsLegacy);
    $chsEnabled = !empty($chsLegacy['type']) && Bridge::isGatewayEnabled($chsLegacy);
} catch (\Throwable $governanceError) {
    error_log('cloudhost247 blockonomics payment gate error: ' . $governanceError->getMessage());
    $chsEnabled = false;
    $chsMatrix = ['btc' => false, 'usdt' => false];
}

if (!$chsEnabled) {
    http_response_code(403);
    $blockonomics->load_blockonomics_template($ca, 'crypto_unavailable');
    $ca->assign('_BLOCKLANG', isset($_BLOCKLANG) ? $_BLOCKLANG : []);
    $ca->output();
    exit();
}

// USDT display name ALWAYS carries its configured network (spec §9).
$chsNetwork = '';
$chsNetworkDisplay = '';
try {
    $chsState = Bridge::state($chsLegacy);
    $chsNetwork = $chsState['usdt_network'];
    if ($chsNetwork !== '') {
        $chsNetworkDisplay = Policy::networkDisplay($chsNetwork);
    }
} catch (\Throwable $ignored) {
    $chsNetworkDisplay = '';
}

// 2) Per-currency gate for any explicit crypto request below.
$chsGuardCurrency = function ($code) use ($chsLegacy) {
    if ($code === 'btc' || $code === 'usdt') {
        try {
            Bridge::assertCurrencyAllowed($code, $chsLegacy);
        } catch (PaymentUnavailableException $blocked) {
            http_response_code(403);
            exit('This payment method is currently unavailable.');
        }
    }
};

if($crypto === "empty"){
    $blockonomics->load_blockonomics_template($ca, 'no_crypto_selected');
}else if ($show_order && $crypto) {
    $chsGuardCurrency($crypto);
    $blockonomics->load_checkout_template($ca, $show_order, $crypto);
}else if ($select_crypto) {
    $blockonomics->load_blockonomics_template($ca, 'crypto_options', array(
        "cryptos" => $blockonomics->getActiveCurrencies(),
        "order_hash" => $select_crypto,
        "usdt_network_display" => $chsNetworkDisplay,
    ));
}else if ($finish_order) {
    if ($crypto == "usdt"){
        $chsGuardCurrency('usdt');
        // The browser reports a candidate txid — it is recorded as
        // "reported, unverified"; only the server-side poller can confirm
        // and credit. Fix: existing code passed an undefined $txn here.
        $reportedTxid = isset($_GET['txn']) ? trim((string) $_GET['txn']) : '';
        if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $reportedTxid)) {
            http_response_code(400);
            exit('Invalid transaction reference.');
        }
        $blockonomics->process_token_order($finish_order, $crypto, $reportedTxid);
    }
    $blockonomics->redirect_finish_order($finish_order);
}else if ($get_order && $crypto) {
    $chsGuardCurrency($crypto);
    $existing_order = $blockonomics->processOrderHash($get_order, $crypto);
    // No order exists, exit
    if (is_null($existing_order->id_order)) {
        exit();
    } else {
        $response = [
            "order_amount" => $blockonomics->fix_displaying_small_values($existing_order->bits, $existing_order->blockonomics_currency),
            "crypto_rate_str" => $blockonomics->get_crypto_rate_from_params($existing_order->value, $existing_order->bits, $existing_order->blockonomics_currency),
            "payment_uri" => $blockonomics->get_payment_uri($blockonomics->getSupportedCurrencies()[$crypto]['uri'], $existing_order->addr, $existing_order->bits)
        ];
        header('Content-Type: application/json');
        exit(json_encode($response));
    }
}

$ca->assign('_BLOCKLANG', $_BLOCKLANG);

$ca->output();

exit();
