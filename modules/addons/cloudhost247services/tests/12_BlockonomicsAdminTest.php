<?php

require __DIR__ . '/bootstrap.php';
require_once (is_dir('/repo') ? '/repo' : dirname(__DIR__, 3)) . '/modules/gateways/blockonomics/cloudhost247/autoload.php';

use CloudHost247\Blockonomics\Bridge;
use CloudHost247\Blockonomics\ConnectionTester;
use CloudHost247\Blockonomics\Governance;
use CloudHost247\Blockonomics\PdoStore;
use CloudHost247\Blockonomics\Vault;
use Chs\Admin\BlockonomicsAdmin;
use Chs\Admin\BlockonomicsTransactions;
use Chs\Core\Db;

chs_boot();

/** Fresh admin wired to an in-memory governance store + recorded legacy mirrors. */
function chs_bn(array $legacy, callable $transport = null, array &$mirrors = [])
{
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    $gov = new Governance($store);
    $vault = new Vault($store, 'fixture-secret-for-tests');
    $legacyLoader = function () use ($legacy) { return $legacy; };
    $tester = new ConnectionTester($transport ?: function () {
        return ['code' => 200, 'body' => '[]', 'errno' => 0, 'error' => ''];
    });
    $mirror = function ($setting, $value) use (&$mirrors) { $mirrors[$setting] = $value; };
    $admin = new BlockonomicsAdmin($gov, $vault, $legacyLoader, $tester, $mirror);
    return [$admin, $store];
}

$LEGACY_FULL = [
    'ApiKey' => 'bk_live_legacykey', 'btcEnabled' => 'on', 'usdtEnabled' => 'on',
    'Confirmations' => '2', 'NetworkType' => 'sepolia',
    'UsdtAddress' => '0x' . str_repeat('c', 40), 'EtherScanAPIKey' => 'ETHKEY',
];

/* ------------------------------------------------------------- panel -- */

T::section('Panel state: seeds, masks, sources');
list($admin, $store) = chs_bn($LEGACY_FULL);
$state = $admin->panelState();
T::ok('gateway adopted on', $state['gateway_enabled']);
T::ok('btc adopted on', $state['btc_enabled']);
T::ok('usdt adopted on', $state['usdt_enabled']);
T::ok('api key seen as configured', $state['api_key_set']);
T::eq('api key masked', Vault::MASK, $state['api_key_mask']);
T::eq('source is legacy until replaced', 'Legacy gateway settings', $state['api_key_source']);
T::ok('etherscan adopted as present', $state['etherscan_set']);
T::eq('network display pre-computed', 'USDT — Sepolia (Test Network)', $state['network_display']);
T::ok('test network badge flagged', $state['network_is_test']);
T::ok('panel never contains the real key', strpos(chs_json($state), 'bk_live_legacykey') === false);

/* -------------------------------------------------- save settings ---- */

T::section('Save settings: validation chain + mirrors + audit');
$mirrors = [];
list($admin, $store) = chs_bn($LEGACY_FULL, null, $mirrors);
$notice = $admin->saveSettings([
    'gateway_enabled' => true, 'btc_enabled' => true, 'usdt_enabled' => false,
    'confirmations' => 1, 'usdt_network' => 'sepolia',
], 42, 'ipx');
T::ok('save notice returned', strpos($notice, 'saved') !== false);
T::eq('usdt mirror cleared', '', $mirrors['usdtEnabled']);
T::eq('btc mirror kept on', 'on', $mirrors['btcEnabled']);
T::eq('confirmations mirrored', '1', $mirrors['Confirmations']);
$audit = array_map(function ($r) { return $r['action']; }, $store->auditList(20));
T::ok('usdt.disabled audited', in_array('usdt.disabled', $audit, true));
T::ok('confirmations.changed audited', in_array('confirmations.changed', $audit, true));

T::section('Save rejected when it would enable a dead config');
list($admin2,) = chs_bn(['ApiKey' => '']);  // no key anywhere
T::throws('gateway cannot enable without key', function () use ($admin2) {
    $admin2->saveSettings(['gateway_enabled' => true, 'btc_enabled' => true], 1);
}, 'InvalidArgumentException');
list($admin3,) = chs_bn($LEGACY_FULL);
T::throws('usdt without network rejected', function () use ($admin3) {
    $admin3->saveSettings(['gateway_enabled' => true, 'usdt_enabled' => true, 'usdt_network' => ''], 1);
}, 'InvalidArgumentException');

/* --------------------------------------------------- credentials ----- */

T::section('Replace API key: vault write + masked + redacted audit');
list($admin, $store) = chs_bn($LEGACY_FULL);
$state = $admin->panelState(); // triggers the one-way legacy seed first
$admin->replaceApiKey('bk_live_NEWKEY_999', 42, 'ipx');
$state = $admin->panelState();
T::eq('source switches to vault', 'CloudHost247 vault', $state['api_key_source']);
T::eq('effective key is new one', 'bk_live_NEWKEY_999', $admin->effectiveApiKey());
T::ok('panel still leak-free', strpos(chs_json($state), 'NEWKEY') === false);
$audit = $store->auditList(5);
T::eq('credentialed change audited', 'credentials.replaced', $audit[0]['action']);
T::eq('audit is redacted', Vault::AUDIT_REDACTED, $audit[0]['new_value']);
T::ok('raw key absent from audit', strpos(chs_json($audit), 'NEWKEY') === false);

T::section('Etherscan key + USDT address flows');
list($admin, $store) = chs_bn($LEGACY_FULL);
$admin->replaceEtherscanKey('NEWETHKEY', 42);
T::eq('etherscan resolves new key', 'NEWETHKEY', $admin->effectiveEtherscanKey());
T::throws('bad address rejected', function () use ($admin) {
    $admin->updateUsdtAddress('bc1qnotethereum', 42);
}, 'InvalidArgumentException');
$mirrors2 = [];
list($admin4, $store4) = chs_bn($LEGACY_FULL, null, $mirrors2);
$msg = $admin4->updateUsdtAddress('0x' . str_repeat('d', 40), 42);
T::ok('valid address accepted', strpos($msg, 'updated') !== false);
T::eq('address mirrored to legacy', '0x' . str_repeat('d', 40), $mirrors2['UsdtAddress']);

/* ------------------------------------------------- connection test --- */

T::section('Connection test: sanitized + audited');
list($admin, $store) = chs_bn($LEGACY_FULL, function () {
    return ['code' => 401, 'body' => '{"message":"unauthorized bk_live_legacykey"}', 'errno' => 0, 'error' => ''];
});
$result = $admin->testConnection(42, 'ipx');
T::eq('401 mapped to auth_failed', ConnectionTester::AUTH_FAILED, $result['category']);
T::ok('provider echo (with key!) never surfaces', strpos(chs_json($result), 'bk_live_legacykey') === false);
$audit = $store->auditList(5);
T::eq('test action audited', 'connection.tested', $audit[0]['action']);
T::eq('audit holds only category', ConnectionTester::AUTH_FAILED, $audit[0]['new_value']);

/* ---------------------------------------------------- transactions --- */

T::section('Transaction listing: filters + normalized statuses + masking');
Db::pdo()->exec("CREATE TABLE blockonomics_orders (
    id_order INTEGER, txid TEXT, `timestamp` INTEGER, addr TEXT PRIMARY KEY,
    status INTEGER, value DECIMAL(10,2), bits INTEGER, bits_payed INTEGER,
    blockonomics_currency TEXT, basecurrencyamount DECIMAL(10,2)
)");
$now = time();
$rows = [
    // [invoice, txid, ts, addr, status, value, bits, bits_payed, cur, base]
    [7001, 'aaa111', $now - 600, '1BoatSLRHtKNngkdXEeobR76b53LETtpyT', 2, '200.00', 100000, 100000, 'btc', '200.00'],
    [7002, '0xdeadbeef', $now - 300, '0x' . str_repeat('e', 40) . '-7002', 1, '50.00', 50000000, 25000000, 'usdt', '50.00'],
    [7003, 'ccc333', $now - 90000, '1ExpiredAddressCCCCCCCCCCCCCCCCCCCC', 0, '10.00', 1000000, 0, 'btc', '10.00'],
];
foreach ($rows as $r) {
    Db::pdo()->exec(sprintf(
        "INSERT INTO blockonomics_orders VALUES (%d, '%s', %d, '%s', %d, '%s', %d, %d, '%s', '%s')",
        $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9]
    ));
}
Db::pdo()->exec("INSERT INTO tblinvoices (id, userid, status, total) VALUES
    (7001, 1, 'Paid', '200.00'), (7002, 1, 'Unpaid', '50.00'), (7003, 2, 'Unpaid', '10.00')");
Db::pdo()->exec("INSERT INTO tblclients (id, firstname, lastname, email, currency) VALUES
    (1, 'Alice', 'Anderson', 'alice@example.com', 1), (2, 'Bob', 'Brown', 'bob@example.com', 1)");

$tx = new BlockonomicsTransactions();
$all = $tx->query(['confirmations' => 2, 'time_period_min' => 10]);
T::eq('three rows', 3, count($all['rows']));
$byStatus = [];
foreach ($all['rows'] as $r) {
    $byStatus[$r['order_id']] = $r['status'];
}
T::eq('paid btc normalised', 'Paid', $byStatus[7001]);
T::eq('1-of-2 confirmations → Confirming', 'Confirming', $byStatus[7002]);
T::eq('ancient unconfirmed → Expired', 'Expired', $byStatus[7003]);

$btc = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'currency' => 'btc']);
T::eq('currency filter', 2, count($btc['rows']));
$search = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'search' => 'alice@example.com']);
T::eq('email search', 2, count($search['rows']));
$searchByTx = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'search' => '0xdeadbeef']);
T::eq('txid search', 1, count($searchByTx['rows']));
$searchByInv = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'search' => '7003']);
T::eq('invoice search', 1, count($searchByInv['rows']));
if ($searchByInv['rows']) {
    T::eq('invoice search result', 7003, $searchByInv['rows'][0]['order_id']);
}
$expired = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'status' => 'Expired']);
T::eq('status filter Expired', 1, count($expired['rows']));
$paid = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'status' => 'Paid']);
T::eq('status filter Paid', 1, count($paid['rows']));

T::section('Address masking never leaks full wallets');
foreach ($all['rows'] as $r) {
    T::ok('mask present', strpos($r['address_masked'], '…') !== false);
    if ($r['currency'] === 'btc') {
        T::ok('full btc address hidden', strpos($r['address_masked'], 'SLRHtKNngkdXEeobR76b53LETtpyT') === false);
    }
    if ($r['currency'] === 'usdt') {
        T::ok('full eth address hidden', strpos($r['address_masked'], str_repeat('e', 40)) === false);
        T::ok('invoice suffix retained for ops', strpos($r['address_masked'], '7002') !== false);
    }
}

T::section('Date-range filter');
$future = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'from' => date('Y-m-d', $now + 86400)]);
T::eq('future range empty', 0, count($future['rows']));
$today = $tx->query(['confirmations' => 2, 'time_period_min' => 10, 'from' => date('Y-m-d', $now - 3600), 'to' => date('Y-m-d', $now)]);
T::eq('today range finds live rows', 2, count($today['rows']));

/* --------------------------------------------------- BCH admin control -- */

T::section('Admin console governs BCH alongside BTC/USDT');
$LEGACY_BCH = array_merge($LEGACY_FULL, ['bchEnabled' => 'on']);
list($adminB, $storeB) = chs_bn($LEGACY_BCH);
$stB = $adminB->panelState();
T::ok('panel exposes bch state', array_key_exists('bch_enabled', $stB));
T::ok('bch adopted on from legacy', $stB['bch_enabled']);

// Legacy install with BCH off must not be switched on by the migration.
list($adminB2,) = chs_bn($LEGACY_FULL);
T::ok('bch stays off when legacy had it off', !$adminB2->panelState()['bch_enabled']);

T::section('Saving BCH mirrors back to tblpaymentgateways');
$mirrorsB = [];
list($adminB3, $storeB3) = chs_bn($LEGACY_BCH, null, $mirrorsB);
$adminB3->saveSettings([
    'gateway_enabled' => true, 'btc_enabled' => true, 'bch_enabled' => false,
    'usdt_enabled' => false, 'confirmations' => 2, 'usdt_network' => 'sepolia',
], 42, 'ipx');
T::eq('bch mirror cleared', '', $mirrorsB['bchEnabled']);
T::ok('bch now off in state', !$adminB3->panelState()['bch_enabled']);
$auditB = array_map(function ($r) { return $r['action']; }, $storeB3->auditList(30));
T::ok('bch.disabled audited', in_array('bch.disabled', $auditB, true));

$mirrorsB2 = [];
list($adminB4, $storeB4) = chs_bn($LEGACY_FULL, null, $mirrorsB2);
$adminB4->saveSettings([
    'gateway_enabled' => true, 'btc_enabled' => true, 'bch_enabled' => true,
    'usdt_enabled' => false, 'confirmations' => 2,
], 42, 'ipx');
T::eq('bch mirror set on', 'on', $mirrorsB2['bchEnabled']);
T::ok('bch enabled in state', $adminB4->panelState()['bch_enabled']);
T::ok('bch.enabled audited',
    in_array('bch.enabled', array_map(function ($r) { return $r['action']; }, $storeB4->auditList(30)), true));

T::section('BCH cannot be enabled without the Blockonomics API key');
list($adminB5,) = chs_bn(['ApiKey' => '', 'btcEnabled' => '', 'bchEnabled' => '']);
T::throws('dead bch config rejected', function () use ($adminB5) {
    $adminB5->saveSettings(['gateway_enabled' => true, 'bch_enabled' => true], 1);
}, 'InvalidArgumentException');
T::ok('bch still off after rejection', !$adminB5->panelState()['bch_enabled']);

T::section('Omitting bch_enabled from the POST turns it off (no sticky flags)');
$mirrorsB3 = [];
list($adminB6,) = chs_bn($LEGACY_BCH, null, $mirrorsB3);
T::ok('precondition: bch on', $adminB6->panelState()['bch_enabled']);
$adminB6->saveSettings([
    'gateway_enabled' => true, 'btc_enabled' => true, 'confirmations' => 2,
], 42, 'ipx'); // unchecked checkbox => key absent
T::ok('absent checkbox disables bch', !$adminB6->panelState()['bch_enabled']);
T::eq('and the mirror follows', '', $mirrorsB3['bchEnabled']);

T::finish();
