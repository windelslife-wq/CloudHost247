<?php
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
define('CLIENTAREA', true);
require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';
$ca = new ClientArea();
$ca->setPageTitle(ch247_page_title('refund-and-cancellation-policy', 'Refund and Cancellation Policy'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('refund-and-cancellation-policy.php', ch247_page_title('refund-and-cancellation-policy', 'Refund and Cancellation Policy'));
$ca->initPage();
$ca->assign('sidebarHostxRemove', 'true');
$ca->setTemplate('refund-and-cancellation-policy');
$ca->output();