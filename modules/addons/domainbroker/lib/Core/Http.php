<?php
/**
 * Domain Broker — request environment helpers.
 *
 * Client IP resolution deliberately does NOT trust X-Forwarded-For unless the
 * operator has declared the proxy (DOMAINBROKER_TRUSTED_PROXIES), because the
 * IP is used for rate limiting, risk scoring and the audit trail.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Http
{
    /** @var string|null test override */
    protected static $ipOverride;

    public static function overrideIp($ip)
    {
        self::$ipOverride = $ip;
    }

    public static function clientIp()
    {
        if (self::$ipOverride !== null) {
            return self::$ipOverride;
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $trusted = array_filter(array_map('trim', explode(',', (string) getenv('DOMAINBROKER_TRUSTED_PROXIES'))));

        if ($remote !== '' && $trusted && in_array($remote, $trusted, true)) {
            $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            if ($forwarded !== '') {
                $parts = array_map('trim', explode(',', $forwarded));
                foreach ($parts as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        return $candidate;
                    }
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    public static function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? Str::clip($_SERVER['HTTP_USER_AGENT'], 400) : '';
    }

    public static function method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    }

    public static function header($name, $default = '')
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        // Content-Type / Content-Length are not HTTP_ prefixed.
        $alt = strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$alt]) ? (string) $_SERVER[$alt] : $default;
    }

    /**
     * Every inbound header, normalised to Title-Case keys.
     *
     * Used by the webhook endpoint, which must hand the signature header to
     * the verifier exactly as it arrived.
     *
     * @return array<string,string>
     */
    public static function headers()
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $out[(string) $name] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') !== 0) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            if (!isset($out[$name])) {
                $out[$name] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($_SERVER[$key]) && !isset($out[$name])) {
                $out[$name] = (string) $_SERVER[$key];
            }
        }
        return $out;
    }

    public static function isAjax()
    {
        return strtolower(self::header('X-Requested-With')) === 'xmlhttprequest';
    }

    /** Raw request body (cached so it can be read for both JSON and signatures). */
    public static function rawBody()
    {
        static $body;
        if ($body === null) {
            $body = (string) file_get_contents('php://input');
        }
        return $body;
    }

    /** Build an absolute client-area URL for a module action. */
    public static function clientUrl(array $params = [])
    {
        $base = 'index.php?m=domainbroker';
        foreach ($params as $k => $v) {
            $base .= '&' . rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        return $base;
    }

    public static function systemUrl($path = '')
    {
        if (class_exists('\WHMCS\Utility\Environment\WebHelper')) {
            try {
                return rtrim(\WHMCS\Utility\Environment\WebHelper::getBaseUrl(), '/') . '/' . ltrim($path, '/');
            } catch (\Throwable $e) {
                // fall through
            }
        }
        $configured = (string) getenv('DOMAINBROKER_BASE_URL');
        if ($configured !== '') {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }
        return ltrim($path, '/');
    }
}
