<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
$ca = new ClientArea();
$ca->setPageTitle('Dedicated Server');
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('dedicated-server.php', 'Dedicated Server');
$ca->initPage();
$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('hostx');
$ca->output();

