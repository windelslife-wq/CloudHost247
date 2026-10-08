<?php
/**
 * CloudHost247 App Cloud — WHMCS addon boundary.
 *
 * WHMCS remains the authority for clients, products, orders, hosting services,
 * and invoices. This addon owns application deployments and the distinct
 * customer-owned VM control plane; it never turns an App Cloud deployment target
 * into a customer server.
 *
 * @package Ch247Apps
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Apps\Catalog\PanelCatalogService;
use Ch247Apps\Core\AppsException;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Whmcs;
use Ch247Apps\Http\PanelCatalogAdmin;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderRegistry;

function cloudhost247apps_config()
{
    return [
        'name' => 'CloudHost247 App Cloud',
        'description' => 'Application deployment, control-panel catalog metadata, and queued customer-owned infrastructure lifecycle, '
            . 'integrated with WHMCS services, invoices, identity and RBAC.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '1.2.0',
        'fields' => [
            'bootstrap_admin_id' => [
                'FriendlyName' => 'Bootstrap administrator ID',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '1',
                'Description' => 'WHMCS administrator granted the App Cloud super-admin role until explicit role mappings are configured.',
            ],
            'default_admin_role' => [
                'FriendlyName' => 'Default staff role',
                'Type' => 'dropdown',
                'Options' => 'staff,admin',
                'Default' => 'staff',
                'Description' => 'Unmapped WHMCS administrators receive this role. Provider credential access remains super-admin only.',
            ],
            'worker_enabled' => [
                'FriendlyName' => 'Shared worker enabled',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Enable queue leasing for App Cloud and infrastructure jobs.',
            ],
            'customer_server_provisioning_enabled' => [
                'FriendlyName' => 'Customer-server provisioning enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Keep off until a real provider adapter is installed, credentials are encrypted and verified, and staging checks pass.',
            ],
            'debug_logging' => [
                'FriendlyName' => 'Verbose logging',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Write debug-level operational entries. Leave off in production.',
            ],
        ],
    ];
}

/** Install additively; never destroy data during activation. */
function cloudhost247apps_activate()
{
    try {
        $migrator = new Migrator(__DIR__ . '/install/migrations');
        $result = $migrator->migrate();
        Rbac::seedMatrix();
        return [
            'status' => 'success',
            'description' => 'CloudHost247 App Cloud installed. ' . count($result['applied'])
                . ' migration(s) applied; RBAC seeded without overwriting operator grants.'
                . ' Application catalog data was not modified. Customer-server provisioning remains disabled by default. Configure '
                . 'CH247APPS_ENCRYPTION_KEY and install a real provider adapter before enabling it.',
        ];
    } catch (\Throwable $e) {
        Logger::error('CloudHost247 App Cloud activation failed.', [
            'exception' => get_class($e), 'message' => $e->getMessage(), 'source' => 'module',
        ]);
        return [
            'status' => 'error',
            'description' => 'App Cloud could not be installed. Review the WHMCS activity log; no existing data was deleted.',
        ];
    }
}

/** Deactivate without dropping tables or revoking customer history. */
function cloudhost247apps_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'App Cloud deactivated. Queue, provider-account, customer-server, panel-catalog, billing and audit data were preserved.',
    ];
}

/** Idempotent additive upgrade path. */
function cloudhost247apps_upgrade($vars)
{
    try {
        (new Migrator(__DIR__ . '/install/migrations'))->migrate();
        Rbac::seedMatrix();
    } catch (\Throwable $e) {
        Logger::error('CloudHost247 App Cloud upgrade failed.', [
            'exception' => get_class($e), 'message' => $e->getMessage(), 'source' => 'module',
        ]);
    }
}

/** Minimal WHMCS admin landing page and live integration readiness disclosure. */
function cloudhost247apps_output($vars)
{
    if (isset($_GET['action']) && (string) $_GET['action'] === 'panel_catalog') {
        (new PanelCatalogAdmin(is_array($vars) ? $vars : []))->render();
        return;
    }

    try {
        $actor = Identity::current();
        Rbac::assert($actor, Rbac::APP_VIEW_ALL);
        ProviderBootstrap::boot();
        $providers = ProviderRegistry::catalog();
        $installed = 0;
        foreach ($providers as $provider) {
            if (!empty($provider['adapter_available'])) {
                $installed++;
            }
        }
        $apiUrl = Whmcs::systemUrl('modules/addons/cloudhost247apps/api/index.php?path=/v1');
        $enabled = Settings::bool('customer_server_provisioning_enabled', false);
        $keyReady = \Ch247Apps\Core\Crypto::isConfigured();

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>CloudHost247 App Cloud</strong></div><div class="panel-body">';
        echo '<p>WHMCS remains authoritative for clients, services and invoices. App Cloud deployment targets are separate from customer-owned provider VMs.</p>';
        $moduleLink = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
            ? (string) $vars['modulelink'] : 'addonmodules.php?module=cloudhost247apps';
        $panelCatalogUrl = rtrim($moduleLink, '&?') . '&action=panel_catalog';
        echo '<p><a class="btn btn-default" href="' . htmlspecialchars($panelCatalogUrl, ENT_QUOTES, 'UTF-8')
            . '">Manage control-panel catalog</a></p>';
        echo '<dl class="dl-horizontal">'
            . '<dt>Customer-server provisioning</dt><dd>' . ($enabled ? 'Enabled' : 'Disabled (safe default)') . '</dd>'
            . '<dt>Encryption key</dt><dd>' . ($keyReady ? 'Configured' : 'Missing — set CH247APPS_ENCRYPTION_KEY before storing provider credentials') . '</dd>'
            . '<dt>Real provider adapters</dt><dd>' . (int) $installed . ' installed of ' . count($providers) . ' catalog entries</dd>'
            . '<dt>Authenticated API</dt><dd><code>' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '</code></dd>'
            . '</dl>';
        if ($installed === 0) {
            echo '<div class="alert alert-warning">No real infrastructure-provider adapter is registered. Provider operations fail with '
                . '<code>PROVIDER_UNAVAILABLE</code>; no synthetic server or success response is generated.</div>';
        }
        echo '<p>Run a dedicated provisioning worker after installing an audited provider adapter:<br>'
            . '<code>php -q modules/addons/cloudhost247apps/worker/worker.php --queue=provisioning</code></p>';
        echo '<p>See <code>docs/PHASE2_INFRASTRUCTURE_PROVISIONING.md</code> for the API contract, key handling and worker setup.</p>';
        echo '</div></div>';
    } catch (\Throwable $e) {
        Logger::warning('App Cloud admin landing page was denied or unavailable.', [
            'exception' => get_class($e), 'source' => 'module',
        ]);
        echo '<div class="alert alert-danger">You do not have permission to view App Cloud, or the module is not ready.</div>';
    }
}

/**
 * Customer-facing, record-driven control-panel catalog.
 *
 * The catalog is intentionally public/read-only. It never creates an order or
 * claims that installation, provider integration, or license activation works.
 */
function cloudhost247apps_clientarea($vars)
{
    $actor = Identity::current();
    try {
        $catalog = (new PanelCatalogService($actor))->customerCatalog();
        return [
            'pagetitle' => 'Control Panel Catalog',
            'breadcrumb' => ['index.php?m=cloudhost247apps' => 'Control Panels'],
            'templatefile' => 'templates/client/control-panels',
            'requirelogin' => false,
            'forcessl' => true,
            'vars' => [
                'panel_categories' => $catalog['categories'],
                'control_panels' => $catalog['panels'],
                'catalog_error' => '',
            ],
        ];
    } catch (AppsException $e) {
        Logger::warning('Control-panel catalog could not be rendered.', [
            'error_code' => $e->errorCode(), 'source' => 'panel_catalog',
        ]);
    } catch (\Throwable $e) {
        Logger::warning('Control-panel catalog could not be rendered.', [
            'exception' => get_class($e), 'source' => 'panel_catalog',
        ]);
    }

    return [
        'pagetitle' => 'Control Panel Catalog',
        'breadcrumb' => ['index.php?m=cloudhost247apps' => 'Control Panels'],
        'templatefile' => 'templates/client/control-panels',
        'requirelogin' => false,
        'forcessl' => true,
        'vars' => [
            'panel_categories' => [],
            'control_panels' => [],
            'catalog_error' => 'The catalog is temporarily unavailable. No order or panel operation was attempted.',
        ],
    ];
}

/** WHMCS client-area navigation entry for the catalog. */
function cloudhost247apps_sidebar($vars)
{
    $link = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
        ? (string) $vars['modulelink'] : 'index.php?m=cloudhost247apps';
    return '<div class="panel panel-default"><div class="panel-heading"><strong>App Cloud</strong></div>'
        . '<div class="list-group"><a class="list-group-item" href="'
        . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Control Panel Catalog</a></div></div>';
}
