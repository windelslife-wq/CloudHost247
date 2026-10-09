<?php
/**
 * CLOUDHOST247 — Bulk domain search landing.
 *
 * Runs through the module's bulk-search service: the list (or keywords) is
 * validated, deduped and capped; small lists answer inline, large lists are
 * processed by the DOMAIN_BULK_SEARCH worker job. Results show live provider
 * availability with server-side pricing, support selection into the WHMCS
 * cart, pagination and CSV export. Availability is never fabricated — an
 * unanswered lookup is shown as unknown.
 */

use Chs\Core\Csrf;
use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Http\Landing;
use Chs\Services\BulkSearchService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-bulk-search', 'Bulk Domain Search', 'bulk-domain-search.php');

Landing::seo([
    'title'       => 'Bulk Domain Search — check a whole list in one go | CLOUDHOST247',
    'description' => 'Paste up to 500 names or keywords, and check availability across the live registry '
        . 'chain in one pass — processed in the background, exportable, priced server-side.',
    'canonical'   => 'bulk-domain-search.php',
    'og_title'    => 'Bulk Domain Search — CLOUDHOST247',
    'og_desc'     => 'One paste, one search, the full answer for your whole list.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'Bulk Domain Search', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [
    'chsSearchFallback' => 'cart.php?a=add&domain=register&query=',
    'chsTransferUrl' => 'domain-transfer.php',
    'chsDirectoryUrl' => 'tld-directory.php',
    'chsSearchUrl' => 'domain-search.php',
    'chsErrors' => [],
    'chsOld' => ['domains' => ''],
    'chsMax' => 500,
];

if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-bulk-search', $vars + Landing::unavailableVars('Bulk domain search'));
    exit;
}

$clientId = Identity::clientId();
$service = new BulkSearchService();

// CSV export of one of the caller's own searches.
$exportId = isset($_GET['export']) ? (int) $_GET['export'] : 0;
if ($exportId > 0) {
    try {
        $search = $service->getSearchFor($exportId, $clientId);
        $csv = $service->exportCsv($exportId);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="domain-search-' . $exportId . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    } catch (\Throwable $e) {
        $vars['chsErrors'] = ['export' => 'That search could not be exported.'];
    }
}

// Submit a new bulk search.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = isset($_POST['_chs_token']) ? (string) $_POST['_chs_token'] : '';
    if (!Csrf::verify($token)) {
        $vars['chsErrors'] = ['token' => 'Your session expired — submit the form again.'];
        $vars['chsOld']['domains'] = isset($_POST['chs_domains']) ? (string) $_POST['chs_domains'] : '';
    } else {
        try {
            $outcome = $service->submit(isset($_POST['chs_domains']) ? (string) $_POST['chs_domains'] : '', $clientId);
            header('Location: bulk-domain-search.php?id=' . (int) $outcome['search']['id'], true, 303);
            exit;
        } catch (\Chs\Core\ValidationException $e) {
            $vars['chsErrors'] = $e->fieldErrors();
            $vars['chsOld']['domains'] = isset($_POST['chs_domains']) ? (string) $_POST['chs_domains'] : '';
        } catch (\Chs\Core\RateLimitException $e) {
            $vars['chsErrors'] = ['limit' => $e->getMessage()];
        } catch (\Throwable $e) {
            $vars['chsErrors'] = ['service' => 'Bulk search is temporarily unavailable. Please try again later.'];
        }
    }
}

// Results view for one search.
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($viewId > 0) {
    try {
        $search = $service->getSearchFor($viewId, $clientId);
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $onlyAvailable = isset($_GET['only_available']) && $_GET['only_available'] === '1';
        $result = $service->results($viewId, $page, 25, $onlyAvailable);
        foreach ($result['rows'] as &$row) {
            $row['register_fmt'] = $row['register_discounted_minor'] !== null
                ? Money::format((int) $row['register_discounted_minor'], $row['currency'])
                : ($row['register_minor'] !== null ? Money::format((int) $row['register_minor'], $row['currency']) : null);
            $row['renew_fmt'] = $row['renew_minor'] !== null
                ? Money::format((int) $row['renew_minor'], $row['currency']) : null;
        }
        unset($row);
        $vars['chsSearch'] = $search;
        $vars['chsRows'] = $result['rows'];
        $vars['chsTotal'] = $result['total'];
        $vars['chsAvailable'] = $result['available'];
        $vars['chsPage'] = $result['page'];
        $vars['chsPages'] = max(1, (int) ceil($result['total'] / $result['per_page']));
        $vars['chsOnlyAvailable'] = $onlyAvailable;
        $vars['chsCartUrl'] = 'cart.php?a=add&domain=register&bulk=1';
    } catch (\Throwable $e) {
        $vars['chsErrors'] = ['view' => 'That search could not be loaded.'];
    }
}

$vars['chsMax'] = \Chs\Core\Settings::int('bulk_max_domains', 500);
$vars['csrf_field'] = Csrf::field();
$vars['chsLoggedIn'] = $clientId !== null;
$vars['chsPortalBulkUrl'] = 'index.php?m=cloudhost247services&action=bulk';

Landing::render($ca, 'chs-bulk-search', $vars);
