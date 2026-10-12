<?php
/**
 * Data Deletion Instructions Page
 *
 * @package    WHMCS
 * @subpackage Client Area
 */

define('CLIENTAREA', true);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new WHMCS\ClientArea();

// Set page title and properties
$ca->setPageTitle(ch247_page_title('data-deletion', 'DATA DELETION INSTRUCTIONS'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('data-deletion.php', ch247_page_title('data-deletion', 'DATA DELETION INSTRUCTIONS'));

$ca->initPage();

// Assign template variables
$ca->assign('effectiveDate', ch247_date('2025-08-14'));
$ca->assign('companyName', 'CloudHost247 Inc');

// Output the page
$ca->setTemplate('datadeletion');
$ca->output();
