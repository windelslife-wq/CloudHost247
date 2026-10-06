<?php
/**
 * CLOUDHOST247 — Discount Domain Club landing page.
 *
 * Membership plans come straight from the database via ClubService; the page
 * shows exactly what discounted registrations/renewals will cost and routes
 * the join into the client portal (sign-in required — the checkout is real).
 */

use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Http\Landing;
use Chs\Services\ClubService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-discount-club', 'Discount Domain Club', 'discount-domain-club.php');

Landing::seo([
    'title'       => 'Discount Domain Club — member pricing on every eligible TLD | CLOUDHOST247',
    'description' => 'Join the Discount Domain Club and pay member pricing on registrations, renewals and '
        . 'transfers— the same discounted figure charged at checkout, administrable TLD by TLD.',
    'canonical'   => 'discount-domain-club.php',
    'og_title'    => 'Discount Domain Club — CLOUDHOST247',
    'og_desc'     => 'Member pricing applied automatically at checkout — no coupons, no guesswork.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'Discount Domain Club', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-discount-club', Landing::unavailableVars('The Discount Domain Club'));
    exit;
}

$plans = [];
$errors = [];
try {
    $service = new ClubService();
    $plans = $service->publicPlans();
    foreach ($plans as &$plan) {
        $plan['price_fmt'] = Money::format((int) $plan['price_minor'], $plan['currency']);
        $plan['discount_fmt'] = rtrim(rtrim(number_format((float) $plan['discount_percent'], 2), '0'), '.') . '%';
        $plan['tld_preview'] = array_slice($plan['tlds'], 0, 8);
        $example = null;
        foreach ($plan['tlds'] as $tldRow) {
            if ($tldRow['tld'] === 'com') {
                $example = $tldRow;
                break;
            }
        }
        if ($example === null && $plan['tlds']) {
            $example = $plan['tlds'][0];
        }
        $plan['example_tld'] = $example ? '.' . $example['tld'] : '';
        $plan['example_pct'] = $example
            ? rtrim(rtrim(number_format($example['discount_percent'] !== null
                ? (float) $example['discount_percent'] : (float) $plan['discount_percent'], 2), '0'), '.') . '%'
            : '';
    }
    unset($plan);
} catch (\Throwable $e) {
    $errors = ['service' => 'Membership plans are temporarily unavailable.'];
}

$vars['chsPlans'] = $plans;
$vars['chsErrors'] = $errors;
$vars['chsJoinUrl'] = 'index.php?m=cloudhost247services&action=club';
$vars['chsDirectoryUrl'] = 'tld-directory.php';
$vars['chsSearchUrl'] = 'cart.php?a=add&domain=register&query=';
$vars['chsLoggedIn'] = Identity::clientId() !== null;

Landing::render($ca, 'chs-discount-club', $vars);
