<?php

declare(strict_types=1);

use WHMCS\ClientArea;
use WHMCS\User\Client;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('fair-usage-policy', 'Fair Usage Policy'));
$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('fair-usage-policy.php', ch247_page_title('fair-usage-policy', 'Fair Usage Policy'));

$ca->initPage();

$ca->assign('templatefile', 'fairusagepolicy');

$ca->setTemplate('fairusagepolicy');

$ca->output();
