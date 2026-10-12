<?php
/**
 * WHMCS Domain Name Auto-Renewal and Deletion Policy Page
 *
 * @package WHMCS
 * @author CloudHost247 Isc
 * @copyright Copyright (c) CloudHost247 Isc, All Rights Reserved
 * @license https://www.cloudhost247.com/license/
 */

define('CLIENTAREA', true);

require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new WHMCS\ClientArea();

$ca->setPageTitle(ch247_page_title('domain-renewal-policy', 'Domain Name Auto-Renewal and Deletion Policy'));

$ca->addToBreadCrumb('index.php', Lang::trans('Home'));
$ca->addToBreadCrumb('domain-renewal-policy.php', ch247_page_title('domain-renewal-policy', 'Domain Name Auto-Renewal and Deletion Policy'));

$ca->initPage();

$ca->setTemplate('domainrenewalpolicy');

$ca->output();
