<?php
/**
 * Append-only audit log (mod_chs_audit_log).
 *
 * IPs are stored as a 64-hex hash (sha256 of pseudonym input salted via
 * Settings::string('ip_hash_salt')), never raw — the log is a compliance
 * trail, not a tracker.
 *
 * @package Chs\Core
 */

namespace Chs\Core;

class Audit
{
    public const ACTOR_CLIENT = 'client';
    public const ACTOR_ADMIN  = 'admin';
    public const ACTOR_SYSTEM = 'system';

    /** Full form: record an action with explicit actor. */
    public static function log($actorType, $actorId, $action, array $context = [])
    {
        Db::insert('audit_log', [
            'actor_type' => $actorType,
            'actor_id'   => (int) $actorId,
            'action'     => (string) $action,
            'context'    => $context === [] ? '' : chs_json($context),
            'ip_hash'    => self::ipHash(),
            'created_at' => Clock::now(),
        ]);
    }

    /** Signed-in client actor. */
    public static function client($clientId, $action, array $context = [])
    {
        self::log(self::ACTOR_CLIENT, (int) $clientId, $action, $context);
    }

    /** Staff actor. */
    public static function admin($adminId, $action, array $context = [])
    {
        self::log(self::ACTOR_ADMIN, (int) $adminId, $action, $context);
    }

    /** Background / system actor. */
    public static function system($action, array $context = [])
    {
        self::log(self::ACTOR_SYSTEM, 0, $action, $context);
    }

    /** Newest-first slice for the admin audit page. */
    public static function recent($limit = 200)
    {
        return Db::all('audit_log', [], 'id DESC', (int) $limit);
    }

    private static function ipHash()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') {
            return '';
        }
        return hash('sha256', Str::pseudonym($ip));
    }
}
