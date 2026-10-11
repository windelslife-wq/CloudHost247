<?php
/**
 * C-2: provisioning entry points + hook closures, behavioural.
 * Real ApiClient via the HTTP seam, Capsule fake, localAPI capture.
 * Hook closures build params without dir passthrough, so the module
 * logs/cache dirs are snapshotted and restored around them.
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();
    require_once ch247_mod() . '/cloudhost247_lteproxy.php';
    require_once ch247_mod() . '/hooks/Automation.php';
    require_once ch247_mod() . '/hooks/Notifications.php';

    use Ch247Test\FakeQuery;
    use CloudHost247\LTEProxy\ApiClient;

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
    function mkparams($tmp, array $over = [])
    {
        return array_merge([
            'serviceid' => 11,
            'username' => '',
            'password' => '',
            'configoption1' => 'K',
            'configoption2' => 'S',
            'configoption3' => 'https://api.test',
            'configoption4' => 30,
            'configoption15' => 'on',
            'configoption16' => 'INFO',
            'configoption17' => 'on',
            'configoption18' => 60,
            'configoptions' => [],
            'customfields' => [],
            'clientsdetails' => ['userid' => 5, 'email' => 'e@x.test'],
            'ch247_log_dir' => $tmp . '/logs',
            'ch247_cache_dir' => $tmp . '/cache',
        ], $over);
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
    function hookfn($point)
    {
        return $GLOBALS['__ch247_hooks'][$point][0];
    }
    function mails($subjectPart = null)
    {
        $out = [];
        foreach ($GLOBALS['__ch247_localapi_calls'] as $c) {
            if ($c[0] !== 'SendEmail') {
                continue;
            }
            if ($subjectPart === null || strpos($c[1]['customsubject'], $subjectPart) !== false) {
                $out[] = $c[1];
            }
        }
        return $out;
    }

    echo "== config + metadata ==\n";
    ch247_reset();
    $opts = cloudhost247_lteproxy_ConfigOptions();
    check('18 config options', count($opts) === 18);
    check('api key required text', $opts['api_key']['Required'] === true && $opts['api_key']['Type'] === 'text');
    check('api secret password', $opts['api_secret']['Type'] === 'password');
    check('region options', isset($opts['region']['Options']['us'], $opts['region']['Options']['uk']));
    check('carrier options incl intl', isset($opts['carrier']['Options']['verizon'], $opts['carrier']['Options']['vodafone_uk']));
    check('trial yesno + autoprov default on',
        $opts['trial_enabled']['Type'] === 'yesno' && $opts['auto_provision']['Default'] === 'on');
    check('log level default INFO', $opts['log_level']['Default'] === 'INFO');
    $meta = cloudhost247_lteproxy_MetaData();
    check('metadata', $meta['DisplayName'] === CH247_LTEPROXY_NAME && $meta['APIVersion'] === '1.1'
        && $meta['RequiresServer'] === false && $meta['DefaultSSLPort'] === '443');
    $tmp = ch247_tmpdir('e');
    $cfg = getCh247Config(mkparams($tmp));
    check('config mapping', $cfg['api_key'] === 'K' && $cfg['api_secret'] === 'S'
        && $cfg['api_base_url'] === 'https://api.test' && $cfg['api_timeout'] === 30
        && $cfg['log_level'] === 'INFO' && $cfg['rate_limit_requests'] === 60);
    check('dir passthrough', $cfg['log_directory'] === $tmp . '/logs' && $cfg['cache_directory'] === $tmp . '/cache');
    $cfg = getCh247Config([]);
    check('config defaults', $cfg['api_base_url'] === 'https://api.cloudhost247.com'
        && $cfg['log_directory'] === ch247_mod() . '/logs');
    $buttons = cloudhost247_lteproxy_AdminCustomButtonArray();
    check('5 admin buttons', $buttons === [
        'Rotate All IPs' => 'adminRotateAll', 'Test All Proxies' => 'adminTestAll',
        'Sync Status' => 'adminSyncStatus', 'Extend Order' => 'adminExtendOrder',
        'Reveal 1:1 IPs' => 'adminRevealIPs',
    ]);
    $tab = cloudhost247_lteproxy_AdminServicesTabFields(['username' => "<script>alert('x')</script>"]);
    check('admin tab escapes order id (LP-38)', strpos($tab['Order ID'], '&lt;script&gt;') !== false
        && strpos($tab['Order ID'], '<script>') === false);
    $tab = cloudhost247_lteproxy_AdminServicesTabFields([]);
    check('admin tab unprovisioned', strpos($tab['Order ID'], 'Not Provisioned') !== false);

    echo "== create (LP-14 idempotency / LP-15 notes / LP-35..36) ==\n";
    ch247_reset();
    $calls = 0;
    ApiClient::setHttpHandler(function () use (&$calls) {
        $calls++;
        return sim(['success' => true, 'data' => ['order_id' => 'ORD-9']]);
    });
    $tmp = ch247_tmpdir('e2');
    check('retry with order id short-circuits',
        cloudhost247_lteproxy_CreateAccount(mkparams($tmp, ['username' => 'ORD-9'])) === 'success'
        && $calls === 0 && \WHMCS\Database\Capsule::$tables === []);
    $hosting = new FakeQuery(['valueResult' => 'ADMIN NOTE']);
    \WHMCS\Database\Capsule::$handler = function () use ($hosting) {
        return $hosting;
    };
    $seen = [];
    ApiClient::setHttpHandler(function ($method, $url, $body) use (&$seen) {
        $seen[] = [$method, $url, $body];
        return sim(['success' => true, 'data' => ['order_id' => 'ORD-9']]);
    });
    $r = cloudhost247_lteproxy_CreateAccount(mkparams($tmp, ['configoptions' => ['quantity' => 3]]));
    check('create buys + succeeds', $r === 'success' && $seen[0][1] === 'https://api.test/proxies/buy');
    check('buy body sanitised', $seen[0][2]['quantity'] === 3 && $seen[0][2]['region'] === 'us'
        && $seen[0][2]['carrier'] === 'verizon' && $seen[0][2]['proxy_type'] === 'SOCKS5'
        && $seen[0][2]['duration'] === 30 && $seen[0][2]['client_id'] === 5);
    check('order ref stored, notes appended', $hosting->updated[0]['username'] === 'ORD-9'
        && $hosting->updated[0]['notes'] === "ADMIN NOTE\nCloudHost247 Proxy Order: ORD-9");
    $hosting2 = new FakeQuery(['valueResult' => '']);
    \WHMCS\Database\Capsule::$handler = function () use ($hosting2) {
        return $hosting2;
    };
    cloudhost247_lteproxy_CreateAccount(mkparams($tmp, ['configoptions' => ['quantity' => 0]]));
    check('quantity 0 clamps to 1, 1by1 route', $seen[1][1] === 'https://api.test/proxies/buy/1by1'
        && $seen[1][2]['quantity'] === 1);
    check('empty notes seeded cleanly',
        $hosting2->updated[0]['notes'] === 'CloudHost247 Proxy Order: ORD-9');
    $nCalls = count($seen);
    $r = cloudhost247_lteproxy_CreateAccount(mkparams($tmp, ['configoptions' => ['region' => 'xx']]));
    check('bad region fails fast, no purchase (LP-36)', $r === 'An error occurred: Invalid region selected'
        && count($seen) === $nCalls);
    $savedServer = $_SERVER;
    $_SERVER = ['REMOTE_ADDR' => '1.1.1.1'];
    cloudhost247_lteproxy_CreateAccount(mkparams($tmp, [
        'configoptions' => ['auth_type' => 'both'], 'customfields' => ['client_ip' => '9.9.9.9'],
    ]));
    $_SERVER = $savedServer;
    check('explicit client_ip wins (LP-35)', $seen[$nCalls][2]['client_ip'] === '9.9.9.9');
    ApiClient::setHttpHandler(function () {
        return sim(['success' => false, 'message' => 'sold out']);
    });
    check('vendor failure surfaces', cloudhost247_lteproxy_CreateAccount(mkparams($tmp)) === 'sold out');

    echo "== terminate / suspend / unsuspend / renew / password ==\n";
    ch247_reset();
    $tmp = ch247_tmpdir('e3');
    check('terminate needs order',
        cloudhost247_lteproxy_TerminateAccount(mkparams($tmp)) === 'No order ID found. Service may already be terminated.');
    $seen = [];
    ApiClient::setHttpHandler(function ($method, $url, $body) use (&$seen) {
        $seen[] = [$method, $url, $body];
        return sim(['success' => true]);
    });
    $r = cloudhost247_lteproxy_TerminateAccount(mkparams($tmp, ['username' => 'ord-1']));
    check('terminate cancels vendor order', $r === 'success'
        && $seen[0][1] === 'https://api.test/orders/ord-1/cancel');
    $hosting = new FakeQuery(['valueResult' => 'ADMIN']);
    \WHMCS\Database\Capsule::$handler = function () use ($hosting) {
        return $hosting;
    };
    $r = cloudhost247_lteproxy_SuspendAccount(mkparams($tmp, ['username' => 'ord-1', 'suspendreason' => 'abuse']));
    check('suspend appends marker (LP-15)', $r === 'success'
        && $hosting->updated[0]['notes'] === "ADMIN\nSUSPENDED - abuse");
    check('suspend needs order',
        cloudhost247_lteproxy_SuspendAccount(mkparams($tmp)) === 'No order ID found.');
    $hosting = new FakeQuery(['valueResult' => "ADMIN\nSUSPENDED - abuse"]);
    \WHMCS\Database\Capsule::$handler = function () use ($hosting) {
        return $hosting;
    };
    $r = cloudhost247_lteproxy_UnsuspendAccount(mkparams($tmp, ['username' => 'ord-1']));
    check('unsuspend strips marker, keeps admin notes', $r === 'success'
        && $hosting->updated[0]['notes'] === 'ADMIN');
    $hosting = new FakeQuery(['valueResult' => 'SUSPENDED - abuse']);
    \WHMCS\Database\Capsule::$handler = function () use ($hosting) {
        return $hosting;
    };
    cloudhost247_lteproxy_UnsuspendAccount(mkparams($tmp, ['username' => 'ord-1']));
    check('unsuspend lonely marker reseeds order line',
        $hosting->updated[0]['notes'] === 'CloudHost247 Proxy Order: ord-1');
    $seen = [];
    ApiClient::setHttpHandler(function ($method, $url, $body) use (&$seen) {
        $seen[] = [$method, $url, $body];
        return sim(['success' => true]);
    });
    $r = cloudhost247_lteproxy_Renew(mkparams($tmp, ['username' => 'ord-1', 'configoptions' => ['duration' => 45]]));
    check('renew extends', $r === 'success' && $seen[0][1] === 'https://api.test/orders/ord-1/extend'
        && $seen[0][2]['duration_days'] === 45);
    check('renew needs order',
        cloudhost247_lteproxy_Renew(mkparams($tmp)) === 'No order ID found for renewal.');
    check('password needs order',
        cloudhost247_lteproxy_ChangePassword(mkparams($tmp, ['password' => 'x'])) === 'No order ID found.');
    $nCalls = count($seen);
    check('empty password rejected silently (LP-17)',
        cloudhost247_lteproxy_ChangePassword(mkparams($tmp, ['username' => 'ord-1'])) === 'No new password supplied.'
        && count($seen) === $nCalls);
    ApiClient::setHttpHandler(function ($method, $url) use (&$seen) {
        $seen[] = [$method, $url];
        if (strpos($url, '/orders/ord-1/proxies') !== false) {
            return sim(['data' => [['id' => 'p1'], ['id' => 'p2'], ['noid' => 1]]]);
        }
        return sim(['success' => true]);
    });
    $r = cloudhost247_lteproxy_ChangePassword(mkparams($tmp, ['username' => 'ord-1', 'password' => 'pw2']));
    $authCalls = array_values(array_filter($seen, function ($c) {
        return strpos($c[1], '/auth') !== false;
    }));
    check('password rotates every proxy', $r === 'success' && count($authCalls) === 2);

    echo "== test-connection / client-area / admin actions ==\n";
    ch247_reset();
    $tmp = ch247_tmpdir('e4');
    ApiClient::setHttpHandler(function ($method, $url) {
        if (strpos($url, '/account/balance') !== false) {
            return sim(['data' => ['balance' => '3.50']]);
        }
        return sim(['data' => ['status' => 'Active', 'api_version' => '1.0']]);
    });
    $t = cloudhost247_lteproxy_TestConnection(mkparams($tmp));
    check('test-connection ok', $t['success'] === true && $t['info']['Balance'] === '$3.50'
        && $t['info']['Account Status'] === 'Active');
    ApiClient::setHttpHandler(function () {
        return sim(['message' => 'bad'], 401);
    });
    $t = cloudhost247_lteproxy_TestConnection(mkparams($tmp));
    check('test-connection auth failure', $t['success'] === false
        && strpos($t['error'], 'API authentication failed') === 0);
    ApiClient::setHttpHandler(function ($method, $url) {
        if (strpos($url, '/account/balance') !== false) {
            return sim(['data' => ['balance' => 9]]);
        }
        if (strpos($url, '/account/info') !== false) {
            return sim(['data' => ['plan' => 'pro']]);
        }
        if (strpos($url, '/orders?') !== false) {
            return sim(['data' => [['id' => 'ord-1']]]);
        }
        return sim(['data' => [['id' => 'p1']]]);
    });
    $_SESSION = [];
    $tmp = ch247_tmpdir('e5');
    $ca = cloudhost247_lteproxy_ClientArea(mkparams($tmp, ['username' => 'ord-1', 'serviceid' => 11]));
    check('client-area dashboard vars', $ca['tabOverviewReplacementTemplate'] === 'templates/client/dashboard.tpl'
        && $ca['templateVariables']['orderId'] === 'ord-1'
        && $ca['templateVariables']['proxies'] === [['id' => 'p1']]
        && $ca['templateVariables']['balance'] === 9
        && $ca['templateVariables']['regions']['us'] === 'United States'
        && $ca['templateVariables']['authTypes'] === ['ip_whitelist', 'username_password', 'both']
        && $ca['templateVariables']['serviceId'] === 11
        && strlen($ca['templateVariables']['csrfToken']) === 32);
    ApiClient::setHttpHandler(function () {
        throw new \CloudHost247\LTEProxy\ApiException('down', 500);
    });
    $tmp = ch247_tmpdir('e6');
    $ca = cloudhost247_lteproxy_ClientArea(mkparams($tmp, ['username' => 'ord-1']));
    check('client-area degrades on vendor outage',
        $ca['tabOverviewReplacementTemplate'] === 'templates/client/dashboard.tpl'
        && $ca['templateVariables']['proxies'] === [] && $ca['templateVariables']['balance'] === 0);
    ApiClient::setHttpHandler(function ($method, $url) {
        if (strpos($url, '/rotate') !== false && strpos($url, 'p2') !== false) {
            throw new \CloudHost247\LTEProxy\ApiException('stuck', 500);
        }
        if (strpos($url, '/test/alive') !== false) {
            return sim(['data' => ['alive' => strpos($url, 'p1') !== false]]);
        }
        if (strpos($url, '/orders?') !== false) {
            return sim(['data' => [['status' => 'active']]]);
        }
        if (strpos($url, '/orders/ord-1/proxies') !== false) {
            return sim(['data' => [['id' => 'p1'], ['id' => 'p2']]]);
        }
        return sim(['success' => true]);
    });
    check('admin rotate tallies failures',
        cloudhost247_lteproxy_adminRotateAll(mkparams($tmp, ['username' => 'ord-1'])) === 'Rotated 1 proxies. Failed: 1');
    check('admin rotate needs order',
        cloudhost247_lteproxy_adminRotateAll(mkparams($tmp)) === 'No order ID found.');
    check('admin test all',
        cloudhost247_lteproxy_adminTestAll(mkparams($tmp, ['username' => 'ord-1'])) === 'Test Results - Alive: 1, Dead: 1');
    check('admin sync status',
        cloudhost247_lteproxy_adminSyncStatus(mkparams($tmp, ['username' => 'ord-1'])) === 'Order status synchronized: Active');
    check('admin extend',
        cloudhost247_lteproxy_adminExtendOrder(mkparams($tmp, ['username' => 'ord-1'])) === 'Order extended by 30 days.');
    check('admin reveal',
        cloudhost247_lteproxy_adminRevealIPs(mkparams($tmp, ['username' => 'ord-1'])) === 'Revealed IPs for 2 proxies.');

    echo "== invoice-paid hook (LP-18 / LP-19) ==\n";
    $snap = snapmod();
    ch247_reset();
    $service = (object) ['id' => 11, 'userid' => 5, 'packageid' => 5, 'domainstatus' => 'Pending',
        'billingcycle' => 'Monthly', 'username' => '', 'password' => ''];
    $product = (object) ['id' => 5, 'servertype' => 'cloudhost247_lteproxy', 'configoption1' => 'K',
        'configoption2' => 'S', 'configoption3' => 'https://api.test', 'configoption4' => 30,
        'configoption14' => 'on', 'configoption15' => 'off', 'configoption16' => 'INFO',
        'configoption17' => 'off', 'configoption18' => 60];
    $client = (object) ['id' => 5, 'email' => 'e@x.test'];
    $hosting = new FakeQuery(['firstResult' => $service, 'valueResult' => '']);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hosting, $product, $client) {
        if ($table === 'tblinvoiceitems') {
            return new FakeQuery(['getResult' => [(object) ['relid' => 11]]]);
        }
        if ($table === 'tblproducts') {
            return new FakeQuery(['firstResult' => $product]);
        }
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => $client]);
        }
        return $hosting;
    };
    ApiClient::setHttpHandler(function () {
        return sim(['success' => true, 'data' => ['order_id' => 'ORD-1']]);
    });
    $fn = hookfn('InvoicePaid');
    $fn(['invoiceid' => 99]);
    check('pending service activated', $hosting->updated[1]['domainstatus'] === 'Active');
    check('due date follows cycle', $hosting->updated[1]['nextduedate'] === date('Y-m-d', strtotime('+1 month')));
    $activated = mails('Proxy Order Activated');
    check('activation mail sent (LP-19)', count($activated) === 1 && $activated[0]['id'] === 5);
    check('provisioning logged', count(array_filter($GLOBALS['__ch247_activity'], function ($m) {
        return strpos($m, 'Auto-provisioned service 11') !== false;
    })) === 1);
    ch247_reset();
    $service->domainstatus = 'Active';
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hosting, $product, $client) {
        if ($table === 'tblinvoiceitems') {
            return new FakeQuery(['getResult' => [(object) ['relid' => 11]]]);
        }
        if ($table === 'tblproducts') {
            return new FakeQuery(['firstResult' => $product]);
        }
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => $client]);
        }
        return $hosting;
    };
    $hosting->updated = [];
    $fn(['invoiceid' => 99]);
    check('non-pending skipped', $hosting->updated === [] && mails() === []);
    $service->domainstatus = 'Pending';
    $product->servertype = 'other';
    $fn(['invoiceid' => 99]);
    check('foreign module skipped', $hosting->updated === [] && mails() === []);
    $product->servertype = 'cloudhost247_lteproxy';
    $product->configoption14 = false;
    $fn(['invoiceid' => 99]);
    check('auto-provision off skipped', $hosting->updated === [] && mails() === []);
    $fn([]);
    check('empty invoice ignored', $hosting->updated === [] && mails() === []);

    echo "== daily cron hook (LP-7) ==\n";
    ch247_reset();
    $svc = function ($id, $due, $user) {
        return (object) ['id' => $id, 'userid' => 7, 'packageid' => 5, 'username' => $user, 'nextduedate' => $due];
    };
    // Tomorrow-anchored: floor((dueMidnight - now) / 86400) === $n at any hour.
    $warn = function ($n) {
        return date('Y-m-d', strtotime('tomorrow') + $n * 86400);
    };
    $services = [
        $svc(21, $warn(7), 'o-21'),
        $svc(22, $warn(3), 'o-22'),
        $svc(23, $warn(1), 'o-23'),
        $svc(24, $warn(30), 'o-24'),
        $svc(25, '0000-00-00', 'o-25'),
        $svc(28, '0000-00-00 00:00:00', 'o-28'),
        $svc(26, date('Y-m-d', time() - 10 * 86400), 'o-26'),
        $svc(27, date('Y-m-d', time() - 10 * 86400), ''),
    ];
    $hostingQ = new FakeQuery(['getResult' => $services]);
    $cronProduct = (object) ['servertype' => 'cloudhost247_lteproxy', 'configoption1' => 'K',
        'configoption2' => 'S', 'configoption3' => 'https://api.test', 'configoption15' => 'off',
        'configoption17' => 'off'];
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hostingQ, $cronProduct) {
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => (object) ['id' => 7]]);
        }
        if ($table === 'tblproducts') {
            return new FakeQuery(['firstResult' => $cronProduct]);
        }
        return $hostingQ;
    };
    $cancelled = [];
    ApiClient::setHttpHandler(function ($method, $url) use (&$cancelled) {
        $cancelled[] = $url;
        return sim(['success' => true]);
    });
    $fn = hookfn('DailyCronJob');
    $fn([]);
    check('7/3/1-day warnings', count(mails('7 days remaining')) === 1
        && count(mails('3 days remaining')) === 1 && count(mails('1 days remaining')) === 1);
    check('healthy service silent', count(array_filter(mails(), function ($m) {
        return strpos($m['custommessage'], 'o-24') !== false;
    })) === 0);
    check('zero date skipped (LP-7)', count(array_filter(mails(), function ($m) {
        return strpos($m['custommessage'], 'o-25') !== false;
    })) === 0);
    check('zero datetime skipped (LP-7b)', count(array_filter(mails(), function ($m) {
        return strpos($m['custommessage'], 'o-28') !== false;
    })) === 0 && count(array_filter($GLOBALS['__ch247_activity'], function ($m) {
        return strpos($m, 'service 28') !== false;
    })) === 0);
    check('ancient order terminated + cancelled', count($cancelled) === 1
        && strpos($cancelled[0], 'o-26') !== false
        && $hostingQ->updated === [['domainstatus' => 'Cancelled']]);
    check('ancient without order id left alone', count($cancelled) === 1);
    check('hooks write module cache dir (snapshot is load-bearing)',
        is_file(ch247_mod() . '/cache/rate_limit.json'));
    restoremod($snap);
    check('module dirs untouched by hooks', snapmod() == $snap);

    echo "== suspend/unsuspend + page hooks ==\n";
    $snap = snapmod();
    ch247_reset();
    $service = (object) ['id' => 11, 'userid' => 5, 'packageid' => 5, 'username' => 'ord-1', 'password' => ''];
    $product = (object) ['servertype' => 'cloudhost247_lteproxy', 'configoption15' => 'off', 'configoption17' => 'off'];
    $hosting = new FakeQuery(['firstResult' => $service, 'valueResult' => 'ADMIN']);
    \WHMCS\Database\Capsule::$handler = function ($table) use ($hosting, $product) {
        if ($table === 'tblproducts') {
            return new FakeQuery(['firstResult' => $product]);
        }
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => (object) ['id' => 5, 'email' => 'e@x.test']]);
        }
        return $hosting;
    };
    $fn = hookfn('ServiceSuspend');
    $fn(['serviceid' => 11]);
    check('suspend hook appends marker', $hosting->updated[0]['notes'] === "ADMIN\nSUSPENDED - Manual suspension");
    $hosting->valueResult = 'SUSPENDED - Manual suspension';
    $hosting->updated = [];
    $fn = hookfn('ServiceUnsuspend');
    $fn(['serviceid' => 11]);
    check('unsuspend hook reseeds order line',
        $hosting->updated[0]['notes'] === 'CloudHost247 Proxy Order: ord-1');
    $fn = hookfn('ClientAreaPage');
    $vars = ['filename' => 'clientarea', 'action' => 'productdetails', 'id' => 11];
    $out = $fn($vars);
    check('clientarea assets injected', strpos($out['headoutput'], 'assets/css/client.css') !== false
        && strpos($out['footeroutput'], 'assets/js/client.js') !== false);
    check('other pages untouched', $fn(['filename' => 'cart']) === ['filename' => 'cart']
        && $fn(['filename' => 'clientarea', 'action' => 'productdetails']) === ['filename' => 'clientarea', 'action' => 'productdetails']);
    $product->servertype = 'other';
    check('foreign module uninjected', !isset($fn($vars)['headoutput']));
    $product->servertype = 'cloudhost247_lteproxy';
    $_GET = ['id' => 11];
    $fn = hookfn('AdminAreaPage');
    $out = $fn(['filename' => 'clientsservices']);
    check('admin assets injected', strpos($out['headoutput'], 'assets/css/admin.css') !== false);
    check('admin other pages untouched', $fn(['filename' => 'clients']) === ['filename' => 'clients']);
    $_GET = [];
    restoremod($snap);
    check('module dirs untouched by page hooks', snapmod() == $snap);

    echo "== notification hooks + sender ==\n";
    ch247_reset();
    $service = (object) ['id' => 11, 'userid' => 5, 'servertype' => 'cloudhost247_lteproxy',
        'username' => 'ORD-1', 'nextduedate' => '2026-12-01'];
    \WHMCS\Database\Capsule::$handler = function ($table) use ($service) {
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => (object) ['id' => 5]]);
        }
        return new FakeQuery(['firstResult' => $service]);
    };
    $cases = [
        'AfterModuleCreate' => 'Your LTE Proxy is Ready',
        'AfterModuleTerminate' => 'LTE Proxy Service Terminated',
        'AfterModuleSuspend' => 'LTE Proxy Service Suspended',
        'AfterModuleUnsuspend' => 'LTE Proxy Service Reactivated',
        'PreModuleRenew' => 'LTE Proxy Renewal Processing',
        'AfterModuleRenew' => 'LTE Proxy Renewal Complete',
    ];
    foreach ($cases as $point => $subject) {
        $before = count(mails());
        $fn = hookfn($point);
        $fn(['params' => ['serviceid' => 11]]);
        $new = array_slice($GLOBALS['__ch247_localapi_calls'], $before);
        check($point . ' mails', count($new) === 1 && $new[0][0] === 'SendEmail'
            && $new[0][1]['customsubject'] === $subject && $new[0][1]['id'] === 5);
    }
    $created = mails('Your LTE Proxy is Ready');
    check('create mail carries order id', strpos($created[0]['custommessage'], 'ORD-1') !== false);
    $renewed = mails('LTE Proxy Renewal Complete');
    check('renew mail carries due date', strpos($renewed[0]['custommessage'], '2026-12-01') !== false);
    ch247_reset();
    \WHMCS\Database\Capsule::$handler = function ($table) use ($service) {
        if ($table === 'tblclients') {
            return new FakeQuery(['firstResult' => (object) ['id' => 5]]);
        }
        return new FakeQuery(['firstResult' => $service]);
    };
    $service->username = '';
    $fn = hookfn('AfterModuleCreate');
    $fn(['params' => ['serviceid' => 11]]);
    check('create without order id stays silent', mails() === []);
    $service->username = 'ORD-1';
    $service->servertype = 'other';
    $fn(['params' => ['serviceid' => 11]]);
    check('foreign module stays silent', mails() === []);
    $service->servertype = 'cloudhost247_lteproxy';
    $fn(['params' => []]);
    check('missing service id stays silent', mails() === []);
    $GLOBALS['__ch247_localapi'] = ['result' => 'error', 'message' => 'smtp down'];
    sendCh247Notification(5, 's', 'm');
    check('sender failure logged', count(array_filter($GLOBALS['__ch247_activity'], function ($m) {
        return strpos($m, 'Failed to send notification') !== false;
    })) === 1);

    ch247_reset();
    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
