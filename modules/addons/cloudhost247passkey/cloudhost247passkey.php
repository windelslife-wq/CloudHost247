<?php
/**
 * CloudHost247 Passkey — native WHMCS addon.
 *
 * Phase 4 provides an opt-in, fail-closed handoff boundary to WHMCS's
 * existing identity, session, and 2FA architecture. No native session is
 * synthesized by this addon and the feature remains disabled by default.
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
        'description' => 'Fail-closed Passkey authentication handoff is available for an explicit WHMCS adapter; login remains disabled by default.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '0.3.0',
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

/** Honest Phase 4 status; the adapter boundary is present but login stays opt-in. */
function cloudhost247passkey_output($vars)
{
    echo '<div class="alert alert-info"><strong>Passkey authentication is currently disabled by default.</strong> '
        . 'Phase 4 adds a fail-closed handoff boundary for an explicitly registered WHMCS identity/session adapter. '
        . 'This module does not perform session creation, bypass existing 2FA, or replace password recovery; no login endpoint is enabled until the deployment owner supplies and verifies that adapter.</div>';
}
