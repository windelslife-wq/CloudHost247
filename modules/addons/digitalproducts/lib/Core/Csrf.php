<?php
namespace DigitalProducts\Core;

/** Uses WHMCS' session token when available and a fallback for tests/CLI. */
class Csrf
{
    const FIELD = 'digitalproducts_csrf';
    const SESSION_KEY = 'digitalproducts_csrf_token';

    public static function token()
    {
        if (function_exists('generate_token')) {
            $token = generate_token('plain');
            if (is_string($token) && $token !== '') return $token;
        }
        self::session();
        if (empty($_SESSION[self::SESSION_KEY])) $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field()
    {
        if (function_exists('generate_token')) return generate_token();
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify($submitted = null)
    {
        if (function_exists('check_token')) {
            check_token();
            return true;
        }
        if ($submitted === null) $submitted = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        self::session();
        if (!is_string($submitted) || empty($_SESSION[self::SESSION_KEY]) || !hash_equals($_SESSION[self::SESSION_KEY], $submitted)) {
            throw new AuthorizationException('Security token mismatch. Please reload the page and try again.');
        }
        return true;
    }

    protected static function session()
    {
        if (php_sapi_name() === 'cli') { if (!isset($_SESSION)) $_SESSION = []; return; }
        if (session_status() === PHP_SESSION_NONE) @session_start();
    }
}
