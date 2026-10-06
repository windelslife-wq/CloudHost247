<?php
/**
 * Session identity accessors.
 *
 * In WHMCS: $_SESSION['uid'] is the client id; an admin masquerading as a
 * client leaves $_SESSION['adminid'] set as well, which we treat as an
 * identity signal and block on sensitive writes (bids, purchases).
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Identity
{
    /** @var int|null test override */
    private static $forcedClient;
    /** @var int|null test override */
    private static $forcedAdmin;

    /** Signed-in client id, or null. */
    public static function clientId()
    {
        if (self::$forcedClient !== null) {
            return self::$forcedClient > 0 ? self::$forcedClient : null;
        }
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        return $uid > 0 ? $uid : null;
    }

    /** Signed-in admin id, or null. */
    public static function adminId()
    {
        if (self::$forcedAdmin !== null) {
            return self::$forcedAdmin > 0 ? self::$forcedAdmin : null;
        }
        $aid = isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : 0;
        return $aid > 0 ? $aid : null;
    }

    /** True when an admin is masquerading as a client (sensitive writes blocked). */
    public static function isMasquerading()
    {
        return self::clientId() !== null && self::adminId() !== null;
    }

    /** Test seam. */
    public static function setClient($clientId)
    {
        self::$forcedClient = $clientId === null ? null : (int) $clientId;
    }

    /** Test seam. */
    public static function setAdmin($adminId)
    {
        self::$forcedAdmin = $adminId === null ? null : (int) $adminId;
    }
}
