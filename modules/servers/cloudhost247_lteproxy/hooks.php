<?php
/**
 * CloudHost 247 LTE Proxy - WHMCS hook bootstrap (LP-2).
 *
 * WHMCS only auto-loads hook files that live directly in /includes/hooks/.
 * The hook implementations in ./hooks/ are inert until this file is copied
 * (or symlinked) to:
 *
 *     /includes/hooks/cloudhost247_lteproxy.php
 *
 * The path below resolves from the copied location back to this module, so
 * the copy keeps working across module upgrades without further edits.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$ch247ModuleRoot = dirname(__DIR__, 3) . '/modules/servers/cloudhost247_lteproxy';

require_once $ch247ModuleRoot . '/hooks/AdminSidebar.php';
require_once $ch247ModuleRoot . '/hooks/Notifications.php';
require_once $ch247ModuleRoot . '/hooks/Automation.php';
