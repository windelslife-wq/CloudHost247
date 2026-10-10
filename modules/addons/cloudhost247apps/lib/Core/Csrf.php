<?php
/**
 * CloudHost247 App Cloud — CSRF protection and event stream.
 *
 * Csrf: double-submit tokens bound to the session, verified on every
 * cookie-authenticated write (portal forms and same-origin API calls). Bearer
 * token and signed agent calls are exempt — there is no ambient credential to
 * forge — which is exactly why the router distinguishes them.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Csrf
{
    const SESSION_KEY = 'ch247apps_csrf';

    /** The token for this session (created on first use). */
    public static function token()
    {
        if (self::sessionAvailable()) {
            if (empty($_SESSION[self::SESSION_KEY])) {
                $_SESSION[self::SESSION_KEY] = Crypto::randomToken(24);
            }
            return (string) $_SESSION[self::SESSION_KEY];
        }
        // CLI / API-only contexts have no session; callers must use a token.
        return '';
    }

    public static function field($name = 'ch247_token')
    {
        $token = self::token();
        return $token === ''
            ? ''
            : '<input type="hidden" name="' . $name . '" value="' . Str::e($token) . '">';
    }

    /**
     * Verify a submitted token.
     *
     * @throws AuthenticationException
     */
    public static function verify($submitted = null)
    {
        if (!self::matches($submitted)) {
            throw new AuthenticationException('Your session expired or the form was tampered with. Please try again.', [
                'reason' => 'csrf',
            ]);
        }
        return true;
    }

    public static function matches($submitted = null)
    {
        $expected = self::token();
        if ($expected === '') {
            return false;
        }
        if ($submitted === null) {
            $submitted = isset($_POST['ch247_token']) ? $_POST['ch247_token'] : Http::header('X-CSRF-Token');
        }
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }
        return Crypto::hashEquals($expected, $submitted);
    }

    private static function sessionAvailable()
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        return true;
    }

    /** Invalidate (logout / privilege change). */
    public static function rotate()
    {
        if (self::sessionAvailable()) {
            $_SESSION[self::SESSION_KEY] = Crypto::randomToken(24);
            return (string) $_SESSION[self::SESSION_KEY];
        }
        return '';
    }
}
