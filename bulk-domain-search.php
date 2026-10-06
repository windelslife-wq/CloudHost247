<?php
/**
 * CLOUDHOST247 — Bulk domain search landing.
 *
 * Bulk availability is submitted straight into WHMCS' bulk registration flow
 * (cart.php, bulk=true). No parallel/demo search exists anywhere in this site.
 */

use Chs\Core\Money;
use Chs\Http\Landing;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-bulk-search', 'Bulk Domain Search', 'bulk-domain-search.php');

Landing::seo([
    'title'       => 'Bulk Domain Search — check a whole list in one go | CLOUDHOST247',
    'description' => 'Paste up to 500 names, whitespace or comma separated, and check availability across the '
        . 'live registry chain in one pass.',
    'canonical'   => 'bulk-domain-search.php',
    'og_title'    => 'Bulk Domain Search — CLOUDHOST247',
    'og_desc'     => 'One paste, one search, the full answer for your whole list.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'Bulk Domain Search', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

Landing::render($ca, 'chs-bulk-search', [
    'chsSearchFallback' => 'cart.php?a=add&domain=register&query=',
    'chsTransferUrl' => 'domain-transfer.php',
    'chsDirectoryUrl' => 'tld-directory.php',
]);
