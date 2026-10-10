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

    /** Test seam: refuses to run outside the offline test harness (C-4). */
    public static function setClient($clientId)
    {
        self::assertTestContext('setClient');
        self::$forcedClient = $clientId === null ? null : (int) $clientId;
    }

    /** Test seam: refuses to run outside the offline test harness (C-4). */
    public static function setAdmin($adminId)
    {
        self::assertTestContext('setAdmin');
        self::$forcedAdmin = $adminId === null ? null : (int) $adminId;
    }

    /** Seams may only be used by the offline test harness, never in a live request. */
    private static function assertTestContext($name)
    {
        if (!defined('CHS_TESTING') || CHS_TESTING !== true) {
            throw new \LogicException('Identity::' . $name . '() is a test seam and is disabled outside the test harness.');
        }
    }
}
