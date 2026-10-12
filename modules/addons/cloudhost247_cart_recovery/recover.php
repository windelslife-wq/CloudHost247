<?php
/**
 * Public recovery + unsubscribe endpoint.
 *
 *   /modules/addons/cloudhost247_cart_recovery/recover.php?token=<token>
 *   /modules/addons/cloudhost247_cart_recovery/recover.php?action=unsubscribe&token=<token>
 *
 * No login is required — the token is the credential — but a token only ever
 * restores its own cart into the current session. It never authenticates the
 * visitor, never exposes account data and never reveals internal errors.
 */

$whmcsRoot = dirname(dirname(dirname(__DIR__)));
if (!is_file($whmcsRoot . '/init.php')) {
    http_response_code(500);
    exit('Service unavailable.');
}
require_once $whmcsRoot . '/init.php';
require_once __DIR__ . '/bootstrap.php';

use CloudHost247\CartRecovery\Log;
use CloudHost247\CartRecovery\RecoveryPage;
use CloudHost247\CartRecovery\RecoveryService;

/**
 * Minimal, CloudHost247-branded response page for the failure paths.
 *
 * Takes catalogue keys rather than sentences: the body lives in RecoveryPage,
 * which resolves the reader's language itself (see Locale). With no catalogue
 * deployed it falls back to the shipped English and prints exactly what it
 * always did.
 *
 * @param string $titleKey   e.g. 'recover_expired_title'
 * @param string $messageKey e.g. 'recover_expired_body'
 * @param int    $httpStatus
 */
function cloudhost247_cart_recovery_page($titleKey, $messageKey, $httpStatus = 200)
{
    RecoveryPage::render((string) $titleKey, (string) $messageKey, (int) $httpStatus);
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'recover';

try {
    if ($action === 'unsubscribe') {
        $result = RecoveryService::unsubscribe($token);
        if ($result['ok']) {
            cloudhost247_cart_recovery_page('recover_unsubscribed_title', 'recover_unsubscribed_body');
        } else {
            cloudhost247_cart_recovery_page(
                'recover_unsubscribe_invalid_title',
                'recover_unsubscribe_invalid_body',
                404
            );
        }
        exit;
    }

    $result = RecoveryService::recover($token);
    if ($result['ok']) {
        $target = RecoveryService::baseUrl() . '/cart.php?a=view';
        header('Location: ' . $target, true, 302);
        exit;
    }

    switch ($result['reason']) {
        case 'expired':
            cloudhost247_cart_recovery_page('recover_expired_title', 'recover_expired_body', 410);
            break;
        case 'already_converted':
            cloudhost247_cart_recovery_page('recover_converted_title', 'recover_converted_body', 410);
            break;
        case 'empty_cart':
        case 'closed':
            cloudhost247_cart_recovery_page(
                'recover_unavailable_title',
                'recover_unavailable_body',
                410
            );
            break;
        default:
            cloudhost247_cart_recovery_page('recover_invalid_title', 'recover_invalid_body', 404);
    }
} catch (\Throwable $e) {
    Log::error('recovery.endpoint_failed', array('error' => Log::safeError($e)));
    cloudhost247_cart_recovery_page('recover_error_title', 'recover_error_body', 500);
}
