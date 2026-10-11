<?php
/**
 * C-2: ajax-handler behavioural suite (FakeApi + real Logger + JSON seam).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();
    require_once ch247_mod() . '/lib/AjaxHandlers.php';
    require_once __DIR__ . '/doubles.php';

    use Ch247Test\FakeApi;
    use CloudHost247\LTEProxy\ApiException;
    use CloudHost247\LTEProxy\JsonResponseException;
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
    function fresh()
    {
        $tmp = ch247_tmpdir('h');
        ch247_reset();
        $_SESSION = ['uid' => 5];
        $GLOBALS['CH247_TEST_JSON_THROW'] = true;
        return [$tmp, new FakeApi($tmp), new Logger(['log_directory' => $tmp . '/logs'])];
    }
    /** Run a handler; returns [data, status]. Non-JSON throwables fail loudly. */
    function capture($fn)
    {
        try {
            $fn();
        } catch (JsonResponseException $e) {
            return [$e->data, $e->status];
        }
        return [null, null];
    }

    echo "== get proxies / order details ==\n";
    list($tmp, $api, $logger) = fresh();
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetProxies($api, '', $logger);
    });
    check('empty order short-circuits', $s === 200 && $d['proxies'] === [] && $d['count'] === 0
        && $api->callsTo('getOrderProxies') === []);
    $api->script['getOrderProxies'] = ['data' => [
        ['id' => 'p1', 'ip' => '1.2.3.4', 'port' => 1080, 'proxy_type' => 'socks5',
         'region' => 'us', 'carrier' => 'verizon', 'status' => 'active',
         'username' => 'u', 'password' => 'secretpassword123', 'expires_at' => ''],
    ]];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetProxies($api, 'ord-1', $logger);
    });
    $row = $d['proxies'][0];
    check('row shaping', $s === 200 && $d['count'] === 1 && $row['id'] === 'p1'
        && $row['type'] === 'SOCKS5' && $row['is_1by1'] === false
        && $row['time_remaining'] === 'Unknown');
    check('password masked, full kept', $row['password'] === 'sec***********123'
        && $row['full_password'] === 'secretpassword123');
    check('proxy strings built', $row['formatted_proxy'] === 'SOCKS5://u:secretpassword123@1.2.3.4:1080'
        && $row['copy_format'] === '1.2.3.4:1080:u:secretpassword123');
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetOrderDetails($api, '', $logger);
    });
    check('empty order details', $s === 200 && $d['order'] === null);
    $api->script['getOrders'] = ['data' => [
        ['id' => 'ord-1', 'status' => 'active', 'quantity' => 3, 'region' => 'us',
         'created_at' => '2026-01-02 15:04:05', 'expires_at' => '2001-01-01'],
    ]];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetOrderDetails($api, 'ord-1', $logger);
    });
    check('order details mapped', $s === 200 && $d['order']['quantity'] === 3
        && $d['order']['created_at'] === 'Jan 2, 2026 3:04 PM'
        && $d['order']['time_remaining'] === 'Expired');
    ch247_rmdir($tmp);

    echo "== update client ip (IDOR guard) ==\n";
    list($tmp, $api, $logger) = fresh();
    $api->script['getOrderProxies'] = ['data' => [['id' => 'p1']]];
    $_POST = [];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateClientIp($api, 'ord-1', $logger);
    });
    check('missing fields 400', $s === 400 && $d['success'] === false);
    $_POST = ['proxy_id' => 'p1', 'client_ip' => '198.51.100.7'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateClientIp($api, '', $logger);
    });
    check('empty order 400', $s === 400);
    $_POST = ['proxy_id' => 'foreign', 'client_ip' => '198.51.100.7'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateClientIp($api, 'ord-1', $logger);
    });
    check('foreign proxy 404 (LP-8)', $s === 404 && $d['error'] === 'Proxy not found in this order'
        && $api->callsTo('updateClientIp') === []);
    $_POST = ['proxy_id' => 'p1', 'client_ip' => 'bogus'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateClientIp($api, 'ord-1', $logger);
    });
    check('bad ip 400', $s === 400 && $api->callsTo('updateClientIp') === []);
    $_POST = ['proxy_id' => 'p1', 'client_ip' => '198.51.100.7'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateClientIp($api, 'ord-1', $logger);
    });
    check('happy path', $s === 200 && $d['success'] === true && $d['ip'] === '198.51.100.7'
        && $api->callsTo('updateClientIp') === [[['p1', '198.51.100.7']][0]]);
    check('update logged', strpos(file_get_contents($tmp . '/logs/cloudhost247_lteproxy.log'), 'Updated client IP') !== false);
    ch247_rmdir($tmp);

    echo "== update region/carrier/type/auth/rotation ==\n";
    list($tmp, $api, $logger) = fresh();
    $api->script['getOrderProxies'] = ['data' => [['id' => 'p1']]];
    $_POST = ['proxy_id' => 'p1', 'region' => 'xx'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateRegion($api, 'ord-1', $logger);
    });
    check('bad region 400', $s === 400 && $api->callsTo('updateRegion') === []);
    $_POST = ['proxy_id' => 'p1', 'region' => 'us'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateRegion($api, 'ord-1', $logger);
    });
    check('region happy', $s === 200 && $d['region'] === 'us'
        && $d['message'] === 'Region updated to United States');
    $_POST = ['proxy_id' => 'p1', 'carrier' => 'xx'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateCarrier($api, 'ord-1', $logger);
    });
    check('bad carrier 400', $s === 400);
    $_POST = ['proxy_id' => 'p1', 'carrier' => 'kpn'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateCarrier($api, 'ord-1', $logger);
    });
    check('carrier happy', $s === 200 && $d['carrier'] === 'kpn'
        && $api->callsTo('updateCarrier') === [[['p1', 'kpn']][0]]);
    $_POST = ['proxy_id' => 'p1', 'proxy_type' => 'socks5'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateProxyType($api, 'ord-1', $logger);
    });
    check('type upper echo', $s === 200 && $d['type'] === 'SOCKS5');
    $_POST = ['proxy_id' => 'p1', 'auth_type' => 'username_password', 'password' => 'newpw'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateAuth($api, 'ord-1', $logger);
    });
    check('auth password passthrough', $s === 200
        && $api->callsTo('updateProxyAuth')[0] === ['p1', ['auth_type' => 'username_password', 'password' => 'newpw']]);
    $savedServer = $_SERVER;
    $_SERVER = ['REMOTE_ADDR' => '9.9.9.9'];
    $_POST = ['proxy_id' => 'p1', 'auth_type' => 'ip_whitelist'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateAuth($api, 'ord-1', $logger);
    });
    check('auth falls back to request ip', $s === 200
        && $api->callsTo('updateProxyAuth')[1] === ['p1', ['auth_type' => 'ip_whitelist', 'client_ip' => '9.9.9.9']]);
    $_SERVER = $savedServer;
    $_POST = ['proxy_id' => 'p1', 'rotation_type' => 'timed', 'rotation_interval' => '9999'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateRotation($api, 'ord-1', $logger);
    });
    check('rotation interval clamped top', $s === 200 && $d['interval'] === 1440
        && $api->callsTo('updateProxyRotation')[0][1]['rotation_interval'] === 1440);
    $_POST = ['proxy_id' => 'p1', 'rotation_type' => 'timed', 'rotation_interval' => '0'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleUpdateRotation($api, 'ord-1', $logger);
    });
    check('rotation interval clamped bottom', $d['interval'] === 1);
    ch247_rmdir($tmp);

    echo "== rotate / reveal / extend / cancel ==\n";
    list($tmp, $api, $logger) = fresh();
    $api->script['getOrderProxies'] = ['data' => [['id' => 'p1']]];
    $_POST = ['proxy_id' => 'foreign'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleRotateIp($api, 'ord-1', $logger);
    });
    check('rotate foreign 404', $s === 404 && $api->callsTo('rotateProxyIp') === []);
    $api->script['rotateProxyIp'] = ['data' => ['new_ip' => '5.6.7.8']];
    $_POST = ['proxy_id' => 'p1'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleRotateIp($api, 'ord-1', $logger);
    });
    check('rotate happy', $s === 200 && $d['new_ip'] === '5.6.7.8');
    $api->script['reveal1By1ProxyIp'] = ['data' => ['ip' => '9.9.9.9']];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleRevealIp($api, 'ord-1', $logger);
    });
    check('reveal happy', $s === 200 && $d['ip'] === '9.9.9.9');
    $_POST = ['duration' => '999'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleExtendOrder($api, 'ord-1', $logger);
    });
    check('extend clamped to 365', $s === 200 && $d['duration'] === 365
        && $api->callsTo('extendOrder')[0] === ['ord-1', 365]);
    $_POST = [];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleExtendOrder($api, '', $logger);
    });
    check('extend needs order', $s === 400);
    list($d, $s) = capture(function () use ($api, $logger) {
        handleCancelOrder($api, 'ord-1', $logger);
    });
    check('cancel is 403, vendor untouched (LP-11)', $s === 403
        && stripos($d['error'], 'billing') !== false && $api->callsTo('cancelOrder') === []);
    ch247_rmdir($tmp);

    echo "== balance / available / trial ==\n";
    list($tmp, $api, $logger) = fresh();
    $api->script['getBalance'] = ['data' => ['balance' => 12.5, 'currency' => 'USD']];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetBalance($api, $logger);
    });
    check('balance shape', $s === 200 && $d['balance'] === 12.5 && $d['formatted_balance'] === '$12.50');
    $api->script['getBalance'] = ['data' => ['balance' => '12.5', 'currency' => 'USD']];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetBalance($api, $logger);
    });
    check('string balance formatted (LP-52b)', $s === 200 && $d['formatted_balance'] === '$12.50');
    $_GET = ['type' => '1by1'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleGetAvailableProxies($api, $logger);
    });
    check('available routes 1by1', $s === 200 && $d['type'] === '1by1'
        && count($api->callsTo('getAvailable1By1Proxies')) === 1);
    $_GET = ['type' => 'us_carrier', 'carrier' => 'kpn'];
    capture(function () use ($api, $logger) {
        handleGetAvailableProxies($api, $logger);
    });
    check('available carrier passthrough', $api->callsTo('getAvailableUsCarrierProxies')[0] === ['kpn']);
    $_GET = ['type' => 'bogus'];
    capture(function () use ($api, $logger) {
        handleGetAvailableProxies($api, $logger);
    });
    check('available defaults to us', count($api->callsTo('getAvailableUsProxies')) === 1);
    list($d, $s) = capture(function () use ($api, $logger) {
        handleRequestTrial($api, [], $logger);
    });
    check('trial disabled 403 (LP-22)', $s === 403 && $api->callsTo('addTrial') === []);
    $params = ['configoption12' => 'on'];
    $_POST = ['region' => 'xx'];
    list($d, $s) = capture(function () use ($api, $params, $logger) {
        handleRequestTrial($api, $params, $logger);
    });
    check('trial bad region 400', $s === 400);
    $_POST = ['region' => 'us', 'carrier' => 'xx'];
    list($d, $s) = capture(function () use ($api, $params, $logger) {
        handleRequestTrial($api, $params, $logger);
    });
    check('trial bad carrier 400', $s === 400);
    $_POST = ['region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'xx'];
    list($d, $s) = capture(function () use ($api, $params, $logger) {
        handleRequestTrial($api, $params, $logger);
    });
    check('trial bad type 400', $s === 400);
    $api->script['addTrial'] = ['data' => ['trial_id' => 't-1', 'expires_at' => 'soon']];
    $_POST = ['region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'SOCKS5'];
    list($d, $s) = capture(function () use ($api, $params, $logger) {
        handleRequestTrial($api, $params, $logger);
    });
    check('trial happy', $s === 200 && $d['trial_id'] === 't-1'
        && $api->callsTo('addTrial')[0] === [['client_id' => 5, 'region' => 'us', 'carrier' => 'verizon', 'proxy_type' => 'SOCKS5']]);
    ch247_rmdir($tmp);

    echo "== alive / speed / batch ==\n";
    list($tmp, $api, $logger) = fresh();
    $api->script['getOrderProxies'] = ['data' => [['id' => 'p1']]];
    $_POST = ['proxy_id' => 'foreign'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleTestAlive($api, 'ord-1', $logger);
    });
    check('alive foreign 404', $s === 404 && $api->callsTo('testProxyAlive') === []);
    $api->script['testProxyAlive'] = ['data' => ['alive' => true, 'status' => 'ok',
        'ip' => '1.2.3.4', 'location' => 'L', 'isp' => 'I']];
    $_POST = ['proxy_id' => 'p1'];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleTestAlive($api, 'ord-1', $logger);
    });
    check('alive happy', $s === 200 && $d['alive'] === true && $d['ip'] === '1.2.3.4'
        && is_numeric($d['response_time_ms']));
    $api->script['testProxySpeed'] = ['data' => ['download_speed' => '1250000', 'upload_speed' => '625000',
        'latency' => '42.5', 'jitter' => '1.25', 'packet_loss' => '0']];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleTestSpeed($api, 'ord-1', $logger);
    });
    check('string numerics do not crash (LP-52)', $s === 200
        && $d['download_speed']['formatted'] === '10 Mbps'
        && $d['upload_speed']['formatted'] === '5 Mbps'
        && $d['latency_ms'] === 42.5 && $d['quality_rating'] === 'Excellent');
    check('quality bands', calculateQualityRating(6250000.0, 20.0, 0.0) === 'Excellent'
        && calculateQualityRating(1250000.0, 150.0, 2.0) === 'Good'
        && calculateQualityRating(125000.0, 150.0, 2.0) === 'Fair'
        && calculateQualityRating(625000.0, 300.0, 7.0) === 'Poor'
        && calculateQualityRating(0.0, 900.0, 50.0) === 'Very Poor');
    $rows = [];
    for ($i = 0; $i < 60; $i++) {
        $rows[] = ['id' => 'p' . $i, 'ip' => '10.0.0.' . $i];
    }
    $api = new FakeApi($tmp);
    $api->script['getOrderProxies'] = ['data' => $rows];
    $api->script['testProxyAlive'] = function ($id) {
        $n = (int) substr($id, 1);
        return ['data' => ['alive' => ($n % 2 === 0)]];
    };
    $api->script['testProxySpeed'] = ['data' => ['download_speed' => 1000000, 'latency' => '10.5']];
    list($d, $s) = capture(function () use ($api, $logger) {
        handleBatchTest($api, 'ord-1', $logger);
    });
    check('batch caps at 50 (LP-23)', $s === 200 && $d['summary']['total'] === 50
        && $d['summary']['truncated'] === true && count($d['results']) === 50
        && count($api->callsTo('testProxyAlive')) === 50);
    check('batch summary math', $d['summary']['alive'] === 25 && $d['summary']['dead'] === 25
        && $d['summary']['success_rate'] === 50.0 && $d['summary']['avg_speed'] === '8 Mbps');
    check('batch string latency cast (LP-52b)', $d['results'][0]['latency'] === 10.5);
    $api2 = new FakeApi($tmp);
    $api2->script['getOrderProxies'] = ['data' => []];
    list($d, $s) = capture(function () use ($api2, $logger) {
        handleBatchTest($api2, 'ord-1', $logger);
    });
    check('batch empty order', $d['summary']['total'] === 0 && $d['summary']['success_rate'] === 0
        && $d['results'] === [] && $d['summary']['truncated'] === false);
    $api2->script['getOrderProxies'] = ['data' => [['id' => 'px', 'ip' => '1.1.1.1']]];
    $api2->script['testProxyAlive'] = new ApiException('vendor down', 500);
    list($d, $s) = capture(function () use ($api2, $logger) {
        handleBatchTest($api2, 'ord-1', $logger);
    });
    check('batch per-proxy failure isolated', $d['summary']['dead'] === 1
        && $d['results'][0]['error'] === 'vendor down');
    ch247_rmdir($tmp);

    echo "== stats fns ==\n";
    list($tmp, $api, $logger) = fresh();
    list($d, $s) = capture(function () use ($api, $logger) {
        getUsageStats($api, '');
    });
    check('usage empty order', $s === 200 && $d['stats'] === null);
    $api->script['getOrderProxies'] = ['data' => [
        ['status' => 'active', 'request_count' => 1500, 'bandwidth_used' => 1024,
         'region' => 'us', 'carrier' => 'verizon'],
        ['status' => 'active', 'request_count' => 500, 'bandwidth_used' => 512.5,
         'region' => 'us', 'carrier' => 'kpn'],
        ['status' => 'suspended', 'region' => 'uk', 'carrier' => 'ee'],
    ]];
    list($d, $s) = capture(function () use ($api, $logger) {
        getUsageStats($api, 'ord-1', $logger);
    });
    check('usage aggregates', $s === 200 && $d['stats']['total_proxies'] === 3
        && $d['stats']['active_proxies'] === 2 && $d['stats']['inactive_proxies'] === 1
        && $d['stats']['total_requests'] === '2,000'
        && $d['stats']['region_distribution'] === ['us' => 2, 'uk' => 1]
        && $d['stats']['carrier_distribution']['kpn'] === 1);
    check('float bandwidth cast (LP-52)', $d['stats']['total_bandwidth'] === '1.5 KB');
    list($d, $s) = capture(function () use ($api, $logger) {
        getOrderHistory($api, '');
    });
    check('history empty order', $s === 200 && $d['history'] === []);
    $api->script['getOrders'] = ['data' => [['id' => 'a1', 'status' => 'active', 'quantity' => 2,
        'region' => 'us', 'carrier' => 'verizon', 'created_at' => '2026-05-01', 'expires_at' => '2026-06-01']]];
    $api->script['getExpiredOrders'] = ['data' => [['id' => 'e1', 'quantity' => 1,
        'region' => 'uk', 'carrier' => 'ee', 'created_at' => '2026-06-01', 'expires_at' => '2026-06-02']]];
    list($d, $s) = capture(function () use ($api, $logger) {
        getOrderHistory($api, 'ord-1', $logger);
    });
    check('history merges, sorts desc, forces expired', $s === 200 && count($d['history']) === 2
        && $d['history'][0]['id'] === 'e1' && $d['history'][0]['type'] === 'expired'
        && $d['history'][0]['status'] === 'expired' && $d['history'][1]['type'] === 'active');
    list($d, $s) = capture(function () use ($api, $logger) {
        getRotationStatus($api, '');
    });
    check('rotation empty order', $s === 200 && $d['rotations'] === []);
    $api->script['getOrderProxies'] = ['data' => [
        ['id' => 'p1', 'ip' => '1.1.1.1', 'rotation_type' => 'timed', 'rotation_interval' => 30,
         'next_rotation' => date('Y-m-d H:i:s', time() + 5400)],
        ['id' => 'p2'],
    ]];
    list($d, $s) = capture(function () use ($api, $logger) {
        getRotationStatus($api, 'ord-1', $logger);
    });
    check('rotation countdown', $s === 200 && $d['rotations'][0]['time_until_next'] >= 89
        && $d['rotations'][0]['time_until_next'] <= 90 && $d['rotations'][1]['time_until_next'] === 0
        && $d['rotations'][1]['rotation_type'] === 'manual');
    $api->script['getAccountInfo'] = ['data' => ['status' => 'active', 'api_version' => '1.0', 'rate_limit' => 60]];
    $api->script['getBalance'] = ['data' => ['balance' => 7.5, 'currency' => 'USD']];
    list($d, $s) = capture(function () use ($api) {
        getAccountSummary($api);
    });
    check('summary happy', $s === 200 && $d['account']['status'] === 'active'
        && $d['balance']['formatted'] === '$7.50');
    $api->script['getAccountInfo'] = new ApiException('down', 500);
    list($d, $s) = capture(function () use ($api) {
        getAccountSummary($api);
    });
    check('summary degrades', $s === 200 && $d['account'] === ['status' => 'unknown']
        && $d['balance'] === ['amount' => 0, 'formatted' => '$0.00']);
    ch247_rmdir($tmp);

    ch247_reset();
    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
