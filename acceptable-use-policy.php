<?php

define("CLIENTAREA", true);

require("init.php");
require_once __DIR__ . '/includes/ch247_content.php';

use WHMCS\ClientArea;

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('acceptable-use-policy', "Acceptable Use Policy (AUP)"));

$ca->addToBreadCrumb("index.php", Lang::trans("globalsystemname"));
$ca->addToBreadCrumb("acceptable-use-policy.php", ch247_page_title('acceptable-use-policy', "Acceptable Use Policy (AUP)"));

$ca->initPage();

$ca->setTemplate('acceptableusepolicy');
$ca->output();
