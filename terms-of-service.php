<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
$ca = new ClientArea();
$ca->setPageTitle('TERMS OF SERVICE');
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('terms-of-service.php', 'TERMS OF SERVICE');
$ca->initPage();
$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('termsofservice');
$ca->output();