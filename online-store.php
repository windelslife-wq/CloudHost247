<?php
/**
 * CLOUDHOST247 — Online Store hub.
 */

use Chs\Http\Landing;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-online-store', 'Open Your Online Store', 'online-store.php');

Landing::seo([
    'title'       => 'Open an Online Store — honest paths on real infrastructure | CLOUDHOST247',
    'description' => 'Self-host WooCommerce on our platform plans, or commission a managed build. '
        . 'Catalogue, payments, shipping and orders on hosting you own and control.',
    'canonical'   => 'online-store.php',
    'og_title'    => 'Open your online store — CLOUDHOST247',
    'og_desc'     => 'Real WooCommerce hosting or a managed build; gateways you choose, costs you can trace.',
]);

Landing::render($ca, 'chs-online-store', [
    'chsHostingUrl' => 'wordpress-hosting.php',
    'chsCpanelUrl'  => 'cpanel-hosting.php',
    'chsExpertUrl'  => 'hire-an-expert.php',
    'chsMarketingUrl' => 'digital-marketing.php',
    'chsSslUrl'     => 'ssl-certificate.php',
]);
