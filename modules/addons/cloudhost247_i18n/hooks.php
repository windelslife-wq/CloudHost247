<?php
/**
 * Hook registrations for the CloudHost247 Internationalization addon.
 *
 * WHMCS loads hook files from includes/hooks/ automatically; this file lets the
 * addon be installed as a self-contained module instead. Both paths point at
 * the same implementation so enabling either one is enough — never both, since
 * the implementation is idempotent and would simply run twice per request.
 *
 * @package    WHMCS
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$implementation = dirname(__DIR__, 3) . '/includes/hooks/cloudhost247_i18n.php';
if (is_file($implementation)) {
    require_once $implementation;
}
