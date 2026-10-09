<?php
/**
 * CLOUDHOST247 — Domain transfer landing.
 *
 * The pre-check (TLD sold, EPP syntax, live WHOIS state) and the price quote
 * run through the module's transfer service — server-side, honest. Signed-in
 * customers can create the tracked transfer right here (a real WHMCS invoice
 * is issued; the transfer is submitted once paid). Guests are routed to the
 * WHMCS cart transfer flow. The page documents the state machine a transfer
 * actually passes through.
 */

use Chs\Core\Csrf;
use Chs\Core\Identity;
use Chs\Core\Money;
use Chs\Http\Landing;
use Chs\Services\TransferService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-domain-transfer', 'Transfer a Domain', 'domain-transfer.php');

Landing::seo([
    'title'       => 'Transfer a Domain — transparent process, no lock-in theatre | CLOUDHOST247',
    'description' => 'Move your domain to CloudHost247 with an honest, step-by-step transfer workflow: '
        . 'unlock, EPP code, approval, completion — with pre-check WHOIS and transfer pricing before you commit.',
    'canonical'   => 'domain-transfer.php',
    'og_title'    => 'Transfer your domain — CLOUDHOST247',
    'og_desc'     => 'Unlock, authorise, approve. A transfer with visible states, not a black box.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'HowTo',
        'name' => 'How to transfer a domain',
        'step' => [
            ['@type' => 'HowToStep', 'name' => 'Unlock & authorise', 'text' => 'Unlock the domain at your current registrar and request the EPP/auth code.'],
            ['@type' => 'HowToStep', 'name' => 'Order the transfer', 'text' => 'Submit your domain and auth code through our transfer flow.'],
            ['@type' => 'HowToStep', 'name' => 'Approve', 'text' => 'Confirm the registry approval email sent to the registrant address.'],
            ['@type' => 'HowToStep', 'name' => 'Completion', 'text' => 'Transfers finish in five to seven days; a renewal year is added on completion.'],
        ],
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [
    'chsSearchUrl' => 'cart.php?a=add&domain=transfer&query=',
    'chsWhoisUrl'   => 'whois-lookup.php',
    'chsDirectoryUrl' => 'tld-directory.php',
    'chsBrokerUrl' => 'domain-broker.php',
    'chsErrors' => [],
    'chsOld' => ['domain' => '', 'epp' => ''],
    'chsEligibility' => null,
    'chsQuote' => null,
    'chsCreated' => null,
    'chsLoggedIn' => Identity::clientId() !== null,
    'chsPortalTransfersUrl' => 'index.php?m=cloudhost247services&action=transfers',
];

if (Landing::moduleReady() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = isset($_POST['_chs_token']) ? (string) $_POST['_chs_token'] : '';
    if (!Csrf::verify($token)) {
        $vars['chsErrors'] = ['token' => 'Your session expired — submit the form again.'];
    } else {
        $vars['chsOld'] = [
            'domain' => isset($_POST['chs_domain']) ? (string) $_POST['chs_domain'] : '',
            'epp'    => isset($_POST['chs_epp']) ? (string) $_POST['chs_epp'] : '',
        ];
        try {
            $service = new TransferService();
            $clientId = Identity::clientId();
            if ($clientId) {
                // Signed in: create the tracked transfer + real invoice.
                $outcome = $service->create($clientId, $vars['chsOld']['domain'], $vars['chsOld']['epp']);
                $vars['chsCreated'] = [
                    'invoice_id' => $outcome['invoice_id'],
                    'url'        => $outcome['url'],
                    'transfer_id' => (int) $outcome['transfer']['id'],
                    'domain'     => $outcome['transfer']['domain'],
                ];
            } else {
                // Guest: pre-check + quote, then hand off to the cart flow.
                $eligibility = $service->checkEligibility($vars['chsOld']['domain'], $vars['chsOld']['epp']);
                $vars['chsEligibility'] = $eligibility;
                if ($eligibility['quote']) {
                    $vars['chsQuote'] = [
                        'final_fmt' => Money::format((int) $eligibility['quote']['final_minor'], $eligibility['quote']['currency']),
                        'currency'  => $eligibility['quote']['currency'],
                        'discount'  => $eligibility['quote']['discount_percent'],
                    ];
                }
            }
        } catch (\Chs\Core\ValidationException $e) {
            $vars['chsErrors'] = $e->fieldErrors();
        } catch (\Chs\Core\RateLimitException $e) {
            $vars['chsErrors'] = ['limit' => $e->getMessage()];
        } catch (\Throwable $e) {
            $vars['chsErrors'] = ['service' => $e->getMessage()];
        }
    }
}

$vars['csrf_field'] = Csrf::field();

Landing::render($ca, 'chs-domain-transfer', $vars);
