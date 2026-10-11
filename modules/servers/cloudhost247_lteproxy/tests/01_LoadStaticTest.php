<?php
/**
 * C-2: real load of every loadable file (parse gate) + static pins for the
 * request-flow files that cannot load offline (ajax mains need init.php).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();
    require_once ch247_mod() . '/lib/AjaxHandlers.php';
    require_once ch247_mod() . '/cloudhost247_lteproxy.php';
    require_once ch247_mod() . '/hooks/Automation.php';
    require_once ch247_mod() . '/hooks/Notifications.php';
    require_once ch247_mod() . '/hooks/AdminSidebar.php';

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
    function modfile($rel)
    {
        return file_get_contents(ch247_mod() . '/' . $rel);
    }
    function fnbody($src, $fn)
    {
        $start = strpos($src, 'function ' . $fn . '(');
        if ($start === false) {
            return null;
        }
        $next = strpos($src, "\nfunction ", $start + 10);
        if ($next === false) {
            return substr($src, $start);
        }
        return substr($src, $start, $next - $start);
    }

    echo "== classes, constants, entry points ==\n";
    check('ApiClient loads', class_exists('CloudHost247\\LTEProxy\\ApiClient'));
    check('ApiException loads', class_exists('CloudHost247\\LTEProxy\\ApiException'));
    check('Logger loads', class_exists('CloudHost247\\LTEProxy\\Logger'));
    check('Cache loads', class_exists('CloudHost247\\LTEProxy\\Cache'));
    check('Helpers loads', class_exists('CloudHost247\\LTEProxy\\Helpers'));
    check('RateLimiter loads', class_exists('CloudHost247\\LTEProxy\\RateLimiter'));
    check('JsonResponseException loads', class_exists('CloudHost247\\LTEProxy\\JsonResponseException'));
    check('version const', CH247_LTEPROXY_VERSION === '1.0.0');
    check('name const', CH247_LTEPROXY_NAME === 'CloudHost247 Isc LTE Proxy Module');

    $handlers = [
        'handleGetProxies', 'handleGetOrderDetails', 'handleUpdateClientIp',
        'handleUpdateRegion', 'handleUpdateCarrier', 'handleUpdateProxyType',
        'handleUpdateAuth', 'handleUpdateRotation', 'handleRotateIp',
        'handleRevealIp', 'handleExtendOrder', 'handleCancelOrder',
        'handleGetBalance', 'handleGetAvailableProxies', 'handleRequestTrial',
        'handleTestAlive', 'handleTestSpeed', 'handleBatchTest',
        'calculateQualityRating', 'getUsageStats', 'getOrderHistory',
        'getRotationStatus', 'getAccountSummary',
    ];
    check('23 handlers extracted', count($handlers) === 23);
    $missing = [];
    foreach ($handlers as $h) {
        if (!function_exists($h)) {
            $missing[] = $h;
        }
    }
    check('all handlers defined (' . implode(',', $missing) . ')', $missing === []);

    $entryFns = [
        'cloudhost247_lteproxy_ConfigOptions', 'cloudhost247_lteproxy_CreateAccount',
        'cloudhost247_lteproxy_TerminateAccount', 'cloudhost247_lteproxy_SuspendAccount',
        'cloudhost247_lteproxy_UnsuspendAccount', 'cloudhost247_lteproxy_Renew',
        'cloudhost247_lteproxy_ChangePassword', 'cloudhost247_lteproxy_TestConnection',
        'cloudhost247_lteproxy_AdminServicesTabFields', 'cloudhost247_lteproxy_ClientArea',
        'cloudhost247_lteproxy_AdminCustomButtonArray', 'cloudhost247_lteproxy_adminRotateAll',
        'cloudhost247_lteproxy_adminTestAll', 'cloudhost247_lteproxy_adminSyncStatus',
        'cloudhost247_lteproxy_adminExtendOrder', 'cloudhost247_lteproxy_adminRevealIPs',
        'getCh247Config', 'getCh247ApiClient', 'getCh247Logger',
        'sendCh247Notification', 'cloudhost247_lteproxy_MetaData',
    ];
    $missing = [];
    foreach ($entryFns as $f) {
        if (!function_exists($f)) {
            $missing[] = $f;
        }
    }
    check('all entry functions defined (' . implode(',', $missing) . ')', $missing === []);

    echo "== hook registration (LP-2 / LP-31) ==\n";
    $points = array_keys($GLOBALS['__ch247_hooks']);
    sort($points);
    foreach (['InvoicePaid', 'ServiceSuspend', 'ServiceUnsuspend', 'DailyCronJob',
              'ClientAreaPage', 'AdminAreaPage', 'AfterModuleCreate',
              'AfterModuleTerminate', 'AfterModuleSuspend', 'AfterModuleUnsuspend',
              'PreModuleRenew', 'AfterModuleRenew', 'AdminClientProfileTabFields',
              'AdminAreaHeaderOutput', 'AdminAreaFooterOutput'] as $p) {
        check('hook point ' . $p, isset($GLOBALS['__ch247_hooks'][$p]));
    }
    $sidebar = array_values(array_filter($points, function ($p) {
        return stripos($p, 'sidebar') !== false;
    }));
    check('no sidebar hook registered (' . implode(',', $sidebar) . ')', $sidebar === []);
    check('sidebar removal documented', strpos(modfile('hooks/AdminSidebar.php'), 'no admin sidebar menu') !== false);
    $hooksBoot = modfile('hooks.php');
    check('hooks.php WHMCS guard', strpos($hooksBoot, "defined('WHMCS')") !== false);
    check('hooks.php loads all 3 hook files',
        substr_count($hooksBoot, ". '/hooks/") === 3
        && strpos($hooksBoot, 'Automation.php') !== false
        && strpos($hooksBoot, 'Notifications.php') !== false
        && strpos($hooksBoot, 'AdminSidebar.php') !== false);
    check('hooks.php documents the copy target', strpos($hooksBoot, '/includes/hooks/cloudhost247_lteproxy.php') !== false);

    echo "== Capsule imports (LP-1) ==\n";
    foreach (['cloudhost247_lteproxy.php', 'hooks/Automation.php', 'hooks/Notifications.php',
              'hooks/AdminSidebar.php', 'ajax/proxy-operations.php', 'ajax/proxy-test.php',
              'ajax/stats.php'] as $f) {
        $src = modfile($f);
        check($f . ': Capsule use import',
            strpos($src, 'Capsule::') === false
            || strpos($src, 'use WHMCS\\Database\\Capsule;') !== false);
    }

    echo "== ajax mains (LP-20 / LP-27 / LP-28 / LP-29) ==\n";
    $mains = [
        'ajax/proxy-operations.php' => modfile('ajax/proxy-operations.php'),
        'ajax/proxy-test.php' => modfile('ajax/proxy-test.php'),
        'ajax/stats.php' => modfile('ajax/stats.php'),
    ];
    foreach ($mains as $f => $src) {
        check($f . ': requires AjaxHandlers', strpos($src, "lib/AjaxHandlers.php") !== false);
        check($f . ': CSRF via header', strpos($src, 'HTTP_X_CSRF_TOKEN') !== false);
        check($f . ': token never from query string', strpos($src, "\$_GET['csrf_token']") === false);
        check($f . ': generic catch', strpos($src, 'catch (\\Exception $e)') !== false);
    }
    check('ops: raw action POST-or-GET',
        strpos($mains['ajax/proxy-operations.php'], "\$_POST['action'] ?? \$_GET['action']") !== false);
    check('test: raw action POST-or-GET',
        strpos($mains['ajax/proxy-test.php'], "\$_POST['action'] ?? \$_GET['action']") !== false);
    check('stats: GET-only action', strpos($mains['ajax/stats.php'], "\$action = \$_GET['action']") !== false);
    check('ops: getCh247Config hoistable (LP-54)', strpos($mains['ajax/proxy-operations.php'], 'function_exists') === false
        && strpos($mains['ajax/proxy-operations.php'], "\nfunction getCh247Config(array \$params): array") !== false);
    check('ops: ApiException catch with safeHttpStatus',
        strpos($mains['ajax/proxy-operations.php'], 'catch (ApiException $e)') !== false
        && strpos($mains['ajax/proxy-operations.php'], 'safeHttpStatus($e->getCode())') !== false);
    check('test: ApiException catch with safeHttpStatus',
        strpos($mains['ajax/proxy-test.php'], 'catch (ApiException $e)') !== false
        && strpos($mains['ajax/proxy-test.php'], 'safeHttpStatus($e->getCode())') !== false);
    check('stats: no ApiException catch (single generic)', strpos($mains['ajax/stats.php'], 'catch (ApiException') === false);

    echo "== handler guards (LP-8 / LP-11 / LP-22 / LP-23) ==\n";
    $ah = modfile('lib/AjaxHandlers.php');
    foreach (['handleUpdateClientIp', 'handleUpdateRegion', 'handleUpdateCarrier',
              'handleUpdateProxyType', 'handleUpdateAuth', 'handleUpdateRotation',
              'handleRotateIp', 'handleRevealIp', 'handleTestAlive',
              'handleTestSpeed'] as $h) {
        $body = fnbody($ah, $h);
        check($h . ': IDOR guard', $body !== null && strpos($body, 'proxyBelongsToOrder(') !== false);
    }
    $cancel = fnbody($ah, 'handleCancelOrder');
    check('cancel: 403 with billing-sync message',
        $cancel !== null && strpos($cancel, ', 403)') !== false
        && strpos($cancel, 'cancelOrder(') === false
        && stripos($cancel, 'billing') !== false);
    $trial = fnbody($ah, 'handleRequestTrial');
    check('trial: disabled gate', $trial !== null && strpos($trial, 'Trial proxies are not enabled') !== false);
    check('trial: region/carrier/type validation',
        $trial !== null && strpos($trial, 'isValidRegion($region)') !== false
        && strpos($trial, 'isValidCarrier($carrier)') !== false
        && strpos($trial, 'isValidProxyType($proxyType)') !== false);
    $batch = fnbody($ah, 'handleBatchTest');
    check('batch: 50-proxy cap with truncation flag',
        $batch !== null && strpos($batch, 'count($proxyList) > 50') !== false
        && strpos($batch, "'truncated' => \$truncated") !== false);
    check('speed: strict casts at the vendor boundary',
        strpos($ah, '(float) ($response[') !== false && strpos($ah, '(float) ($speedResult[') !== false);
    check('usage: bandwidth int cast', strpos($ah, 'formatBytes((int) $totalBandwidth)') !== false);

    echo "== entry + lib pins ==\n";
    $entry = modfile('cloudhost247_lteproxy.php');
    check('create: quantity falls back to 1', strpos($entry, "['quantity'] ?? 1") !== false);
    check('create: sanitizeOrderParams wired', strpos($entry, 'sanitizeOrderParams($orderParams)') !== false);
    check('create: explicit client_ip wins (LP-35)',
        strpos($entry, "\$params['customfields']['client_ip']") !== false);
    check('create: idempotent on retry', strpos(fnbody($entry, 'cloudhost247_lteproxy_CreateAccount'), "if (!empty(\$params['username']))") !== false);
    check('notes append (create)', substr_count($entry, '$existingNotes . "\n" . ') >= 2);
    check('suspend marker stripped on unsuspend', strpos($entry, 'SUSPENDED - ') !== false);
    check('change-password: empty guard', strpos($entry, 'No new password supplied.') !== false);
    $helpers = modfile('lib/Helpers.php');
    check('sanitizeString ENT_QUOTES', strpos($helpers, 'ENT_QUOTES') !== false);
    check('carrier check covers all carriers', strpos($helpers, 'self::getAllCarriers()') !== false);
    check('proxy creds rawurlencoded', strpos($helpers, 'rawurlencode($username)') !== false
        && strpos($helpers, 'rawurlencode($password)') !== false);
    check('JSON-throw test seam', strpos($helpers, 'CH247_TEST_JSON_THROW') !== false);
    check('seam unwinds past catch (\\Exception)', strpos($helpers, 'class JsonResponseException extends \\Error') !== false);
    $client = modfile('lib/ApiClient.php');
    check('timeout floor', strpos($client, 'max(1, (int) ($config[') !== false);
    check('retry gated on idempotent methods', strpos($client, 'self::isIdempotentMethod($method)') !== false);
    check('scalar-JSON guard', strpos($client, '!is_array($decoded)') !== false);
    check('string-code guard', strpos($client, "is_int(\$decoded['code'])") !== false);
    check('client_ip validated pre-request', strpos($client, 'filter_var($clientIp, FILTER_VALIDATE_IP)') !== false);
    $exc = modfile('lib/ApiException.php');
    check('errorCode cast (LP-21b)', strpos($exc, 'is_scalar($rawCode) ? (string) $rawCode : null') !== false);
    check('random_bytes int cast (LP-53)', strpos($helpers, 'random_bytes((int) ceil(') !== false);
    check('balance/latency float casts (LP-52b)', substr_count($ah, 'number_format((float) (') === 2
        && strpos($ah, 'round((float) ($speedResult[') !== false);
    check('duration ints (LP-55)', substr_count($helpers, '(int) floor($days') === 2);
    foreach (['lib/Logger.php', 'lib/Cache.php', 'lib/RateLimiter.php'] as $f) {
        check($f . ': access guards', strpos(modfile($f), 'writeAccessGuards(') !== false);
    }
    $cache = modfile('lib/Cache.php');
    check('cache: JSON storage', strpos($cache, 'json_encode($data)') !== false);
    check('cache: no serialize()', strpos($cache, 'serialize(') === false);
    $auto = modfile('hooks/Automation.php');
    check('invoice-paid: cycle-aware due date', strpos($auto, '$service->billingcycle') !== false
        && strpos($auto, "'+1 month'") !== false);
    check('invoice-paid: activation mail', strpos($auto, "'Proxy Order Activated'") !== false);
    check('cron: zero-date skip (LP-7b)', strpos($auto, "'0000-00-00 00:00:00'") !== false
        && strpos($auto, 'strtotime($dueRaw)') !== false);
    $install = modfile('install.php');
    check('install: CLI-only', strpos($install, "php_sapi_name() !== 'cli'") !== false);
    check('install: checks AjaxHandlers', strpos($install, 'lib/AjaxHandlers.php') !== false);
    check('install: hooks copy step', strpos($install, '/includes/hooks/cloudhost247_lteproxy.php') !== false);

    echo "== web-access hardening ==\n";
    $htaccess = modfile('.htaccess');
    check('.htaccess: no indexes', strpos($htaccess, 'Options -Indexes') !== false);
    check('.htaccess: blocks log/cache/tpl', strpos($htaccess, '"\\.(log|cache|tpl') !== false);
    // cache/ + logs/ are git-ignored runtime dirs: production self-heals their
    // guards on first use, so do the same here before asserting.
    new \CloudHost247\LTEProxy\Logger(['log_directory' => ch247_mod() . '/logs']);
    new \CloudHost247\LTEProxy\Cache(['cache_directory' => ch247_mod() . '/cache']);
    $deep = 0;
    $shallow = 0;
    $bad = [];
    $expected = ['ajax', 'assets/css', 'assets/js', 'cache', 'hooks', 'lang', 'lib', 'logs', 'templates/admin', 'templates/client'];
    foreach ($expected as $dir) {
        $f = ch247_mod() . '/' . $dir . '/index.php';
        if (!is_file($f)) {
            $bad[] = $dir . ' (missing)';
            continue;
        }
        $src = file_get_contents($f);
        $hasRedirect = strpos($src, 'header("Location:') !== false;
        $isRuntime = ($dir === 'cache' || $dir === 'logs');
        if ($isRuntime) {
            // Shipped redirect or runtime "Silence" guard are both fine.
            if (!$hasRedirect && strpos($src, 'Silence is golden') === false) {
                $bad[] = $dir . ' (no guard)';
                continue;
            }
        } elseif (!$hasRedirect) {
            $bad[] = $dir . ' (no redirect)';
            continue;
        }
        if ($hasRedirect) {
            $want = strpos($dir, '/') === false ? 4 : 5;
            if (substr_count($src, '../') !== $want) {
                $bad[] = $dir . ' (depth)';
            }
        }
        if (strpos($dir, '/') === false) {
            $shallow++;
        } else {
            $deep++;
        }
    }
    check('10 guarded dirs (' . implode(',', $bad) . ')', $bad === [] && $shallow === 6 && $deep === 4);
    check('runtime htaccess self-heals', is_file(ch247_mod() . '/logs/.htaccess')
        && is_file(ch247_mod() . '/cache/.htaccess'));

    // The shipped module must stay PHP 7.4-parseable (WHMCS fleets still run
    // 7.4): no match(), nullsafe, enums, readonly, or PHP-8 str_* helpers.
    $modern = [];
    foreach (glob(ch247_mod() . '/lib/*.php') as $f) {
        $src = file_get_contents($f);
        foreach (['match (', '?->', 'str_contains(', 'str_starts_with(', 'str_ends_with(',
            'readonly ', 'enum ', '#['] as $needle) {
            if (strpos($src, $needle) !== false) {
                $modern[] = basename($f) . ':' . $needle;
            }
        }
    }
    check('PHP 7.4-parseable lib (' . implode(',', $modern) . ')', $modern === []);

    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
