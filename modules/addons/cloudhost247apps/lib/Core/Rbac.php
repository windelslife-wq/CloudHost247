<?php
/**
 * CloudHost247 App Cloud — role based access control.
 *
 * Permissions are granted to roles; roles are granted to principals. The shipped
 * matrix below is the fallback and is seeded into
 * mod_ch247apps_role_permissions at install time so an operator can tighten or
 * loosen it per deployment without touching code. No role receives a wildcard
 * except `super_admin`, and even that cannot bypass the platform invariants
 * (verified payment before provisioning, verified health before ONLINE) — those
 * are invariants, not permissions.
 *
 * Authorisation is always evaluated server-side: the API router asserts the
 * route's permission, and every service method asserts again, so a controller
 * that forgets cannot widen access.
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Rbac
{
    /* ------------------------------------------------------- permissions -- */

    // Catalog (customer + public)
    const APP_VIEW                = 'app.view';
    const APP_INSTALL             = 'app.install';
    const PANEL_CATALOG_VIEW      = 'panel_catalog.view';
    const PANEL_CATALOG_MANAGE    = 'panel_catalog.manage';
    const PANEL_ACCOUNT_VIEW      = 'panel_account.view';
    const PANEL_ACCOUNT_MANAGE    = 'panel_account.manage';
    const PANEL_ACCOUNT_TERMINATE = 'panel_account.terminate';
    const PANEL_ACCOUNT_VERIFY   = 'panel_account.verify';

    // Installation management (own resources)
    const INSTALL_VIEW_OWN        = 'installation.view.own';
    const INSTALL_START           = 'installation.start';
    const INSTALL_STOP            = 'installation.stop';
    const INSTALL_RESTART         = 'installation.restart';
    const INSTALL_UPDATE          = 'installation.update';
    const INSTALL_DELETE          = 'installation.delete';
    const INSTALL_BACKUP          = 'installation.backup';
    const INSTALL_RESTORE         = 'installation.restore';
    const INSTALL_ENV_WRITE       = 'installation.env.write';
    const INSTALL_ENV_READ        = 'installation.env.read';
    const INSTALL_LOGS_VIEW       = 'installation.logs.view';
    const INSTALL_METRICS_VIEW    = 'installation.metrics.view';
    const INSTALL_DOMAIN_MANAGE   = 'installation.domain.manage';
    const INSTALL_SSL_MANAGE      = 'installation.ssl.manage';
    const INSTALL_SETTINGS_WRITE  = 'installation.settings.write';

    // Domains and billing (own resources)
    const DOMAIN_VIEW_OWN         = 'domain.view.own';
    const DOMAIN_MANAGE_OWN       = 'domain.manage.own';
    const BILLING_VIEW_OWN        = 'billing.view.own';
    const BILLING_CHECKOUT        = 'billing.checkout';

    // Admin: catalog governance
    const APP_VIEW_ALL            = 'app.view.all';
    const APP_MANAGE              = 'app.manage';
    const APP_VERSION_MANAGE      = 'app.version.manage';
    const MANIFEST_UPLOAD         = 'manifest.upload';
    const MANIFEST_VALIDATE       = 'manifest.validate';
    const APP_TEST_DEPLOY         = 'app.test.deploy';
    const APP_PUBLISH             = 'app.publish';
    const APP_APPROVE             = 'app.approve';
    const APP_SUSPEND             = 'app.suspend';

    // Admin: deployments
    const DEPLOYMENT_VIEW_ALL     = 'deployment.view.all';
    const DEPLOYMENT_CANCEL       = 'deployment.cancel';
    const DEPLOYMENT_RETRY        = 'deployment.retry';
    const DEPLOYMENT_ROLLBACK     = 'deployment.rollback';

    // Admin: infrastructure
    const SERVER_VIEW             = 'server.view';
    const SERVER_MANAGE           = 'server.manage';
    const SERVER_CREDENTIAL_WRITE = 'server.credential.write';
    const SERVER_CREDENTIAL_ROTATE= 'server.credential.rotate';
    const AGENT_VIEW              = 'agent.view';
    const AGENT_MANAGE            = 'agent.manage';

    // Customer-owned infrastructure VMs and provider accounts (separate from
    // App Cloud deployment targets and their agents).
    const CUSTOMER_SERVER_VIEW_OWN = 'customer_server.view.own';
    const CUSTOMER_SERVER_ORDER    = 'customer_server.order';
    const CUSTOMER_SERVER_VIEW_ALL = 'customer_server.view.all';
    const CUSTOMER_SERVER_MANAGE   = 'customer_server.manage';
    const PROVIDER_ACCOUNT_VIEW     = 'provider_account.view';
    const PROVIDER_ACCOUNT_MANAGE   = 'provider_account.manage';
    const PROVIDER_CREDENTIAL_WRITE = 'provider_account.credential.write';
    const PROVIDER_CREDENTIAL_ROTATE= 'provider_account.credential.rotate';
    const PROVIDER_ACCOUNT_VERIFY  = 'provider_account.verify';

    // Admin: customers, billing, domains, backups, monitoring, audit, settings
    const CUSTOMER_VIEW           = 'customer.view';
    const CUSTOMER_SUSPEND        = 'customer.suspend';
    const CUSTOMER_MANAGE         = 'customer.manage';
    const BILLING_VIEW_ALL        = 'billing.view.all';
    const PLAN_MANAGE             = 'plan.manage';
    const DOMAIN_VIEW_ALL         = 'domain.view.all';
    const DOMAIN_MANAGE_ALL       = 'domain.manage.all';
    const BACKUP_VIEW_ALL         = 'backup.view.all';
    const BACKUP_MANAGE           = 'backup.manage';
    const MONITORING_VIEW         = 'monitoring.view';
    const AUDIT_VIEW              = 'audit.view';
    const SETTINGS_MANAGE         = 'settings.manage';
    const RBAC_MANAGE             = 'rbac.manage';
    const PII_VIEW                = 'pii.view';

    /** Shipped role → permission matrix. */
    const MATRIX = [
        Actor::ROLE_CUSTOMER => [
            self::APP_VIEW, self::APP_INSTALL, self::PANEL_CATALOG_VIEW,
            self::INSTALL_VIEW_OWN, self::INSTALL_START, self::INSTALL_STOP, self::INSTALL_RESTART,
            self::INSTALL_UPDATE, self::INSTALL_DELETE, self::INSTALL_BACKUP, self::INSTALL_RESTORE,
            self::INSTALL_ENV_WRITE, self::INSTALL_ENV_READ, self::INSTALL_LOGS_VIEW,
            self::INSTALL_METRICS_VIEW, self::INSTALL_DOMAIN_MANAGE, self::INSTALL_SSL_MANAGE,
            self::INSTALL_SETTINGS_WRITE,
            self::DOMAIN_VIEW_OWN, self::DOMAIN_MANAGE_OWN,
            self::BILLING_VIEW_OWN, self::BILLING_CHECKOUT,
            self::CUSTOMER_SERVER_VIEW_OWN, self::CUSTOMER_SERVER_ORDER,
        ],
        // Read-only operations staff: can look at everything, change nothing.
        Actor::ROLE_STAFF => [
            self::APP_VIEW, self::PANEL_CATALOG_VIEW, self::PANEL_ACCOUNT_VIEW,
            self::APP_VIEW_ALL, self::DEPLOYMENT_VIEW_ALL, self::SERVER_VIEW,
            self::CUSTOMER_SERVER_VIEW_ALL, self::PROVIDER_ACCOUNT_VIEW,
            self::AGENT_VIEW, self::CUSTOMER_VIEW, self::BILLING_VIEW_ALL, self::DOMAIN_VIEW_ALL,
            self::BACKUP_VIEW_ALL, self::MONITORING_VIEW, self::AUDIT_VIEW, self::INSTALL_LOGS_VIEW,
            self::INSTALL_METRICS_VIEW, self::MANIFEST_VALIDATE,
        ],
        // Day-to-day administrators: catalog, deployments, servers, customers.
        Actor::ROLE_ADMIN => [
            self::APP_VIEW, self::PANEL_CATALOG_VIEW, self::PANEL_CATALOG_MANAGE,
            self::PANEL_ACCOUNT_VIEW, self::PANEL_ACCOUNT_MANAGE, self::PANEL_ACCOUNT_VERIFY,
            self::APP_VIEW_ALL, self::APP_MANAGE, self::APP_VERSION_MANAGE,
            self::MANIFEST_UPLOAD, self::MANIFEST_VALIDATE, self::APP_TEST_DEPLOY, self::APP_APPROVE,
            self::APP_PUBLISH, self::APP_SUSPEND,
            self::DEPLOYMENT_VIEW_ALL, self::DEPLOYMENT_CANCEL, self::DEPLOYMENT_RETRY,
            self::DEPLOYMENT_ROLLBACK,
            self::SERVER_VIEW, self::SERVER_MANAGE, self::SERVER_CREDENTIAL_WRITE,
            self::SERVER_CREDENTIAL_ROTATE, self::AGENT_VIEW, self::AGENT_MANAGE,
            self::CUSTOMER_SERVER_VIEW_ALL, self::CUSTOMER_SERVER_MANAGE,
            self::PROVIDER_ACCOUNT_VIEW, self::PROVIDER_ACCOUNT_MANAGE,
            self::CUSTOMER_VIEW, self::CUSTOMER_SUSPEND, self::BILLING_VIEW_ALL, self::PLAN_MANAGE,
            self::DOMAIN_VIEW_ALL, self::DOMAIN_MANAGE_ALL, self::BACKUP_VIEW_ALL, self::BACKUP_MANAGE,
            self::MONITORING_VIEW, self::AUDIT_VIEW,
            self::INSTALL_VIEW_OWN, self::INSTALL_LOGS_VIEW, self::INSTALL_METRICS_VIEW,
            self::INSTALL_START, self::INSTALL_STOP, self::INSTALL_RESTART, self::INSTALL_BACKUP,
        ],
        // Service owner: everything, including RBAC and platform settings.
        Actor::ROLE_SUPER_ADMIN => [
            self::APP_VIEW, self::PANEL_CATALOG_VIEW, self::PANEL_CATALOG_MANAGE,
            self::PANEL_ACCOUNT_VIEW, self::PANEL_ACCOUNT_MANAGE,
            self::PANEL_ACCOUNT_TERMINATE, self::PANEL_ACCOUNT_VERIFY,
            self::APP_VIEW_ALL, self::APP_MANAGE, self::APP_VERSION_MANAGE,
            self::MANIFEST_UPLOAD, self::MANIFEST_VALIDATE, self::APP_TEST_DEPLOY, self::APP_APPROVE,
            self::APP_PUBLISH, self::APP_SUSPEND,
            self::DEPLOYMENT_VIEW_ALL, self::DEPLOYMENT_CANCEL, self::DEPLOYMENT_RETRY,
            self::DEPLOYMENT_ROLLBACK,
            self::SERVER_VIEW, self::SERVER_MANAGE, self::SERVER_CREDENTIAL_WRITE,
            self::SERVER_CREDENTIAL_ROTATE, self::AGENT_VIEW, self::AGENT_MANAGE,
            self::CUSTOMER_SERVER_VIEW_ALL, self::CUSTOMER_SERVER_MANAGE,
            self::PROVIDER_ACCOUNT_VIEW, self::PROVIDER_ACCOUNT_MANAGE,
            self::PROVIDER_CREDENTIAL_WRITE, self::PROVIDER_CREDENTIAL_ROTATE, self::PROVIDER_ACCOUNT_VERIFY,
            self::CUSTOMER_VIEW, self::CUSTOMER_SUSPEND, self::CUSTOMER_MANAGE,
            self::BILLING_VIEW_ALL, self::PLAN_MANAGE,
            self::DOMAIN_VIEW_ALL, self::DOMAIN_MANAGE_ALL, self::BACKUP_VIEW_ALL, self::BACKUP_MANAGE,
            self::MONITORING_VIEW, self::AUDIT_VIEW, self::SETTINGS_MANAGE, self::RBAC_MANAGE,
            self::PII_VIEW,
            self::INSTALL_VIEW_OWN, self::INSTALL_LOGS_VIEW, self::INSTALL_METRICS_VIEW,
            self::INSTALL_START, self::INSTALL_STOP, self::INSTALL_RESTART, self::INSTALL_UPDATE,
            self::INSTALL_BACKUP, self::INSTALL_RESTORE, self::INSTALL_ENV_READ, self::INSTALL_ENV_WRITE,
            self::INSTALL_DOMAIN_MANAGE, self::INSTALL_SSL_MANAGE, self::INSTALL_SETTINGS_WRITE,
            self::APP_INSTALL,
        ],
        // Worker / cron: drives jobs and automated workflow, never human sign-off.
        Actor::ROLE_SYSTEM => [],
        // Server agent: reports state, executes dispatched operations only.
        Actor::ROLE_AGENT => [],
        Actor::ROLE_GUEST => [self::APP_VIEW, self::PANEL_CATALOG_VIEW],
    ];

    /** @var array|null role => permission[] loaded from the database */
    private static $cache;

    /** @var array forced role overrides for tests: actorKey => role */
    private static $forced = [];

    public static function flush()
    {
        self::$cache = null;
    }

    /** Force a role (tests only). */
    public static function forceRole($identity, $role)
    {
        self::$forced[(string) $identity] = $role === null ? null : (string) $role;
    }

    public static function resetForced()
    {
        self::$forced = [];
    }

    /** Every permission the platform defines. */
    public static function allPermissions()
    {
        $out = [];
        foreach ((new \ReflectionClass(__CLASS__))->getConstants() as $name => $value) {
            if ($name === 'MATRIX' || !is_string($value) || strpos($name, 'ROLE_') === 0) {
                continue;
            }
            $out[] = $value;
        }
        sort($out);
        return array_values(array_unique($out));
    }

    public static function roles()
    {
        return array_keys(self::MATRIX);
    }

    /** @return string[] permissions granted to a role (database wins) */
    public static function grants($role)
    {
        $role = (string) $role;
        if (self::$cache === null) {
            self::$cache = [];
            try {
                if (Db::isBound() && Db::tableExists('role_permissions')) {
                    foreach (Db::fetch('role_permissions') as $row) {
                        if ((int) $row['granted'] === 1) {
                            self::$cache[$row['role']][] = $row['permission'];
                        } elseif (!isset(self::$cache[$row['role']])) {
                            self::$cache[$row['role']] = [];
                        }
                    }
                }
            } catch (\Throwable $e) {
                self::$cache = [];
            }
        }
        if (array_key_exists($role, self::$cache)) {
            return self::$cache[$role];
        }
        return isset(self::MATRIX[$role]) ? self::MATRIX[$role] : [];
    }

    public static function allows(Actor $actor, $permission)
    {
        if (!$actor->isAuthenticated() && !in_array($permission, [self::APP_VIEW, self::PANEL_CATALOG_VIEW], true)) {
            return false;
        }
        $role = $actor->role;
        if (isset(self::$forced[$actor->identity()]) && self::$forced[$actor->identity()] !== null) {
            $role = self::$forced[$actor->identity()];
        }
        if ($actor->isSystem()) {
            return in_array($permission, self::systemGrants(), true);
        }
        if ($actor->isAgent()) {
            return in_array($permission, self::agentGrants(), true);
        }
        if (in_array($permission, [self::APP_VIEW, self::PANEL_CATALOG_VIEW], true)) {
            // Published catalogs are public read-only marketing content; a
            // signed-out visitor may browse but never install or manage records.
            return true;
        }
        return in_array($permission, self::grants($role), true);
    }

    /** @throws AuthorizationException */
    public static function assert(Actor $actor, $permission)
    {
        if (!self::allows($actor, $permission)) {
            throw new AuthorizationException('You do not have permission to perform this action.', [
                'permission' => $permission,
                'role' => $actor->role,
                'actor' => $actor->identity(),
            ]);
        }
        return true;
    }

    /**
     * What the worker/cron may do automatically. Deliberately excludes anything
     * a human must sign off: publishing, credential rotation, refunds, RBAC.
     */
    public static function systemGrants()
    {
        return [
            self::INSTALL_VIEW_OWN, self::INSTALL_START, self::INSTALL_STOP, self::INSTALL_RESTART,
            self::INSTALL_UPDATE, self::INSTALL_BACKUP, self::INSTALL_RESTORE,
            self::INSTALL_LOGS_VIEW, self::INSTALL_METRICS_VIEW, self::INSTALL_SSL_MANAGE,
            self::INSTALL_DOMAIN_MANAGE, self::DEPLOYMENT_VIEW_ALL, self::DEPLOYMENT_RETRY,
            self::DEPLOYMENT_ROLLBACK, self::SERVER_VIEW, self::AGENT_VIEW, self::MONITORING_VIEW,
            self::PANEL_ACCOUNT_VIEW, self::PANEL_ACCOUNT_MANAGE,
            self::PANEL_ACCOUNT_TERMINATE, self::PANEL_ACCOUNT_VERIFY,
            self::CUSTOMER_SERVER_VIEW_ALL, self::CUSTOMER_SERVER_MANAGE,
            self::PROVIDER_ACCOUNT_VIEW, self::PROVIDER_ACCOUNT_VERIFY,
            self::BACKUP_VIEW_ALL, self::DOMAIN_VIEW_ALL, self::BILLING_VIEW_ALL, self::APP_VIEW_ALL,
            self::MANIFEST_VALIDATE,
        ];
    }

    /**
     * What a server agent may do: report state and return results for work the
     * control plane dispatched. An agent can never create, publish, delete or
     * reconfigure anything on its own initiative.
     */
    public static function agentGrants()
    {
        return [
            self::INSTALL_METRICS_VIEW, self::INSTALL_LOGS_VIEW, self::MONITORING_VIEW, self::SERVER_VIEW,
        ];
    }

    /** Grant/revoke at runtime (admin console). Auditable, survives upgrades. */
    public static function setGrant($role, $permission, $granted)
    {
        if (!in_array($permission, self::allPermissions(), true)) {
            throw new ValidationException('Unknown permission.', ['permission' => $permission]);
        }
        if (!in_array($role, self::roles(), true)) {
            throw new ValidationException('Unknown role.', ['role' => $role]);
        }
        if (in_array($role, [Actor::ROLE_SYSTEM, Actor::ROLE_AGENT, Actor::ROLE_GUEST], true)) {
            throw new ValidationException('Machine roles are not editable.', ['role' => $role]);
        }
        $now = Clock::now();
        $existing = Db::first('role_permissions', ['role' => $role, 'permission' => $permission]);
        if ($existing) {
            Db::update('role_permissions', ['granted' => $granted ? 1 : 0, 'updated_at' => $now],
                ['id' => (int) $existing['id']]);
        } else {
            Db::insert('role_permissions', [
                'role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        self::flush();
        return true;
    }

    /** Seed the shipped matrix. Never overwrites an operator's edits. */
    public static function seedMatrix()
    {
        $now = Clock::now();
        foreach (self::MATRIX as $role => $permissions) {
            foreach (self::allPermissions() as $permission) {
                if (Db::count('role_permissions', ['role' => $role, 'permission' => $permission]) > 0) {
                    continue;
                }
                Db::insert('role_permissions', [
                    'role' => $role,
                    'permission' => $permission,
                    'granted' => in_array($permission, $permissions, true) ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        self::flush();
    }
}
