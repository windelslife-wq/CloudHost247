<?php
/**
 * C-2: Cache + Logger + RateLimiter behavioural suite (real classes, temp dirs).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();

    use CloudHost247\LTEProxy\Cache;
    use CloudHost247\LTEProxy\Logger;
    use CloudHost247\LTEProxy\RateLimiter;

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

    $tmp = ch247_tmpdir('clr');

    echo "== cache ==\n";
    $cache = new Cache(['cache_directory' => $tmp . '/cache', 'cache_ttl' => 60]);
    check('set/get string', $cache->set('k1', 'v1') && $cache->get('k1') === 'v1');
    check('set/get array', $cache->set('k2', ['a' => [1, 2]]) && $cache->get('k2') === ['a' => [1, 2]]);
    check('missing key null', $cache->get('nope') === null);
    check('has', $cache->has('k1') && !$cache->has('nope'));
    check('delete', $cache->delete('k1') && $cache->get('k1') === null && $cache->delete('k1'));
    $cache->set('f1', 1);
    $cache->set('f2', 2);
    check('flush', $cache->flush() && !$cache->has('f1') && !$cache->has('f2'));
    check('increment chain', $cache->increment('n') === 1 && $cache->increment('n', 2) === 3
        && $cache->decrement('n') === 2);
    $runs = 0;
    $r1 = $cache->remember('memo', function () use (&$runs) {
        $runs++;
        return 'computed';
    });
    $r2 = $cache->remember('memo', function () use (&$runs) {
        $runs++;
        return 'computed';
    });
    check('remember computes once', $r1 === 'computed' && $r2 === 'computed' && $runs === 1);
    $cache->set('old', 'x', -5);
    check('expired reads null', $cache->get('old') === null);
    check('expired file reaped', glob($tmp . '/cache/*old*.cache') === []);
    $off = new Cache(['cache_directory' => $tmp . '/cache', 'cache_enabled' => false]);
    check('disabled cache inert', $off->set('d', 1) === false && $off->get('d') === null && !$off->has('d'));
    $cache->set('odd/key ..', 'v');
    check('odd key roundtrip', $cache->get('odd/key ..') === 'v');
    $cache->set('fmt', ['n' => 1]);
    $diskFiles = glob($tmp . '/cache/*.cache');
    $disk = null;
    foreach ($diskFiles as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (is_array($d) && ($d['key'] ?? null) === 'fmt') {
            $disk = $d;
        }
    }
    check('on-disk format is JSON with envelope (LP-33)',
        is_array($disk) && $disk['value'] === ['n' => 1]
        && isset($disk['created'], $disk['expires']) && $disk['expires'] > time());
    file_put_contents($tmp . '/cache/corrupt.cache', 'not-json{{{');
    check('corrupt entry reads null', $cache->get('corrupt') === null);
    check('corrupt file reaped', !is_file($tmp . '/cache/corrupt.cache'));
    check('cache index guard', is_file($tmp . '/cache/index.php')
        && strpos(file_get_contents($tmp . '/cache/index.php'), 'Silence') !== false);
    check('cache htaccess guard', is_file($tmp . '/cache/.htaccess')
        && strpos(file_get_contents($tmp . '/cache/.htaccess'), 'Require all denied') !== false);

    echo "== logger ==\n";
    $log = new Logger(['log_directory' => $tmp . '/logs', 'log_level' => 'DEBUG']);
    $log->info('hello', ['service_id' => 7]);
    $logFile = $tmp . '/logs/cloudhost247_lteproxy.log';
    check('log file written', is_file($logFile));
    $lines = array_values(array_filter(explode("\n", file_get_contents($logFile))));
    $first = json_decode($lines[0], true);
    check('log line is JSON envelope', is_array($first) && $first['level'] === 'INFO'
        && $first['message'] === 'hello' && $first['context'] === ['service_id' => 7]
        && isset($first['timestamp'], $first['pid']));
    $infoOnly = new Logger(['log_directory' => $tmp . '/logs2', 'log_level' => 'INFO']);
    $infoOnly->debug('dropped');
    $infoOnly->warning('kept');
    $log2 = file_get_contents($tmp . '/logs2/cloudhost247_lteproxy.log');
    check('level filter drops debug', strpos($log2, 'dropped') === false && strpos($log2, 'kept') !== false);
    $nope = new Logger(['log_directory' => $tmp . '/logs3', 'log_level' => 'NOPE']);
    $nope->debug('fallback-debug');
    check('unknown level falls back to DEBUG',
        is_file($tmp . '/logs3/cloudhost247_lteproxy.log')
        && strpos(file_get_contents($tmp . '/logs3/cloudhost247_lteproxy.log'), 'fallback-debug') !== false);
    $disabled = new Logger(['log_directory' => $tmp . '/logs4', 'logging_enabled' => false]);
    $disabled->error('dropped');
    check('disabled logger writes nothing', !is_file($tmp . '/logs4/cloudhost247_lteproxy.log'));
    $log->logModuleAction('create', 7, ['password' => 's3cret', 'api_key' => 'KEY-1',
        'nested' => ['token' => 'TOK'], 'ok' => 1]);
    $masked = file_get_contents($logFile);
    check('module-action masks secrets (LP-12)',
        strpos($masked, 's3cret') === false && strpos($masked, 'KEY-1') === false
        && strpos($masked, 'TOK') === false && strpos($masked, '******') !== false
        && strpos($masked, '*****') !== false && strpos($masked, '"ok":1') !== false);
    $log->logApiRequest('POST', '/proxies/buy', 200, 0.012, ['password' => 'pw9'], ['balance' => 5]);
    $apiLog = file_get_contents($logFile);
    check('api-request masks body secrets', strpos($apiLog, 'pw9') === false
        && strpos($apiLog, '"balance":5') !== false && strpos($apiLog, '/proxies/buy') !== false);
    $log->logApiRequest('GET', '/boom', 500, 0.5);
    check('api-request error level', strpos(file_get_contents($logFile), '"level":"ERROR"') !== false);
    $log->logException(new \RuntimeException('boom'), 'CTX');
    $excLog = file_get_contents($logFile);
    check('exception logged with context', strpos($excLog, 'Exception: boom') !== false
        && strpos($excLog, '"context":"CTX"') !== false && strpos($excLog, '"trace":') !== false);
    check('log index guard', is_file($tmp . '/logs/index.php'));
    check('log htaccess guard', is_file($tmp . '/logs/.htaccess'));

    echo "== rate limiter ==\n";
    $rl = new RateLimiter(['cache_directory' => $tmp . '/rl']);
    check('fresh limiter proceeds', $rl->canProceed() && $rl->getWaitTime() === 0
        && $rl->getCurrentRequestCount() === 0);
    $st = $rl->getStatus();
    check('status shape', $st['max_requests'] === 60 && $st['time_window'] === 60
        && $st['burst_limit'] === 10 && $st['can_proceed'] === true && isset($st['wait_time']));
    $small = new RateLimiter(['cache_directory' => $tmp . '/rl2', 'rate_limit_requests' => 3,
        'rate_limit_window' => 60, 'rate_limit_burst' => 10, 'rate_limit_cooldown' => 5]);
    $small->recordRequest();
    $small->recordRequest();
    $small->recordRequest();
    check('max gate trips', !$small->canProceed());
    check('wait time positive when limited', $small->getWaitTime() > 0);
    check('counts when limited', $small->getCurrentRequestCount() === 3 && $small->getRemainingRequests() === 0);
    $small->reset();
    check('reset clears', $small->canProceed() && $small->getCurrentRequestCount() === 0
        && $small->getWaitTime() === 0);
    $small->recordRequest();
    $small->recordRequest();
    $again = new RateLimiter(['cache_directory' => $tmp . '/rl2']);
    check('counts persist across instances', $again->getCurrentRequestCount() === 2);
    $burst = new RateLimiter(['cache_directory' => $tmp . '/rl3', 'rate_limit_requests' => 60,
        'rate_limit_burst' => 2]);
    $burst->recordRequest();
    $burst->recordRequest();
    check('burst gate trips within the second', !$burst->canProceed());
    $cdDir = $tmp . '/rl4';
    @mkdir($cdDir, 0777, true);
    file_put_contents($cdDir . '/rate_limit.json', json_encode([
        'requests' => [time() - 5000], 'rate_limit_hit_at' => null, 'saved_at' => time() - 5000,
    ]));
    $stale = new RateLimiter(['cache_directory' => $cdDir, 'rate_limit_window' => 60]);
    check('stale timestamps age out', $stale->getCurrentRequestCount() === 0 && $stale->canProceed());
    check('limiter guards written', is_file($tmp . '/rl/index.php') && is_file($tmp . '/rl/.htaccess'));

    ch247_rmdir($tmp);
    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
