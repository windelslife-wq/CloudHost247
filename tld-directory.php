<?php
/**
 * CLOUDHOST247 — TLD Directory.
 *
 * Live catalogue: TLDs, current registration/renewal/transfer pricing and the
 * operator's merchandising layer (badges, categories, featured order), served
 * straight from the addon's catalog service in the visitor's currency.
 */

use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Core\Platform;
use Chs\Http\Landing;
use Chs\Services\TldCatalogService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-tld-directory', 'TLD Directory', 'tld-directory.php');

Landing::seo([
    'title'       => 'TLD Directory — every extension with today\'s pricing | CLOUDHOST247',
    'description' => 'A live, database-driven directory of every domain extension we sell: current prices, '
        . 'registration quality signals and operator-curated categories. Search, filter and sort.',
    'canonical'   => 'tld-directory.php',
    'og_title'    => 'TLD Directory with live pricing — CLOUDHOST247',
    'og_desc'     => 'Search every extension we sell, with prices in your currency and honest flags for DNS, '
        . 'privacy and EPP support.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        'name' => 'CLOUDHOST247 TLD Directory',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-tld-directory', Landing::unavailableVars('The TLD directory'));
    exit;
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['featured', 'name', 'price_asc', 'price_desc'], true)
    ? $_GET['sort'] : 'featured';

$rows = [];
$categories = [];
$currency = 'USD';
try {
    $clientId = Identity::clientId();
    $currency = $clientId ? Platform::gateway()->clientCurrency($clientId)
        : Platform::gateway()->defaultCurrency();
    $service = new TldCatalogService();
    $cat = $service->catalog(['search' => $q, 'category' => $category, 'sort' => $sort], $currency);
    $rows = $cat['rows'];
    $categories = $cat['categories'];
} catch (\Throwable $e) {
    $vars['chsErrors'] = ['service' => 'The price catalogue is temporarily unavailable.'];
}

foreach ($rows as &$row) {
    $row['register_fmt'] = $row['register_minor'] !== null ? Money::format($row['register_minor'], $currency) : null;
    $row['renew_fmt'] = $row['renew_minor'] !== null ? Money::format($row['renew_minor'], $currency) : null;
    $row['transfer_fmt'] = $row['transfer_minor'] !== null ? Money::format($row['transfer_minor'], $currency) : null;
}
unset($row);

$vars['chsRows'] = $rows;
$vars['chsCategories'] = $categories;
$vars['chsCurrency'] = $currency;
$vars['chsQ'] = $q;
$vars['chsCategory'] = $category;
$vars['chsSort'] = $sort;
$vars['chsSearchUrl'] = 'cart.php?a=add&domain=register&query=';
$vars['chsAuctionsUrl'] = 'domain-auctions.php';
$vars['chsValuationUrl'] = 'domain-valuation.php';
$vars['chsLoggedIn'] = Identity::clientId() !== null;

Landing::render($ca, 'chs-tld-directory', $vars);
