<?php
/**
 * Domain Broker — CSRF protection.
 *
 * Inside WHMCS the host application's own token is used so there is one token
 * per session rather than a competing scheme; generate_token()/check_token()
 * are WHMCS globals. When those are unavailable (CLI, tests, standalone API)
 * a per-session HMAC token is issued instead.
 *
 * Token-authenticated API calls do not use CSRF tokens: they are not
 * cookie-authenticated, so there is nothing to forge.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Csrf
{
    const SESSION_KEY = 'domainbroker_csrf';
    const FIELD = 'domainbroker_token';

    /** Issue a token for the current session. */
    public static function token()
    {
        if (function_exists('generate_token')) {
            // WHMCS returns a full hidden input; extract the value so callers
            // can place it wherever they need.
            $html = generate_token('plain');
            if (is_string($html) && $html !== '') {
                return $html;
            }
        }
        return self::fallbackToken();
    }

    /** Ready-to-print hidden input. */
    public static function field()
    {
        if (function_exists('generate_token')) {
            return generate_token();
        }
        return '<input type="hidden" name="' . self::FIELD . '" value="' . Str::e(self::fallbackToken()) . '">';
    }

    /**
     * Validate the token on a state-changing request.
     *
     * @throws AuthorizationException
     */
    public static function verify($submitted = null)
    {
        if (function_exists('check_token')) {
            // check_token() halts the request itself on failure in WHMCS.
            check_token();
            return true;
        }

        $expected = self::peekFallbackToken();
        if ($expected === null) {
            throw new AuthorizationException('Your session has expired. Please reload the page and try again.');
        }
        if ($submitted === null) {
            $submitted = isset($_POST[self::FIELD]) ? $_POST[self::FIELD] : Http::header('X-CSRF-Token');
        }
        if (!is_string($submitted) || !hash_equals($expected, $submitted)) {
            throw new AuthorizationException('Security token mismatch. Please reload the page and try again.');
        }
        return true;
    }

    public static function matches($submitted)
    {
        try {
            return self::verify($submitted);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function fallbackToken()
    {
        self::startSession();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = Crypto::randomToken(32);
        }
        return $_SESSION[self::SESSION_KEY];
    }

    protected static function peekFallbackToken()
    {
        self::startSession();
        return isset($_SESSION[self::SESSION_KEY]) ? $_SESSION[self::SESSION_KEY] : null;
    }

    protected static function startSession()
    {
        if (php_sapi_name() === 'cli') {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
