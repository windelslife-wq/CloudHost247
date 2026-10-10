<?php
require __DIR__ . '/bootstrap.php';

// Regression guards for the C-3..C-6 hardening. Behavioural where the code can
// run offline; static wiring checks where the code needs WHMCS or a browser.

use Chs\Core\Csrf;
use Chs\Core\Identity;
use Chs\Providers\Ai\HttpAiProvider;

$root = __DIR__ . '/..';

// C-3: the consent endpoint is gated by the session token.
$portal = file_get_contents($root . '/lib/Http/CustomerPortal.php');
$consentStart = strpos($portal, 'protected function recordConsent()');
$consentBody = $consentStart === false ? '' : substr($portal, $consentStart, 1400);
T::ok('C-3: recordConsent verifies the CSRF token before recording',
    $consentBody !== ''
    && strpos($consentBody, 'Csrf::verifyRequest()') !== false
    && strpos($consentBody, 'Csrf::verifyRequest()') < strpos($consentBody, 'ConsentService'));
T::ok('C-3: a missing or stale token answers 403 (not a silent write)',
    strpos($consentBody, "'Session token missing or stale") !== false
    && strpos($consentBody, '], 403)') !== false);

$hooks = file_get_contents($root . '/hooks.php');
T::ok('C-3: head hook emits the chs-csrf-token meta on every page',
    strpos($hooks, 'name="chs-csrf-token"') !== false);

// The cookie banner (C-3 client side) lives in the theme, outside this module,
// so this offline suite cannot read it. It was checked by hand at commit time.

// C-3 behaviour: the verifier rejects missing, empty, and wrong tokens.
Csrf::pin('consent-token-abc');
T::ok('C-3: correct token verifies', Csrf::verify('consent-token-abc') === true);
T::ok('C-3: empty token rejected', Csrf::verify('') === false);
T::ok('C-3: wrong token rejected', Csrf::verify('consent-token-xyz') === false);
Csrf::clear();

// C-4: the test seams refuse to run outside the harness. The harness defines
// CHS_TESTING, so the seams must work here.
T::ok('C-4: CHS_TESTING is set by the harness', defined('CHS_TESTING') && CHS_TESTING === true);
$identitySrc = file_get_contents($root . '/lib/Core/Identity.php');
T::ok('C-4: Identity seams call the test-context guard',
    substr_count($identitySrc, "self::assertTestContext(") === 2);
$csrfSrc = file_get_contents($root . '/lib/Core/Csrf.php');
T::ok('C-4: Csrf::pin is guarded by CHS_TESTING',
    preg_match('/function pin\(\$token\)\s*\{\s*\/\/ C-4[^}]*CHS_TESTING/s', $csrfSrc) === 1);
Identity::setClient(4242);
T::ok('C-4: seam still works inside the harness', Identity::clientId() === 4242);
Identity::setClient(null);

// C-5: the AI provider refuses non-http(s) endpoints (no file:// reads).
$ai = new HttpAiProvider(null);
$post = new ReflectionMethod($ai, 'post');
$post->setAccessible(true);
T::throws('C-5: AI endpoint rejects file:// URLs', function () use ($post, $ai) {
    $post->invoke($ai, 'file:///etc/passwd', [], '{}', 5);
}, 'RuntimeException');
T::throws('C-5: AI endpoint rejects gopher:// URLs', function () use ($post, $ai) {
    $post->invoke($ai, 'gopher://127.0.0.1:70/', [], '{}', 5);
}, 'RuntimeException');

$registrar = file_get_contents($root . '/lib/Providers/Domain/HttpRegistrarProvider.php');
T::ok('C-5: registrar curl restricts protocols to http(s), including redirects',
    strpos($registrar, 'CURLOPT_PROTOCOLS') !== false
    && strpos($registrar, 'CURLOPT_REDIR_PROTOCOLS') !== false
    && strpos($registrar, 'CURLPROTO_HTTP | CURLPROTO_HTTPS') !== false);

// C-6: the cron job refuses web requests before bootstrapping WHMCS.
$cron = file_get_contents($root . '/cron/cloudhost247services.php');
$guardPos = strpos($cron, "if (php_sapi_name() !== 'cli')");
$initPos = strpos($cron, 'init.php');
T::ok('C-6: cron has a CLI-only guard', $guardPos !== false && strpos($cron, 'http_response_code(403)') !== false);
T::ok('C-6: the guard runs before WHMCS init.php is loaded',
    $guardPos !== false && $initPos !== false && $guardPos < $initPos);
T::ok('C-6: the old WHMCS-defined bypass is gone',
    strpos($cron, "!defined('WHMCS')") === false);

T::finish();
