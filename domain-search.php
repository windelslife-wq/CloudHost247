<?php
/**
 * CLOUDHOST247 — Domain search landing.
 *
 * The search runs through the module's domain-search service: normalisation,
 * syntax validation, TLD support check, live provider availability and
 * server-side register/renew/transfer pricing (Discount Domain Club discount
 * applied for signed-in members). "Add to cart" lands in WHMCS' real cart and
 * checkout — the registry chain and the billing stay in the platform.
 */

use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Core\Platform;
use Chs\Http\Landing;
use Chs\Services\ClubService;
use Chs\Services\DomainSearchService;
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
$result = null;
$suggestions = [];
$errors = [];
$membership = null;

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

if (Landing::moduleReady() && $q !== '') {
    try {
        $clientId = Identity::clientId();
        $service = new DomainSearchService();
        $result = $service->search($q, $clientId);
        if ($result['available'] === true) {
            $suggestions = $service->suggestions(\Chs\Core\DomainName::parse($q), $clientId, 4);
        }
        $membership = $clientId ? (new ClubService())->activeMembership($clientId) : null;
        foreach ($suggestions as &$s) {
            $s['register_fmt'] = $s['register_final_minor'] !== null
                ? Money::format($s['register_final_minor'], $s['currency']) : null;
        }
        unset($s);
        if ($result['register_final_minor'] !== null) {
            $result['register_fmt'] = Money::format($result['register_final_minor'], $result['currency']);
            $result['renew_fmt'] = $result['renew_final_minor'] !== null
                ? Money::format($result['renew_final_minor'], $result['currency']) : null;
            $result['transfer_fmt'] = $result['transfer_final_minor'] !== null
                ? Money::format($result['transfer_final_minor'], $result['currency']) : null;
        }
        if ($result['register_minor'] !== null) {
            $result['register_base_fmt'] = Money::format($result['register_minor'], $result['currency']);
        }
    } catch (\Chs\Core\ValidationException $e) {
        $errors = $e->fieldErrors();
    } catch (\Chs\Core\RateLimitException $e) {
        $errors['limit'] = $e->getMessage();
    } catch (\Throwable $e) {
        $errors['service'] = 'Domain search is temporarily unavailable. Please try again in a moment.';
    }
}

Landing::render($ca, 'chs-domain-search', [
    'chsQuery' => $q,
    'chsSpotlight' => $spotlight,
    'chsCurrency' => $currency,
    'chsSearchUrl' => 'domain-search.php?q=',
    'chsCartUrl' => 'cart.php?a=add&domain=register&query=',
    'chsTransferUrl' => 'domain-transfer.php',
    'chsBulkUrl' => 'bulk-domain-search.php',
    'chsDirectoryUrl' => 'tld-directory.php',
    'chsValuationUrl' => 'domain-valuation.php',
    'chsAuctionsUrl' => 'domain-auctions.php',
    'chsWhoisUrl' => 'whois-lookup.php',
    'chsResult' => $result,
    'chsSuggestions' => $suggestions,
    'chsErrors' => $errors,
    'chsMembership' => $membership,
    'chsLoggedIn' => Identity::clientId() !== null,
    'chsPortalSearchUrl' => 'index.php?m=cloudhost247services&action=search',
]);
