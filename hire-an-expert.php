<?php
/**
 * CLOUDHOST247 — Hire an Expert.
 *
 * Service catalogue from the module (types + budget ranges), plus the real
 * request flow in the client portal — quotes arrive as invoices, never as
 * "we'll see" handshakes.
 */

use Chs\Http\Landing;
use Chs\Services\ServiceRequestService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-hire-expert', 'Hire an Expert', 'hire-an-expert.php');

Landing::seo([
    'title'       => 'Hire an Expert — design, build, migrate, manage | CLOUDHOST247',
    'description' => 'A structured brief gets a fixed quote, a named engineer, and an invoice-only settlement. '
        . 'Design, development, ecommerce, SEO, migrations, retainer maintenance and integrations.',
    'canonical'   => 'hire-an-expert.php',
    'og_title'    => 'Hire an Expert — CLOUDHOST247',
    'og_desc'     => 'Fixed quotes, accountable delivery, invoice-only settlement.',
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-hire-expert', Landing::unavailableVars('Expert services'));
    exit;
}

$types = [];
$budgets = [];
$errors = [];
try {
    $service = new ServiceRequestService();
    $types = $service->types();
    $budgets = $service->budgetRanges();
} catch (\Throwable $e) {
    $errors = ['service' => 'The service catalogue is temporarily unavailable.'];
}

$vars['chsTypes'] = $types;
$vars['chsBudgets'] = $budgets;
$vars['chsErrors'] = $errors;
$vars['chsRequestUrl'] = 'index.php?m=cloudhost247services&action=requestnew';
$vars['chsPortalUrl'] = 'index.php?m=cloudhost247services&action=requests';
$vars['chsLoggedIn'] = \Chs\Core\Identity::clientId() !== null;

Landing::render($ca, 'chs-hire-expert', $vars);
