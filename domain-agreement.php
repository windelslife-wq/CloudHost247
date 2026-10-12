<?php

/**
 * Domain Name Registration Agreement
 *
 * @package    WHMCS
 * @author     CloudHost247 ISC
 * @copyright  Copyright (c) CloudHost247 ISC
 * @license    https://www.cloudhost247.com/license/
 */

use WHMCS\ClientArea;
use WHMCS\User\Client;

define('CLIENTAREA', true);

require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('domain-agreement', 'Domain Name Registration Agreement'));

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('domain-agreement.php', ch247_page_title('domain-agreement', 'Domain Name Registration Agreement'));

$ca->initPage();

$ca->setTemplate('domainagreement');

$ca->output();
