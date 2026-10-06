<?php
/**
 * CloudHost247 Marketing — addon module entry point.
 *
 * Email campaign platform for WHMCS: subscribers, lists, WHMCS-backed dynamic
 * segments, a block-based email builder, templates, scheduling, a queued
 * delivery engine with pluggable transports, tracking and analytics.
 *
 * Activation is additive and re-runnable (numbered migrations, ledger, no
 * destructive operations). Deactivation removes nothing — campaign history and
 * the suppression list are compliance records and must survive.
 *
 * Sending is OFF by default. An operator has to set a sender identity, a
 * postal address and a transport, then explicitly enable it.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Ch247Mkt\Core\Migrator;

define('CH247M_VERSION', '1.0.0');

function cloudhost247marketing_config()
{
    return [
        'name' => 'CloudHost247 Marketing',
        'description' => 'Email marketing for WHMCS: subscribers, dynamic WHMCS segments, drag-and-drop email builder, templates, scheduling, queued delivery, tracking and analytics. Sending stays disabled until you configure a sender identity and transport.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => CH247M_VERSION,
        'fields' => [
            'service_enabled' => [
                'FriendlyName' => 'Module enabled',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Master switch. Turn off to hide the module and stop the cron worker.',
            ],
        ],
    ];
}

function cloudhost247marketing_activate()
{
    try {
        $result = (new Migrator())->migrate();
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Marketing activated: ' . count($result['applied']) . ' migration(s) applied. Sending remains disabled until configured.');
        }
        return [
            'status' => 'success',
            'description' => 'CloudHost247 Marketing activated. Tables were created additively and the template library was seeded. '
                . 'Sending is disabled by default — open Settings to set your sender identity, postal address and transport, then enable it.',
        ];
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Marketing activation failed: ' . $e->getMessage());
        }
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function cloudhost247marketing_deactivate()
{
    // Never drop tables. Campaign history, the per-recipient ledger and above
    // all the suppression list are the evidence that you honoured opt-outs.
    return [
        'status' => 'success',
        'description' => 'Deactivated without dropping campaigns, subscribers, delivery history or the suppression list. Re-activating reuses the existing data.',
    ];
}

function cloudhost247marketing_upgrade($vars)
{
    require_once __DIR__ . '/autoload.php';
    try {
        (new Migrator())->migrate();
        \Ch247Mkt\Campaign\TemplateService::seed();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Marketing upgrade failed: ' . $e->getMessage());
        }
    }
}

function cloudhost247marketing_output($vars)
{
    require_once __DIR__ . '/lib/Http/AdminPortal.php';
    return (new \Ch247Mkt\Http\AdminPortal($vars))->render();
}

function cloudhost247marketing_sidebar($vars)
{
    $link = htmlspecialchars($vars['modulelink'], ENT_QUOTES, 'UTF-8');

    $groups = [
        'Campaigns' => [
            'dashboard'   => 'Dashboard',
            'campaigns'   => 'Campaigns',
            'templates'   => 'Templates',
            'automations' => 'Automations',
        ],
        'Audience' => [
            'subscribers' => 'Subscribers',
            'lists'       => 'Lists',
            'segments'    => 'Segments',
        ],
        'Delivery' => [
            'queue'        => 'Queue',
            'suppressions' => 'Suppressions',
            'settings'     => 'Settings',
        ],
    ];

    $html = '<div class="panel panel-default">'
        . '<div class="panel-heading"><strong><i class="fa fa-envelope-o"></i> Email Marketing</strong></div>';

    foreach ($groups as $heading => $items) {
        $html .= '<div class="list-group">'
            . '<div class="list-group-item small text-muted" style="background:#f7f8fa;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">'
            . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</div>';
        foreach ($items as $action => $label) {
            $html .= '<a class="list-group-item" href="' . $link . '&amp;action=' . $action . '">' . $label . '</a>';
        }
        $html .= '</div>';
    }

    $sending = 'unknown';
    try {
        $sending = \Ch247Mkt\Core\Settings::bool('sending_enabled', false) ? 'enabled' : 'disabled';
    } catch (\Throwable $e) {
        // Settings unavailable before activation completes.
    }
    $badge = $sending === 'enabled'
        ? '<span class="label label-success">sending enabled</span>'
        : '<span class="label label-warning">sending disabled</span>';

    $html .= '<div class="panel-body small text-muted">v' . htmlspecialchars(CH247M_VERSION, ENT_QUOTES, 'UTF-8') . ' &nbsp; ' . $badge . '</div></div>';
    return $html;
}
