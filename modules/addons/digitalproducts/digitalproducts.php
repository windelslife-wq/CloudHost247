<?php
/** CloudHost247 Digital Products Marketplace — WHMCS addon entry point. */
if (!defined('WHMCS')) die('This file cannot be accessed directly');

require_once __DIR__ . '/autoload.php';

use DigitalProducts\Core\Migrator;
use WHMCS\Database\Capsule;

function digitalproducts_config()
{
    return [
        'name' => 'CloudHost247 Digital Products',
        'description' => 'Secure downloadable products, version releases, entitlements and licensing for WHMCS.',
        'author' => 'CloudHost247', 'language' => 'english', 'version' => DIGITALPRODUCTS_VERSION,
        'fields' => [
            'download_limit' => ['FriendlyName' => 'Default download limit', 'Type' => 'text', 'Size' => '8', 'Default' => '5', 'Description' => 'Downloads per entitlement; 0 means unlimited.'],
            'link_expiry_hours' => ['FriendlyName' => 'Download-link expiry (hours)', 'Type' => 'text', 'Size' => '8', 'Default' => '48', 'Description' => '0 means links do not expire.'],
            'access_mode' => ['FriendlyName' => 'Default version access', 'Type' => 'dropdown', 'Options' => 'current_version,purchase_version', 'Default' => 'current_version', 'Description' => 'Current release or the release purchased.'],
            'license_enabled' => ['FriendlyName' => 'License keys', 'Type' => 'yesno', 'Default' => 'on', 'Description' => 'Enable license generation by default.'],
            'email_delivery' => ['FriendlyName' => 'Purchase email', 'Type' => 'yesno', 'Default' => 'on', 'Description' => 'Send the WHMCS email template after access is granted.'],
            'update_notifications' => ['FriendlyName' => 'Update notifications', 'Type' => 'yesno', 'Default' => '', 'Description' => 'Queue release notifications from cron.'],
            'max_upload_size' => ['FriendlyName' => 'Maximum upload size (bytes)', 'Type' => 'text', 'Size' => '14', 'Default' => '524288000', 'Description' => 'The web server upload limit must also allow this value.'],
            'allowed_extensions' => ['FriendlyName' => 'Allowed extensions', 'Type' => 'text', 'Size' => '60', 'Default' => 'zip,tar.gz,pdf,js,css,php,json,xml,txt,md', 'Description' => 'Comma-separated allowlist.'],
            'storage_path' => ['FriendlyName' => 'Private storage path', 'Type' => 'text', 'Size' => '70', 'Default' => '', 'Description' => 'Must be outside the WHMCS document root. DIGITALPRODUCTS_STORAGE is also supported.'],
            'api_rate_limit' => ['FriendlyName' => 'API requests per minute', 'Type' => 'text', 'Size' => '8', 'Default' => '60', 'Description' => 'Sensitive license operations have an additional limit.'],
        ],
    ];
}

function digitalproducts_activate()
{
    try { (new Migrator())->migrate(); return ['status' => 'success', 'description' => 'CloudHost247 Digital Products activated. Existing data was preserved and migrations were applied.']; }
    catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts activation failed: ' . $e->getMessage()); return ['status' => 'error', 'description' => 'Activation failed. Check the WHMCS activity log.']; }
}
function digitalproducts_deactivate() { return ['status' => 'success', 'description' => 'Deactivated without dropping products, purchases, licenses, files or audit history.']; }
function digitalproducts_upgrade($vars) { require_once __DIR__ . '/autoload.php'; try { (new Migrator())->migrate(); } catch (\Throwable $e) { if (function_exists('logActivity')) logActivity('DigitalProducts upgrade failed: ' . $e->getMessage()); } }
function digitalproducts_output($vars) { require_once __DIR__ . '/lib/Admin.php'; return (new DigitalProducts\Admin($vars))->render(); }
function digitalproducts_sidebar($vars)
{
    $link = htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8');
    return '<div class="panel panel-default"><div class="panel-heading"><strong><i class="fa fa-cloud-download"></i> Digital Products</strong></div><div class="list-group">'
        . '<a class="list-group-item" href="' . $link . '&action=dashboard">Dashboard</a>'
        . '<a class="list-group-item" href="' . $link . '&action=products">Products</a>'
        . '<a class="list-group-item" href="' . $link . '&action=upload">Upload version</a>'
        . '<a class="list-group-item" href="' . $link . '&action=versions">Versions</a>'
        . '<a class="list-group-item" href="' . $link . '&action=entitlements">Entitlements</a>'
        . '<a class="list-group-item" href="' . $link . '&action=licenses">Licenses</a>'
        . '<a class="list-group-item" href="' . $link . '&action=downloads">Downloads</a>'
        . '<a class="list-group-item" href="' . $link . '&action=api">API tokens</a>'
        . '<a class="list-group-item" href="' . $link . '&action=settings">Settings</a></div><div class="panel-body small text-muted">CloudHost247 v' . htmlspecialchars(DIGITALPRODUCTS_VERSION, ENT_QUOTES, 'UTF-8') . '</div></div>';
}
