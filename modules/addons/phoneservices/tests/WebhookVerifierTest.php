<?php
/**
 * Offline tests for webhook signature verification.
 * Run with run.mjs (php-wasm). Prints FAIL=<n> on completion.
 */

require_once __DIR__ . '/../lib/Security/WebhookVerifier.php';

use PhoneServices\Security\WebhookVerifier;

$pass = 0;
$fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

// ---- Twilio ----
// Known answer computed independently (Node crypto) from the documented
// algorithm: URL + sorted name/value pairs, HMAC-SHA1 with the auth token, base64.
$twToken = '12345';
$twUrl = 'https://mycompany.com/myapp';
$twParams = [
    'CallSid' => 'CA1234567890ABCDE',
    'Caller' => '+12349013030',
    'Digits' => '1234',
    'From' => '+12349013030',
    'To' => '+18005551212',
];
$twSig = '3KI2uRuYyAdhZIJXcpU0izDUzWI=';
check('twilio: known-answer vector accepted', WebhookVerifier::twilio($twToken, $twUrl, $twParams, $twSig));
$shuffled = array_reverse($twParams, true);
check('twilio: parameter order does not matter', WebhookVerifier::twilio($twToken, $twUrl, $shuffled, $twSig));
$tampered = $twParams; $tampered['Digits'] = '9999';
check('twilio: tampered parameter rejected', !WebhookVerifier::twilio($twToken, $twUrl, $tampered, $twSig));
check('twilio: wrong URL rejected', !WebhookVerifier::twilio($twToken, 'https://evil.example/myapp', $twParams, $twSig));
check('twilio: URL with extra query string rejected', !WebhookVerifier::twilio($twToken, $twUrl . '?x=1', $twParams, $twSig));
check('twilio: wrong token rejected', !WebhookVerifier::twilio('wrong', $twUrl, $twParams, $twSig));
check('twilio: missing signature rejected', !WebhookVerifier::twilio($twToken, $twUrl, $twParams, ''));
check('twilio: unsigned request (no params, no sig) rejected', !WebhookVerifier::twilio($twToken, $twUrl, [], ''));
check('twilio: empty token fails closed', !WebhookVerifier::twilio('', $twUrl, $twParams, $twSig));
check('twilio: array parameter fails closed', !WebhookVerifier::twilio($twToken, $twUrl, ['A' => ['x']], $twSig));

// ---- Vonage (HS256 JWT) ----
function b64u($raw) { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function makeJwt($secret, array $payload, $alg = 'HS256') {
    $h = b64u(json_encode(['alg' => $alg, 'typ' => 'JWT']));
    $p = b64u(json_encode($payload));
    $s = b64u(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$s";
}
$vSecret = 'vonage-signature-secret-0123456789';
$good = makeJwt($vSecret, ['exp' => time() + 300, 'payload_hash' => str_repeat('a', 64)]);
check('vonage: valid token accepted', WebhookVerifier::vonageJwt($vSecret, "Bearer $good"));
check('vonage: lowercase bearer accepted', WebhookVerifier::vonageJwt($vSecret, "bearer $good"));
check('vonage: wrong secret rejected', !WebhookVerifier::vonageJwt('other-secret', "Bearer $good"));
check('vonage: missing header rejected', !WebhookVerifier::vonageJwt($vSecret, ''));
check('vonage: non-Bearer scheme rejected', !WebhookVerifier::vonageJwt($vSecret, "Basic $good"));
check('vonage: empty secret fails closed', !WebhookVerifier::vonageJwt('', "Bearer $good"));
$expired = makeJwt($vSecret, ['exp' => time() - 10]);
check('vonage: expired token rejected', !WebhookVerifier::vonageJwt($vSecret, "Bearer $expired"));
$none = makeJwt($vSecret, ['exp' => time() + 300], 'none');
check('vonage: alg none rejected', !WebhookVerifier::vonageJwt($vSecret, "Bearer $none"));
$parts = explode('.', $good);
$forgedPayload = b64u(json_encode(['exp' => time() + 300, 'payload_hash' => 'x']));
check('vonage: tampered payload rejected', !WebhookVerifier::vonageJwt($vSecret, "Bearer {$parts[0]}.$forgedPayload.{$parts[2]}"));
check('vonage: malformed token rejected', !WebhookVerifier::vonageJwt($vSecret, 'Bearer not-a-jwt'));

echo "\nwebhook verifier: PASS=$pass FAIL=$fail\n";
echo "FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
