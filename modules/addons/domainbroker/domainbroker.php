<?php
/**
 * Domain Broker Service — WHMCS addon module.
 *
 * A complete brokered domain acquisition desk: customer requests, broker
 * negotiation, escrowed payment through WHMCS billing, registrar transfer,
 * verification, disputes, reporting and a REST API.
 *
 * Nothing here is a demo. Payments run through WHMCS invoices and the
 * configured gateways; transfers are recorded against real registrar state;
 * no broker account, price or fee is hard-coded; secrets come from the
 * environment.
 *
 * @package DomainBroker
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use DomainBroker\Core\Db;
use DomainBroker\Core\Identity;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Migrator;
use DomainBroker\Core\Rbac;
use DomainBroker\Core\Settings;
use DomainBroker\Http\AdminPortal;
use DomainBroker\Http\CustomerPortal;
use DomainBroker\Http\View;

/**
 * Module metadata and the settings an operator edits in Setup → Addon Modules.
 *
 * Operational configuration lives in the module's own settings screen; only
 * the handful of values WHMCS needs before the module boots are here. Secrets
 * are never defined as module fields — they are read from DOMAINBROKER_*
 * environment variables.
 */
function domainbroker_config()
{
    return [
        'name' => 'Domain Broker Service',
        'description' => 'Brokered domain acquisition: requests, negotiation, escrowed payment, '
            . 'transfer management, verification, disputes and reporting — integrated with WHMCS '
            . 'clients, invoices, domains, tickets and notifications.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '1.0.0',
        'fields' => [
            'service_enabled' => [
                'FriendlyName' => 'Service enabled',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Uncheck to hide the service from clients while keeping existing data intact.',
            ],
            'public_landing_enabled' => [
                'FriendlyName' => 'Public landing page',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Show domain-broker.php to signed-out visitors.',
            ],
            'bootstrap_admin_id' => [
                'FriendlyName' => 'Bootstrap administrator ID',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '1',
                'Description' => 'WHMCS admin ID granted the Domain Broker super role until the role '
                    . 'mapping is configured in the module\'s Settings tab.',
            ],
            'default_admin_role' => [
                'FriendlyName' => 'Default staff role',
                'Type' => 'dropdown',
                'Options' => 'admin_viewer,admin_manager,admin_finance',
                'Default' => 'admin_viewer',
                'Description' => 'Role for staff not explicitly listed in the role mapping. '
                    . 'Keep this read-only: access should be granted deliberately.',
            ],
            'escrow_provider' => [
                'FriendlyName' => 'Escrow provider',
                'Type' => 'dropdown',
                'Options' => 'internal,manual',
                'Default' => 'internal',
                'Description' => 'Which provider holds acquisition funds. Third-party providers are '
                    . 'registered through the escrow abstraction layer and appear here once installed.',
            ],
            'debug_logging' => [
                'FriendlyName' => 'Verbose logging',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Write debug-level entries to the WHMCS activity log. Leave off in production.',
            ],
        ],
    ];
}

/**
 * Install: run the migrations and seed the RBAC matrix.
 */
function domainbroker_activate()
{
    try {
        $migrator = new Migrator(__DIR__ . '/install/migrations');
        $applied = $migrator->migrate();
        domainbroker_seed_roles();

        return [
            'status' => 'success',
            'description' => 'Domain Broker installed. ' . count($applied) . ' migration(s) applied. '
                . 'Set DOMAINBROKER_ENCRYPTION_KEY in your environment, then open the module and '
                . 'configure roles, currencies and fee rules.',
        ];
    } catch (\Throwable $e) {
        Logger::error('Domain Broker activation failed.', ['message' => $e->getMessage()]);
        return [
            'status' => 'error',
            'description' => 'Domain Broker could not be installed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate without destroying anything.
 *
 * Financial and negotiation history must survive an accidental deactivation,
 * so no table is dropped here. Removing data is a deliberate, manual act.
 */
function domainbroker_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Domain Broker deactivated. All acquisition, negotiation, payment and audit '
            . 'history has been preserved; re-activate the module to restore access.',
    ];
}

/**
 * Upgrade: migrations are idempotent, so re-running them is the upgrade path.
 */
function domainbroker_upgrade($vars)
{
    try {
        $migrator = new Migrator(__DIR__ . '/install/migrations');
        $migrator->migrate();
        domainbroker_seed_roles();
    } catch (\Throwable $e) {
        Logger::error('Domain Broker upgrade failed.', ['message' => $e->getMessage()]);
    }
}

/** Seed the shipped role → permission matrix so an operator can edit it. */
function domainbroker_seed_roles()
{
    if (!Db::tableExists('roles')) {
        return;
    }
    foreach (Rbac::MATRIX as $role => $permissions) {
        foreach ($permissions as $permission) {
            $existing = Db::first('roles', ['role' => $role, 'permission' => $permission]);
            if (!$existing) {
                Db::insert('roles', [
                    'role' => $role,
                    'permission' => $permission,
                    'granted' => 1,
                    'created_at' => \DomainBroker\Core\Clock::now(),
                    'updated_at' => \DomainBroker\Core\Clock::now(),
                ]);
            }
        }
    }
}

/**
 * Admin area. Staff brokers are routed to their desk, administrators to the
 * console; both are resolved from the WHMCS admin session, never from input.
 */
function domainbroker_output($vars)
{
    try {
        View::setAdminLink(isset($vars['modulelink']) ? $vars['modulelink'] : '');
        $portal = new AdminPortal(Identity::current());
        echo $portal->handle($vars);
    } catch (\Throwable $e) {
        Logger::error('Domain Broker admin page failed.', [
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        echo '<div class="alert alert-danger">The Domain Broker admin page could not be rendered. '
            . 'See the activity log for details.</div>';
    }
}

/** Admin sidebar. */
function domainbroker_sidebar($vars)
{
    $link = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=domainbroker';
    $actor = Identity::current();

    $items = [
        'dashboard' => 'Dashboard',
        'requests' => 'Requests',
        'brokers' => 'Brokers',
        'payments' => 'Payments',
        'disputes' => 'Disputes',
        'risk' => 'Risk review',
        'fees' => 'Fees',
        'reports' => 'Reports',
        'audit' => 'Audit log',
        'settings' => 'Settings',
    ];

    if ($actor->isBroker()) {
        $items = ['desk' => 'Broker desk'];
    }

    $html = '<span class="header"><i class="fa fa-handshake-o"></i> Domain Broker</span><ul class="menu">';
    foreach ($items as $action => $label) {
        $html .= '<li><a href="' . htmlspecialchars($link . '&action=' . $action, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul>';
}

/**
 * Client area. Returns the page definition WHMCS renders through the module's
 * own Smarty templates.
 */
function domainbroker_clientarea($vars)
{
    if (!Settings::bool('service_enabled', true)) {
        return [
            'pagetitle' => 'Domain Broker',
            'breadcrumb' => ['index.php?m=domainbroker' => 'Domain Broker'],
            'templatefile' => 'login_required',
            'requirelogin' => false,
            'vars' => [
                'flash' => [],
                'is_staff' => false,
                'landing_url' => 'domain-broker.php',
                'staff_url' => '',
            ],
        ];
    }

    try {
        $portal = new CustomerPortal(Identity::current());
        return $portal->handle($vars);
    } catch (\Throwable $e) {
        Logger::error('Domain Broker client page failed.', [
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        return [
            'pagetitle' => 'Domain Broker',
            'breadcrumb' => ['index.php?m=domainbroker' => 'Domain Broker'],
            'templatefile' => 'login_required',
            'requirelogin' => true,
            'vars' => [
                'flash' => [['type' => 'danger', 'message' => 'That page could not be loaded. Please try again.']],
                'is_staff' => false,
                'landing_url' => 'domain-broker.php',
                'staff_url' => '',
            ],
        ];
    }
}
