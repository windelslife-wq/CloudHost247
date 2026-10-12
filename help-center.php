<?php
/**
 * Help Center Page
 *
 * @package    WHMCS
 * @subpackage HostX Theme
 * @copyright  CloudHost247 Isc
 * @license    Private
 */

define('CLIENTAREA', true);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new WHMCS\ClientArea();

/**
 * Page Initialization
 *
 * Sets the page title, assigns breadcrumb navigation, and loads the Smarty template.
 * No authentication required — public support page.
 */
$ca->setPageTitle(ch247_page_title('help-center', 'Help Center'));
$ca->initPage();

/**
 * Breadcrumb Navigation
 *
 * Adds structured breadcrumb trail for easy navigation back to home.
 */
$ca->addToBreadCrumb('index.php', $_LANG['globalsystemname']);
$ca->addToBreadCrumb('help-center.php', ch247_page_title('help-center', 'Help Center'));

/**
 * Template Assignment
 *
 * Template file: templates/hostx/helpcenter.tpl
 */
$ca->setTemplate('helpcenter');

/**
 * Render Output
 *
 * Compiles and outputs the page using the assigned Smarty template.
 */
$ca->output();
