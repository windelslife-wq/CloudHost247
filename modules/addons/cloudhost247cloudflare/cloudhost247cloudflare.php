<?php
/** CloudHost247 Cloudflare Reseller & Management — native WHMCS addon. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once __DIR__ . '/autoload.php';

use CloudHost247\Cloudflare\Core\Logger;
use CloudHost247\Cloudflare\Core\Migrator;
use CloudHost247\Cloudflare\Http\AdminPortal;
use CloudHost247\Cloudflare\Http\ClientPortal;

function cloudhost247cloudflare_config()
{
    return [
        'name' => 'CloudHost247 Cloudflare',
        'description' => 'Cloudflare service provisioning and customer management integrated with WHMCS products, invoices, domains, addons and hosting services.',
        'author' => 'CloudHost247', 'language' => 'english', 'version' => '1.0.3',
        'fields' => [
            'service_enabled' => [
                'FriendlyName' => 'Cloudflare integration enabled', 'Type' => 'yesno', 'Default' => '',
                'Description' => 'Master switch. Configure encrypted Cloudflare accounts and WHMCS package mappings in the module before enabling live provisioning.',
            ],
            'dns_inventory_adapter_enabled' => [
                'FriendlyName' => 'Internal read-only DNS inventory adapter enabled', 'Type' => 'yesno', 'Default' => '',
                'Description' => 'Separate default-off gate for the internal, customer-service-scoped DNS inventory adapter. This adds no customer or App Cloud route and performs no call until an authorized internal caller invokes it.',
            ],
        ],
    ];
}
function cloudhost247cloudflare_activate()
{
    try {
        $result = (new Migrator())->migrate();
        if (function_exists('logActivity')) logActivity('CloudHost247 Cloudflare activated; ' . count($result['applied']) . ' migration(s) applied. No Cloudflare service was provisioned.');
        return ['status' => 'success', 'description' => 'CloudHost247 Cloudflare tables installed additively. Configure an account and product mappings, then assign the cloudhost247cloudflare provisioning module to the relevant WHMCS products.'];
    } catch (\Throwable $e) {
        Logger::error('activation failed', ['error' => get_class($e)]);
        return ['status' => 'error', 'description' => 'Cloudflare module installation failed. Check the WHMCS activity log.'];
    }
}
function cloudhost247cloudflare_deactivate()
{
    return ['status' => 'success', 'description' => 'Cloudflare module deactivated without deleting account credentials, service mappings, DNS cache, audit history or jobs.'];
}
function cloudhost247cloudflare_upgrade($vars)
{
    require_once __DIR__ . '/autoload.php';
    try { (new Migrator())->migrate(); }
    catch (\Throwable $e) { Logger::error('upgrade failed', ['error' => get_class($e)]); }
}
function cloudhost247cloudflare_output($vars)
{
    return (new AdminPortal($vars))->render();
}
function cloudhost247cloudflare_clientarea($vars)
{
    return (new ClientPortal())->dispatch($vars);
}
function cloudhost247cloudflare_sidebar($vars)
{
    $link = htmlspecialchars((string) ($vars['modulelink'] ?? 'addonmodules.php?module=cloudhost247cloudflare'), ENT_QUOTES, 'UTF-8');
    $items = ['dashboard' => 'Overview', 'accounts' => 'Integrations / Accounts', 'packages' => 'Product mappings', 'services' => 'Cloudflare services', 'zones' => 'Zones', 'logs' => 'API & audit logs'];
    $html = '<div class="panel panel-default"><div class="panel-heading"><strong><i class="fa fa-cloud"></i> Cloudflare</strong></div><div class="list-group">';
    foreach ($items as $action => $label) $html .= '<a class="list-group-item" href="' . $link . '&amp;action=' . $action . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    return $html . '</div><div class="panel-body small text-muted">CloudHost247 native provider module</div></div>';
}
