<?php

/**
 * Tests for the authenticated mail usage/log proxy (S-6 remediation).
 * Included by run.php, which provides smtp_test(), smtp_assert() and smtp_assert_throws().
 */

use ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageProxy;
use ModulesGarden\ProductsReseller\Server\Smtphosting\Helpers\SmtpUsageRateLimiter;

/** Records every upstream call and returns a canned response. */
class SmtpFakeTransport
{
    public $calls = [];
    public $response;
    public $throw = false;

    public function __construct($response = null)
    {
        $this->response = $response ?: ['status' => 200, 'body' => json_encode(['status' => 'ok', 'records' => [['hostname' => 'mx.example']]])];
    }

    public function __invoke($url, $query)
    {
        $this->calls[] = ['url' => $url, 'query' => $query];
        if ($this->throw) {
            throw new \RuntimeException('transport down');
        }
        return $this->response;
    }
}

/** In-memory rate limiter with a configurable failure mode. */
class SmtpFakeRateLimiter
{
    public $allow = true;
    public $fail = false;

    public function allow($key, $limit, $window, $now = null)
    {
        if ($this->fail) {
            throw new \RuntimeException('storage unavailable');
        }
        return $this->allow;
    }
}

function smtp_proxy_fixture(array $overrides = [])
{
    $transport = isset($overrides['transport']) ? $overrides['transport'] : new SmtpFakeTransport();
    $limiter   = isset($overrides['limiter']) ? $overrides['limiter'] : new SmtpFakeRateLimiter();
    $secrets   = array_key_exists('secrets', $overrides) ? $overrides['secrets'] : ['usage' => 'USAGE-SECRET-VALUE', 'logs' => 'LOGS-SECRET-VALUE'];
    $service   = array_key_exists('service', $overrides) ? $overrides['service'] : ['username' => 'acme_user', 'domain' => 'acme.example'];

    $lookupCalls = [];
    $lookup = function ($serviceId, $clientId) use ($service, &$lookupCalls) {
        $lookupCalls[] = [$serviceId, $clientId];
        return $service;
    };

    $proxy = new SmtpUsageProxy($secrets, $lookup, $transport, $limiter);
    return ['proxy' => $proxy, 'transport' => $transport, 'lookupCalls' => &$lookupCalls];
}

function smtp_proxy_handle(array $fixture, array $request, $clientId = 7)
{
    return $fixture['proxy']->handle($request, $clientId);
}

/* ------------------------------------------------------------------------ *
 * Authentication, authorization and input validation: no upstream calls
 * ------------------------------------------------------------------------ */

smtp_test('Proxy: unauthenticated request is rejected with 401 and never calls upstream', function () {
    $f = smtp_proxy_fixture();
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5'], 0);
    smtp_assert($r['httpStatus'] === 401, 'expected 401, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called');
});

smtp_test('Proxy: null client identity is rejected with 401', function () {
    $f = smtp_proxy_fixture();
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5'], null);
    smtp_assert($r['httpStatus'] === 401, 'expected 401, got ' . $r['httpStatus']);
});

smtp_test('Proxy: unknown or non-string fn is rejected with 400', function () {
    $f = smtp_proxy_fixture();
    foreach (['secrets', '', 'usage/../x', ['usage'], null] as $fn) {
        $r = smtp_proxy_handle($f, ['fn' => $fn, 'serviceid' => '5']);
        smtp_assert($r['httpStatus'] === 400, 'fn ' . json_encode($fn) . ' expected 400, got ' . $r['httpStatus']);
    }
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called for invalid fn');
});

smtp_test('Proxy: invalid serviceid values are rejected with 400', function () {
    $f = smtp_proxy_fixture();
    foreach ([null, '', '0', '-3', 'abc', '1.5', '12abc', [5], str_repeat('9', 30)] as $sid) {
        $req = ['fn' => 'usage'];
        if ($sid !== null) {
            $req['serviceid'] = $sid;
        }
        $r = smtp_proxy_handle($f, $req);
        smtp_assert($r['httpStatus'] === 400, 'serviceid ' . json_encode($sid) . ' expected 400, got ' . $r['httpStatus']);
    }
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called for invalid serviceid');
});

smtp_test('Proxy: service not owned by the client (or nonexistent) returns 404 and never calls upstream', function () {
    $f = smtp_proxy_fixture(['service' => null]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '99']);
    smtp_assert($r['httpStatus'] === 404, 'expected 404, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called for a service the client does not own');
    smtp_assert($f['lookupCalls'][0] === [99, 7], 'ownership lookup must be scoped to the service and the client');
});

smtp_test('Proxy: owned service without username/domain returns 404 and never calls upstream', function () {
    $f = smtp_proxy_fixture(['service' => ['username' => '', 'domain' => 'acme.example']]);
    $r = smtp_proxy_handle($f, ['fn' => 'logs', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 404, 'expected 404, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called without an account identity');
});

smtp_test('Proxy: missing upstream secret returns 503 and never calls upstream', function () {
    $f = smtp_proxy_fixture(['secrets' => ['usage' => '', 'logs' => 'LOGS-SECRET-VALUE']]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 503, 'expected 503, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called without a secret');
});

smtp_test('Proxy: rate-limited client gets 429 and never calls upstream', function () {
    $limiter = new SmtpFakeRateLimiter();
    $limiter->allow = false;
    $f = smtp_proxy_fixture(['limiter' => $limiter]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 429, 'expected 429, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called when rate limited');
});

smtp_test('Proxy: rate-limiter storage failure fails closed with 503', function () {
    $limiter = new SmtpFakeRateLimiter();
    $limiter->fail = true;
    $f = smtp_proxy_fixture(['limiter' => $limiter]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 503, 'expected 503, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 0, 'upstream must not be called when the limiter fails');
});

/* ------------------------------------------------------------------------ *
 * Success paths: identity comes from the service record, never the request
 * ------------------------------------------------------------------------ */

smtp_test('Proxy: usage uses the owned service identity and ignores request-supplied user_name/main_domain/secret', function () {
    $f = smtp_proxy_fixture();
    $r = smtp_proxy_handle($f, [
        'fn' => 'usage', 'serviceid' => '5',
        'user_name' => 'victim_user', 'main_domain' => 'victim.example', 'secret' => 'attacker',
    ]);
    smtp_assert($r['httpStatus'] === 200, 'expected 200, got ' . $r['httpStatus']);
    smtp_assert(count($f['transport']->calls) === 1, 'expected exactly one upstream call');

    $call = $f['transport']->calls[0];
    smtp_assert(strpos($call['url'], 'mail-usage-log.php') !== false, 'usage must call the usage endpoint');
    smtp_assert($call['query']['user_name'] === 'acme_user', 'user_name must come from the service record');
    smtp_assert($call['query']['main_domain'] === 'acme.example', 'main_domain must come from the service record');
    smtp_assert($call['query']['secret'] === 'USAGE-SECRET-VALUE', 'the usage secret must be used server-side');
    smtp_assert(!isset($call['query']['page']), 'usage must not send pagination');
});

smtp_test('Proxy: logs uses the logs endpoint and the logs secret', function () {
    $f = smtp_proxy_fixture();
    smtp_proxy_handle($f, ['fn' => 'logs', 'serviceid' => '5']);
    $call = $f['transport']->calls[0];
    smtp_assert(strpos($call['url'], 'mail-sent-log.php') !== false, 'logs must call the sent-log endpoint');
    smtp_assert($call['query']['secret'] === 'LOGS-SECRET-VALUE', 'the logs secret must be used for logs');
});

smtp_test('Proxy: successful upstream JSON is passed through and contains no secret', function () {
    $f = smtp_proxy_fixture();
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['payload']['status'] === 'ok', 'upstream status must be passed through');
    smtp_assert($r['payload']['records'][0]['hostname'] === 'mx.example', 'upstream records must be passed through');
    smtp_assert(strpos(json_encode($r), 'USAGE-SECRET-VALUE') === false, 'response must not contain the secret');
});

smtp_test('Proxy: pagination is clamped (page >= 1, per_page 1..100, default 10)', function () {
    $cases = [
        [['page' => '3', 'per_page' => '25'], 3, 25],
        [['page' => 'x', 'per_page' => 'y'], 1, 10],
        [['page' => '-5', 'per_page' => '0'], 1, 10],
        [['page' => '2', 'per_page' => '9999'], 2, 100],
        [[], 1, 10],
    ];
    foreach ($cases as $case) {
        $f = smtp_proxy_fixture();
        smtp_proxy_handle($f, array_merge(['fn' => 'logs', 'serviceid' => '5'], $case[0]));
        $q = $f['transport']->calls[0]['query'];
        smtp_assert($q['page'] === $case[1], 'page for ' . json_encode($case[0]) . ' expected ' . $case[1] . ' got ' . $q['page']);
        smtp_assert($q['per_page'] === $case[2], 'per_page for ' . json_encode($case[0]) . ' expected ' . $case[2] . ' got ' . $q['per_page']);
    }
});

/* ------------------------------------------------------------------------ *
 * Upstream failure handling
 * ------------------------------------------------------------------------ */

smtp_test('Proxy: non-200 upstream returns a generic 502 without echoing the upstream body', function () {
    $transport = new SmtpFakeTransport(['status' => 500, 'body' => 'internal details USAGE-SECRET-VALUE']);
    $f = smtp_proxy_fixture(['transport' => $transport]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 502, 'expected 502, got ' . $r['httpStatus']);
    smtp_assert(strpos(json_encode($r), 'internal details') === false, 'upstream body must not be echoed');
    smtp_assert(strpos(json_encode($r), 'USAGE-SECRET-VALUE') === false, 'secret must not be echoed');
});

smtp_test('Proxy: non-JSON upstream body returns 502', function () {
    $transport = new SmtpFakeTransport(['status' => 200, 'body' => '<html>oops</html>']);
    $f = smtp_proxy_fixture(['transport' => $transport]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 502, 'expected 502, got ' . $r['httpStatus']);
});

smtp_test('Proxy: upstream that echoes the secret has it redacted before reaching the client', function () {
    $transport = new SmtpFakeTransport(['status' => 200, 'body' => json_encode(['status' => 'ok', 'records' => [], 'debug' => 'USAGE-SECRET-VALUE'])]);
    $f = smtp_proxy_fixture(['transport' => $transport]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 200, 'expected 200, got ' . $r['httpStatus']);
    smtp_assert(strpos(json_encode($r), 'USAGE-SECRET-VALUE') === false, 'secret must be redacted');
});

smtp_test('Proxy: transport exception returns a generic 502', function () {
    $transport = new SmtpFakeTransport();
    $transport->throw = true;
    $f = smtp_proxy_fixture(['transport' => $transport]);
    $r = smtp_proxy_handle($f, ['fn' => 'usage', 'serviceid' => '5']);
    smtp_assert($r['httpStatus'] === 502, 'expected 502, got ' . $r['httpStatus']);
    smtp_assert(strpos(json_encode($r), 'transport down') === false, 'internal exception text must not leak');
});

/* ------------------------------------------------------------------------ *
 * Rate limiter (file-backed) and secret loading
 * ------------------------------------------------------------------------ */

function smtp_temp_dir($label)
{
    $dir = sys_get_temp_dir() . DS . 'smtp-tests-' . $label . '-' . uniqid();
    mkdir($dir, 0700, true);
    return $dir;
}

smtp_test('RateLimiter: allows up to the limit per key, denies beyond it, keys are independent', function () {
    $dir = smtp_temp_dir('rl');
    $rl = new SmtpUsageRateLimiter($dir);
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) {
        smtp_assert($rl->allow('client:1', 3, 300, $now), 'request ' . ($i + 1) . ' should be allowed');
    }
    smtp_assert($rl->allow('client:1', 3, 300, $now) === false, 'fourth request must be denied');
    smtp_assert($rl->allow('client:2', 3, 300, $now) === true, 'a different client must not be affected');
});

smtp_test('RateLimiter: window resets after it elapses', function () {
    $dir = smtp_temp_dir('rlw');
    $rl = new SmtpUsageRateLimiter($dir);
    $t0 = 2000000;
    $rl->allow('client:9', 1, 300, $t0);
    smtp_assert($rl->allow('client:9', 1, 300, $t0 + 10) === false, 'second request in the window must be denied');
    smtp_assert($rl->allow('client:9', 1, 300, $t0 + 301) === true, 'request after the window must be allowed');
});

smtp_test('RateLimiter: unwritable storage throws (fail closed) instead of allowing', function () {
    $file = smtp_temp_dir('rlf') . DS . 'not-a-dir';
    file_put_contents($file, 'x');
    $rl = new SmtpUsageRateLimiter($file);
    smtp_assert_throws(\RuntimeException::class, 'rate limiter storage', function () use ($rl) {
        $rl->allow('client:1', 1, 300, 1);
    });
});

smtp_test('Secrets: environment variables take precedence and the file is a fallback', function () {
    $dir = smtp_temp_dir('sec');
    $file = $dir . DS . 'secrets.php';
    file_put_contents($file, "<?php\nreturn ['usage' => 'FILE-USAGE', 'logs' => 'FILE-LOGS'];\n");

    putenv('SMTPHOSTING_USAGE_SECRET=ENV-USAGE');
    putenv('SMTPHOSTING_LOGS_SECRET');
    $s = SmtpUsageProxy::loadSecrets($file);
    putenv('SMTPHOSTING_USAGE_SECRET');
    smtp_assert($s['usage'] === 'ENV-USAGE', 'env var must win over the file');
    smtp_assert($s['logs'] === 'FILE-LOGS', 'missing env var must fall back to the file');

    $none = SmtpUsageProxy::loadSecrets($dir . DS . 'absent.php');
    smtp_assert($none['usage'] === '' && $none['logs'] === '', 'no configuration must yield empty secrets (503 later)');
});

/* ------------------------------------------------------------------------ *
 * Regression / static checks on shipped files
 * ------------------------------------------------------------------------ */

smtp_test('Regression: the previously hardcoded upstream secrets are not present in shipped module files', function () {
    // SHA-256 digests of the two secrets that were committed in smtp-api.php (values are not stored here).
    $blocked = [
        '3912fcc2d55b5fac9a2d354dcc9c2fff296604b8c62b8216540d53f24b225a9b',
        '7570458e8b2b9e0e22b5d68f25d6a646a7f611150fc3e3df84ad184210c34f30',
    ];
    $moduleRoot = dirname(__DIR__);
    $hits = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleRoot, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        $rel  = str_replace($moduleRoot . DS, '', $path);
        if (strpos($rel, 'vendor' . DS) === 0 || strpos($rel, 'tests' . DS) === 0 || !is_file($path)) {
            continue;
        }
        if (!preg_match('/\.(php|tpl|js|md|txt|json|yml|yaml|html)$/i', $path)) {
            continue;
        }
        if (preg_match_all('/[A-Za-z0-9]{36}/', (string) file_get_contents($path), $m)) {
            foreach (array_unique($m[0]) as $token) {
                if (in_array(hash('sha256', $token), $blocked, true)) {
                    $hits[] = $rel;
                }
            }
        }
    }
    smtp_assert(count($hits) === 0, 'committed upstream secret found in: ' . implode(', ', array_unique($hits)));
});

smtp_test('Regression: client-area template never sends a secret or a customer identity from the browser', function () {
    $tpl = file_get_contents(dirname(__DIR__) . DS . 'templates' . DS . 'assets' . DS . 'tpl' . DS . 'DefaultSubmodule' . DS . 'clientarea.tpl');
    smtp_assert(strpos($tpl, 'secret=') === false, 'clientarea.tpl must not put a secret in the request URL');
    smtp_assert(strpos($tpl, 'user_name=') === false, 'clientarea.tpl must not send user_name; the server derives it');
    smtp_assert(strpos($tpl, 'main_domain=') === false, 'clientarea.tpl must not send main_domain; the server derives it');
    smtp_assert(strpos($tpl, 'smtp-api.php?fn=usage&serviceid=') !== false, 'usage request must send serviceid');
    smtp_assert(strpos($tpl, 'smtp-api.php?fn=logs&serviceid=') !== false, 'logs request must send serviceid');
});

smtp_test('Regression: upstream redirects are not followed and only HTTPS is allowed in the curl transport', function () {
    $src = file_get_contents(dirname(__DIR__) . DS . 'Helpers' . DS . 'SmtpUsageProxy.php');
    smtp_assert(strpos($src, 'CURLOPT_FOLLOWLOCATION, false') !== false, 'CURLOPT_FOLLOWLOCATION must be false');
    smtp_assert(strpos($src, 'CURLOPT_PROTOCOLS, CURLPROTO_HTTPS') !== false, 'CURLOPT_PROTOCOLS must be HTTPS only');
    smtp_assert(strpos($src, 'CURLOPT_SSL_VERIFYPEER, true') !== false, 'TLS peer verification must stay enabled');
});
