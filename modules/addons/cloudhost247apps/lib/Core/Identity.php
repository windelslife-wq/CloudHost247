<?php
/**
 * CloudHost247 App Cloud — resolve the current principal.
 *
 * The single place that answers "who is calling?", always from server-side
 * state: the WHMCS client session, the WHMCS admin session, an API token hash or
 * a verified agent signature. Nothing the browser sends (a client id in a form
 * field, a role in a cookie, a status in a POST body) is ever trusted.
 *
 * Test seams: Identity::override(Actor), Identity::setClient(id),
 * Identity::setAdmin(id, role).
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

use Ch247Apps\Integration\Gateway;

class Identity
{
    /** @var Actor|null */
    private static $override;

    /** @var Actor|null memoised for this request */
    private static $resolved;

    /** @var int|null */
    private static $forcedClient;

    /** @var array|null [adminId, role] */
    private static $forcedAdmin;

    public static function override(Actor $actor = null)
    {
        self::$override = $actor;
        self::$resolved = null;
    }

    public static function setClient($clientId)
    {
        self::$forcedClient = $clientId === null ? null : (int) $clientId;
        self::$resolved = null;
    }

    public static function setAdmin($adminId, $role = null)
    {
        self::$forcedAdmin = $adminId === null ? null : [(int) $adminId, $role];
        self::$resolved = null;
    }

    public static function reset()
    {
        self::$override = null;
        self::$resolved = null;
        self::$forcedClient = null;
        self::$forcedAdmin = null;
    }

    /**
     * The principal for this request. Falls back to a guest actor — never to an
     * administrator.
     */
    public static function current()
    {
        if (self::$override !== null) {
            return self::$override;
        }
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $actor = self::fromAdminSession();
        if ($actor === null) {
            $actor = self::fromClientSession();
        }
        if ($actor === null) {
            $actor = Actor::guest(['ip' => Http::clientIp(), 'userAgent' => Http::userAgent()]);
        }
        self::$resolved = $actor;
        return $actor;
    }

    /** The signed-in client, or null. */
    public static function fromClientSession()
    {
        $clientId = self::$forcedClient !== null ? self::$forcedClient : self::sessionInt(['uid', 'userid', 'clientid']);
        if ($clientId <= 0) {
            return null;
        }
        $name = '';
        $status = '';
        try {
            $client = Gateway::get()->getClient($clientId);
            if ($client) {
                $name = trim(
                    (isset($client['firstname']) ? $client['firstname'] : '') . ' '
                    . (isset($client['lastname']) ? $client['lastname'] : '')
                );
                $status = isset($client['status']) ? (string) $client['status'] : '';
            }
        } catch (\Throwable $e) {
            Logger::debug('Could not load the client record for this session.', ['client' => $clientId]);
        }

        return Actor::customer($clientId, $name, [
            'ip' => Http::clientIp(),
            'userAgent' => Http::userAgent(),
            'authMethod' => 'session',
            'meta' => ['client_status' => $status],
        ]);
    }

    /**
     * The signed-in staff member. Their App Cloud role comes from the module's
     * own role mapping, never from WHMCS' admin role, so granting someone WHMCS
     * admin does not silently grant them server credentials or publish rights.
     */
    public static function fromAdminSession()
    {
        $adminId = self::$forcedAdmin !== null
            ? self::$forcedAdmin[0]
            : self::sessionInt(['adminid']);
        if ($adminId <= 0) {
            return null;
        }

        $role = self::$forcedAdmin !== null && self::$forcedAdmin[1] !== null
            ? self::$forcedAdmin[1]
            : self::adminRole($adminId);
        $name = isset($_SESSION['adminname']) ? (string) $_SESSION['adminname'] : ('Admin #' . $adminId);

        return Actor::admin($adminId, $role, $name, [
            'ip' => Http::clientIp(),
            'userAgent' => Http::userAgent(),
            'authMethod' => 'session',
        ]);
    }

    /**
     * Map a WHMCS admin id onto an App Cloud role. The mapping is configuration
     * (admin console → Settings → Access), so an operator decides who holds
     * admin or super-admin rights. Anyone not listed gets the read-only staff
     * role: access is granted explicitly, never by default.
     */
    public static function adminRole($adminId)
    {
        $adminId = (int) $adminId;
        foreach ([Actor::ROLE_SUPER_ADMIN, Actor::ROLE_ADMIN, Actor::ROLE_STAFF] as $role) {
            foreach (Settings::listOf('role_admins_' . $role) as $id) {
                if ((int) $id === $adminId) {
                    return $role;
                }
            }
        }
        // A fresh deployment still needs a way in: the bootstrap admin id.
        if ($adminId > 0 && $adminId === Settings::int('bootstrap_admin_id', 0)) {
            return Actor::ROLE_SUPER_ADMIN;
        }
        return Settings::string('default_admin_role', Actor::ROLE_STAFF);
    }

    /* ------------------------------------------------------------ tokens -- */

    /**
     * Resolve a bearer token to an actor, or null. Tokens are matched on their
     * SHA-256 hash; the plaintext is never stored or logged.
     */
    public static function fromApiToken($plaintext)
    {
        $plaintext = trim((string) $plaintext);
        if ($plaintext === '' || !Db::isBound() || !Db::tableExists('api_tokens')) {
            return null;
        }

        $row = Db::first('api_tokens', [
            'token_hash' => hash('sha256', $plaintext), 'active' => 1,
            'revoked_at' => null, 'deleted_at' => null,
        ]);
        if (!$row) {
            return null;
        }
        if (!empty($row['expires_at']) && Clock::isPast($row['expires_at'])) {
            Logger::warning('Expired API token presented.', ['token' => (int) $row['id']]);
            return null;
        }
        if (!self::ipAllowed($row['ip_allowlist'], Http::clientIp())) {
            Logger::warning('API token used from a disallowed address.', [
                'token' => (int) $row['id'], 'ip' => Http::clientIp(),
            ]);
            return null;
        }

        Db::update('api_tokens', [
            'last_used_at' => Clock::now(),
            'last_used_ip' => Str::clip((string) Http::clientIp(), 45),
            'request_count' => (int) $row['request_count'] + 1,
            'updated_at' => Clock::now(),
        ], ['id' => (int) $row['id']]);

        $context = [
            'ip' => Http::clientIp(),
            'userAgent' => Http::userAgent(),
            'authMethod' => 'api_token',
            'meta' => ['token_id' => (int) $row['id'], 'token' => $row['name']],
        ];

        switch ($row['actor_type']) {
            case Actor::TYPE_CUSTOMER:
                return Actor::customer((int) $row['actor_id'], (string) $row['actor_label'], $context);
            case Actor::TYPE_ADMIN:
                $role = $row['actor_role'] !== '' ? (string) $row['actor_role'] : self::adminRole((int) $row['actor_id']);
                return Actor::admin((int) $row['actor_id'], $role, (string) $row['actor_label'], $context);
        }
        return null;
    }

    /** Scopes attached to a token, or null when it inherits the role's surface. */
    public static function tokenScopes(Actor $actor)
    {
        if ($actor->authMethod !== 'api_token' || empty($actor->meta['token_id'])) {
            return null;
        }
        $row = Db::first('api_tokens', [
            'id' => (int) $actor->meta['token_id'], 'active' => 1,
            'revoked_at' => null, 'deleted_at' => null,
        ]);
        if (!$row || (!empty($row['expires_at']) && Clock::isPast($row['expires_at']))) {
            throw new AuthenticationException('The bearer token is invalid or expired.');
        }
        if (empty($row['scopes'])) {
            return null;
        }
        $scopes = Str::jsonDecode($row['scopes'], []);
        return is_array($scopes) && $scopes ? $scopes : null;
    }

    private static function ipAllowed($allowlist, $ip)
    {
        $allowlist = trim((string) $allowlist);
        if ($allowlist === '') {
            return true;
        }
        foreach (array_filter(array_map('trim', explode(',', $allowlist))) as $entry) {
            if (self::ipMatches($entry, (string) $ip)) {
                return true;
            }
        }
        return false;
    }

    private static function ipMatches($entry, $ip)
    {
        if ($entry === $ip) {
            return true;
        }
        if (strpos($entry, '/') === false) {
            return false;
        }
        list($subnet, $bits) = explode('/', $entry, 2);
        $bits = (int) $bits;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function sessionInt(array $keys)
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return 0;
        }
        foreach ($keys as $key) {
            if (!empty($_SESSION[$key]) && (int) $_SESSION[$key] > 0) {
                return (int) $_SESSION[$key];
            }
        }
        return 0;
    }
}
