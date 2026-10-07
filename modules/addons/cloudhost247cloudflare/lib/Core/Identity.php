<?php
namespace CloudHost247\Cloudflare\Core;
class Identity
{
    public static function clientId() { $id = (int) ($_SESSION['uid'] ?? 0); return $id > 0 ? $id : null; }
    public static function adminId() { $id = (int) ($_SESSION['adminid'] ?? 0); return $id > 0 ? $id : null; }
    public static function isMasquerading() { return self::clientId() !== null && self::adminId() !== null; }
    public static function requireClient()
    {
        $id = self::clientId();
        if (!$id) throw new AuthorizationException('Please sign in to manage Cloudflare services.');
        return $id;
    }
    public static function requireAdmin()
    {
        $id = self::adminId();
        if (!$id) throw new AuthorizationException('An administrator session is required.');
        if (function_exists('checkPermission') && !checkPermission('Configure Addon Modules')) throw new AuthorizationException('Your administrator role cannot manage Cloudflare integration settings.');
        return $id;
    }
}
