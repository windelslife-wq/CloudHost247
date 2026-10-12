<?php
/**
 * Legal Notice Page
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
 * Sets the page title and assigns the Smarty template.
 * No authentication required — public legal page.
 */
$ca->setPageTitle(ch247_page_title('legal-notice', 'Legal Notice'));
$ca->initPage();

/**
 * Breadcrumb Navigation
 *
 * Adds structured breadcrumb trail for easy navigation back to home.
 */
$ca->addToBreadCrumb('index.php', $_LANG['globalsystemname']);
$ca->addToBreadCrumb('legal-notice.php', ch247_page_title('legal-notice', 'Legal Notice'));

/**
 * Template Assignment
 *
 * Template file: templates/hostx/legalnotice.tpl
 */
$ca->setTemplate('legalnotice');

/**
 * Render Output
 *
 * Compiles and outputs the page using the assigned Smarty template.
 */
$ca->output();
