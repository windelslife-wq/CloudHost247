<?php
/**
 * CSRF protection for authenticated Passkey requests.
 *
 * Uses WHMCS generate_token()/check_token() when the runtime provides them
 * and a session-bound fallback otherwise. Public login-ceremony endpoints
 * are rate-limited instead, matching the WHMCS login flow.
 *
 * @package CloudHost247\Passkey
 */

namespace CloudHost247\Passkey\Http;

class CsrfProtection
{
    const FIELD = 'ch247pk_csrf';
    const SESSION_KEY = 'ch247pk_csrf_token';
    const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token()
    {
        if (function_exists('generate_token')) {
            $token = generate_token('plain');
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }
        self::ensureSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field()
    {
        if (function_exists('generate_token')) {
            return generate_token();
        }
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Validate the token for an authenticated state-changing request. */
    public static function verify($submitted = null)
    {
        if (function_exists('check_token')) {
            check_token();
            return true;
        }
        if ($submitted === null) {
            $submitted = isset($_POST[self::FIELD])
                ? $_POST[self::FIELD]
                : (isset($_SERVER[self::HEADER]) ? $_SERVER[self::HEADER] : null);
        }
        self::ensureSession();
        $expected = isset($_SESSION[self::SESSION_KEY]) ? $_SESSION[self::SESSION_KEY] : '';
        if (!is_string($submitted) || $submitted === '' || !is_string($expected) || $expected === ''
            || !hash_equals($expected, $submitted)) {
            throw new \RuntimeException('CSRF_FAILED');
        }
        return true;
    }

    private static function ensureSession()
    {
        if (php_sapi_name() === 'cli') {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
