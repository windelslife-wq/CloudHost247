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
use Ch247Apps\Core\Csrf;
use Ch247Apps\Core\Db;
use Ch247Apps\Core\Str;
use Ch247Apps\Core\Identity;
use Ch247Apps\Core\Logger;
use Ch247Apps\Core\Migrator;
use Ch247Apps\Core\Rbac;
use Ch247Apps\Core\Settings;
use Ch247Apps\Core\Whmcs;
use Ch247Apps\Http\PanelAccountAdmin;
use Ch247Apps\Http\PanelCatalogAdmin;
use Ch247Apps\Infrastructure\ProviderBootstrap;
use Ch247Apps\Infrastructure\ProviderRegistry;
use Ch247Apps\Infrastructure\ServerProductMappingService;
use Ch247Apps\Integration\Gateway;

function cloudhost247apps_config()
{
    return [
        'name' => 'CloudHost247 App Cloud',
        'description' => 'Application deployment, control-panel catalog metadata, and queued customer-owned infrastructure lifecycle, '
            . 'integrated with WHMCS services, invoices, identity and RBAC.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '1.11.1',
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
            'customer_server_self_service_enabled' => [
                'FriendlyName' => 'Self-service VPS provisioning enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Separately gated: only paid customer-owned WHMCS server services with an enabled operator product mapping can request a fixed VM. Keep off until WHMCS/provider staging and billing review pass.',
            ],
            'contabo_adoption_enabled' => [
                'FriendlyName' => 'Contabo existing-instance adoption enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Staff-only read-back of an existing paid Contabo instance, never provider ordering or cancellation. Keep off until Contabo/WHMCS identity and billing staging passes.',
            ],
            'ovh_legacy_inspection_enabled' => [
                'FriendlyName' => 'Legacy SoYouStart VPS inspection enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Staff-only WHMCS custom-field snapshot for active paid legacy VPS services. No OVH API call, adoption, purchase or customer VM. Keep off until WHMCS custom-field staging is validated.',
            ],
            'agent_heartbeat_ingress_enabled' => [
                'FriendlyName' => 'Agent heartbeat ingress enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Accept only HMAC-signed, replay-protected liveness reports from the currently assigned agent. Keep off until WHMCS/agent staging validates the signed protocol. Does not provide a command-listening daemon or deployment operations.',
            ],
            'agent_uptime_ingress_enabled' => [
                'FriendlyName' => 'Linux agent uptime samples enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Separately gated, bounded /proc/uptime samples from an assigned signed agent on a monitoring-enabled server. Requires heartbeat ingress; never implies healthy status or enables CPU, RAM, disk, commands or deployments. Keep off until isolated Linux/WHMCS staging and metrics retention review.',
            ],
            'agent_uptime_retention_enabled' => [
                'FriendlyName' => 'Prune expired node uptime samples',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Default off. Cron removes at most 200 source-tagged node uptime rows per run after the approved retention period. No other metric rows are eligible; verify backup/export and schema migration first.',
            ],
            'agent_uptime_retention_days' => [
                'FriendlyName' => 'Node uptime retention days',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '30',
                'Description' => '1–3650 days after an operator enables the separate cleanup switch. Rows exactly on the cutoff are retained.',
            ],
            'panel_account_workflow_enabled' => [
                'FriendlyName' => 'cPanel account lifecycle workflow enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Keep off until dedicated cPanel staging validates WHM API permissions and TLS. When enabled, staff may link existing accounts and queue lifecycle actions; customer account creation stays unavailable until secure password/SSO handoff is separately reviewed.',
            ],
            'cpanel_uapi_domains_enabled' => [
                'FriendlyName' => 'cPanel UAPI domain inventory enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Phase 5 read-only, staff-only domain inventory. Keep off until the WHM uapi_cpanel permission, DomainInfo::list_domains response, TLS and redaction are validated in dedicated staging.',
            ],
            'cpanel_uapi_aliases_enabled' => [
                'FriendlyName' => 'cPanel UAPI built-in alias inventory enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Phase 6 read-only, staff-only values reported by DomainInfo::main_domain_builtin_subdomain_aliases. Keep off until its exact UAPI permissions, response behavior, TLS and redaction are validated in dedicated staging; this is not a complete DNS inventory.',
            ],
            'cpanel_uapi_quota_usage_enabled' => [
                'FriendlyName' => 'cPanel UAPI quota-usage snapshots enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Phase 7 read-only, staff-only, queued disk and inode quota snapshot via Quota::get_quota_info. Keep off until its WHM token ACL, UAPI response normalization, TLS and audit behavior pass dedicated staging. Zero limits/remaining may mean unlimited or disabled quotas; zero used may mean no usage or disabled quotas.',
            ],
            'cpanel_uapi_bandwidth_usage_enabled' => [
                'FriendlyName' => 'cPanel UAPI bandwidth-usage snapshots enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Phase 8 read-only, staff-only, queued bandwidth snapshot via the fixed StatsBar::get_stats display=bandwidthusage call. Keep off until the exact UAPI ACL, response schema, TLS and audit behavior pass dedicated staging; preserve cPanel zeroisunlimited semantics as reported.',
            ],
            'dns_inventory_api_enabled' => [
                'FriendlyName' => 'Cloudflare DNS inventory API enabled',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Phase 14 default-off authenticated GET endpoint for verified customer domains. Customers need domain.view.own; staff need domain.view.all, including bearer-token scopes. App Cloud registration remains lazy; the Cloudflare addon master and DNS-inventory gates remain independently required. No DNS writes are exposed.',
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
                . ' Application catalog data was not modified. Customer-server provisioning and cPanel account workflow remain disabled by default; '
                . 'cPanel UAPI domain, alias, quota, and bandwidth snapshots are disabled until their dedicated staging gates pass; the DNS inventory API is separately disabled by default. Configure '
                . 'CH247APPS_ENCRYPTION_KEY and install a real provider adapter before enabling infrastructure provisioning.',
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
        'description' => 'App Cloud deactivated. Queue, provider-account, customer-server, panel-catalog, panel-account, billing and audit data were preserved.',
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
    if (isset($_GET['action']) && (string) $_GET['action'] === 'panel_accounts') {
        (new PanelAccountAdmin(is_array($vars) ? $vars : []))->render();
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
        $selfServiceEnabled = Settings::bool('customer_server_self_service_enabled', false);
        $panelWorkflowEnabled = Settings::bool('panel_account_workflow_enabled', false);
        $uapiDomainsEnabled = Settings::bool('cpanel_uapi_domains_enabled', false);
        $uapiAliasesEnabled = Settings::bool('cpanel_uapi_aliases_enabled', false);
        $uapiQuotaUsageEnabled = Settings::bool('cpanel_uapi_quota_usage_enabled', false);
        $uapiBandwidthUsageEnabled = Settings::bool('cpanel_uapi_bandwidth_usage_enabled', false);
        $keyReady = \Ch247Apps\Core\Crypto::isConfigured();

        echo '<div class="panel panel-default"><div class="panel-heading"><strong>CloudHost247 App Cloud</strong></div><div class="panel-body">';
        echo '<p>WHMCS remains authoritative for clients, services and invoices. App Cloud deployment targets are separate from customer-owned provider VMs.</p>';
        $moduleLink = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
            ? (string) $vars['modulelink'] : 'addonmodules.php?module=cloudhost247apps';
        $panelCatalogUrl = rtrim($moduleLink, '&?') . '&action=panel_catalog';
        echo '<p><a class="btn btn-default" href="' . htmlspecialchars($panelCatalogUrl, ENT_QUOTES, 'UTF-8')
            . '">Manage control-panel catalog</a>';
        if (Rbac::allows($actor, Rbac::PANEL_ACCOUNT_VIEW)) {
            $panelAccountsUrl = rtrim($moduleLink, '&?') . '&action=panel_accounts';
            echo ' <a class="btn btn-default" href="' . htmlspecialchars($panelAccountsUrl, ENT_QUOTES, 'UTF-8')
                . '">Linked cPanel accounts</a>';
        }
        echo '</p>';
        echo '<dl class="dl-horizontal">'
            . '<dt>Customer-server provisioning</dt><dd>' . ($enabled ? 'Enabled' : 'Disabled (safe default)') . '</dd>'
            . '<dt>Customer VPS self-service</dt><dd>' . ($selfServiceEnabled ? 'Enabled (requires paid mapped service)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>cPanel account workflow</dt><dd>' . ($panelWorkflowEnabled
                ? 'Enabled (staff-only existing accounts; customer creation unavailable)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>cPanel UAPI domain inventory</dt><dd>' . ($uapiDomainsEnabled
                ? 'Enabled (staff-only read-only capability; staging approval still required)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>cPanel built-in alias inventory</dt><dd>' . ($uapiAliasesEnabled
                ? 'Enabled (staff-only vendor-reported values; not complete DNS; staging approval still required)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>cPanel quota-usage snapshots</dt><dd>' . ($uapiQuotaUsageEnabled
                ? 'Enabled (staff-only, queued, vendor-reported values; staging approval still required)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>cPanel bandwidth-usage snapshots</dt><dd>' . ($uapiBandwidthUsageEnabled
                ? 'Enabled (staff-only, queued, vendor-reported values; staging approval still required)' : 'Disabled (safe default)') . '</dd>'
            . '<dt>Encryption key</dt><dd>' . ($keyReady ? 'Configured' : 'Missing — set CH247APPS_ENCRYPTION_KEY before storing provider credentials') . '</dd>'
            . '<dt>Real provider adapters</dt><dd>' . (int) $installed . ' installed of ' . count($providers) . ' catalog entries</dd>'
            . '<dt>Authenticated API</dt><dd><code>' . htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8') . '</code></dd>'
            . '</dl>';
        if ($installed === 0) {
            echo '<div class="alert alert-warning">No real infrastructure-provider adapter is registered. Provider operations fail with '
                . '<code>PROVIDER_UNAVAILABLE</code>; no synthetic server or success response is generated.</div>';
        } else {
            $installedNames = [];
            foreach ($providers as $provider) {
                if (!empty($provider['adapter_available'])) {
                    $installedNames[] = htmlspecialchars((string) $provider['name'], ENT_QUOTES, 'UTF-8');
                }
            }
            echo '<div class="alert alert-info">' . count($installedNames) . ' real provider adapter(s) installed: '
                . implode(', ', $installedNames) . '. Catalog providers without an adapter still fail closed with '
                . '<code>PROVIDER_UNAVAILABLE</code>. Provisioning additionally requires an encrypted, verified provider '
                . 'account and passed staging checks; this page claims no live provider verification.</div>';
        }
        echo '<p>Run a dedicated provisioning worker after installing an audited provider adapter:<br>'
            . '<code>php -q modules/addons/cloudhost247apps/worker/worker.php --queue=provisioning</code></p>';
        echo '<p>See <code>docs/PHASE2_INFRASTRUCTURE_PROVISIONING.md</code> for the API contract, key handling and worker setup. See <code>docs/PHASE4_CPANEL_WORKFLOW.md</code> for account-lifecycle gates, <code>docs/PHASE5_CPANEL_UAPI_DOMAINS.md</code> for domain inventory, <code>docs/PHASE6_CPANEL_BUILTIN_ALIASES.md</code> for built-in aliases, <code>docs/PHASE7_CPANEL_QUOTA_USAGE.md</code> for quota snapshots, <code>docs/PHASE8_CPANEL_BANDWIDTH_USAGE.md</code> for the disabled bandwidth snapshot capability, <code>docs/PHASE9_CPANEL_USAGE_HISTORY.md</code> for retained job history, and <code>docs/PHASE10_CPANEL_USAGE_OVERVIEW.md</code> for the validated staff overview.</p>';
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
    if (isset($_GET['action']) && (string) $_GET['action'] === 'vps') {
        return cloudhost247apps_vps_clientarea($actor);
    }
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

/** Paid WHMCS service → fixed-spec request; the cart remains the only checkout. */
function cloudhost247apps_vps_clientarea($actor)
{
    $page = [
        'pagetitle' => 'My VPS',
        'breadcrumb' => ['index.php?m=cloudhost247apps&action=vps' => 'My VPS'],
        'templatefile' => 'templates/client/vps',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => [
            'vps_products' => [], 'vps_services' => [], 'vps_error' => '',
            'vps_enabled' => false, 'vps_csrf' => '', 'vps_api_url' => '', 'vps_js_url' => '',
        ],
    ];
    if (!$actor->isCustomer()) {
        return $page;
    }
    try {
        $enabled = Settings::bool('customer_server_provisioning_enabled', false)
            && Settings::bool('customer_server_self_service_enabled', false);
        $page['vars']['vps_enabled'] = $enabled;
        if (!$enabled) {
            return $page;
        }
        ProviderBootstrap::boot();
        $mappings = new ServerProductMappingService($actor);
        $page['vars']['vps_products'] = $mappings->customerCatalog();
        $gateway = Gateway::get();
        foreach ($gateway->getClientServices($actor->clientId) as $service) {
            $serviceId = isset($service['id']) ? (int) $service['id'] : 0;
            if ($serviceId <= 0) {
                continue;
            }
            try {
                $mappings->forService($serviceId);
                $existing = Db::first('customer_servers', ['whmcs_service_id' => $serviceId]);
                $product = $gateway->getProduct((int) $service['packageid']);
                $page['vars']['vps_services'][] = [
                    'id' => $serviceId,
                    'product' => $product ? (string) $product['name'] : 'VPS',
                    'status' => isset($service['domainstatus']) ? (string) $service['domainstatus'] : '',
                    'existing' => $existing !== null,
                    'request_key' => Str::uuid4(),
                ];
            } catch (AppsException $e) {
                // Unmapped, retired or inaccessible products are not offered.
                continue;
            }
        }
        $page['vars']['vps_csrf'] = Csrf::token();
        $page['vars']['vps_api_url'] = Whmcs::moduleUrl('api/index.php?path=/v1/servers/self-service');
        $page['vars']['vps_js_url'] = Whmcs::moduleUrl('assets/vps.js');
    } catch (\Throwable $e) {
        Logger::warning('VPS client page unavailable.', ['exception' => get_class($e), 'source' => 'vps']);
        $page['vars']['vps_products'] = [];
        $page['vars']['vps_services'] = [];
        $page['vars']['vps_error'] = 'The VPS service is temporarily unavailable.';
    }
    return $page;
}

/** WHMCS client-area navigation entry for the catalog. */
function cloudhost247apps_sidebar($vars)
{
    $link = isset($vars['modulelink']) && is_scalar($vars['modulelink'])
        ? (string) $vars['modulelink'] : 'index.php?m=cloudhost247apps';
    $vpsLink = (Identity::current()->isCustomer() && Settings::bool('customer_server_self_service_enabled', false))
        ? '<a class="list-group-item" href="' . htmlspecialchars($link . '&action=vps', ENT_QUOTES, 'UTF-8')
            . '">My VPS</a>' : '';
    return '<div class="panel panel-default"><div class="panel-heading"><strong>App Cloud</strong></div>'
        . '<div class="list-group"><a class="list-group-item" href="'
        . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Control Panel Catalog</a>' . $vpsLink . '</div></div>';
}
