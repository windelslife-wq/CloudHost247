<?php
/**
 * C-2: ApiClient behavioural suite via the static HTTP seam (no sockets).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();

    use CloudHost247\LTEProxy\ApiClient;
    use CloudHost247\LTEProxy\ApiException;
    use CloudHost247\LTEProxy\Logger;

    $pass = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $pass, $fail;
        if ($cond) {
            $pass++;
        } else {
            $fail++;
            echo "FAIL: $label\n";
        }
    }
    function mkclient($tmp, array $extra = [])
    {
        $config = array_merge([
            'api_key' => 'KEY-abc-1234567890',
            'api_secret' => 'S',
            'api_base_url' => 'https://api.test/',
            'log_directory' => $tmp . '/logs',
            'cache_directory' => $tmp . '/cache',
        ], $extra);
        return new ApiClient($config, new Logger(['log_directory' => $tmp . '/logs']));
    }
    function sim($data, $code = 200)
    {
        return ['response' => is_string($data) ? $data : json_encode($data), 'http_code' => $code];
    }
    function timeoutOf(ApiClient $c)
    {
        $r = new ReflectionProperty(ApiClient::class, 'timeout');
        $r->setAccessible(true);
        return $r->getValue($c);
    }

    echo "== construction ==\n";
    $tmp = ch247_tmpdir('api');
    check('timeout floor 0->1', timeoutOf(mkclient($tmp, ['api_timeout' => 0])) === 1);
    check('timeout floor negative->1', timeoutOf(mkclient($tmp, ['api_timeout' => -5])) === 1);
    check('timeout kept', timeoutOf(mkclient($tmp, ['api_timeout' => 45])) === 45);
    check('timeout default 30', timeoutOf(mkclient($tmp)) === 30);
    $c = mkclient($tmp);
    check('base url rtrimmed', $c->getApiBaseUrl() === 'https://api.test');
    check('masked key long', $c->getMaskedApiKey() === 'KEY-****7890');
    $short = mkclient($tmp, ['api_key' => 'short']);
    check('masked key short', $short->getMaskedApiKey() === '****');

    echo "== success path + headers ==\n";
    ch247_reset();
    $seen = [];
    ApiClient::setHttpHandler(function ($method, $url, $body, $headers) use (&$seen) {
        $seen[] = [$method, $url, $body, $headers];
        return sim(['success' => true, 'data' => ['balance' => 12]]);
    });
    $tmp = ch247_tmpdir('api2');
    $c = mkclient($tmp);
    $res = $c->getBalance();
    check('GET decodes', $res['data']['balance'] === 12 && $c->getLastHttpCode() === 200);
    check('auth header sent', in_array('Authorization: Bearer KEY-abc-1234567890', $seen[0][3], true));
    check('version header sent', in_array('X-API-Version: 1', $seen[0][3], true)
        || in_array('X-API-Version: 1.0', $seen[0][3], true));
    check('balance cached (one HTTP call)', $c->getBalance() === $res && count($seen) === 1);
    check('credentials valid', $c->validateCredentials() === true);
    ApiClient::setHttpHandler(function () {
        return sim(['message' => 'bad key'], 401);
    });
    $tmp = ch247_tmpdir('api3');
    $c = mkclient($tmp);
    check('credentials invalid', $c->validateCredentials() === false);
    check('last error kept', $c->getLastError() === 'bad key');

    echo "== response guards (LP-21) ==\n";
    ch247_reset();
    ApiClient::setHttpHandler(function () {
        return sim('"just a string"');
    });
    $tmp = ch247_tmpdir('api4');
    $c = mkclient($tmp);
    try {
        $c->getAccountInfo();
        check('scalar JSON rejected', false);
    } catch (ApiException $e) {
        check('scalar JSON rejected', $e->getMessage() === 'Invalid API response format' && $e->getCode() === 500);
    }
    ApiClient::setHttpHandler(function () {
        return ['response' => 'not json{{{', 'http_code' => 200];
    });
    try {
        $c->getAccountInfo();
        check('malformed JSON rejected', false);
    } catch (ApiException $e) {
        check('malformed JSON rejected', $e->getCode() === 500);
    }
    ApiClient::setHttpHandler(function () {
        return sim(['success' => false, 'message' => 'nope', 'code' => 503]);
    });
    try {
        $c->getAccountInfo();
        check('int code survives (no TypeError)', false);
    } catch (ApiException $e) {
        check('int code survives (no TypeError)', $e->getMessage() === 'nope' && $e->getCode() === 503
            && $e->getErrorCode() === '503');
    } catch (\TypeError $e) {
        check('int code survives (no TypeError)', false);
    }
    ApiClient::setHttpHandler(function () {
        return sim(['success' => false, 'message' => 'weird', 'code' => 'E_X']);
    });
    try {
        $c->getAccountInfo();
        check('string code falls back to HTTP code', false);
    } catch (ApiException $e) {
        check('string code falls back to HTTP code', $e->getCode() === 200 && $e->getErrorCode() === 'E_X');
    }
    ApiClient::setHttpHandler(function () {
        return sim(['message' => 'bad input'], 422);
    });
    try {
        $c->getAccountInfo();
        check('HTTP error message extracted', false);
    } catch (ApiException $e) {
        check('HTTP error message extracted', $e->getMessage() === 'bad input' && $e->getCode() === 422);
    }
    ApiClient::setHttpHandler(function () {
        return ['response' => '<html>oops', 'http_code' => 500];
    });
    try {
        $c->getAccountInfo();
        check('non-JSON HTTP error generic', false);
    } catch (ApiException $e) {
        check('non-JSON HTTP error generic', $e->getMessage() === 'HTTP Error 500');
    }

    echo "== param validation (LP-26) ==\n";
    ch247_reset();
    $calls = 0;
    ApiClient::setHttpHandler(function () use (&$calls) {
        $calls++;
        return sim(['success' => true]);
    });
    $tmp = ch247_tmpdir('api5');
    $c = mkclient($tmp);
    try {
        $c->updateClientIp('p1', 'bogus');
        check('bad client ip rejected pre-request', false);
    } catch (ApiException $e) {
        check('bad client ip rejected pre-request', $e->getCode() === 400 && $calls === 0);
    }
    try {
        $c->updateClientIp('', '198.51.100.7');
        check('empty proxy id rejected', false);
    } catch (ApiException $e) {
        check('empty proxy id rejected', $e->getCode() === 400 && $calls === 0);
    }
    try {
        $c->buyProxy1By1(['carrier' => 'verizon']);
        check('missing buy params rejected', false);
    } catch (ApiException $e) {
        check('missing buy params rejected',
            strpos($e->getMessage(), 'Missing required parameters:') === 0 && $calls === 0);
    }

    echo "== request shaping ==\n";
    ch247_reset();
    $seen = [];
    ApiClient::setHttpHandler(function ($method, $url, $body) use (&$seen) {
        $seen[] = [$method, $url, $body];
        return sim(['success' => true, 'data' => []]);
    });
    $tmp = ch247_tmpdir('api6');
    $c = mkclient($tmp);
    $c->updateClientIp('p1', '198.51.100.7');
    check('update URL + body', $seen[0][0] === 'POST'
        && $seen[0][1] === 'https://api.test/proxies/p1/client-ip'
        && $seen[0][2] === ['client_ip' => '198.51.100.7']);
    $c->buyProxy1By1(['region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'SOCKS5', 'duration' => 30]);
    check('buy URL + method', $seen[1][0] === 'POST' && $seen[1][1] === 'https://api.test/proxies/buy/1by1');
    $c->getOrders(['status' => 'active']);
    check('filter query string', $seen[2][1] === 'https://api.test/orders?status=active');

    echo "== retry policy (LP-9) ==\n";
    check('idempotent set', ApiClient::isIdempotentMethod('GET') && ApiClient::isIdempotentMethod('head')
        && ApiClient::isIdempotentMethod('PUT') && ApiClient::isIdempotentMethod('DELETE'));
    check('non-idempotent set', !ApiClient::isIdempotentMethod('POST') && !ApiClient::isIdempotentMethod('PATCH')
        && !ApiClient::isIdempotentMethod('OPTIONS'));
    ch247_reset();
    $attempts = 0;
    ApiClient::setHttpHandler(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            return ['response' => false, 'http_code' => 0, 'error' => 'conn refused', 'errno' => CURLE_COULDNT_CONNECT];
        }
        return sim(['success' => true, 'data' => ['ok' => 1]]);
    });
    $tmp = ch247_tmpdir('api7');
    $c = mkclient($tmp);
    $res = $c->getAccountInfo();
    check('GET transport failure retried', $attempts === 2 && $res['data']['ok'] === 1);
    $attempts = 0;
    ApiClient::setHttpHandler(function () use (&$attempts) {
        $attempts++;
        return ['response' => false, 'http_code' => 0, 'error' => 'conn refused', 'errno' => CURLE_COULDNT_CONNECT];
    });
    try {
        $c->buyProxy(['quantity' => 1, 'region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'SOCKS5', 'duration' => 30]);
        check('POST never retried', false);
    } catch (ApiException $e) {
        check('POST never retried', $attempts === 1 && $e->getCode() === 0);
    }
    $attempts = 0;
    ApiClient::setHttpHandler(function () use (&$attempts) {
        $attempts++;
        return ['response' => false, 'http_code' => 0, 'error' => 'bad url', 'errno' => 3];
    });
    try {
        $c->getAccountInfo();
        check('non-retryable errno not retried', false);
    } catch (ApiException $e) {
        check('non-retryable errno not retried', $attempts === 1);
    }
    $attempts = 0;
    ApiClient::setHttpHandler(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            return sim(['message' => 'try again'], 503);
        }
        return sim(['success' => true, 'data' => ['ok' => 2]]);
    });
    $res = $c->getAccountInfo();
    check('GET 503 retried', $attempts === 2 && $res['data']['ok'] === 2);
    $attempts = 0;
    ApiClient::setHttpHandler(function () use (&$attempts) {
        $attempts++;
        return sim(['message' => 'try again'], 503);
    });
    try {
        $c->buyProxy(['quantity' => 1, 'region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'SOCKS5', 'duration' => 30]);
        check('POST 503 not retried', false);
    } catch (ApiException $e) {
        check('POST 503 not retried', $attempts === 1 && $e->getCode() === 503);
    }

    echo "== limiter integration ==\n";
    ch247_reset();
    ApiClient::setHttpHandler(function () {
        return sim(['success' => true, 'data' => []]);
    });
    $tmp = ch247_tmpdir('api8');
    $c = mkclient($tmp, ['rate_limit_requests' => 1, 'rate_limit_window' => 60, 'rate_limit_burst' => 10]);
    $c->getAccountInfo();
    try {
        $c->getAccountInfo();
        check('limiter blocks second request', false);
    } catch (ApiException $e) {
        check('limiter blocks second request', $e->getCode() === 429
            && strpos($e->getMessage(), 'Rate limit exceeded') === 0);
    }

    ch247_reset();
    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
