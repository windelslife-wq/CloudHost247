<?php
/** Session identity accessors (same semantics as Chs\Core\Identity). */

namespace Ch247Ai\Core;

class Identity
{
    /** @var int|null */
    private static $forcedClient;
    /** @var int|null */
    private static $forcedAdmin;

    public static function clientId()
    {
        if (self::$forcedClient !== null) {
            return self::$forcedClient > 0 ? self::$forcedClient : null;
        }
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        return $uid > 0 ? $uid : null;
    }
    public static function adminId()
    {
        if (self::$forcedAdmin !== null) {
            return self::$forcedAdmin > 0 ? self::$forcedAdmin : null;
        }
        $aid = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
        return $aid > 0 ? $aid : null;
    }
    /** An admin logged in AS a client: block AI authority in that state. */
    public static function isMasquerading()
    {
        return self::clientId() !== null && self::adminId() !== null;
    }
    public static function setClient($clientId)
    {
        self::$forcedClient = $clientId === null ? null : (int) $clientId;
    }
    public static function setAdmin($adminId)
    {
        self::$forcedAdmin = $adminId === null ? null : (int) $adminId;
    }
}
