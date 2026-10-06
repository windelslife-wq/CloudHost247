<?php
/**
 * CSRF protection for module client pages.
 *
 * In a WHMCS client session the token lives in $_SESSION; in the test harness
 * (or a stateless context) Csrf::pin() plants the token for the request so
 * field()/verify() round-trip deterministically.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Csrf
{
    public const PARAM = '_chs_token';
    private const SESSION_KEY = 'chs_csrf';

    /** @var string|null request-scoped pinned token */
    private static $pinned;

    /** Current token (create on first use). */
    public static function token()
    {
        if (self::$pinned !== null) {
            return self::$pinned;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION[self::SESSION_KEY])) {
                $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(24));
            }
            return (string) $_SESSION[self::SESSION_KEY];
        }
        // Stateless context: pin a throwaway token for this request.
        self::$pinned = bin2hex(random_bytes(24));
        return self::$pinned;
    }

    /** Hidden-input markup for forms. */
    public static function field()
    {
        return '<input type="hidden" name="' . self::PARAM . '" value="' . chs_h(self::token()) . '">';
    }

    /** Timing-safe check; empty input never passes. */
    public static function verify($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return false;
        }
        return hash_equals(self::token(), $token);
    }

    /**
     * Verify the token that arrived with the current request (POST param or
     * X-CSRF header); throws on mismatch, void on success.
     */
    public static function verifyRequest()
    {
        $token = isset($_POST[self::PARAM]) ? (string) $_POST[self::PARAM]
            : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        if (!self::verify($token)) {
            throw new ValidationException(['_token' => 'Your session token is stale — refresh the page and try again.']);
        }
    }

    /** Test seam / request pinning: plant the token for subsequent calls. */
    public static function pin($token)
    {
        self::$pinned = (string) $token;
        if (session_status() === PHP_SESSION_ACTIVE && $token !== '') {
            $_SESSION[self::SESSION_KEY] = (string) $token;
        }
    }

    /** Release the pinned token. */
    public static function clear()
    {
        self::$pinned = null;
    }
}
