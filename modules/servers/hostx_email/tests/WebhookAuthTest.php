<?php
/**
 * Offline regression tests for webhook authentication.
 * Run with tests/run.mjs (php-wasm). Prints FAIL=<n> on completion.
 */

namespace WHMCS\Database {
    class CapsuleFakeQuery {
        private $setting;
        public function where($col, $val) { $this->setting = $val; return $this; }
        public function value($col) { return $GLOBALS['__cfg'][$this->setting] ?? null; }
    }
    class Capsule {
        public static function table($name) { return new CapsuleFakeQuery(); }
    }
}

namespace {

define('WHMCS', true);

// Passthrough stand-in so the test can set the API key directly
// (the real decrypt needs the WHMCS encryption hash).
if (!function_exists('hostx_email_decrypt')) {
    function hostx_email_decrypt($data) { return (string) $data; }
}

require_once __DIR__ . '/../api.php';

$pass = 0;
$fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

$body = '{"type":"mailbox.deleted","email":"victim@example.com"}';
$apiKey = 'test-api-key-123';
$googleToken = 'google-channel-secret';

function api($key) { return new HostxEmailAPI(['serverpassword' => $key, 'serverip' => '']); }

// --- Professional: HMAC required ---
$GLOBALS['__cfg'] = ['hostx_email_google_channel_token' => $googleToken];
$p = api($apiKey);
$goodSig = hash_hmac('sha256', $body, $apiKey);
check('professional: no signature rejected', !$p->authenticateWebhook('professional', $body, []));
check('professional: empty signature rejected', !$p->authenticateWebhook('professional', $body, ['x-webhook-signature' => '']));
check('professional: wrong signature rejected', !$p->authenticateWebhook('professional', $body, ['x-webhook-signature' => 'deadbeef']));
check('professional: valid X-Webhook-Signature accepted', $p->authenticateWebhook('professional', $body, ['x-webhook-signature' => $goodSig]));
check('professional: valid X-Hub-Signature-256 accepted', $p->authenticateWebhook('professional', $body, ['x-hub-signature-256' => 'sha256=' . $goodSig]));
check('professional: tampered body rejected', !$p->authenticateWebhook('professional', $body . ' ', ['x-webhook-signature' => $goodSig]));

// Empty API key must fail closed (HMAC keyed with '' is forgeable)
$pNoKey = api('');
$forged = hash_hmac('sha256', $body, '');
check('professional: no API key rejects even a correct-looking HMAC', !$pNoKey->authenticateWebhook('professional', $body, ['x-webhook-signature' => $forged]));

// --- Google Workspace: channel token required ---
check('google: no token rejected', !$p->authenticateWebhook('google_workspace', $body, []));
check('google: wrong token rejected', !$p->authenticateWebhook('google_workspace', $body, ['x-goog-channel-token' => 'nope']));
check('google: correct token accepted', $p->authenticateWebhook('google_workspace', $body, ['x-goog-channel-token' => $googleToken]));

$GLOBALS['__cfg'] = [];
check('google: any token rejected when no secret configured', !$p->authenticateWebhook('google_workspace', $body, ['x-goog-channel-token' => 'anything']));
check('google: empty token rejected when no secret configured', !$p->authenticateWebhook('google_workspace', $body, ['x-goog-channel-token' => '']));

// --- Unknown provider ---
check('unknown provider rejected', !$p->authenticateWebhook('mystery', $body, []));

echo "\nwebhook auth: PASS=$pass FAIL=$fail\n";
echo "FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
}
