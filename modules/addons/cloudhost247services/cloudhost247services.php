<?php
/**
 * CloudHost247 Services Suite — WHMCS addon module.
 *
 * One module, every net-new platform capability that WHMCS does not already
 * provide natively: domain valuation, domain auctions, the Discount Domain
 * Club, a database-driven TLD catalogue, public WHOIS lookup, expert-service
 * intake, the Logo Studio, the AI Website Builder shell and the unified
 * inbox. Domain search, transfers, bulk search, TLD pricing and management,
 * cart, checkout and the client dashboard remain — deliberately — in WHMCS
 * core, and this module integrates with them rather than duplicating them.
 *
 * @package Chs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Chs\Core\Db;
use Chs\Core\Logger;
use Chs\Core\I18n;
use Chs\Core\Migrator;
use Chs\Http\AdminPortal;
use Chs\Http\CustomerPortal;

function cloudhost247services_config()
{
    return [
        'name' => 'CloudHost247 Services Suite',
        'description' => 'Domain valuation, domain auctions, Discount Domain Club, TLD catalogue, '
            . 'WHOIS lookup, expert services, Logo Studio, AI Website Builder, unified inbox, and the '
            . 'infrastructure layer: OS catalog, provider image mappings, server provisioning, '
            . 'OS reinstall and server management — integrated with WHMCS billing, domains and the client area.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '1.2.0',
        'fields' => [
            'service_enabled' => [
                'FriendlyName' => 'Suite enabled',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Master switch for the public and client-area features.',
            ],
            'public_pages_enabled' => [
                'FriendlyName' => 'Public landing pages',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Serve the public service pages (valuation, auctions, directory…) to signed-out visitors.',
            ],
            'ai_enabled' => [
                'FriendlyName' => 'AI Website Builder',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Requires ai_endpoint, ai_model and the CHS_AI_API_KEY environment variable — configure under the module\'s Settings tab.',
            ],
            'debug_logging' => [
                'FriendlyName' => 'Verbose logging',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Write debug entries to the activity log. Leave off in production.',
            ],
        ],
    ];
}

/**
 * Install: run the migrations, seed starter content. No data is ever dropped.
 */
function cloudhost247services_activate()
{
    try {
        $migrator = new Migrator(__DIR__ . '/install/migrations');
        $applied = $migrator->migrate();
        return [
            'status' => 'success',
            'description' => 'CloudHost247 Services installed. ' . count($applied['applied'])
                . ' migration(s) applied. Next: open the module\'s Settings tab, then review the '
                . 'TLD Catalogue and the Discount Domain Club plan. Schedule '
                . 'modules/addons/cloudhost247services/cron/cloudhost247services.php every 5 minutes '
                . 'for auction closing.',
        ];
    } catch (\Throwable $e) {
        Logger::error('CloudHost247 Services activation failed', ['message' => $e->getMessage()]);
        return [
            'status' => 'error',
            'description' => 'Installation failed: ' . $e->getMessage(),
        ];
    }
}

function cloudhost247services_deactivate()
{
    // Deliberately non-destructive: financial history, bids and requests must
    // survive an accidental toggle.
    return [
        'status' => 'success',
        'description' => 'Module deactivated. All data has been preserved; re-activate to restore access.',
    ];
}

function cloudhost247services_upgrade($vars)
{
    try {
        $migrator = new Migrator(__DIR__ . '/install/migrations');
        $migrator->migrate();
    } catch (\Throwable $e) {
        Logger::error('CloudHost247 Services upgrade failed', ['message' => $e->getMessage()]);
    }
}

/**
 * Admin control center.
 */
function cloudhost247services_output($vars)
{
    echo (new AdminPortal($vars))->render();
}

/**
 * Client-area portal.
 */
function cloudhost247services_clientarea($vars)
{
    $portal = new CustomerPortal();
    return $portal->dispatch($vars);
}

/**
 * Sidebar / nav contribution inside the module's own pages.
 */
function cloudhost247services_sidebar($vars)
{
    $items = [
        ''              => ['dashboard', 'Dashboard'],
        'search'        => ['domain_search', 'Domain search'],
        'bulk'          => ['bulk_domain_search', 'Bulk domain search'],
        'domains'       => ['my_domains', 'My domains'],
        'transfers'     => ['domain_transfers', 'Domain transfers'],
        'servers'       => ['my_servers', 'My servers'],
        'order'         => ['order_server', 'Order server'],
        'valuation'     => ['domain_valuation', 'Domain valuation'],
        'auctions'      => ['domain_auctions_nav', 'Domain auctions'],
        'watchlist'     => ['auction_watchlist', 'Auction watchlist'],
        'sell'          => ['sell_a_domain', 'Sell a domain'],
        'club'          => ['discount_domain_club', 'Discount Domain Club'],
        'requests'      => ['service_requests', 'Service requests'],
        'logos'         => ['logo_projects', 'Logo projects'],
        'ai'            => ['ai_website_builder', 'AI Website Builder'],
        'inbox'         => ['unified_inbox', 'Unified inbox'],
        'notifications' => ['notifications', 'Notifications'],
    ];
    $current = isset($_GET['action']) ? (string) $_GET['action'] : '';
    $html = '<div class="list-group chs-module-nav">';
    foreach ($items as $action => $label) {
        $href = 'index.php?m=cloudhost247services' . ($action !== '' ? '&action=' . $action : '');
        $active = ($action === $current || ($action === '' && $current === '')) ? ' active' : '';
        $text = I18n::text($label[0], $label[1]);
        $html .= '<a class="list-group-item' . $active . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    $html .= '</div>';
    return $html;
}
