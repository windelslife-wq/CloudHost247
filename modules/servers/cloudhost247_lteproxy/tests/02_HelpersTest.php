<?php
/**
 * C-2: Helpers + ApiException pure-unit suite (real classes, no I/O).
 * Prints FAIL=<n>.
 */
namespace {
    require_once __DIR__ . '/bootstrap.php';
    ch247_require_lib();

    use CloudHost247\LTEProxy\ApiException;
    use CloudHost247\LTEProxy\Helpers;
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

    echo "== sanitize + validators ==\n";
    check('sanitizeString strips tags', Helpers::sanitizeString('  <b>x</b> ') === 'x');
    check('sanitizeString escapes quotes (ENT_QUOTES)',
        Helpers::sanitizeString("a'b\"c") === 'a&#039;b&quot;c');
    check('isValidIp v4', Helpers::isValidIp('203.0.113.9') && !Helpers::isValidIp('999.1.1.1'));
    check('isValidIp v6', Helpers::isValidIp('2001:db8::1') && !Helpers::isValidIp('not-an-ip'));
    check('isValidCidr', Helpers::isValidCidr('10.0.0.0/8') && !Helpers::isValidCidr('10.0.0.0/33')
        && !Helpers::isValidCidr('10.0.0.0') && !Helpers::isValidCidr('nope/8'));
    check('isValidProxyType case-insensitive',
        Helpers::isValidProxyType('socks5') && Helpers::isValidProxyType('HTTPS') && !Helpers::isValidProxyType('ftp'));
    check('isValidRotationType',
        Helpers::isValidRotationType('per_request') && !Helpers::isValidRotationType('hourly'));
    check('isValidAuthType',
        Helpers::isValidAuthType('both') && !Helpers::isValidAuthType('oauth'));
    check('isValidConnectionType',
        Helpers::isValidConnectionType('wifi_and_cellular') && !Helpers::isValidConnectionType('satellite'));
    check('isValidRegion', Helpers::isValidRegion('us_west') && !Helpers::isValidRegion('xx'));
    check('isValidCarrier covers intl (LP-16)',
        Helpers::isValidCarrier('verizon') && Helpers::isValidCarrier('kpn')
        && Helpers::isValidCarrier('VODAFONE_DE') && !Helpers::isValidCarrier('nope'));
    check('getProxyTypes', Helpers::getProxyTypes() === ['SOCKS5', 'HTTPS', 'HTTP']);
    check('getAuthTypes order', Helpers::getAuthTypes() === ['ip_whitelist', 'username_password', 'both']);
    check('getRegions has us label', Helpers::getRegions()['us'] === 'United States');
    check('getAllCarriers merges intl',
        Helpers::getAllCarriers()['verizon'] === 'Verizon' && Helpers::getAllCarriers()['kpn'] === 'KPN (NL)');

    echo "== formatters ==\n";
    check('formatProxy basic',
        Helpers::formatProxy(['proxy_type' => 'socks5', 'ip' => '1.2.3.4', 'port' => 1080]) === 'SOCKS5://1.2.3.4:1080');
    check('formatProxy encodes creds (LP-37)',
        Helpers::formatProxy(['ip' => '1.2.3.4', 'port' => 1080, 'username' => 'u@s', 'password' => 'p@ss:x'])
        === 'SOCKS5://u%40s:p%40ss%3Ax@1.2.3.4:1080');
    check('formatProxyForCopy keeps raw userinfo',
        Helpers::formatProxyForCopy(['ip' => '1.2.3.4', 'port' => 1080, 'username' => 'u', 'password' => 'p'])
        === '1.2.3.4:1080:u:p');
    check('formatDuration shapes',
        Helpers::formatDuration(0) === 'Invalid' && Helpers::formatDuration(1) === '1 day'
        && Helpers::formatDuration(9) === '1 week 2 days' && Helpers::formatDuration(30) === '1 month'
        && Helpers::formatDuration(61) === '2 months 1 day');
    check('formatBytes', Helpers::formatBytes(512) === '512 B' && Helpers::formatBytes(2048) === '2 KB'
        && Helpers::formatBytes(1536) === '1.5 KB');
    check('formatSpeed', Helpers::formatSpeed(125000.0) === '1 Mbps'
        && Helpers::formatSpeed(125000000.0) === '1 Gbps');
    check('formatDate valid', Helpers::formatDate('2026-01-02 15:04:05') === 'Jan 2, 2026 3:04 PM');
    check('formatDate garbage passes through', Helpers::formatDate('nonsense') === 'nonsense');
    check('getRelativeTime now', Helpers::getRelativeTime(date('Y-m-d H:i:s')) === 'Just now');
    check('getRelativeTime garbage passes through', Helpers::getRelativeTime('nonsense') === 'nonsense');
    check('getTimeRemaining unknown/expired',
        Helpers::getTimeRemaining('') === 'Unknown' && Helpers::getTimeRemaining('2001-01-01') === 'Expired');
    check('getTimeRemaining future shape',
        preg_match('/^\d+d \d+h \d+m$/', Helpers::getTimeRemaining(date('Y-m-d H:i:s', time() + 90061))) === 1);
    check('generateRandomString length', strlen(Helpers::generateRandomString(32)) === 32
        && strlen(Helpers::generateRandomString(16)) === 16);
    check('generateRandomString unique', Helpers::generateRandomString(32) !== Helpers::generateRandomString(32));
    check('generateRandomString odd length rounds up (LP-53)', strlen(Helpers::generateRandomString(15)) === 16);
    check('hash is sha256', Helpers::hash('x') === hash('sha256', 'x'));
    check('maskString short fully masked', Helpers::maskString('ab', 4) === '**');
    check('maskString keeps ends', Helpers::maskString('secretpassword123', 3) === 'sec***********123');

    echo "== client ip ==\n";
    $savedServer = $_SERVER;
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];
    check('getClientIp remote fallback', Helpers::getClientIp() === '203.0.113.9');
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1'];
    check('getClientIp first forwarded', Helpers::getClientIp() === '198.51.100.7');
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.9.9.9'];
    check('getClientIp skips private', Helpers::getClientIp() === '203.0.113.9');
    $_SERVER = ['REMOTE_ADDR' => '10.0.0.5'];
    check('getClientIp private remote as last resort', Helpers::getClientIp() === '10.0.0.5');
    $_SERVER = [];
    check('getClientIp empty env sentinel', Helpers::getClientIp() === '0.0.0.0');
    $_SERVER = $savedServer;
    check('isPrivateIp', Helpers::isPrivateIp('10.1.2.3') && !Helpers::isPrivateIp('8.8.8.8'));

    echo "== sanitizeOrderParams ==\n";
    $base = ['quantity' => 5, 'region' => 'US_WEST', 'carrier' => 'KPN', 'proxy_type' => 'https',
             'duration' => 10, 'connection_type' => 'wifi', 'rotation' => 'timed', 'auth_type' => 'both',
             'client_ip' => '198.51.100.7', 'notes' => '<i>hi</i>'];
    $s = Helpers::sanitizeOrderParams($base);
    check('normalises enums', $s['region'] === 'us_west' && $s['carrier'] === 'kpn'
        && $s['proxy_type'] === 'HTTPS' && $s['connection_type'] === 'WIFI'
        && $s['rotation'] === 'timed' && $s['auth_type'] === 'both');
    check('keeps valid scalars', $s['quantity'] === 5 && $s['duration'] === 10
        && $s['client_ip'] === '198.51.100.7' && $s['notes'] === 'hi');
    $s = Helpers::sanitizeOrderParams(['quantity' => 0, 'duration' => 9999]);
    check('clamps quantity/duration', $s['quantity'] === 1 && $s['duration'] === 365);
    $s = Helpers::sanitizeOrderParams(['quantity' => 5000]);
    check('clamps quantity top', $s['quantity'] === 1000);
    $s = Helpers::sanitizeOrderParams([]);
    check('defaults region/carrier/type', $s['region'] === 'us' && $s['carrier'] === 'verizon'
        && $s['proxy_type'] === 'SOCKS5' && $s['auth_type'] === 'username_password');
    check('no client_ip for password auth', !isset($s['client_ip']));
    $s = Helpers::sanitizeOrderParams(['auth_type' => 'ip_whitelist', 'client_ip' => '198.51.100.7']);
    check('whitelist keeps explicit ip', $s['client_ip'] === '198.51.100.7');
    $threw = false;
    try {
        Helpers::sanitizeOrderParams(['region' => 'xx']);
    } catch (\InvalidArgumentException $e) {
        $threw = $e->getMessage() === 'Invalid region selected';
    }
    check('bad region throws', $threw);
    $threw = false;
    try {
        Helpers::sanitizeOrderParams(['carrier' => 'xx']);
    } catch (\InvalidArgumentException $e) {
        $threw = $e->getMessage() === 'Invalid carrier selected';
    }
    check('bad carrier throws', $threw);
    $threw = false;
    try {
        Helpers::sanitizeOrderParams(['proxy_type' => 'xx']);
    } catch (\InvalidArgumentException $e) {
        $threw = $e->getMessage() === 'Invalid proxy type';
    }
    check('bad proxy type throws', $threw);
    $threw = false;
    try {
        Helpers::sanitizeOrderParams(['auth_type' => 'both', 'client_ip' => 'bogus']);
    } catch (\InvalidArgumentException $e) {
        $threw = $e->getMessage() === 'Invalid client IP address';
    }
    check('bad whitelist ip throws', $threw);
    $s = Helpers::sanitizeOrderParams(['connection_type' => 'xx', 'rotation' => 'xx', 'auth_type' => 'xx']);
    check('soft enums fall back', $s['connection_type'] === 'WIFI_AND_CELLULAR'
        && $s['rotation'] === 'manual' && $s['auth_type'] === 'username_password');

    echo "== csrf + guards ==\n";
    $_SESSION = [];
    $tok = Helpers::generateCsrfToken();
    check('csrf token sticky + 32 hex', strlen($tok) === 32 && Helpers::generateCsrfToken() === $tok);
    check('csrf validates', Helpers::validateCsrfToken($tok) && !Helpers::validateCsrfToken('nope'));
    $_SESSION = [];
    check('csrf without session fails', !Helpers::validateCsrfToken($tok));
    check('belongs: hit', Helpers::proxyBelongsToOrder([['id' => 'a'], ['id' => 'b']], 'b'));
    check('belongs: miss', !Helpers::proxyBelongsToOrder([['id' => 'a']], 'zzz'));
    check('belongs: empty id never matches', !Helpers::proxyBelongsToOrder([['id' => 'a']], ''));
    check('belongs: int ids coerced', Helpers::proxyBelongsToOrder([['id' => 42]], '42'));
    check('belongs: rows without id skipped', !Helpers::proxyBelongsToOrder([['x' => 1]], '1'));
    check('safeHttpStatus passes 4xx/5xx', Helpers::safeHttpStatus(404) === 404
        && Helpers::safeHttpStatus(503) === 503 && Helpers::safeHttpStatus(400) === 400
        && Helpers::safeHttpStatus(599) === 599);
    check('safeHttpStatus clamps the rest', Helpers::safeHttpStatus(0) === 500
        && Helpers::safeHttpStatus(200) === 500 && Helpers::safeHttpStatus(99) === 500
        && Helpers::safeHttpStatus(600) === 500 && Helpers::safeHttpStatus(-1) === 500);

    echo "== JSON seam + ApiException ==\n";
    $GLOBALS['CH247_TEST_JSON_THROW'] = true;
    try {
        Helpers::jsonSuccess(['a' => 1]);
        check('jsonSuccess throws under seam', false);
    } catch (JsonResponseException $e) {
        check('jsonSuccess shape', $e->status === 200 && $e->data === ['success' => true, 'timestamp' => $e->data['timestamp'], 'a' => 1]);
    }
    try {
        Helpers::jsonError('boom', 403, ['x' => 1]);
        check('jsonError throws under seam', false);
    } catch (JsonResponseException $e) {
        check('jsonError shape', $e->status === 403 && $e->data['success'] === false
            && $e->data['error'] === 'boom' && $e->data['x'] === 1
            && isset($e->data['timestamp']));
    }
    $GLOBALS['CH247_TEST_JSON_THROW'] = false;
    $e = new ApiException('m', 503, ['code' => 503, 'message' => 'm']);
    check('int error code coerced (LP-21b)', $e->getErrorCode() === '503' && $e->getCode() === 503);
    $e = new ApiException('m', 400, ['error_code' => 'BAD_THING']);
    check('string error_code honored', $e->getErrorCode() === 'BAD_THING');
    $e = new ApiException('m', 400, ['code' => ['nested' => 1]]);
    check('non-scalar code becomes null', $e->getErrorCode() === null);
    $e = new ApiException('m', 400);
    check('missing code is null', $e->getErrorCode() === null);
    check('user messages by class',
        (new ApiException('x', 401))->getUserMessage() === 'Authentication failed. Please check your API credentials.'
        && (new ApiException('x', 429))->getUserMessage() === 'Too many requests. Please wait a moment and try again.'
        && (new ApiException('x', 500))->getUserMessage() === 'The proxy service is temporarily unavailable. Please try again later.'
        && (new ApiException('plain', 400))->getUserMessage() === 'plain');
    check('retryable flags', (new ApiException('x', 500))->isRetryable()
        && (new ApiException('x', 429))->isRetryable() && (new ApiException('x', 0))->isRetryable()
        && !(new ApiException('x', 400))->isRetryable());

    echo "PASS=$pass FAIL=$fail\n";
    exit($fail ? 1 : 0);
}
