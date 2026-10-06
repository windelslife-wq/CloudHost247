<?php
/**
 * CLOUDHOST247 — Domain search landing.
 *
 * The search itself is submitted to WHMCS' own domain availability/registration
 * flow (cart.php) — real registrar-chain answers, real orders. This page adds
 * the merchandising layer (spotlight TLD pricing) plus deep links into bulk
 * search, transfers, WHOIS and valuation.
 */

use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Core\Platform;
use Chs\Http\Landing;
use Chs\Services\TldCatalogService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-domain-search', 'Find Your Domain', 'domain-search.php');

Landing::seo([
    'title'       => 'Search Domains — live availability & pricing | CLOUDHOST247',
    'description' => 'Search any domain against the live registry chain, see today\'s register/renew/transfer '
        . 'pricing per extension, and check bulk lists, transfers and alternatives in one place.',
    'canonical'   => 'domain-search.php',
    'og_title'    => 'Find your domain — CLOUDHOST247',
    'og_desc'     => 'Live availability, today\'s prices, honest suggestions.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'Domain Search', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$spotlight = [];
$currency = 'USD';
try {
    $clientId = Identity::clientId();
    $currency = $clientId ? Platform::gateway()->clientCurrency($clientId)
        : Platform::gateway()->defaultCurrency();
    $spotlight = (new TldCatalogService())->spotlight(4);
    foreach ($spotlight as &$row) {
        $row['register_fmt'] = $row['register_minor'] !== null
            ? Money::format($row['register_minor'], $currency) : null;
    }
    unset($row);
} catch (\Throwable $e) {
    $spotlight = [];
}

Landing::render($ca, 'chs-domain-search', [
    'chsQuery' => $q,
    'chsSpotlight' => $spotlight,
    'chsCurrency' => $currency,
    'chsSearchUrl' => 'cart.php?a=add&domain=register&query=',
    'chsBulkUrl' => 'bulk-domain-search.php',
    'chsTransferUrl' => 'domain-transfer.php',
    'chsDirectoryUrl' => 'tld-directory.php',
    'chsValuationUrl' => 'domain-valuation.php',
    'chsAuctionsUrl' => 'domain-auctions.php',
]);
