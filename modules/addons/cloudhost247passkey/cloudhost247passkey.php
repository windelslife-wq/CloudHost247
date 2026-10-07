<?php
/**
 * CloudHost247 Passkey — native WHMCS addon.
 *
 * Phase 4 provides an opt-in, fail-closed handoff boundary, Phase 5
 * provides authenticated credential management, Phase 6 provides policy,
 * recovery-grant, rate-limit, and audit orchestration, and Phase 7 provides
 * a host-verified external-identity linking boundary, Phase 8 provides
 * opt-in notification preference and host-delivery orchestration, Phase 9
 * provides bounded host-invoked retention maintenance, and Phase 10 provides
 * explicitly authorized administrator policy management for WHMCS's existing
 * identity, session, and 2FA architecture. Phase 11 adds the native WHMCS
 * HTTP boundary: client and administrator Passkey login, registration,
 * credential management, step-up confirmation, Passkey-assisted password
 * reset, notifications, and the admin dashboard. Password login and 2FA
 * remain available and the feature stays disabled by default.
 *
 * @package CloudHost247\Passkey
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use CloudHost247\Passkey\Core\Migrator;
use CloudHost247\Passkey\Core\PasskeyIntegrationRegistry;
use CloudHost247\Passkey\Integration\WhmcsAuthBridgeInterface;
use CloudHost247\Passkey\Integration\WhmcsIdentityProviderInterface;

function cloudhost247passkey_config()
{
    return [
        'name' => 'CloudHost247 Passkey',
        'description' => 'Native WebAuthn/Passkey login for clients and administrators, with credential management, enforcement policies, step-up confirmation, Passkey-assisted password reset, activity logs, and login notifications. Disabled by default; password login and 2FA remain available.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '0.11.0',
        'fields' => [],
    ];
}

function cloudhost247passkey_activate()
{
    try {
        $result = (new Migrator(__DIR__ . '/install/migrations'))->migrate();
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Passkey schema installed or verified. Authentication remains disabled.');
        }
        return [
            'status' => 'success',
            'description' => 'Passkey data schema installed (' . count($result['applied']) . ' migration(s) applied). No login, session, customer, or administrator records were changed.',
        ];
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Passkey schema activation failed: ' . get_class($e));
        }
        return [
            'status' => 'error',
            'description' => 'Schema installation failed. Check the WHMCS activity log; no authentication feature was enabled.',
        ];
    }
}

function cloudhost247passkey_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Passkey data preserved. Deactivation does not remove credentials, policy, or audit history.',
    ];
}

function cloudhost247passkey_upgrade($vars)
{
    try {
        (new Migrator(__DIR__ . '/install/migrations'))->migrate();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('CloudHost247 Passkey schema upgrade failed: ' . get_class($e));
        }
    }
}

/**
 * Register host-owned adapters for the Phase 4 handoff.
 *
 * The host integration must resolve current WHMCS account status and delegate
 * the final session/2FA transition to WHMCS. This addon never accepts a raw
 * session ID/token and never writes a synthetic login session.
 */
function cloudhost247passkey_register_auth_integration($identityProvider, $authBridge)
{
    if (!$identityProvider instanceof WhmcsIdentityProviderInterface
        || !$authBridge instanceof WhmcsAuthBridgeInterface) {
        throw new \InvalidArgumentException('Passkey integration requires explicit WHMCS identity and auth bridge adapters.');
    }
    PasskeyIntegrationRegistry::configure($identityProvider, $authBridge);
}

function cloudhost247passkey_auth_integration_configured()
{
    return PasskeyIntegrationRegistry::isConfigured();
}

/** Administrator dashboard; admin-session JSON actions exit through Admin::dispatchAjax(). */
function cloudhost247passkey_output($vars)
{
    $ajax = (isset($_GET['passkey_ajax']) ? (string) $_GET['passkey_ajax'] : '')
        . (isset($_POST['passkey_ajax']) ? (string) $_POST['passkey_ajax'] : '');
    if ($ajax !== '') {
        \CloudHost247\Passkey\Admin::dispatchAjax();
    }
    $admin = new \CloudHost247\Passkey\Admin(is_array($vars) ? $vars : []);
    $admin->render();
}

function cloudhost247passkey_sidebar($vars)
{
    $link = isset($vars['modulelink']) ? (string) $vars['modulelink'] : 'addonmodules.php?module=cloudhost247passkey';
    $safe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    return '<div class="panel panel-default"><div class="panel-heading"><strong><i class="fa fa-key"></i> Passkey</strong></div>'
        . '<div class="list-group">'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=dashboard">Dashboard</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=mine">My Passkeys</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=credentials">Credentials</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=events">Activity Log</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=policy">Policy</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=settings">Settings</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=entra">Entra ID</a>'
        . '<a class="list-group-item" href="' . $safe . '&ch247pk_tab=diagnostics">Diagnostics</a>'
        . '</div></div>';
}
