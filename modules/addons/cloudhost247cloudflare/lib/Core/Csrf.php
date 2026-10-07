<?php
namespace CloudHost247\Cloudflare\Core;
class Csrf
{
    const KEY = 'ch247cf_csrf';
    public static function token()
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY]) || strlen($_SESSION[self::KEY]) < 32) $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        return $_SESSION[self::KEY];
    }
    public static function field()
    {
        return '<input type="hidden" name="ch247cf_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
    public static function verifyRequest()
    {
        $submitted = isset($_POST['ch247cf_csrf']) ? (string) $_POST['ch247cf_csrf'] : '';
        if ($submitted === '' || !hash_equals(self::token(), $submitted)) throw new AuthorizationException('Your security token expired. Reload the page and try again.');
    }
}
