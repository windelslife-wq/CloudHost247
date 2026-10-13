<?php
/**
 * Offline regressions for the phoneservices P-5…P-8 fixes.
 * Run with run.mjs (php-wasm). Prints PASS=<n> FAIL=<n> on completion.
 *
 * Covers:
 *  - P-5: per-client API key store (pure parts) + source pins that the
 *         middleware no longer grants all-tenant access and that mutating
 *         number operations require a client-scoped identity.
 *  - P-6: Config encryption-at-rest dual-read guards + source pins that the
 *         admin form stops echoing secrets and routes saves through Config::set.
 *  - P-7: no legacy mysql_* result functions remain anywhere in the module.
 *  - P-8: provider status → message-enum mapping (pure) + source pins that
 *         the Twilio status callback and Vonage DLR move the stored record.
 */

require_once __DIR__ . '/../autoload.php';

use PhoneServices\Core\Config;
use PhoneServices\Core\ApiKeyStore;
use PhoneServices\Services\SmsService;

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

echo "== P-8: provider status mapping (pure) ==\n";
$map = 'PhoneServices\\Services\\SmsService::mapProviderStatus';
check('twilio queued -> pending', $map('twilio', 'queued') === 'pending');
check('twilio sent -> sent', $map('twilio', 'sent') === 'sent');
check('twilio delivered -> delivered', $map('twilio', 'delivered') === 'delivered');
check('twilio received -> received', $map('twilio', 'received') === 'received');
check('twilio read -> read', $map('twilio', 'read') === 'read');
check('twilio failed -> failed', $map('twilio', 'failed') === 'failed');
check('twilio expired -> failed', $map('twilio', 'expired') === 'failed');
check('twilio mapping is case-insensitive', $map('twilio', 'Delivered') === 'delivered');
check('twilio unknown status falls back to sent', $map('twilio', 'something-new') === 'sent');
check('vonage 0 accepted -> sent', $map('vonage', '0') === 'sent');
check('vonage 1 queued -> pending', $map('vonage', '1') === 'pending');
check('vonage 2 delivered -> delivered', $map('vonage', '2') === 'delivered');
check('vonage 3 expired -> failed', $map('vonage', '3') === 'failed');
check('vonage 4 undelivered -> failed', $map('vonage', '4') === 'failed');
check('vonage 5 rejected -> failed', $map('vonage', '5') === 'failed');
check('vonage 6 deleted -> failed', $map('vonage', '6') === 'failed');
check('vonage 7 receipt-unavailable -> sent', $map('vonage', '7') === 'sent');
check('vonage 8 cancelled -> failed', $map('vonage', '8') === 'failed');
check('vonage 9 not-delivered -> failed', $map('vonage', '9') === 'failed');
check('vonage 10 failed -> failed', $map('vonage', '10') === 'failed');
check('vonage 11 error -> failed', $map('vonage', '11') === 'failed');
check('vonage 12 unknown-error -> failed', $map('vonage', '12') === 'failed');
check('vonage text delivered -> delivered', $map('vonage', 'delivered') === 'delivered');
check('vonage text rejected -> failed', $map('vonage', 'rejected') === 'failed');
check('generic queued -> pending', $map('unknown', 'queued') === 'pending');
check('generic accepted -> sent', $map('unknown', 'accepted') === 'sent');
check('generic failed -> failed', $map('unknown', 'failed') === 'failed');
check('generic unknown -> sent', $map('unknown', 'what?') === 'sent');

echo "== P-5: per-client API key store (pure) ==\n";
$key = ApiKeyStore::generateKey();
check('generated key carries the psk_ prefix', strpos($key, 'psk_') === 0);
check('generated key is 48 hex chars after the prefix', strlen($key) === 4 + 48 && ctype_xdigit(substr($key, 4)));
check('generated keys are unique', ApiKeyStore::generateKey() !== $key);
check('key hashes are SHA-256', ApiKeyStore::hashKey($key) === hash('sha256', $key));
check('empty key never resolves', ApiKeyStore::lookup('') === null);
check('non-psk keys are rejected without a DB hit', ApiKeyStore::lookup('some-shared-legacy-key') === null);

echo "== P-6: encrypted-at-rest dual-read guards (pure) ==\n";
check('twilio_auth_token is a sensitive key', Config::isSensitiveKey('twilio_auth_token'));
check('vonage_signature_secret is a sensitive key', Config::isSensitiveKey('vonage_signature_secret'));
check('api_key is a sensitive key (legacy rows still encrypted on save)', Config::isSensitiveKey('api_key'));
check('api_mode is not a sensitive key', !Config::isSensitiveKey('api_mode'));
check('empty secrets are never "encrypted"', Config::encryptSecret('') === '');
if (!function_exists('encrypt')) {
    check('outside WHMCS, encryptSecret is a transparent no-op (offline guard)', Config::encryptSecret('topsecret') === 'topsecret');
}
check('legacy plaintext values read back unchanged', Config::decryptValue('legacy-plaintext') === 'legacy-plaintext');
check('undecryptable enc:v1: blobs fall back to the stored string (offline guard)', Config::decryptValue('enc:v1:garbage') === 'enc:v1:garbage');

echo "== source pins: P-5 middleware + controller scoping ==\n";
$middleware = $read('lib/API/Middleware/AuthMiddleware.php');
check('middleware resolves keys through ApiKeyStore', strpos($middleware, 'ApiKeyStore::lookup($apiKey)') !== false);
check('a valid key scopes the session to that client', strpos($middleware, "\$_SESSION['api_client_id'] = \$row['clientid'];") !== false);
check('stale api_client_id is cleared at the start of each request', strpos($middleware, "unset(\$_SESSION['api_client_id']);") !== false);
check('the all-tenant legacy key is rejected fail-closed', strpos($middleware, "return !empty(\$validKey) && hash_equals(\$validKey, \$apiKey);") === false);
check('the legacy key attempt is logged, not accepted', strpos($middleware, 'Legacy shared phoneservices API key rejected') !== false);

$base = $read('lib/API/Controllers/BaseController.php');
check('API-key client scope wins over the session uid', strpos($base, "if (!empty(\$_SESSION['api_client_id']))") !== false);

$numbers = $read('lib/API/Controllers/NumbersController.php');
check('number mutations require a client-scoped identity (x3)', substr_count($numbers, 'Client authentication required') === 3);
check('ownership check no longer bypassable with userId=0', strpos($numbers, "(\$userId > 0 && \$number['user_id'] != \$userId)") === false);

echo "== source pins: P-6 admin form + Config ==\n";
$configSrc = $read('lib/Core/Config.php');
check('Config encrypts sensitive settings at rest', strpos($configSrc, 'function_exists(\'encrypt\')') !== false && strpos($configSrc, 'ENCRYPT_PREFIX') !== false);
check('Config decrypts transparently with a legacy fallback', strpos($configSrc, 'function_exists(\'decrypt\')') !== false);

$moduleSrc = $read('lib/Core/Module.php');
check('config saves route through Config::set (encrypted)', strpos($moduleSrc, '$config->set($field, $post[$field]);') !== false);
check('vonage_signature_secret is now saveable from the admin UI', strpos($moduleSrc, "'vonage_signature_secret'") !== false);
check('per-client key management screen exists', strpos($moduleSrc, 'function renderApiKeys') !== false && strpos($moduleSrc, 'ApiKeyStore::create($clientid') !== false);
check('the apikeys table is created on activate and upgrade', substr_count($moduleSrc, 'ApiKeyStore::ensureTable();') >= 2);

$tpl = $read('templates/admin/api_config.tpl');
check('api_config no longer echoes stored secrets into the form', strpos($tpl, 'value="<?php echo $config->get(') === false);
check('api_config explains blank-means-keep for secrets', strpos($tpl, 'Leave a secret field blank to keep its current value') !== false);

echo "== source pins: P-7 legacy mysql_* removed ==\n";
$legacyHits = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir));
foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $path = $file->getPathname();
    if (strpos($path, 'node_modules') !== false || strpos($path, '/vendor/') !== false || strpos($path, '/tests/') !== false) {
        continue;
    }
    if (preg_match('/mysql_fetch_assoc|mysql_num_rows|mysql_error|mysql_affected_rows/', file_get_contents($path))) {
        $legacyHits[] = $path;
    }
}
check('no legacy mysql_* result functions remain in the module', empty($legacyHits));
if (!empty($legacyHits)) {
    foreach ($legacyHits as $hit) {
        echo "  - $hit\n";
    }
}

echo "== source pins: P-8 webhooks move the stored record ==\n";
$twilioHook = $read('api/webhooks/twilio.php');
check('twilio status callback updates the message record', strpos($twilioHook, "updateMessageStatus(\$messageId, 'twilio', \$status") !== false);
check('twilio status callback carries the provider error text', strpos($twilioHook, "\$_POST['ErrorMessage']") !== false);
$vonageHook = $read('api/webhooks/vonage.php');
check('vonage DLR updates the message record', strpos($vonageHook, "updateMessageStatus(\$messageId, 'vonage', \$status") !== false);
check('vonage DLR carries the provider error text', strpos($vonageHook, "\$data['error-text']") !== false);

$smsSrc = $read('lib/Services/SmsService.php');
check('status updates are scoped to message_id + provider + outbound direction',
    strpos($smsSrc, "->where('message_id', \$messageId)") !== false
        && strpos($smsSrc, "->where('provider', \$provider)") !== false
        && strpos($smsSrc, "->where('direction', 'outbound')") !== false);

echo "== migration + schema ==\n";
check('schema defines mod_phoneservices_apikeys', strpos($read('install/schema.sql'), 'CREATE TABLE IF NOT EXISTS `mod_phoneservices_apikeys`') !== false);
check('migration 1.0.1 exists for existing installs', file_exists($moduleDir . '/install/migrations/1.0.1.sql'));
$entry = $read('phoneservices.php');
check('admin output routes the api_keys screen', strpos($entry, "case 'api_keys':") !== false);
check('admin sidebar links the api_keys screen', strpos($entry, 'action=api_keys') !== false);

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
