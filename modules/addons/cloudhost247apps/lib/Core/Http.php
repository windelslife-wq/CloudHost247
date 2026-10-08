<?php
/**
 * CloudHost247 App Cloud — HTTP primitives.
 *
 * Request context (client IP, user agent, method, headers, raw body) and the
 * response helpers every entry point uses. Client IP resolution honours a
 * configured trusted proxy list only — an attacker-supplied X-Forwarded-For can
 * never spoof the address that lands in the audit log or drives rate limiting.
 *
 * Test seam: Http::overrideIp('203.0.113.9').
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Http
{
    /** @var string|null */
    private static $ipOverride;

    /** @var array<string,string> header overrides for tests */
    private static $headerOverrides = [];

    /** @var callable|null outbound request fake: fn($method,$url,$options) */
    private static $clientFake;

    /** @var array recorded outbound requests */
    private static $clientCalls = [];

    public static function overrideIp($ip)
    {
        self::$ipOverride = $ip === null ? null : (string) $ip;
    }

    public static function overrideHeader($name, $value)
    {
        self::$headerOverrides[strtolower((string) $name)] = $value === null ? null : (string) $value;
    }

    public static function resetOverrides()
    {
        self::$ipOverride = null;
        self::$headerOverrides = [];
    }

    /**
     * Install a fake transport. Every outbound call the platform makes (agent,
     * WHM, UAPI, Kubernetes, DNS provider, object storage, ACME) goes through
     * request(), so one seam replaces all of them in tests.
     */
    public static function setClientFake($fake)
    {
        self::$clientFake = $fake;
        self::$clientCalls = [];
    }

    /** @return array[] [['method','url','options','response'], …] */
    public static function clientCalls($urlContains = null)
    {
        if ($urlContains === null) {
            return self::$clientCalls;
        }
        return array_values(array_filter(self::$clientCalls, function ($call) use ($urlContains) {
            return strpos($call['url'], (string) $urlContains) !== false;
        }));
    }

    /**
     * Redact a recorded outbound call.
     *
     * Logger::redact only walks arrays, but a request body is usually an opaque
     * JSON or form-encoded string — exactly where a credential would hide. Bodies
     * are decoded, redacted and re-encoded for the *record*; the bytes on the wire
     * are untouched.
     */
    private static function redactOptions(array $options)
    {
        $options = Logger::redact($options);
        foreach (['body', 'json'] as $key) {
            if (!isset($options[$key]) || !is_string($options[$key]) || $options[$key] === '') {
                continue;
            }
            $decoded = json_decode($options[$key], true);
            if (is_array($decoded)) {
                $options[$key] = Str::jsonEncode(Logger::redact($decoded));
                continue;
            }
            $options[$key] = self::redactString($options[$key]);
        }
        return $options;
    }

    /** Mask `key=value` / `"key": "value"` pairs whose key looks secret-bearing. */
    private static function redactString($value)
    {
        $pattern = '/([A-Za-z0-9_.\-]*(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|'
            . 'authorization|credential|access[_-]?key)[A-Za-z0-9_.\-]*)'
            . '(["\']?\s*[:=]\s*["\']?)([^"\',&}\s]+)/i';
        return preg_replace($pattern, '$1$2[redacted]', (string) $value);
    }

    public static function clientIp()
    {
        if (self::$ipOverride !== null) {
            return self::$ipOverride;
        }
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        $trusted = Settings::listOf('trusted_proxies');
        if ($trusted && self::ipInList($remote, $trusted)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (!empty($_SERVER[$header])) {
                    $candidates = array_map('trim', explode(',', (string) $_SERVER[$header]));
                    foreach ($candidates as $candidate) {
                        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    private static function ipInList($ip, array $entries)
    {
        foreach ($entries as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (strpos($entry, '/') !== false) {
                list($subnet, $bits) = explode('/', $entry, 2);
                $ipLong = ip2long((string) $ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong !== false && $subnetLong !== false) {
                    $mask = (-1 << (32 - max(0, min(32, (int) $bits))));
                    if (($ipLong & $mask) === ($subnetLong & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function userAgent()
    {
        return Str::clip(isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', 255);
    }

    public static function method()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    public static function header($name, $default = '')
    {
        $lower = strtolower((string) $name);
        if (array_key_exists($lower, self::$headerOverrides)) {
            $value = self::$headerOverrides[$lower];
            return $value === null ? $default : $value;
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $lower));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $header => $value) {
                if (strtolower((string) $header) === $lower) {
                    return (string) $value;
                }
            }
        }
        return $default;
    }

    /** @return array<string,string> */
    public static function headers()
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $out[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (self::$headerOverrides as $name => $value) {
            if ($value === null) {
                unset($out[$name]);
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    public static function isAjax()
    {
        return strtolower(self::header('X-Requested-With')) === 'xmlhttprequest';
    }

    public static function rawBody()
    {
        $body = file_get_contents('php://input');
        return $body === false ? '' : $body;
    }

    /**
     * Perform an outbound HTTP request. This is the only egress path in the
     * module, so TLS policy, timeouts and logging are enforced in one place.
     *
     * @param array $options headers, body, timeout, verify_tls, auth (basic),
     *                       bearer, ca_bundle, form (array), json (mixed),
     *                       method, max_bytes
     * @return array{status:int, headers:array<string,string>, body:string, error:string|null}
     */
    public static function request($method, $url, array $options = [])
    {
        $method = strtoupper((string) $method);
        $call = ['method' => $method, 'url' => (string) $url, 'options' => self::redactOptions($options)];

        if (self::$clientFake !== null) {
            $response = call_user_func(self::$clientFake, $method, (string) $url, $options);
            $response = is_array($response) ? $response : ['status' => 200, 'body' => (string) $response, 'headers' => []];
            $response += ['status' => 200, 'headers' => [], 'body' => '', 'error' => null];
            $call['response'] = [
                'status' => $response['status'],
                'body' => !empty($options['redact_response']) ? '[redacted]' : Str::clip($response['body'], 500),
            ];
            self::$clientCalls[] = $call;
            return $response;
        }

        self::$clientCalls[] = $call;

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'cURL is not available'];
        }

        $ch = curl_init();
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];

        if (isset($options['json'])) {
            $body = Str::jsonEncode($options['json']);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($options['form']) && is_array($options['form'])) {
            $body = http_build_query($options['form']);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else {
            $body = isset($options['body']) ? (string) $options['body'] : '';
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = (is_int($name) ? $value : $name . ': ' . $value);
        }

        curl_setopt($ch, CURLOPT_URL, (string) $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, isset($options['timeout']) ? (int) $options['timeout'] : 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CloudHost247-AppCloud/' . CH247APPS_VERSION);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (!empty($options['bearer'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
            curl_setopt($ch, CURLOPT_XOAUTH2_BEARER, (string) $options['bearer']);
        }
        if (!empty($options['auth']) && is_array($options['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, implode(':', $options['auth']));
        }

        // TLS is verified by default. An operator may pin a CA bundle; disabling
        // verification entirely is allowed only for a private agent endpoint and
        // is logged, never the default.
        $verify = !array_key_exists('verify_tls', $options) || !empty($options['verify_tls']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
        if (!empty($options['ca_bundle'])) {
            curl_setopt($ch, CURLOPT_CAINFO, (string) $options['ca_bundle']);
        }
        if (!empty($options['client_cert'])) {
            curl_setopt($ch, CURLOPT_SSLCERT, (string) $options['client_cert']);
        }
        if (!empty($options['client_key'])) {
            curl_setopt($ch, CURLOPT_SSLKEY, (string) $options['client_key']);
        }
        if (isset($options['max_bytes'])) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, (int) $options['max_bytes']);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => $status, 'headers' => [], 'body' => '', 'error' => $error !== '' ? $error : 'Request failed'];
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        return [
            'status' => $status,
            'headers' => self::parseHeaders($rawHeaders),
            'body' => (string) $responseBody,
            'error' => $error !== '' ? $error : null,
        ];
    }

    public static function parseHeaders($raw)
    {
        $out = [];
        foreach (explode("\n", (string) $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($name, $value) = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }
        return $out;
    }

    /* -------------------------------------------------------- responses -- */

    public static function sendSecurityHeaders()
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
    }

    public static function redirect($url)
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
        }
        echo '<script>window.location.href = ' . json_encode((string) $url) . ';</script>';
        exit;
    }

    /** The current request URL (path + query), used for post-login returns. */
    public static function currentPath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return Str::clip($uri, 500);
    }

    public static function isSecure()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return strtolower(self::header('X-Forwarded-Proto')) === 'https';
    }
}
