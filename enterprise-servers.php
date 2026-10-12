<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';
$ca = new ClientArea();
$ca->setPageTitle(ch247_page_title('enterprise-servers', 'Enterprise Servers'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('enterpriseservers.php', ch247_page_title('enterprise-servers', 'Enterprise Servers'));
$ca->initPage();
$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('hostx');
$ca->output();