<?php
/** CSRF token (WHMCS check_token when present; standalone fallback otherwise). */

namespace Ch247Mkt\Core;

class Csrf
{
    const KEY = 'Ch247MktCsrf';

    public static function token()
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(16));
        }
        return $_SESSION[self::KEY];
    }
    public static function field()
    {
        return '<input type="hidden" name="' . self::KEY . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
    public static function verify($token)
    {
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }
    public static function verifyRequest()
    {
        $token = isset($_POST[self::KEY]) ? $_POST[self::KEY] : '';
        if (!self::verify($token)) {
            throw new ForbiddenException('Invalid or missing security token. Reload the page and try again.');
        }
    }
}
