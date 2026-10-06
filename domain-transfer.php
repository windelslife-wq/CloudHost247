<?php
/**
 * CLOUDHOST247 — Domain transfer landing.
 *
 * Transfers run through WHMCS' native transfer order flow; this page documents
 * the state machine a transfer actually passes through, with honest timing and
 * pre-flight requirements, and pre-checks the current WHOIS in one click.
 */

use Chs\Http\Landing;
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

Landing::render($ca, 'chs-domain-transfer', [
    'chsSearchUrl' => 'cart.php?a=add&domain=transfer&query=',
    'chsWhoisUrl'   => 'whois-lookup.php',
    'chsDirectoryUrl' => 'tld-directory.php',
    'chsBrokerUrl' => 'domain-broker.php',
]);
