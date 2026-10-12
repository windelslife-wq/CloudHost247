<?php
/**
 * Privacy Policy Page Controller
 *
 * @package    WHMCS
 * @author     CloudHost247
 * @copyright  Copyright (c) CloudHost247, All Rights Reserved
 * @license    Private Use
 * @version    1.0.0
 * @link       https://www.cloudhost247.com
 */

use WHMCS\ClientArea;
use WHMCS\Lang;

define('CLIENTAREA', true);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/ch247_content.php';

$ca = new ClientArea();

$ca->setPageTitle(ch247_page_title('privacy-policy', 'Privacy Policy'));

$ca->addToBreadCrumb('index.php', Lang::trans('globalsystemname'));
$ca->addToBreadCrumb('privacy-policy.php', ch247_page_title('privacy-policy', 'Privacy Policy'));

$ca->initPage();

$ca->setTemplate('privacypolicy');

$ca->output();
