<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';
$ca = new ClientArea();
$ca->setPageTitle(ch247_page_title('all-element-hostx', 'All Element Hostx'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('all-element-hostx.php', ch247_page_title('all-element-hostx', 'Hostx Element'));
$ca->initPage();
$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('all-element-hostx');
$ca->output();