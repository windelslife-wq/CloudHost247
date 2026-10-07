<?php
/**
 * CloudHost247 AI — addon module entry point.
 *
 * Activation is additive and re-runnable (numbered migrations, ledger, no
 * destructive operations). Deactivation removes nothing. All privileged
 * actions live behind Rbac + CSRF in the admin portal and api.php.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Ai\Core\Migrator;

define('CH247AI_VERSION', '1.0.0');

function cloudhost247ai_config()
{
    return [
        'name' => 'CloudHost247 AI',
        'description' => 'AI operating layer: agent registry, read-only grounded tools, approvals, audit chain, event capture and daily briefings. Fails closed without a model provider; never invents data.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => CH247AI_VERSION,
        'fields' => [
            'service_enabled' => ['FriendlyName' => 'AI service enabled', 'Type' => 'yesno', 'Default' => 'on', 'Description' => 'Master switch. Turn off to stop every model call and tool execution.'],
        ],
    ];
}

function cloudhost247ai_activate()
{
    try {
        (new Migrator())->migrate();
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 AI activated: schema ensured, registry seeded. No existing data was modified.');
        }
        return ['status' => 'success', 'description' => 'CloudHost247 AI activated. Tables were created additively and the agent/tool registry was seeded. No customer data is duplicated.'];
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 AI activation failed: ' . $e->getMessage());
        }
        return ['status' => 'error', 'description' => 'Activation failed. Check the WHMCS activity log for the exact error.'];
    }
}

function cloudhost247ai_deactivate()
{
    // Never drop tables: audit history, runs and approvals are records of
    // operations and must survive deactivation.
    return ['status' => 'success', 'description' => 'Deactivated without dropping audit history, runs, approvals or knowledge. Re-activating reuses the existing data.'];
}

function cloudhost247ai_upgrade($vars)
{
    require_once __DIR__ . '/autoload.php';
    try {
        (new Migrator())->migrate();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 AI upgrade failed: ' . $e->getMessage());
        }
    }
}

function cloudhost247ai_output($vars)
{
    require_once __DIR__ . '/lib/Http/AdminPortal.php';
    return (new \Ch247Ai\Http\AdminPortal($vars))->render();
}

/**
 * Customer-facing surface (brief §17/§33), reached at
 * index.php?m=cloudhost247ai in the normal WHMCS client area.
 *
 * The question is posted to this page and handled server-side; api.php stays
 * strictly admin-only so the two authority models never share a door.
 */
function cloudhost247ai_clientarea($vars)
{
    require_once __DIR__ . '/lib/Http/CustomerPortal.php';
    return (new \Ch247Ai\Http\CustomerPortal())->dispatch($vars);
}

function cloudhost247ai_sidebar($vars)
{
    $link = htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8');
    $items = [
        'dashboard' => 'Dashboard',
        'copilot' => 'Copilot',
        'agents' => 'Agents',
        'tools' => 'Tools',
        'knowledge' => 'Knowledge',
        'approvals' => 'Approvals',
        'runs' => 'Runs &amp; usage',
        'audit' => 'Audit chain',
        'settings' => 'Settings',
    ];
    $html = '<div class="panel panel-default"><div class="panel-heading"><strong><i class="fa fa-rocket"></i> CloudHost247 AI</strong></div><div class="list-group">';
    foreach ($items as $action => $label) {
        $html .= '<a class="list-group-item" href="' . $link . '&amp;action=' . $action . '">' . $label . '</a>';
    }
    $html .= '</div><div class="panel-body small text-muted">v' . htmlspecialchars(CH247AI_VERSION, ENT_QUOTES, 'UTF-8') . ' — read-only phase</div></div>';
    return $html;
}
