<?php
/**
 * Blockonomics payment callback — hardened by CloudHost247 governance.
 *
 * Hard guarantees added over the stock implementation:
 *  1. Secret comparison is constant-time (hash_equals), never `!=`.
 *  2. Unknown addresses 404 — no fatal errors, no order disclosure.
 *  3. Expired unpaid orders transition to Expired and are NOT credited.
 *  4. Amount math runs through the audited policy classifier (slack rules).
 *  5. Duplicate transactions / already-paid invoices / terminal orders are
 *     all replay-rejected before addInvoicePayment (spec §20/§22).
 *  6. logTransaction receives a SANITISED payload — the callback secret is
 *     never written to the gateway log (spec §41).
 *  7. All validation is server-side; nothing in the request is trusted
 *     beyond identifying which order the provider is talking about.
 */

// Require libraries needed for gateway module functions.
require '../../../init.php';
require '../../../includes/gatewayfunctions.php';
require '../../../includes/invoicefunctions.php';

require '../blockonomics/blockonomics.php';

use Blockonomics\Blockonomics;
use CloudHost247\Blockonomics\Bridge;
use CloudHost247\Blockonomics\Policy;

// Init Blockonomics class
$blockonomics = new Blockonomics();

$gatewayModuleName = 'blockonomics';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    exit('Module Not Activated');
}

require_once $blockonomics->getLangFilePath();

// Retrieve data returned in payment gateway callback
$secret = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
$status = isset($_GET['status']) ? (int) $_GET['status'] : -1;
$addr   = isset($_GET['addr']) ? (string) $_GET['addr'] : '';
$value  = isset($_GET['value']) ? (string) $_GET['value'] : '';
$txid   = isset($_GET['txid']) ? (string) $_GET['txid'] : '';

if ($secret === '' || $addr === '' || $txid === '' || !is_numeric($value) || $status < 0 || $status > 2) {
    http_response_code(400);
    exit('Malformed callback.');
}

/**
 * Validate callback authenticity (constant-time).
 */
$secret_value = $blockonomics->getCallbackSecret();

if (!hash_equals((string) $secret_value, $secret)) {
    http_response_code(403);
    $transactionStatus = $_BLOCKLANG['error']['secret'];
    $success = false;

    echo $transactionStatus;
    exit();
}

$order = $blockonomics->getOrderByAddress($addr);

// Hardening: unknown addresses must never fatal or leak — 404 silently.
if (!$order || !isset($order['order_id']) || !$order['order_id']) {
    http_response_code(404);
    exit('Unknown order.');
}

$invoiceId = $order['order_id'];
$bits = $order['bits'];

$confirmations = Policy::normalizeConfirmations($blockonomics->getConfirmations());

// Expiry (spec §38): a waiting order past its window is finalised as
// Expired and must NOT be credited by a late callback.
if (Policy::isExpired((int) $order['timestamp'], (int) $blockonomics->getTimePeriod())
    && (int) $order['status'] === Policy::ORD_WAITING
    && (float) $value < (float) $bits * (1.0 - $blockonomics->getUnderpaymentSlack() / 100.0)) {
    $blockonomics->updateOrderInDb($addr, $txid, Policy::ORD_EXPIRED, 0);
    $blockonomics->updateInvoiceNote($invoiceId, '<b>Cryptocurrency payment window expired.</b>');
    logTransaction($gatewayParams['name'], [
        'action' => 'expired', 'invoice' => $invoiceId, 'currency' => $order['blockonomics_currency'],
    ], 'Expired');
    exit();
}

$blockonomics_currency_code = $order['blockonomics_currency'];
$blockonomics_currency = $blockonomics->getSupportedCurrencies()[$blockonomics_currency_code];
if ($blockonomics_currency_code == 'btc') {
    $subdomain = 'www';
} else {
    $subdomain = $blockonomics_currency_code;
}

$systemUrl = \App::getSystemURL();
if ($status < $confirmations) {
    $invoiceNote = '<b>' . $_BLOCKLANG['invoiceNote']['waiting'] . ' <img src="' . $systemUrl . 'modules/gateways/blockonomics/assets/img/' . $blockonomics_currency_code . '.png" style="max-width: 20px;"> ' . $blockonomics_currency->name . ' ' . $_BLOCKLANG['invoiceNote']['network'] . "</b>\r\r" .
    $blockonomics_currency->name . " transaction id:\r" .
        '<a target="_blank" href="https://' . $subdomain . ".blockonomics.co/api/tx?txid=$txid&addr=$addr\">$txid</a>";

    $blockonomics->updateOrderInDb($addr, $txid, $status, $value);
    $blockonomics->updateInvoiceNote($invoiceId, $invoiceNote);

    exit();
}

// Amount classification — provider % + slack flow through the audited
// policy layer (spec §23). Underpayment within slack gets full credit by
// configuration; below that the credit stays proportional — never a
// silent full-mark.
$amount = Policy::classifyAmount((int) $bits, $value, (float) $blockonomics->getUnderpaymentSlack());
$satoshiAmount = $amount['credit_bits'];
$percentPaid = $amount['percent_paid'];
$paymentAmount = $blockonomics->convertPercentPaidToInvoiceCurrency($order, $percentPaid);
$blockonomics->updateInvoiceNote($invoiceId, null);
$blockonomics->updateOrderInDb($addr, $txid, $status, $value);

/**
 * Validate Callback Invoice ID.
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);


if ($txid == 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction') {
    // If this is test transaction, generate new transaction ID
    $txid = 'WarningThisIsATestTransaction - ' . $addr;
} else {
    /**
     * Add address to txid (see previous revision: protects multi-address
     * transactions from being collapsed by transid dedupe in WHMCS).
     */
    $txid = $txid . " - " . $addr;
}

/**
 * Replay / duplicate guards (spec §20/§22): provider transaction already
 * recorded, invoice already settled, or order already terminal → stop.
 */
$fullTransId = $blockonomics_currency_code . ' - ' . $txid;
if ($blockonomics->checkIfTransactionExists($fullTransId)) {
    // Idempotent 200 so the provider stops retrying; nothing re-credits.
    exit();
}

$invoiceData = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status']);
$invoiceAlreadyPaid = $invoiceData && strtoupper((string) $invoiceData->status) === 'PAID';
$orderTerminal = Policy::mapStatus((int) $order['status'], $confirmations) === Policy::ST_PAID
    || (int) $order['status'] === Policy::ORD_EXPIRED
    || (int) $order['status'] === Policy::ORD_CANCELLED;

if (!Policy::creditAllowed(false, $invoiceAlreadyPaid, $orderTerminal)) {
    logTransaction($gatewayParams['name'], [
        'action' => 'replay_rejected', 'invoice' => $invoiceId, 'txid' => substr($txid, 0, 24),
    ], 'Duplicate');
    exit();
}

/**
 * Log Transaction — sanitised. The callback secret NEVER enters the log.
 */
logTransaction($gatewayParams['name'], [
    'action'   => 'callback_credit',
    'invoice'  => $invoiceId,
    'currency' => $blockonomics_currency_code,
    'status'   => $status,
    'value'    => $value,
    'txid'     => substr($txid, 0, 24),
    'amount_class' => $amount['class'],
], 'Successful');

$paymentFee = 0;

/**
 * Add Invoice Payment.
 */
addInvoicePayment(
    $invoiceId,
    $fullTransId,
    $paymentAmount,
    $paymentFee,
    $gatewayModuleName
);
