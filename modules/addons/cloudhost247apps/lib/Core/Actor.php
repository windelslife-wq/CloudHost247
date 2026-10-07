<?php
/**
 * CloudHost247 App Cloud — the authenticated principal.
 *
 * Every service method takes an Actor; there is no "current user" global and no
 * implicit trust. The Actor is built once at the edge (client session, admin
 * session, API token, signed agent request, cron) and carried down, so
 * authorisation is always evaluated against a server-resolved identity — never
 * against anything the browser sent.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Actor
{
    const TYPE_CUSTOMER = 'customer';
    const TYPE_ADMIN    = 'admin';
    const TYPE_AGENT    = 'agent';   // a registered server agent (signed requests)
    const TYPE_SYSTEM   = 'system';  // worker, cron, scheduler
    const TYPE_GUEST    = 'guest';

    /** Roles required by the platform specification (§4). */
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ADMIN       = 'admin';
    const ROLE_STAFF       = 'staff';
    const ROLE_CUSTOMER    = 'customer';
    const ROLE_AGENT       = 'agent';
    const ROLE_SYSTEM      = 'system';
    const ROLE_GUEST       = 'guest';

    /** @var string */
    public $type = self::TYPE_GUEST;

    /** @var int WHMCS client id */
    public $clientId = 0;

    /** @var int WHMCS admin id */
    public $adminId = 0;

    /** @var int mod_ch247apps_agents.id */
    public $agentId = 0;

    /** @var int mod_ch247apps_servers.id when the agent acts for one server */
    public $serverId = 0;

    /** @var string */
    public $role = self::ROLE_GUEST;

    /** @var string */
    public $name = 'Guest';

    /** @var string|null */
    public $ip;

    /** @var string|null */
    public $userAgent;

    /** @var string session|api_token|agent_signature|cron|internal */
    public $authMethod = 'session';

    /** @var array non-authoritative context for audit records */
    public $meta = [];

    public static function customer($clientId, $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_CUSTOMER;
        $a->clientId = (int) $clientId;
        $a->role = self::ROLE_CUSTOMER;
        $a->name = $name !== '' ? $name : ('Client #' . (int) $clientId);
        return $a->withExtra($extra);
    }

    public static function admin($adminId, $role = self::ROLE_STAFF, $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_ADMIN;
        $a->adminId = (int) $adminId;
        $a->role = $role ?: self::ROLE_STAFF;
        $a->name = $name !== '' ? $name : ('Admin #' . (int) $adminId);
        return $a->withExtra($extra);
    }

    /** A server agent authenticated by a signed request. */
    public static function agent($agentId, $serverId = 0, $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_AGENT;
        $a->agentId = (int) $agentId;
        $a->serverId = (int) $serverId;
        $a->role = self::ROLE_AGENT;
        $a->name = $name !== '' ? $name : ('Agent #' . (int) $agentId);
        $a->authMethod = 'agent_signature';
        return $a->withExtra($extra);
    }

    /** Worker / cron / scheduler. May drive jobs, never human sign-offs. */
    public static function system($name = 'System')
    {
        $a = new self();
        $a->type = self::TYPE_SYSTEM;
        $a->role = self::ROLE_SYSTEM;
        $a->name = $name;
        $a->authMethod = 'internal';
        return $a;
    }

    public static function guest(array $extra = [])
    {
        $a = new self();
        return $a->withExtra($extra);
    }

    protected function withExtra(array $extra)
    {
        foreach (['ip', 'userAgent', 'authMethod', 'adminId', 'clientId', 'agentId', 'serverId'] as $k) {
            if (isset($extra[$k])) {
                $this->{$k} = $extra[$k];
            }
        }
        if (isset($extra['meta']) && is_array($extra['meta'])) {
            $this->meta = $extra['meta'];
        }
        return $this;
    }

    public function isCustomer()
    {
        return $this->type === self::TYPE_CUSTOMER;
    }

    public function isAdmin()
    {
        return $this->type === self::TYPE_ADMIN;
    }

    public function isStaff()
    {
        return $this->isAdmin() && in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN, self::ROLE_STAFF], true);
    }

    public function isSuperAdmin()
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isAgent()
    {
        return $this->type === self::TYPE_AGENT;
    }

    public function isSystem()
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    public function isGuest()
    {
        return $this->type === self::TYPE_GUEST;
    }

    public function isAuthenticated()
    {
        return $this->type !== self::TYPE_GUEST;
    }

    /** Machine-driven principal (worker, cron, agent). */
    public function isMachine()
    {
        return $this->isSystem() || $this->isAgent();
    }

    public function identity()
    {
        switch ($this->type) {
            case self::TYPE_CUSTOMER:
                return 'client:' . $this->clientId;
            case self::TYPE_ADMIN:
                return 'admin:' . $this->adminId;
            case self::TYPE_AGENT:
                return 'agent:' . $this->agentId;
            case self::TYPE_SYSTEM:
                return 'system';
            default:
                return 'guest';
        }
    }

    public function actorId()
    {
        switch ($this->type) {
            case self::TYPE_CUSTOMER:
                return $this->clientId;
            case self::TYPE_ADMIN:
                return $this->adminId;
            case self::TYPE_AGENT:
                return $this->agentId;
            default:
                return 0;
        }
    }

    public function can($permission)
    {
        return Rbac::allows($this, $permission);
    }

    /** @throws AuthorizationException */
    public function authorize($permission)
    {
        Rbac::assert($this, $permission);
        return $this;
    }

    /**
     * Ownership check: a customer may only act on their own resources.
     *
     * @throws AuthorizationException
     */
    public function assertOwns($ownerClientId)
    {
        if ($this->isAdmin() || $this->isSystem()) {
            return true;
        }
        if (!$this->isCustomer() || (int) $ownerClientId !== (int) $this->clientId) {
            throw new AuthorizationException('That resource belongs to another account.');
        }
        return true;
    }

    public function toArray()
    {
        return [
            'type' => $this->type,
            'id' => $this->actorId(),
            'role' => $this->role,
            'name' => $this->name,
            'identity' => $this->identity(),
            'auth' => $this->authMethod,
        ];
    }
}
