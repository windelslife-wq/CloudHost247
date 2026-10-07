<?php
/**
 * CloudHost247 Passkey — native WHMCS addon.
 *
 * Phase 3 provides a library-backed ceremony core and module-owned persistence.
 * Login/session integration remains disabled until a later explicitly approved phase.
 *
 * @package CloudHost247\Passkey
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use CloudHost247\Passkey\Core\Migrator;

function cloudhost247passkey_config()
{
    return [
        'name' => 'CloudHost247 Passkey',
        'description' => 'WebAuthn ceremony core is verified; Passkey login and session integration remain disabled.',
        'author' => 'CloudHost247',
        'language' => 'english',
        'version' => '0.2.0',
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

/** Honest phase status; this is not a credential-management or login UI. */
function cloudhost247passkey_output($vars)
{
    echo '<div class="alert alert-info"><strong>Passkey authentication is currently disabled.</strong> '
        . 'The WebAuthn core is not exposed through login or management endpoints and does not provide session creation. No registration UI is enabled; existing WHMCS authentication remains unchanged.</div>';
}
