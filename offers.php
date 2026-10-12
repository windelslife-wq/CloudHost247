<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';
$ca = new ClientArea();
$ca->setPageTitle(ch247_page_title('offers', 'Offers'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('offers.php', ch247_page_title('offers', 'Offers'));
$ca->initPage();

$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('hostx');
$ca->output();