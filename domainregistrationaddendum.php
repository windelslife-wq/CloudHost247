<?php

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('domainregistrationaddendum', 'Domain Registration Addendum'));

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('domainregistrationaddendum.php', ch247_page_title('domainregistrationaddendum', 'Domain Registration Addendum'));

$ca->setTemplate('domainregistrationaddendum');

$ca->output();
