<?php
/**
 * CloudHost247 Services Suite — request helpers.
 *
 * Small, pure functions over superglobals so services stay testable: tests
 * populate $_GET/$_POST/$_SERVER exactly as a web server would.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Http
{
    public static function get($key, $default = '')
    {
        return isset($_GET[$key]) ? self::clean($_GET[$key]) : $default;
    }

    public static function post($key, $default = '')
    {
        return isset($_POST[$key]) ? self::clean($_POST[$key]) : $default;
    }

    public static function postInt($key, $default = 0)
    {
        $v = isset($_POST[$key]) ? $_POST[$key] : null;
        return Validator::isInt($v) ? (int) $v : (int) $default;
    }

    public static function getInt($key, $default = 0)
    {
        $v = isset($_GET[$key]) ? $_GET[$key] : null;
        return Validator::isInt($v) ? (int) $v : (int) $default;
    }

    public static function method()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    }

    public static function isPost()
    {
        return self::method() === 'POST';
    }

    /** Raw client IP (never trusted for auth — only for pseudonymised buckets). */
    public static function clientIp()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function redirect($url)
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, 303);
        }
        echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url='
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></head><body>'
            . '<p>Redirecting to <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>…</p></body></html>';
        exit;
    }

    /** Emit a JSON response and stop. */
    public static function json(array $payload, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function clean($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'clean'], $value);
        }
        return trim((string) $value);
    }
}
