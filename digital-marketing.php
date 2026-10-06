<?php
/**
 * CLOUDHOST247 — Digital Marketing services.
 */

use Chs\Http\Landing;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-marketing', 'Digital Marketing', 'digital-marketing.php');

Landing::seo([
    'title'       => 'Digital Marketing — SEO, search ads, analytics | CLOUDHOST247',
    'description' => 'Campaigns measured against your own analytics, not screenshots: technical SEO, search ads, '
        . 'email flows and content that ships monthly. Fixed-quote engagements through our expert desk.',
    'canonical'   => 'digital-marketing.php',
    'og_title'    => 'Digital Marketing — CLOUDHOST247',
    'og_desc'     => 'Marketing that beats its own last quarter — instrumented, quoted, delivered.',
]);

Landing::render($ca, 'chs-marketing', [
    'chsExpertUrl'     => 'index.php?m=cloudhost247services&action=requestnew&type=digital_marketing',
    'chsSeoUrl'        => 'index.php?m=cloudhost247services&action=requestnew&type=seo',
    'chsPortalUrl'     => 'index.php?m=cloudhost247services&action=requests',
    'chsValuationUrl'  => 'domain-valuation.php',
    'chsExpertHubUrl'  => 'hire-an-expert.php',
]);
