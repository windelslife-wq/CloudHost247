<?php

use WHMCS\ClientArea;
use WHMCS\User\Client;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('data-privacy-notice-and-consent-form', 'Data Privacy Notice & Consent'));

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('privacy-policy.php', ch247_page_title('data-privacy-notice-and-consent-form', 'Data Privacy Notice & Consent'));

$ca->initPage();

$ca->assign('WEB_ROOT', $whmcs->get_ConfigString('SystemURL'));
$ca->assign('template', $whmcs->get_config('Template'));

$ca->setTemplate('dataprivacynoticeandconsentform');

$ca->output();
