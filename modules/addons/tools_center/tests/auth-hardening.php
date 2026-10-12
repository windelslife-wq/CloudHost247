<?php
/** Offline tests for API authentication and WHMCS session CSRF helpers. */

$GLOBALS['tc_test_csrf_token'] = 'csrf-session-token-0123456789abcdef';
function generate_token($format = null) {
    return isset($GLOBALS['tc_test_csrf_token']) ? $GLOBALS['tc_test_csrf_token'] : '';
}
class ToolsCenterCacheWakeupProbe {
    public static $wakeupCalls = 0;
    public function __wakeup() { self::$wakeupCalls++; }
}

require __DIR__ . '/../external-api/auth.php';
require __DIR__ . '/../external-api/cache.php';
require __DIR__ . '/../external-api/rate-limit.php';
require __DIR__ . '/../external-api/tools/productivity.php';
require __DIR__ . '/../external-api/tools/webmaster.php';
require __DIR__ . '/../csrf.php';

$pass = 0;
$fail = 0;
function auth_chk($label, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; } else { $fail++; echo "FAIL $label\n"; }
}
function auth_make_hmac($secret, $clientId, $timestamp, $nonce, $signature = null) {
    $payload = json_encode(['client_id' => $clientId, 'timestamp' => $timestamp, 'nonce' => $nonce]);
    $sig = $signature === null ? hash_hmac('sha256', $payload, $secret) : $signature;
    return base64_encode($payload) . '.' . $sig;
}

$nonceDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tc-auth-' . bin2hex(random_bytes(8));
mkdir($nonceDir, 0700, true);
$secret = 'test-secret-with-sufficient-entropy';
$config = [
    'api_token' => 'static-test-token-1234567890',
    'api_secret' => $secret,
    'cache_path' => $nonceDir . DIRECTORY_SEPARATOR,
    'hmac_ttl_seconds' => 300,
    'hmac_clock_skew_seconds' => 30,
];

$_GET = [];
$_COOKIE = [];
$_POST = [];
$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_X_API_TOKEN' => $config['api_token']];
$auth = new APIAuth($config);
auth_chk('static API key in X-API-Token header is accepted', $auth->validateRequest([]) && $auth->getClientId() === 0);

$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer ' . $config['api_token']];
$auth = new APIAuth($config);
auth_chk('static API key in Authorization Bearer header is accepted', $auth->validateRequest([]));

$_SERVER = ['REQUEST_METHOD' => 'POST'];
$auth = new APIAuth($config);
auth_chk('legacy POST-body API key remains supported', $auth->validateRequest(['api_token' => $config['api_token']]));

$_GET = ['api_token' => $config['api_token']];
$_REQUEST = ['api_token' => $config['api_token']];
$auth = new APIAuth($config);
auth_chk('query and merged REQUEST credentials are rejected', !$auth->validateRequest([]));

$_GET = [];
$_REQUEST = [];
$_COOKIE = ['api_token' => $config['api_token']];
$auth = new APIAuth($config);
auth_chk('cookie credentials are rejected', !$auth->validateRequest([]));

$_COOKIE = [];
$_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_X_API_TOKEN' => $config['api_token']];
$auth = new APIAuth($config);
auth_chk('non-POST requests cannot authenticate', !$auth->validateRequest(['api_token' => $config['api_token']]));

$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_X_API_TOKEN' => 'your-secure-api-token-here'];
$placeholderConfig = $config;
$placeholderConfig['api_token'] = 'your-secure-api-token-here';
$auth = new APIAuth($placeholderConfig);
auth_chk('shipped example API key cannot authenticate', !$auth->validateRequest([]));

$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_X_API_TOKEN' => ''];
$auth = new APIAuth($config);
auth_chk('an empty auth header cannot fall through to a body credential', !$auth->validateRequest(['api_token' => $config['api_token']]));

$_SERVER = ['REQUEST_METHOD' => 'POST'];
$oneTimeToken = APIAuth::generateToken(42, $secret);
$_SERVER['HTTP_X_API_TOKEN'] = $oneTimeToken;
$auth = new APIAuth($config);
auth_chk('fresh signed HMAC token is accepted', $auth->validateRequest([]));
auth_chk('HMAC token binds the authenticated client ID', $auth->getClientId() === 42);
$auth = new APIAuth($config);
auth_chk('HMAC token replay is rejected', !$auth->validateRequest([]));

$oldToken = auth_make_hmac($secret, 7, time() - 301, str_repeat('a', 32));
$_SERVER['HTTP_X_API_TOKEN'] = $oldToken;
$auth = new APIAuth($config);
auth_chk('expired HMAC token is rejected', !$auth->validateRequest([]));

$futureToken = auth_make_hmac($secret, 7, time() + 31, str_repeat('b', 32));
$_SERVER['HTTP_X_API_TOKEN'] = $futureToken;
$auth = new APIAuth($config);
auth_chk('HMAC token outside clock-skew allowance is rejected', !$auth->validateRequest([]));

$badSignature = auth_make_hmac($secret, 7, time(), str_repeat('c', 32), str_repeat('0', 64));
$_SERVER['HTTP_X_API_TOKEN'] = $badSignature;
$auth = new APIAuth($config);
auth_chk('incorrect HMAC signature is rejected', !$auth->validateRequest([]));

$missingNoncePayload = json_encode(['client_id' => 7, 'timestamp' => time()]);
$_SERVER['HTTP_X_API_TOKEN'] = base64_encode($missingNoncePayload) . '.' . hash_hmac('sha256', $missingNoncePayload, $secret);
$auth = new APIAuth($config);
auth_chk('HMAC token without a nonce is rejected', !$auth->validateRequest([]));

$emptySecretConfig = $config;
$emptySecretConfig['api_secret'] = '';
$_SERVER['HTTP_X_API_TOKEN'] = auth_make_hmac($secret, 7, time(), str_repeat('d', 32));
$auth = new APIAuth($emptySecretConfig);
auth_chk('HMAC authentication fails closed without a configured secret', !$auth->validateRequest([]));

$GLOBALS['tc_test_csrf_token'] = 'csrf-session-token-0123456789abcdef';
auth_chk('WHMCS CSRF token can be issued for the page', tools_center_issue_csrf_token() === $GLOBALS['tc_test_csrf_token']);
auth_chk('matching session CSRF token is accepted', tools_center_verify_csrf_token($GLOBALS['tc_test_csrf_token']));
auth_chk('wrong session CSRF token is rejected', !tools_center_verify_csrf_token('csrf-wrong-token-0123456789abcdef'));
auth_chk('non-string CSRF token is rejected', !tools_center_verify_csrf_token(['csrf-session-token-0123456789abcdef']));
$GLOBALS['tc_test_csrf_token'] = '';
auth_chk('missing WHMCS CSRF token fails closed', !tools_center_verify_csrf_token('csrf-session-token-0123456789abcdef'));

$limitConfig = [
    'cache_path' => $nonceDir . DIRECTORY_SEPARATOR,
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 2,
        'requests_per_hour' => 100,
        'requests_per_day' => 100,
    ],
];
$limiter = new RateLimiter($limitConfig);
auth_chk('rate limiter allows a request within the configured limit', $limiter->check(501, '203.0.113.25', 'dns', 'mxLookup'));
auth_chk('rate limiter allows the last request within the configured limit', $limiter->check(501, '203.0.113.25', 'dns', 'mxLookup'));
auth_chk('rate limiter blocks requests above the per-minute limit', !$limiter->check(501, '203.0.113.25', 'dns', 'mxLookup'));
$limiterReloaded = new RateLimiter($limitConfig);
auth_chk('rate limit block survives a new limiter instance', !$limiterReloaded->check(501, '203.0.113.25', 'dns', 'mxLookup'));
auth_chk('per-client usage can be read from the locked store', $limiterReloaded->getUsage(501, '203.0.113.25')['per_minute'] === 3);
auth_chk('separate authenticated clients receive independent buckets', $limiterReloaded->check(502, '203.0.113.25', 'dns', 'mxLookup'));

$rateFile = $nonceDir . DIRECTORY_SEPARATOR . 'rate-limits.json';
file_put_contents($rateFile, 'corrupt-rate-limit-store');
$brokenLimiter = new RateLimiter($limitConfig);
auth_chk('corrupt rate-limit storage fails closed', !$brokenLimiter->check(503, '203.0.113.25', 'dns', 'mxLookup'));

$cacheConfig = [
    'cache_enabled' => true,
    'cache_driver' => 'file',
    'cache_path' => $nonceDir . DIRECTORY_SEPARATOR,
    'redis' => [],
];
$cache = new CacheManager($cacheConfig);
$probeFile = $nonceDir . DIRECTORY_SEPARATOR . 'cache-probe.cache';
file_put_contents($probeFile, serialize(['expires' => time() + 60, 'value' => new ToolsCenterCacheWakeupProbe()]));
$cachedProbe = $cache->get('cache-probe');
auth_chk('cache reads do not instantiate stored PHP objects', is_object($cachedProbe)
    && get_class($cachedProbe) === '__PHP_Incomplete_Class'
    && ToolsCenterCacheWakeupProbe::$wakeupCalls === 0);
$expiredFile = $nonceDir . DIRECTORY_SEPARATOR . 'cache-expired.cache';
file_put_contents($expiredFile, serialize(['expires' => time() - 1, 'value' => 'stale']));
auth_chk('expired cache entries are removed', $cache->get('cache-expired') === null && !file_exists($expiredFile));
auth_chk('cache set/get preserves ordinary array results', $cache->set('fresh', ['ok' => true], 60)
    && $cache->get('fresh') === ['ok' => true]);

$productivityTools = new ProductivityTools([]);
$validUsername = $productivityTools->usernameChecker(['username' => 'Cloud_Host-247']);
auth_chk('username format accepts common letters, digits, dot, underscore and hyphen',
    $validUsername['valid_format'] === true && $validUsername['availability_checked'] === false
    && count($validUsername['platform_urls']) > 0
    && strpos($validUsername['platform_urls']['github']['url'], 'Cloud_Host-247') !== false);
$invalidUsernameInput = ' Cloud/Host ';
$invalidUsername = $productivityTools->usernameChecker(['username' => $invalidUsernameInput]);
auth_chk('invalid username characters and surrounding whitespace are preserved, not stripped',
    $invalidUsername['username'] === $invalidUsernameInput && $invalidUsername['valid_format'] === false
    && $invalidUsername['platform_urls'] === [] && $invalidUsername['suggestions'] === []);
$badUsernameRejected = false;
try { $productivityTools->usernameChecker(['username' => ['invalid']]); } catch (Throwable $e) { $badUsernameRejected = true; }
auth_chk('non-string username input is rejected', $badUsernameRejected);
$longUsernameRejected = false;
try { $productivityTools->usernameChecker(['username' => str_repeat('a', 129)]); } catch (Throwable $e) { $longUsernameRejected = true; }
auth_chk('oversize username input is rejected', $longUsernameRejected);

$webmasterTools = new WebmasterTools([]);
$pageRankResult = $webmasterTools->pageRankChecker(['url' => 'example.com']);
auth_chk('PageRank unavailable result contains no invented or substitute scores',
    $pageRankResult['status'] === 'not_configured' && $pageRankResult['page_rank'] === null
    && $pageRankResult['alternatives'] === [
        'ahrefs_rank' => null,
        'moz_domain_authority' => null,
        'semrush_rank' => null,
    ]
    && strpos($pageRankResult['note'], 'No PageRank score') !== false);

$syntaxFiles = [
    __DIR__ . '/../external-api/api.php',
    __DIR__ . '/../external-api/auth.php',
    __DIR__ . '/../external-api/rate-limit.php',
    __DIR__ . '/../external-api/cache.php',
    __DIR__ . '/../external-api/config.php',
    __DIR__ . '/../external-api/outbound.php',
    __DIR__ . '/../external-api/tools/search.php',
    __DIR__ . '/../external-api/tools/security.php',
    __DIR__ . '/../external-api/tools/dns.php',
    __DIR__ . '/../external-api/tools/ip.php',
    __DIR__ . '/../external-api/tools/network.php',
    __DIR__ . '/../external-api/tools/productivity.php',
    __DIR__ . '/../external-api/tools/webmaster.php',
    __DIR__ . '/../hooks.php',
    __DIR__ . '/../clientarea.php',
    __DIR__ . '/../csrf.php',
    __DIR__ . '/domain-search-test.php',
    __DIR__ . '/clientarea-route-test.php',
    __DIR__ . '/tool-outbound-security.php',
];
$syntaxOk = true;
foreach ($syntaxFiles as $syntaxFile) {
    try {
        token_get_all(file_get_contents($syntaxFile), TOKEN_PARSE);
    } catch (ParseError $e) {
        $syntaxOk = false;
        echo 'PHP parse error in ' . basename($syntaxFile) . ': ' . $e->getMessage() . "\n";
    }
}
auth_chk('modified PHP integration files parse cleanly', $syntaxOk);

@unlink($rateFile);
@unlink($nonceDir . DIRECTORY_SEPARATOR . 'hmac-used-nonces.json');
@unlink($probeFile);
@unlink($nonceDir . DIRECTORY_SEPARATOR . 'fresh.cache');
@rmdir($nonceDir);
echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
