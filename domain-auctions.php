<?php
/**
 * CLOUDHOST247 — Domain Auctions landing page (public browse).
 *
 * Live auctions + scheduled listings + settled history, all from the real
 * order engine. Bidding and selling require a client sign-in and route into
 * the module client portal — this page never feigns interactivity it can't
 * deliver to anonymous visitors.
 */

use Chs\Core\Csrf;
use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Http\Landing;
use Chs\Services\AuctionService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-auctions', 'Domain Auctions', 'domain-auctions.php');

Landing::seo([
    'title'       => 'Domain Auctions — bid, sell, own premium names | CLOUDHOST247',
    'description' => 'Live domain auctions with transparent proxy bidding, anti-sniping extensions, '
        . 'reserve pricing and invoice-settled wins. Watch a domain or list your own from your account.',
    'canonical'   => 'domain-auctions.php',
    'og_title'    => 'CLOUDHOST247 Domain Auctions',
    'og_desc'     => 'A real auction floor for premium domains — bids settle through invoices, never off-book.',
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => 'CLOUDHOST247 Domain Auctions',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-auctions', Landing::unavailableVars('Domain auctions'));
    exit;
}

$service = new AuctionService();
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['ending', 'price_asc', 'price_desc', 'bids', 'newest'], true)
    ? $_GET['sort'] : 'ending';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$result = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 20];
$endingSoon = [];
try {
    $result = $service->browse([
        'search'  => $search,
        'sort'    => $sort,
        'page'    => $page,
        'per_page' => 12,
    ]);
    $ending = $service->browse(['ending_soon' => true, 'per_page' => 6]);
    $endingSoon = $ending['rows'];
} catch (\Throwable $e) {
    $vars['chsErrors'] = ['service' => 'Auctions are temporarily unavailable. Please try again later.'];
}

foreach ($result['rows'] as &$row) {
    $row['price_fmt'] = Money::format($row['current_price_minor'], $row['currency']);
    $row['bin_fmt'] = $row['bin_price_minor'] !== null ? Money::format($row['bin_price_minor'], $row['currency']) : '';
}
unset($row);

$vars['chsAuctions'] = $result['rows'];
$vars['chsEndingSoon'] = $endingSoon;
$vars['chsTotal'] = $result['total'];
$vars['chsPage'] = $result['page'];
$vars['chsPages'] = $result['per_page'] > 0 ? (int) ceil($result['total'] / $result['per_page']) : 1;
$vars['chsSearch'] = $search;
$vars['chsSort'] = $sort;
$vars['chsLoggedIn'] = Identity::clientId() !== null;
$vars['chsDetailBase'] = 'index.php?m=cloudhost247services&action=auction&id=';
$vars['chsSellUrl'] = 'index.php?m=cloudhost247services&action=sell';
$vars['chsWatchUrl'] = 'index.php?m=cloudhost247services&action=watchlist';
$vars['chsPagerUrl'] = function ($p) use ($search, $sort) {
    return 'domain-auctions.php?sort=' . urlencode($sort)
        . ($search !== '' ? '&q=' . urlencode($search) : '')
        . '&page=' . max(1, (int) $p);
};

Landing::render($ca, 'chs-auctions', $vars);
