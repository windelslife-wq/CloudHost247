<?php
/**
 * CloudHost 247 LTE Proxy - WHMCS hook bootstrap (LP-O1).
 *
 * Loads the LTE Proxy module's hook implementations from the standard WHMCS
 * /includes/hooks/ directory.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$ch247LteProxyModuleRoot = dirname(__DIR__, 2) . '/modules/servers/cloudhost247_lteproxy';

if (is_dir($ch247LteProxyModuleRoot . '/hooks')) {
    require_once $ch247LteProxyModuleRoot . '/hooks/AdminSidebar.php';
    require_once $ch247LteProxyModuleRoot . '/hooks/Notifications.php';
    require_once $ch247LteProxyModuleRoot . '/hooks/Automation.php';
}
