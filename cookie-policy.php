<?php
/**
 * Cookie Policy Page
 *
 * @package    WHMCS
 * @subpackage ClientArea
 */

define('CLIENTAREA', true);
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new WHMCS_ClientArea();
$ca->setPageTitle(ch247_page_title('cookie-policy', 'COOKIE POLICY'));
$ca->addToBreadCrumb('index.php', 'Home');
$ca->addToBreadCrumb('cookie-policy.php', ch247_page_title('cookie-policy', 'Cookie Policy'));
$ca->setTemplate('cookiepolicy');
$ca->output();
