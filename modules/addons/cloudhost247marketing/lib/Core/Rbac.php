<?php
/**
 * Marketing permission groups.
 *
 * Super admins (role 1) hold everything. Every other admin role must be
 * granted groups explicitly on the settings page. Sending to the whole
 * customer base is the highest-consequence action in this module, so it has
 * its own group separate from campaign authoring.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Core;

class Rbac
{
    public const ROLE_SUPER = 1;

    public const MKT_READ      = 'mkt.read';      // dashboards, campaign + analytics read
    public const MKT_AUDIENCE  = 'mkt.audience';  // subscribers, lists, segments, imports
    public const MKT_COMPOSE   = 'mkt.compose';   // create/edit campaigns and templates
    public const MKT_SEND      = 'mkt.send';      // schedule, send, pause, cancel
    public const MKT_MANAGE    = 'mkt.manage';    // settings, transports, suppressions
    public const MKT_AUDIT     = 'mkt.audit';     // audit chain + delivery logs

    public const ALL_GROUPS = [
        self::MKT_READ,
        self::MKT_AUDIENCE,
        self::MKT_COMPOSE,
        self::MKT_SEND,
        self::MKT_MANAGE,
        self::MKT_AUDIT,
    ];

    public const LABELS = [
        self::MKT_READ     => 'Read campaigns & analytics',
        self::MKT_AUDIENCE => 'Manage subscribers, lists & segments',
        self::MKT_COMPOSE  => 'Create & edit campaigns and templates',
        self::MKT_SEND     => 'Send, schedule, pause & cancel campaigns',
        self::MKT_MANAGE   => 'Module settings, transports & suppressions',
        self::MKT_AUDIT    => 'Audit chain & delivery logs',
    ];

    /** @var array<int,int|null> test seam: admin id -> role id */
    private static $forcedRoles = [];

    public static function adminCan($group)
    {
        $adminId = Identity::adminId();
        if (!$adminId) {
            return false;
        }
        if (!in_array($group, self::ALL_GROUPS, true)) {
            return false; // unknown group -> refuse (fail closed)
        }
        $roleId = self::roleFor($adminId);
        if ($roleId === self::ROLE_SUPER) {
            return true;
        }
        if ($roleId === null) {
            return false;
        }
        try {
            if (!Db::tableExists('role_permissions')) {
                return false;
            }
            return Db::count('role_permissions', ['role_id' => $roleId, 'permission' => $group]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function requireAdmin($group)
    {
        if (!self::adminCan($group)) {
            throw new ForbiddenException('You do not have the "' . (self::LABELS[$group] ?? $group) . '" permission.');
        }
    }

    /** @return int[] groups granted to a role */
    public static function groupsForRole($roleId)
    {
        $roleId = (int) $roleId;
        if ($roleId === self::ROLE_SUPER) {
            return self::ALL_GROUPS;
        }
        if (!Db::tableExists('role_permissions')) {
            return [];
        }
        $out = [];
        foreach (Db::all('role_permissions', ['role_id' => $roleId]) as $row) {
            $out[] = (string) $row['permission'];
        }
        return $out;
    }

    public static function setRoleGroups($roleId, array $groups)
    {
        $roleId = (int) $roleId;
        if ($roleId <= 0) {
            throw new ValidationException('Invalid role.');
        }
        Db::exec('DELETE FROM ' . Db::t('role_permissions') . ' WHERE role_id = ?', [$roleId]);
        foreach (array_unique($groups) as $group) {
            if (!in_array($group, self::ALL_GROUPS, true)) {
                continue;
            }
            Db::insert('role_permissions', ['role_id' => $roleId, 'permission' => $group]);
        }
    }

    public static function roleFor($adminId)
    {
        $adminId = (int) $adminId;
        if (array_key_exists($adminId, self::$forcedRoles)) {
            return self::$forcedRoles[$adminId];
        }
        try {
            if (!Db::whmcsTableExists('tbladmins')) {
                return null;
            }
            $rows = Db::query('SELECT roleid FROM tbladmins WHERE id = ? LIMIT 1', [$adminId]);
            return $rows ? (int) $rows[0]['roleid'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Test seam. */
    public static function setForcedRole($adminId, $roleId)
    {
        if ($roleId === null) {
            unset(self::$forcedRoles[(int) $adminId]);
            return;
        }
        self::$forcedRoles[(int) $adminId] = (int) $roleId;
    }
}
