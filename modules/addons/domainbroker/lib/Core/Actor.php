<?php
/**
 * Domain Broker — the authenticated principal performing an operation.
 *
 * Every service method takes an Actor. There is no "current user" global and
 * no implicit trust: the Actor is built once at the edge (client area session,
 * admin session, API token, cron) and carried down, so authorisation is always
 * evaluated against a server-resolved identity rather than anything the client
 * sent.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Core;

class Actor
{
    const TYPE_CUSTOMER = 'customer';
    const TYPE_BROKER   = 'broker';
    const TYPE_ADMIN    = 'admin';
    const TYPE_SYSTEM   = 'system';
    const TYPE_GUEST    = 'guest';

    /** @var string */
    public $type = self::TYPE_GUEST;

    /** @var int WHMCS client id (customers) */
    public $clientId = 0;

    /** @var int WHMCS admin id (admins / staff brokers) */
    public $adminId = 0;

    /** @var int domain_broker_brokers.id */
    public $brokerId = 0;

    /** @var string RBAC role name */
    public $role = 'guest';

    /** @var string Display name for audit records */
    public $name = 'Guest';

    /** @var string|null */
    public $ip;

    /** @var string|null */
    public $userAgent;

    /** @var string How the actor authenticated: session|api_token|cron|internal */
    public $authMethod = 'session';

    /** @var array Extra, non-authoritative context for the audit log. */
    public $meta = [];

    public static function customer($clientId, $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_CUSTOMER;
        $a->clientId = (int) $clientId;
        $a->role = 'customer';
        $a->name = $name !== '' ? $name : ('Client #' . (int) $clientId);
        return $a->withExtra($extra);
    }

    public static function broker($brokerId, $role = 'broker', $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_BROKER;
        $a->brokerId = (int) $brokerId;
        $a->role = $role ?: 'broker';
        $a->name = $name !== '' ? $name : ('Broker #' . (int) $brokerId);
        return $a->withExtra($extra);
    }

    public static function admin($adminId, $role = 'admin_manager', $name = '', array $extra = [])
    {
        $a = new self();
        $a->type = self::TYPE_ADMIN;
        $a->adminId = (int) $adminId;
        $a->role = $role ?: 'admin_manager';
        $a->name = $name !== '' ? $name : ('Admin #' . (int) $adminId);
        return $a->withExtra($extra);
    }

    /** Cron / queue / internal workflow steps. Has no interactive permissions. */
    public static function system($name = 'System')
    {
        $a = new self();
        $a->type = self::TYPE_SYSTEM;
        $a->role = 'system';
        $a->name = $name;
        $a->authMethod = 'internal';
        return $a;
    }

    public static function guest()
    {
        return new self();
    }

    protected function withExtra(array $extra)
    {
        foreach (['ip', 'userAgent', 'authMethod', 'adminId', 'clientId', 'brokerId'] as $k) {
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

    public function isBroker()
    {
        return $this->type === self::TYPE_BROKER;
    }

    public function isAdmin()
    {
        return $this->type === self::TYPE_ADMIN;
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

    /** Stable identifier used in audit records. */
    public function identity()
    {
        switch ($this->type) {
            case self::TYPE_CUSTOMER:
                return 'client:' . $this->clientId;
            case self::TYPE_BROKER:
                return 'broker:' . $this->brokerId;
            case self::TYPE_ADMIN:
                return 'admin:' . $this->adminId;
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
            case self::TYPE_BROKER:
                return $this->brokerId;
            case self::TYPE_ADMIN:
                return $this->adminId;
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

    public function toArray()
    {
        return [
            'type'      => $this->type,
            'id'        => $this->actorId(),
            'role'      => $this->role,
            'name'      => $this->name,
            'identity'  => $this->identity(),
            'auth'      => $this->authMethod,
        ];
    }
}
