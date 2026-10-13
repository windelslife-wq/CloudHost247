<?php
/**
 * Offline regressions for the smmaddon M-2/M-5/M-6/M-7 fixes.
 * Run with run.mjs (php-wasm). Prints PASS=<n> FAIL=<n> on completion.
 */

require_once __DIR__ . '/../lib/OrderQuantity.php';
require_once __DIR__ . '/../lib/ApiClient.php';

use SmmAddon\OrderQuantity;
use SmmAddon\ApiClient;

$pass = 0;
$fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

$moduleDir = dirname(__DIR__);
$read = function ($rel) use ($moduleDir) {
    return file_get_contents($moduleDir . '/' . $rel);
};

echo "== M-5: quantity resolution (pure) ==\n";
$r = OrderQuantity::resolve(100, 500, 0, 0);
check('paid config option wins over client field (M-5 core)', $r['quantity'] === 100 && $r['source'] === 'config_option' && !$r['refused']);
$r = OrderQuantity::resolve(100, 500, 0, 1000);
check('paid quantity caps the order even with a provider max', $r['quantity'] === 100);
$r = OrderQuantity::resolve(100, 500, 50, 200);
check('bounds applied with paid quantity', $r['quantity'] === 100);
$r = OrderQuantity::resolve(0, 500, 0, 0);
check('client-editable quantity refused when smm_max = 0 (M-5 core)', $r['refused'] === true && $r['quantity'] === 0);
$r = OrderQuantity::resolve(0, 500, 0, 1000);
check('client field accepted as fallback when a positive cap exists', $r['quantity'] === 500 && $r['source'] === 'custom_field' && !$r['refused']);
$r = OrderQuantity::resolve(0, 500, 0, 300);
check('client field clamped to smm_max', $r['quantity'] === 300);
$r = OrderQuantity::resolve(0, 100, 200, 1000);
check('smm_min floor applied to fallback quantity', $r['quantity'] === 200);
$r = OrderQuantity::resolve(0, 0, 0, 0);
check('no quantity at all is refused', $r['refused'] === true);
$r = OrderQuantity::resolve(50, 0, 100, 0);
check('paid quantity never inflated above the paid amount (min floor cannot overbill)', $r['quantity'] === 50);
$r = OrderQuantity::resolve(1000, 0, 0, 250);
check('paid quantity clamped down to smm_max', $r['quantity'] === 250);

echo "== M-5: option value parsing (pure) ==\n";
check('parses plain number', OrderQuantity::parseOptionValue('1000') === 1000);
check('parses comma number', OrderQuantity::parseOptionValue('1,000 followers') === 1000);
check('parses embedded number', OrderQuantity::parseOptionValue('abc 250 def') === 250);
check('no digits -> 0', OrderQuantity::parseOptionValue('Premium') === 0);
check('empty -> 0', OrderQuantity::parseOptionValue('') === 0);
check('null -> 0', OrderQuantity::parseOptionValue(null) === 0);
check('array -> 0', OrderQuantity::parseOptionValue([]) === 0);
check('recognises "Quantity"', OrderQuantity::isQuantityOptionName('Quantity'));
check('recognises "Number of Followers"', OrderQuantity::isQuantityOptionName('Number of Followers'));
check('recognises "Package Size"', OrderQuantity::isQuantityOptionName('Package Size'));
check('rejects "Domain"', !OrderQuantity::isQuantityOptionName('Domain'));
check('rejects "Username"', !OrderQuantity::isQuantityOptionName('Username'));

echo "== M-6: API client HTTPS enforcement ==\n";
try { new ApiClient('https://panel.example.com/api/v2', 'key'); check('https URL accepted', true); }
catch (Exception $e) { check('https URL accepted', false); }
try { new ApiClient('http://panel.example.com/api/v2', 'key'); check('remote http URL rejected', false); }
catch (Exception $e) { check('remote http URL rejected', strpos($e->getMessage(), 'HTTPS') !== false); }
try { new ApiClient('http://127.0.0.1:8080/api', 'key'); check('local http endpoint permitted', true); }
catch (Exception $e) { check('local http endpoint permitted', false); }
try { new ApiClient('http://localhost/api', 'key'); check('localhost http permitted', true); }
catch (Exception $e) { check('localhost http permitted', false); }
try { new ApiClient('panel.example.com/api/v2', 'key'); check('schemeless URL rejected', false); }
catch (Exception $e) { check('schemeless URL rejected', true); }
try { new ApiClient('  ', 'key'); check('empty URL rejected', false); }
catch (Exception $e) { check('empty URL rejected', true); }

echo "== source pins: M-5 hook wiring ==\n";
$hooks = $read('hooks.php');
check('hook derives paid quantity from config options', strpos($hooks, 'function smmaddon_derive_paid_quantity') !== false);
check('hook resolves quantity through OrderQuantity', strpos($hooks, 'OrderQuantity::resolve(') !== false);
check('custom field no longer assigned straight to $quantity', strpos($hooks, '$quantity = (int) $cf->value') === false);
check('legacy unclamped max check removed from the hook', strpos($hooks, 'if ($smmService->smm_max > 0 && $quantity > $smmService->smm_max)') === false);
check('refused orders are recorded as error rows', strpos($hooks, "'refused' => true, 'reason' => \$resolved['reason']") !== false);

echo "== source pins: M-2 ajax hardening ==\n";
$dispatcher = $read('lib/AdminDispatcher.php');
check('mutating ajax actions are listed', strpos($dispatcher, "in_array(\$action, ['sync_services', 'refresh_order'], true)") !== false);
check('mutating ajax require POST + CSRF with a 403', strpos($dispatcher, "http_response_code(403);") !== false);

echo "== source pins: M-6 + M-7 settings save ==\n";
check('settings save validates the URL scheme', strpos($dispatcher, 'API URL must use HTTPS') !== false);
$keepKey = "if (\$apiKey !== '')";
$writeKey = "Helper::setConfig('api_key', \$apiKey);";
check('blank key keeps the stored credential', strpos($dispatcher, $keepKey) !== false && strpos($dispatcher, $writeKey) !== false);
check('ApiClient constructor enforces https', strpos($read('lib/ApiClient.php'), '#^https://#i') !== false);
$helper = $read('lib/Helper.php');
check('Helper encrypts secrets with the enc:v1: prefix', strpos($helper, 'const ENCRYPT_PREFIX') !== false && strpos($helper, 'function_exists(\'encrypt\')') !== false);
check('config api_key decrypted on read', strpos($helper, 'if ($key === \'api_key\') {') !== false);
check('server-map key encrypted on write, decrypted on read', strpos($helper, "self::encryptSecret(\$apiKey)") !== false && strpos($helper, 'self::decryptValue($row->api_key)') !== false);
$tpl = $read('templates/admin/settings.php');
check('settings form no longer prefills the stored key', strpos($tpl, "htmlspecialchars(\$config['api_key']") === false);

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
