<?php
/**
 * C-2: ajax endpoint request flows, executed for real via ch247_eval_main
 * (requires stripped, fresh namespace per scenario). Ownership, CSRF,
 * action dispatch and catch mapping are all live; HTTP stays offline
 * through the ApiClient seam. Module dirs are snapshotted/restored.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();
    require_once ch247_mod() . '/lib/AjaxHandlers.php';
    require_once __DIR__ . '/doubles.php';

    use Ch247Test\FakeQuery;
    use CloudHost247\LTEProxy\ApiClient;
    use CloudHost247\LTEProxy\ApiException;
    use CloudHost247\LTEProxy\JsonResponseException;

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
    function sim($data, $code = 200)
    {
        return ['response' => is_string($data) ? $data : json_encode($data), 'http_code' => $code];
    }
    function snapmod()
    {
        $out = [];
        foreach (['logs', 'cache'] as $d) {
            $dir = ch247_mod() . '/' . $d;
            $out[$d] = is_dir($dir) ? scandir($dir) : null;
        }
        return $out;
    }
    function restoremod($snap)
    {
        foreach (['logs', 'cache'] as $d) {
            $dir = ch247_mod() . '/' . $d;
            if ($snap[$d] === null) {
                ch247_rmdir($dir);
                continue;
            }
            foreach (array_diff(scandir($dir), $snap[$d]) as $extra) {
                $p = $dir . '/' . $extra;
                if (is_dir($p)) {
                    ch247_rmdir($p);
                } else {
                    @unlink($p);
                }
            }
        }
    }
    function productRow(array $over = [])
    {
        return (object) array_merge([
            'id' => 5, 'servertype' => 'cloudhost247_lteproxy',
            'configoption1' => 'K', 'configoption2' => 'S',
            'configoption3' => 'https://api.test', 'configoption4' => 30,
            'configoption15' => 'off', 'configoption16' => 'INFO',
            'configoption17' => 'off', 'configoption18' => 600,
        ], $over);
    }
    function serviceRow(array $over = [])
    {
        return (object) array_merge([
            'id' => 11, 'userid' => 5, 'packageid' => 5, 'username' => 'ord-1',
        ], $over);
    }
    /** Standard ownership setup; returns nothing. */
    function own($service, $product)
    {
        \WHMCS\Database\Capsule::$handler = function ($table) use ($service, $product) {
            if ($table === 'tblproducts') {
                return new FakeQuery(['firstResult' => $product]);
            }
            return new FakeQuery(['firstResult' => $service]);
        };
    }
    /** Run one endpoint scenario; returns [data, status]. */
    function scenario($file, $tag)
    {
        try {
            ch247_eval_main($file, $tag);
        } catch (JsonResponseException $e) {
            return [$e->data, $e->status];
        }
        return [null, null];
    }
    function authbase()
    {
        $_SESSION = ['uid' => 5, 'csrf_token' => 'tok'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
        $GLOBALS['CH247_TEST_JSON_THROW'] = true;
    }

    $snap = snapmod();

    echo "== proxy-operations gates ==\n";
    ch247_reset();
    $GLOBALS['CH247_TEST_JSON_THROW'] = true;
    list($d, $s) = scenario('proxy-operations.php', 'ops401');
    check('anonymous 401', $s === 401 && $d['success'] === false);
    ch247_reset();
    authbase();
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
    list($d, $s) = scenario('proxy-operations.php', 'ops403');
    check('bad csrf 403', $s === 403);
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    $_POST = ['action' => 'get_proxies'];
    list($d, $s) = scenario('proxy-operations.php', 'ops400');
    check('missing service 400', $s === 400);
    ch247_reset();
    authbase();
    own(null, productRow());
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'ops404');
    check('unknown service 404', $s === 404);
    ch247_reset();
    authbase();
    own(serviceRow(), productRow(['servertype' => 'other']));
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'opsType');
    check('foreign servertype 400', $s === 400);

    echo "== proxy-operations dispatch ==\n";
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function ($method, $url) {
        if (strpos($url, '/orders/ord-1/proxies') !== false) {
            return sim(['data' => [['id' => 'p1', 'ip' => '1.2.3.4', 'port' => 1080]]]);
        }
        return sim(['data' => []]);
    });
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'opsHappy');
    check('get_proxies end to end', $s === 200 && $d['count'] === 1
        && $d['proxies'][0]['formatted_proxy'] === 'SOCKS5://1.2.3.4:1080');
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => []]);
    });
    $_POST = ['action' => 'nope', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'opsUnknown');
    check('unknown action names itself', $s === 400 && $d['error'] === 'Unknown action: nope');
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        throw new ApiException('weird code', 99);
    });
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'ops99');
    check('ApiException code clamped to 500 (LP-27)', $s === 500 && $d['error'] === 'weird code'
        && array_key_exists('error_code', $d) && array_key_exists('is_retryable', $d));
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        throw new ApiException('gone', 404);
    });
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'ops404b');
    check('valid exception code passes through', $s === 404 && $d['error'] === 'gone');
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        throw new \RuntimeException('kaboom');
    });
    $_POST = ['action' => 'get_proxies', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-operations.php', 'ops500');
    check('generic catch masks internals (LP-28)', $s === 500
        && $d['error'] === 'An unexpected error occurred. Please try again.');
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => []]);
    });
    $_GET = ['action' => 'get_balance', 'service_id' => '11'];
    $_POST = [];
    list($d, $s) = scenario('proxy-operations.php', 'opsGet');
    check('GET action works (LP-29 raw action)', $s === 200 && $d['success'] === true
        && $d['formatted_balance'] === '$0.00');

    echo "== proxy-test dispatch ==\n";
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function ($method, $url) {
        if (strpos($url, '/test/alive') !== false) {
            return sim(['data' => ['alive' => true, 'ip' => '1.2.3.4']]);
        }
        return sim(['data' => [['id' => 'p1']]]);
    });
    $_POST = ['action' => 'test_alive', 'service_id' => '11', 'proxy_id' => 'p1'];
    list($d, $s) = scenario('proxy-test.php', 'tHappy');
    check('test_alive end to end', $s === 200 && $d['alive'] === true && $d['ip'] === '1.2.3.4');
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => []]);
    });
    $_POST = ['action' => 'batch_test', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-test.php', 'tBatch');
    check('batch_test end to end', $s === 200 && $d['summary']['total'] === 0);
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        throw new ApiException('bad', 99);
    });
    $_POST = ['action' => 'test_alive', 'service_id' => '11', 'proxy_id' => 'p1'];
    list($d, $s) = scenario('proxy-test.php', 't99');
    check('test catch clamps + slimmer body', $s === 500 && $d['error'] === 'bad'
        && !array_key_exists('error_code', $d));
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => []]);
    });
    $_POST = ['action' => 'wat', 'service_id' => '11'];
    list($d, $s) = scenario('proxy-test.php', 'tUnknown');
    check('unknown test action', $s === 400 && $d['error'] === 'Unknown test action');

    echo "== stats dispatch ==\n";
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => [['status' => 'active', 'region' => 'us', 'carrier' => 'v']]]);
    });
    $_GET = ['action' => 'get_usage_stats', 'service_id' => '11'];
    list($d, $s) = scenario('stats.php', 'sHappy');
    check('usage stats end to end', $s === 200 && $d['stats']['total_proxies'] === 1
        && $d['stats']['active_proxies'] === 1);
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        return sim(['data' => []]);
    });
    $_GET = ['action' => 'wat', 'service_id' => '11'];
    list($d, $s) = scenario('stats.php', 'sUnknown');
    check('unknown stats action', $s === 400);
    ch247_reset();
    authbase();
    own(serviceRow(), productRow());
    ApiClient::setHttpHandler(function () {
        throw new \RuntimeException('kaboom');
    });
    $_GET = ['action' => 'get_usage_stats', 'service_id' => '11'];
    list($d, $s) = scenario('stats.php', 's500');
    check('stats generic catch', $s === 500 && $d['error'] === 'Failed to load statistics');

    echo "== hygiene ==\n";
    check('mains write module runtime dirs (snapshot is load-bearing)',
        is_file(ch247_mod() . '/cache/rate_limit.json'));
    restoremod($snap);
    check('module dirs restored', snapmod() == $snap);

    ch247_reset();
    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
