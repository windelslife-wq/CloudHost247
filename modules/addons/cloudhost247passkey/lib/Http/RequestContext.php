<?php
/**
 * Trusted server-side request context for the native WHMCS boundary.
 *
 * TLS state comes only from the PHP/server environment, never from
 * X-Forwarded-* headers. Client IP uses REMOTE_ADDR for the same reason.
 * Browser-supplied bodies are size-capped before decoding.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Http;

class RequestContext
{
    const MAX_JSON_BYTES = 262144;

    public static function method()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    /**
     * Verified HTTPS state. Deployments behind a TLS-terminating proxy must
     * set HTTPS at the PHP layer; forwarding headers are never trusted.
     */
    public static function isHttps()
    {
        if (isset($_SERVER['HTTPS']) && is_string($_SERVER['HTTPS'])) {
            $flag = strtolower(trim($_SERVER['HTTPS']));
            if ($flag === 'on' || $flag === '1') {
                return true;
            }
        }
        if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
            return true;
        }
        return false;
    }

    /**
     * Canonical request origin for RP/origin binding. Prefers the browser
     * Origin header on credentialed requests, otherwise derives the canonical
     * origin from verified TLS state plus the Host header.
     */
    public static function requestOrigin()
    {
        if (isset($_SERVER['HTTP_ORIGIN']) && is_string($_SERVER['HTTP_ORIGIN'])) {
            $origin = trim($_SERVER['HTTP_ORIGIN']);
            if ($origin !== '' && strlen($origin) <= 512) {
                return $origin;
            }
        }
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
        if ($host === '' || strlen($host) > 253 || strpos($host, '@') !== false) {
            return '';
        }
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . strtolower($host);
    }

    public static function ipAddress()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '' || strlen($ip) > 45 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    public static function userAgent()
    {
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ($agent === '' || preg_match('/[\x00-\x1F\x7F]/', $agent)) {
            return null;
        }
        return substr($agent, 0, 512);
    }

    /** Decode a size-capped JSON body; returns null when absent. */
    public static function jsonBody()
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_JSON_BYTES) {
            throw new \InvalidArgumentException('Request body is too large.');
        }
        $decoded = json_decode($raw, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \InvalidArgumentException('Request body is not valid JSON.');
        }
        return $decoded;
    }

    public static function postParam($key, $default = null)
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        $value = $_POST[$key];
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    public static function queryParam($key, $default = null)
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $value = $_GET[$key];
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    /** Best-effort WHMCS SystemURL for RP configuration hints. */
    public static function systemUrl()
    {
        try {
            if (!class_exists('WHMCS\\Database\\Capsule')) {
                return '';
            }
            $url = \WHMCS\Database\Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')->value('value');
            return is_string($url) ? rtrim($url, '/') : '';
        } catch (\Throwable $error) {
            return '';
        }
    }
}
