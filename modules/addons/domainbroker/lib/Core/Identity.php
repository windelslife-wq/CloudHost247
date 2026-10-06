<?php
/**
 * Domain Broker — resolve the current principal from the host application.
 *
 * This is the single place where "who is calling?" is answered, and it always
 * answers from server-side state: the WHMCS client session, the WHMCS admin
 * session, or a hashed API token. Nothing the browser sends (a client id in a
 * form field, a role in a cookie, a status in a POST body) is ever trusted.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

use DomainBroker\Integration\Gateway;

class Identity
{
    /** @var Actor|null test / CLI override */
    protected static $override;

    /** @var Actor|null memoised resolution for this request */
    protected static $resolved;

    /** Force an actor (tests, CLI tooling). */
    public static function override(Actor $actor = null)
    {
        self::$override = $actor;
        self::$resolved = null;
    }

    public static function reset()
    {
        self::$override = null;
        self::$resolved = null;
    }

    /**
     * The principal for the current request. Falls back to a guest actor —
     * never to an administrator.
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
        $clientId = self::sessionInt(['uid', 'userid', 'clientid']);
        if ($clientId <= 0) {
            return null;
        }
        $name = '';
        try {
            $client = Gateway::get()->getClient($clientId);
            if ($client) {
                $name = trim(
                    (isset($client['firstname']) ? $client['firstname'] : '') . ' ' .
                    (isset($client['lastname']) ? $client['lastname'] : '')
                );
            }
        } catch (\Throwable $e) {
            Logger::debug('Could not load the client record for the session.', ['client' => $clientId]);
        }

        return Actor::customer($clientId, $name, [
            'ip' => Http::clientIp(),
            'userAgent' => Http::userAgent(),
            'authMethod' => 'session',
        ]);
    }

    /**
     * The signed-in staff member, resolved to a *broker* actor when they are
     * on the broker roster and to an admin actor otherwise. Their Domain
     * Broker role comes from the roster / settings, never from WHMCS' own
     * admin role, so granting someone WHMCS admin does not silently grant
     * them refund or override authority here.
     */
    public static function fromAdminSession()
    {
        $adminId = self::sessionInt(['adminid']);
        if ($adminId <= 0) {
            return null;
        }

        $name = isset($_SESSION['adminname']) ? (string) $_SESSION['adminname'] : ('Admin #' . $adminId);
        $context = ['ip' => Http::clientIp(), 'userAgent' => Http::userAgent(), 'authMethod' => 'session'];

        $broker = Db::isBound()
            ? Db::first('brokers', ['whmcs_admin_id' => $adminId, 'deleted_at' => null])
            : null;

        if ($broker && $broker['status'] === 'active' && !self::isAdminRoleHolder($adminId)) {
            return Actor::broker($broker['id'], $broker['role'], $broker['display_name'], array_merge($context, [
                'adminId' => $adminId,
            ]));
        }

        return Actor::admin($adminId, self::adminRole($adminId), $name, $context);
    }

    /**
     * Map a WHMCS admin id onto a Domain Broker administrative role.
     *
     * The mapping is configuration (Admin → Domain Broker → Settings), so an
     * operator decides which staff hold finance or super rights. Anyone not
     * listed gets the read-only role: access is granted explicitly, never by
     * default.
     */
    public static function adminRole($adminId)
    {
        $adminId = (int) $adminId;
        foreach (['admin_super', 'admin_finance', 'admin_manager', 'admin_viewer'] as $role) {
            $ids = Settings::listOf('role_admins_' . $role);
            foreach ($ids as $id) {
                if ((int) $id === $adminId) {
                    return $role;
                }
            }
        }
        // A deployment that has not configured the mapping yet still needs a
        // way in: the bootstrap admin id, if set, is the service owner.
        if ($adminId > 0 && $adminId === Settings::int('bootstrap_admin_id', 0)) {
            return 'admin_super';
        }
        return Settings::string('default_admin_role', 'admin_viewer');
    }

    protected static function isAdminRoleHolder($adminId)
    {
        foreach (['admin_super', 'admin_finance', 'admin_manager'] as $role) {
            foreach (Settings::listOf('role_admins_' . $role) as $id) {
                if ((int) $id === (int) $adminId) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ------------------------------------------------------------ tokens */

    /**
     * Resolve a bearer token to an actor, or null. The token is matched on its
     * SHA-256 hash; the plaintext is never stored or logged.
     *
     * @return Actor|null
     */
    public static function fromApiToken($plaintext)
    {
        $plaintext = trim((string) $plaintext);
        if ($plaintext === '' || !Db::isBound()) {
            return null;
        }

        $row = Db::first('tokens', [
            'token_hash' => hash('sha256', $plaintext),
            'active' => 1,
            'deleted_at' => null,
        ]);
        if (!$row) {
            return null;
        }
        if (!empty($row['expires_at']) && Clock::isPast($row['expires_at'])) {
            return null;
        }
        if (!self::ipAllowed($row['ip_allowlist'], Http::clientIp())) {
            Logger::warning('API token used from a disallowed address.', [
                'token' => (int) $row['id'], 'ip' => Http::clientIp(),
            ]);
            return null;
        }

        Db::update('tokens', [
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
            case Actor::TYPE_BROKER:
                $broker = Db::first('brokers', ['id' => (int) $row['actor_id'], 'deleted_at' => null]);
                if (!$broker || $broker['status'] !== 'active') {
                    return null;
                }
                return Actor::broker((int) $broker['id'], $broker['role'], $broker['display_name'], array_merge(
                    $context,
                    ['adminId' => (int) $broker['whmcs_admin_id']]
                ));
            case Actor::TYPE_ADMIN:
                return Actor::admin((int) $row['actor_id'], (string) $row['actor_role'], (string) $row['actor_label'], $context);
        }
        return null;
    }

    /** Scopes attached to a token, or null when it inherits the role's surface. */
    public static function tokenScopes(Actor $actor)
    {
        if ($actor->authMethod !== 'api_token' || empty($actor->meta['token_id'])) {
            return null;
        }
        $row = Db::first('tokens', ['id' => (int) $actor->meta['token_id']]);
        if (!$row || empty($row['scopes'])) {
            return null;
        }
        $scopes = Str::jsonDecode($row['scopes'], []);
        return is_array($scopes) && $scopes ? $scopes : null;
    }

    protected static function ipAllowed($allowlist, $ip)
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

    protected static function ipMatches($entry, $ip)
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

    protected static function sessionInt(array $keys)
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
