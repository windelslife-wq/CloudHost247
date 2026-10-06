<?php
/**
 * AI permission system.
 *
 * Two independent dimensions must BOTH pass for any tool call:
 *   1. Actor authority  — what the human on whose behalf the agent runs may do.
 *   2. Agent capability — what this agent may ever do (enforced by the runtime).
 *
 * Admin authority: super admins (role 1) hold everything; every other admin
 * role must be granted permission groups in the module settings. Client
 * authority is always the session's client id — client-bound tools filter in
 * SQL, never in prompts.
 */

namespace Ch247Ai\Core;

class Rbac
{
    public const ROLE_SUPER = 1;

    // Permission groups (the admin authority dimension).
    public const AI_READ = 'ai.read';                 // all read tools + copilot
    public const AI_CLIENT_READ = 'ai.client.read';   // customer PII rows
    public const AI_DIAGNOSTICS = 'ai.diagnostics';   // run network diagnostics
    public const AI_APPROVE = 'ai.approve';           // decide approvals
    public const AI_MANAGE = 'ai.manage';             // agents/tools/workflows/settings
    public const AI_AUDIT = 'ai.audit';               // audit log + observability
    public const ALL_GROUPS = [self::AI_READ, self::AI_CLIENT_READ, self::AI_DIAGNOSTICS, self::AI_APPROVE, self::AI_MANAGE, self::AI_AUDIT];

    /** @var array<string,string> tool permission -> admin group required */
    public const TOOL_GROUPS = [
        'read.clients' => self::AI_CLIENT_READ,
        'read.client_details' => self::AI_CLIENT_READ,
        'read.billing' => self::AI_CLIENT_READ,
        'read.orders' => self::AI_READ,
        'read.services' => self::AI_READ,
        'read.support' => self::AI_READ,
        'read.domains' => self::AI_READ,
        'read.metrics' => self::AI_READ,
        'read.knowledge' => self::AI_READ,
        'read.diagnostics' => self::AI_DIAGNOSTICS,
    ];

    /** @var array<int,int>|null test seam: admin id -> role id */
    private static $forcedRoles = [];

    /** Permission a tool requires, derived from its declared group. */
    public static function groupForTool($toolPermission)
    {
        $toolPermission = (string) $toolPermission;
        if (array_key_exists($toolPermission, self::TOOL_GROUPS)) {
            return self::TOOL_GROUPS[$toolPermission];
        }
        // Write tools always require the approval group; management of the
        // registry requires manage. Nothing else grants execution.
        if (strpos($toolPermission, 'ai.write.') === 0 || strpos($toolPermission, 'ai.manage') === 0) {
            return self::AI_APPROVE;
        }
        return null; // unknown permission -> refuse (fail closed)
    }

    /** May the current admin perform an action in $group? */
    public static function adminCan($group)
    {
        $adminId = Identity::adminId();
        if (!$adminId) {
            return false;
        }
        if (Identity::isMasquerading()) {
            return false; // an admin masquerading as a client gets no AI authority
        }
        $role = self::adminRole($adminId);
        if ($role === null) {
            return false;
        }
        if ((int) $role === self::ROLE_SUPER) {
            return true;
        }
        if (!Db::tableExists('role_permissions')) {
            return false; // fail closed when the grant table is missing
        }
        return Db::count('role_permissions', ['role_id' => (int) $role, 'permission' => (string) $group]) > 0;
    }

    public static function adminRole($adminId)
    {
        if (isset(self::$forcedRoles[(int) $adminId])) {
            return self::$forcedRoles[(int) $adminId];
        }
        $rows = Db::query('SELECT roleid FROM tbladmins WHERE id = ?', [(int) $adminId]);
        return $rows && isset($rows[0]['roleid']) ? (int) $rows[0]['roleid'] : null;
    }

    /** Grant/revoke a group for a role (settings UI). */
    public static function grant($roleId, $group)
    {
        if (!in_array($group, self::ALL_GROUPS, true)) {
            throw new ValidationException('Unknown permission group.');
        }
        if ((int) $roleId === self::ROLE_SUPER) {
            return; // super admin is always everything
        }
        if (!Db::count('role_permissions', ['role_id' => (int) $roleId, 'permission' => $group])) {
            Db::insert('role_permissions', ['role_id' => (int) $roleId, 'permission' => $group]);
        }
    }
    public static function revoke($roleId, $group)
    {
        Db::delete('role_permissions', ['role_id' => (int) $roleId, 'permission' => (string) $group]);
    }
    public static function grantsFor($roleId)
    {
        $out = [];
        foreach (Db::all('role_permissions', ['role_id' => (int) $roleId]) as $row) {
            $out[$row['permission']] = true;
        }
        return $out;
    }
    public static function setForcedRole($adminId, $roleId)
    {
        if ($roleId === null) {
            unset(self::$forcedRoles[(int) $adminId]);
        } else {
            self::$forcedRoles[(int) $adminId] = (int) $roleId;
        }
    }
}
