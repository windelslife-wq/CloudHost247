<?php

require __DIR__ . '/bootstrap.php';
require_once (is_dir('/repo') ? '/repo' : dirname(__DIR__, 3)) . '/modules/gateways/blockonomics/cloudhost247/autoload.php';

use CloudHost247\Blockonomics\CapsuleStore;
use CloudHost247\Blockonomics\ConnectionTester;
use CloudHost247\Blockonomics\Governance;
use CloudHost247\Blockonomics\PdoStore;
use CloudHost247\Blockonomics\PaymentUnavailableException;
use CloudHost247\Blockonomics\Policy;
use CloudHost247\Blockonomics\Vault;

chs_boot();

function chs_gov()
{
    $pdo = new PDO('sqlite::memory:');
    $store = new PdoStore($pdo);
    return [new Governance($store), $store];
}

/* ------------------------------------------------------------- policy -- */

T::section('Availability matrix (spec §15 A–E)');
$m = function ($gateway, $btc, $usdt) {
    return Policy::availabilityMatrix([
        'gateway_enabled' => $gateway, 'btc_enabled' => $btc, 'usdt_enabled' => $usdt,
    ]);
};
T::eq('A: gateway+btc on, usdt off → btc only', ['btc' => true, 'usdt' => false], $m(1, 1, 0));
T::eq('B: gateway+usdt on, btc off → usdt only', ['btc' => false, 'usdt' => true], $m(1, 0, 1));
T::eq('C: all on → both', ['btc' => true, 'usdt' => true], $m(1, 1, 1));
T::eq('D: gateway on, both off → none', ['btc' => false, 'usdt' => false], $m(1, 0, 0));
T::eq('E: gateway off → nothing even if currencies on', ['btc' => false, 'usdt' => false], $m(0, 1, 1));

T::section('Master/currency enforcement throws 403-style (spec §16)');
$state = ['gateway_enabled' => true, 'btc_enabled' => false, 'usdt_enabled' => true];
Policy::assertPaymentAllowed($state, 'usdt'); // must not throw
T::ok('usdt allowed when on', true);
T::throws('btc blocked when off', function () use ($state) {
    Policy::assertPaymentAllowed($state, 'btc');
}, 'CloudHost247\\Blockonomics\\PaymentUnavailableException');
T::throws('gateway off blocks everything', function () {
    Policy::assertPaymentAllowed(['gateway_enabled' => false, 'btc_enabled' => true, 'usdt_enabled' => true], 'btc');
}, 'CloudHost247\\Blockonomics\\PaymentUnavailableException');
T::throws('unknown currency blocked', function () use ($state) {
    Policy::assertPaymentAllowed($state, 'doge');
}, 'CloudHost247\\Blockonomics\\PaymentUnavailableException');

T::section('Amount classification (spec §23)');
$r = Policy::classifyAmount(10000, 10000, 5);
T::eq('exact pays full', Policy::AMT_EXACT, $r['class']);
T::eq('exact credits expected', 10000, $r['credit_bits']);
$r = Policy::classifyAmount(10000, 9700, 5); // 3% under within 5% slack
T::eq('under within slack = full credit', Policy::AMT_UNDER_SLACK, $r['class']);
T::eq('slack credits full expectation', 10000, $r['credit_bits']);
$r = Policy::classifyAmount(10000, 5000, 5);
T::eq('true underpayment flagged', Policy::AMT_UNDER, $r['class']);
T::eq('underpayment credits proportionally', 5000, $r['credit_bits']);
T::eq('underpayment percent', 50.0, $r['percent_paid']);
$r = Policy::classifyAmount(10000, 12000, 5);
T::eq('overpayment flagged', Policy::AMT_OVER, $r['class']);
T::eq('overpayment percent', 120.0, $r['percent_paid']);
$r = Policy::classifyAmount(0, 100, 5);
T::eq('zero expectation invalid', Policy::AMT_INVALID, $r['class']);

T::section('Status normalisation (spec §19)');
T::eq('waiting → Pending', Policy::ST_PENDING, Policy::mapStatus(-1, 2));
T::eq('0-of-2 → Confirming', Policy::ST_CONFIRMING, Policy::mapStatus(0, 2));
T::eq('1-of-2 → Confirming', Policy::ST_CONFIRMING, Policy::mapStatus(1, 2));
T::eq('2-of-2 → Paid', Policy::ST_PAID, Policy::mapStatus(2, 2));
T::eq('expired waiting, nothing received → Expired', Policy::ST_EXPIRED, Policy::mapStatus(-1, 2, true, 0));
T::eq('expired with partial value → Failed', Policy::ST_FAILED, Policy::mapStatus(0, 2, true, 500));
T::eq('ord-expired flag → Expired', Policy::ST_EXPIRED, Policy::mapStatus(-2, 2));
T::eq('cancelled flag → Cancelled', Policy::ST_CANCELLED, Policy::mapStatus(-3, 2));

T::section('Expiry window (spec §38)');
T::ok('inside window not expired', !Policy::isExpired(1000, 10, 1000 + 599));
T::ok('past window expired', Policy::isExpired(1000, 10, 1000 + 601));
T::ok('zero-minute never means zero window', !Policy::isExpired(1000, 0, 1000 + 30));

T::section('Network naming (spec §9 — never bare USDT, no invented networks)');
T::eq('ethereum display', 'USDT — Ethereum', Policy::networkDisplay('ethereum'));
T::eq('sepolia display', 'USDT — Sepolia (Test Network)', Policy::networkDisplay('sepolia'));
T::ok('sepolia flagged as test', Policy::networkIsTest('sepolia'));
T::ok('ethereum not test', !Policy::networkIsTest('ethereum'));
T::ok('ethereum supported', Policy::isSupportedNetwork('ethereum'));
T::ok('trc20 NOT supported (not invented)', !Policy::isSupportedNetwork('trc20'));
T::throws('unknown network rejected', function () {
    Policy::networkDisplay('bsc');
}, 'InvalidArgumentException');

T::section('Confirmations clamped to supported range (spec §39)');
T::eq('2 stays', 2, Policy::normalizeConfirmations(2));
T::eq('below range clamps to 0', 0, Policy::normalizeConfirmations(-5));
T::eq('above range clamps to 2', 2, Policy::normalizeConfirmations(99));

T::section('Readiness gates before enabling (spec §34)');
list($ok, $why) = Policy::btcReadiness(['api_key' => '']);
T::ok('btc blocked without key', !$ok);
T::ok('btc reason given', strpos($why, 'API key') !== false);
list($ok,) = Policy::btcReadiness(['api_key' => 'x']);
T::ok('btc ready with key', $ok);
list($ok, $why) = Policy::usdtReadiness(['usdt_address' => '', 'usdt_network' => 'ethereum', 'etherscan_api_key' => 'e']);
T::ok('usdt blocked without address', !$ok);
list($ok, $why) = Policy::usdtReadiness(['usdt_address' => 'not-an-address', 'usdt_network' => 'ethereum', 'etherscan_api_key' => 'e']);
T::ok('usdt blocked with malformed address', !$ok);
list($ok, $why) = Policy::usdtReadiness(['usdt_address' => '0x' . str_repeat('a', 40), 'usdt_network' => 'trc20', 'etherscan_api_key' => 'e']);
T::ok('usdt blocked with unsupported network', !$ok);
list($ok, $why) = Policy::usdtReadiness(['usdt_address' => '0x' . str_repeat('a', 40), 'usdt_network' => 'ethereum', 'etherscan_api_key' => '']);
T::ok('usdt blocked without verifier key', !$ok);
list($ok,) = Policy::usdtReadiness(['usdt_address' => '0x' . str_repeat('a', 40), 'usdt_network' => 'ethereum', 'etherscan_api_key' => 'KEY']);
T::ok('usdt ready when fully configured', $ok);

T::section('Replay/idempotency guard (spec §20/§22)');
T::ok('fresh callback allowed', Policy::creditAllowed(false, false, false));
T::ok('dup transaction denied', !Policy::creditAllowed(true, false, false));
T::ok('paid invoice denied', !Policy::creditAllowed(false, true, false));
T::ok('terminal order denied', !Policy::creditAllowed(false, false, true));
T::ok('all combined denied', !Policy::creditAllowed(true, true, true));

/* -------------------------------------------------------------- vault -- */

T::section('Vault round-trip (spec §12/§13)');
list($gov, $store) = chs_gov();
$vault = new Vault($store, 'test-installation-secret-0123456789');
T::ok('no key initially', !$vault->configured());
T::eq('mask empty when unset', '', $vault->mask());
$vault->save('bk_live_TESTKEY_abcdef0123456789');
T::ok('configured after save', $vault->configured());
T::eq('read returns exact key', 'bk_live_TESTKEY_abcdef0123456789', $vault->read());
T::eq('mask is constant bullets', Vault::MASK, $vault->mask());
T::ok('stored ciphertext contains no key material',
    strpos((string) $store->get(Vault::STORE_KEY), 'TESTKEY') === false);
// tamper: corrupted ciphertext must never decrypt
$store->set(Vault::STORE_KEY, base64_encode(str_repeat("\x01", 60)));
T::eq('tampered ciphertext reads empty', '', $vault->read());
// wrong installation secret must not decrypt
$vault2 = new Vault($store, 'different-secret');
T::eq('wrong secret cannot read', '', $vault2->read());
$vault->save('');
T::ok('empty save clears key', !$vault->configured());
$vaultNoSecret = new Vault($store, '');
T::ok('vault unavailable without secret', !$vaultNoSecret->available());
T::eq('unavailable vault reads empty', '', $vaultNoSecret->read());

/* --------------------------------------------------------- governance -- */

T::section('Governance seeding from legacy (spec §31)');
list($gov, $store) = chs_gov();
$state = $gov->state();
T::ok('fresh install fail-closed: gateway off', !$state['gateway_enabled']);
T::ok('fresh install fail-closed: btc off', !$state['btc_enabled']);
T::eq('default confirmations 2', 2, $state['confirmations']);
$seeded = $gov->seedFromLegacy([
    'ApiKey' => 'bk_live_legacy', 'btcEnabled' => 'on', 'usdtEnabled' => '',
    'Confirmations' => '1', 'NetworkType' => 'ethereum',
], 7, 'ipx');
T::ok('seed ran', $seeded);
$state = $gov->state();
T::ok('seed adopts master on (working legacy install)', $state['gateway_enabled']);
T::ok('seed adopts btc on', $state['btc_enabled']);
T::ok('seed adopts usdt off', !$state['usdt_enabled']);
T::eq('seed adopts confirmations 1', 1, $state['confirmations']);
T::eq('seed adopts network', 'ethereum', $state['usdt_network']);
T::ok('seed is one-way (no double import)', !$gov->seedFromLegacy(['btcEnabled' => '', 'ApiKey' => ''], 7));
$stateAfter = $gov->state();
T::eq('state untouched after second seed', $state, $stateAfter);
$audit = $store->auditList(10);
T::ok('seed wrote audit', count($audit) >= 1);
T::eq('audit actor recorded', 7, (int) $audit[0]['actor_id']);

T::section('Seed with dead legacy config keeps master OFF (fail-closed)');
list($gov2,) = chs_gov();
$gov2->seedFromLegacy(['ApiKey' => '', 'btcEnabled' => 'on']);
T::ok('no api key → master stays off', !$gov2->state()['gateway_enabled']);

T::section('Save validation (spec §34: no enabled-but-dead configs)');
list($gov, $store) = chs_gov();
T::throws('enabling gateway without key rejected', function () use ($gov) {
    $gov->save(['gateway_enabled' => true, 'effective_api_key' => ''], 1);
}, 'InvalidArgumentException');
T::throws('enabling usdt without address rejected', function () use ($gov) {
    $gov->save([
        'gateway_enabled' => true, 'usdt_enabled' => true,
        'effective_api_key' => 'k', 'usdt_address' => '',
        'usdt_network' => 'ethereum', 'etherscan_api_key' => 'E',
    ], 1);
}, 'InvalidArgumentException');
T::throws('invented network rejected', function () use ($gov) {
    $gov->save(['gateway_enabled' => true, 'effective_api_key' => 'k', 'usdt_network' => 'polygon'], 1);
}, 'InvalidArgumentException');

T::section('Save success path + per-setting audit (spec §28)');
$gov->save([
    'gateway_enabled' => true, 'btc_enabled' => true, 'usdt_enabled' => true,
    'confirmations' => 1, 'usdt_network' => 'sepolia',
    'effective_api_key' => 'k', 'usdt_address' => '0x' . str_repeat('b', 40),
    'etherscan_api_key' => 'E',
], 42, 'iphash');
$state = $gov->state();
T::ok('gateway on', $state['gateway_enabled']);
T::ok('btc on', $state['btc_enabled']);
T::ok('usdt on', $state['usdt_enabled']);
T::eq('confirmations stored', 1, $state['confirmations']);
T::eq('network stored', 'sepolia', $state['usdt_network']);
$audit = $store->auditList(20);
$actions = array_map(function ($r) { return $r['action']; }, $audit);
T::ok('gateway.enabled audited', in_array('gateway.enabled', $actions, true));
T::ok('btc.enabled audited', in_array('btc.enabled', $actions, true));
T::ok('usdt.enabled audited', in_array('usdt.enabled', $actions, true));
T::ok('confirmations.changed audited', in_array('confirmations.changed', $actions, true));
T::ok('usdt.network_changed audited', in_array('usdt.network_changed', $actions, true));
T::ok('audit carries actor 42', (bool) array_filter($audit, function ($r) { return (int) $r['actor_id'] === 42; }));
T::ok('no secrets anywhere in audit rows',
    strpos(json_encode($audit), '0x' . str_repeat('b', 40)) === false);

T::section('Second save only audits what changed');
$before = count($store->auditList(100));
$gov->save([
    'gateway_enabled' => true, 'btc_enabled' => false, 'usdt_enabled' => true,
    'confirmations' => 1, 'usdt_network' => 'sepolia',
    'effective_api_key' => 'k', 'usdt_address' => '0x' . str_repeat('b', 40),
    'etherscan_api_key' => 'E',
], 42);
$audit = $store->auditList(100);
$newRows = array_slice($audit, 0, count($audit) - $before);
T::eq('exactly one change line', 1, count($newRows));
T::eq('change is btc.disabled', 'btc.disabled', $newRows[0]['action']);
T::ok('matrix now matches state', Policy::availabilityMatrix($gov->state()) === ['btc' => false, 'usdt' => true]);

T::section('Flip helper audits');
$gov->flip(Governance::KEY_GATEWAY, false, 9);
T::ok('master flipped off', !$gov->state()['gateway_enabled']);
T::ok('matrix collapses to none',
    Policy::availabilityMatrix($gov->state()) === ['btc' => false, 'usdt' => false]);

/* ---------------------------------------------------- connection test -- */

T::section('Connection tester taxonomy (spec §14)');
$mk = function ($response) { return new ConnectionTester(function () use ($response) { return $response; }); };
$r = $mk(['code' => 200, 'body' => '[]', 'errno' => 0, 'error' => ''])->test('k');
T::eq('200 → connected', ConnectionTester::OK, $r['category']);
T::ok('result ok flag', $r['ok']);
$r = $mk(['code' => 401, 'body' => '', 'errno' => 0, 'error' => ''])->test('k');
T::eq('401 → auth failed', ConnectionTester::AUTH_FAILED, $r['category']);
$r = $mk(['code' => 403, 'body' => '', 'errno' => 0, 'error' => ''])->test('k');
T::eq('403 → auth failed', ConnectionTester::AUTH_FAILED, $r['category']);
$r = $mk(['code' => 0, 'body' => '', 'errno' => 28, 'error' => 'timed out'])->test('k');
T::eq('curl timeout → timeout', ConnectionTester::TIMEOUT, $r['category']);
$r = $mk(['code' => 0, 'body' => '', 'errno' => 7, 'error' => 'refused'])->test('k');
T::eq('curl failure → unavailable', ConnectionTester::UNAVAILABLE, $r['category']);
$r = $mk(['code' => 502, 'body' => '', 'errno' => 0, 'error' => ''])->test('k');
T::eq('5xx → unavailable', ConnectionTester::UNAVAILABLE, $r['category']);
$t = new ConnectionTester(function () { throw new RuntimeException('boom'); });
T::eq('transport exception → unavailable', ConnectionTester::UNAVAILABLE, $t->test('k')['category']);
T::eq('empty key → invalid config', ConnectionTester::INVALID_CFG, $mk(['code' => 200, 'body' => '', 'errno' => 0, 'error' => ''])->test('')['category']);

T::section('No secret leaks through tester results');
$seen = json_encode($t->test('super-secret-key-1234567890'))
    . json_encode($mk(['code' => 401, 'body' => '{"error":"bad key super-secret-key-1234567890"}', 'errno' => 0, 'error' => ''])->test('super-secret-key-1234567890'));
T::ok('api key absent from all tester output', strpos($seen, 'super-secret-key-1234567890') === false);

/* -------------------------------------------------------- bridge glue -- */

T::section('Bridge: store factory + seeding + vault resolution');
CloudHost247\Blockonomics\Bridge::reset();
$pdoBridge = new PDO('sqlite::memory:');
$storeFactory = function () use ($pdoBridge) { return new PdoStore($pdoBridge); };
CloudHost247\Blockonomics\Bridge::$storeFactory = $storeFactory;
try {
    CloudHost247\Blockonomics\Bridge::state(['ApiKey' => 'x', 'btcEnabled' => 'on']);
    $matrix = CloudHost247\Blockonomics\Bridge::availableCurrencies();
    T::eq('bridge exposes matrix from seeded state', ['btc' => true, 'usdt' => false], $matrix);
    T::throws('bridge enforces disabled currency', function () {
        CloudHost247\Blockonomics\Bridge::assertCurrencyAllowed('usdt');
    }, 'CloudHost247\\Blockonomics\\PaymentUnavailableException');
    CloudHost247\Blockonomics\Bridge::assertCurrencyAllowed('btc');
    T::ok('bridge permits enabled currency', true);
    T::eq('resolver falls back to legacy key', 'legacy-key', CloudHost247\Blockonomics\Bridge::resolveApiKey(' legacy-key '));
    T::eq('resolver empty without anything', '', CloudHost247\Blockonomics\Bridge::resolveApiKey(''));
} finally {
    CloudHost247\Blockonomics\Bridge::$storeFactory = null;
    CloudHost247\Blockonomics\Bridge::reset();
}

T::finish();
