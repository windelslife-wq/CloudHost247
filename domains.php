<?php
/**
 * CLOUDHOST247 — Domain Services & Marketplace section page.
 *
 * The eight domain services, grouped in three columns. Every entry is a real
 * navigation component into a working service — search, transfer, extensions,
 * auctions, appraisal, club, WHOIS and bulk search — not a decorative card.
 */

use Chs\Http\Landing;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-domains', 'Domain Services', 'domains.php');

Landing::seo([
    'title'       => 'Domain Services — search, transfer, invest, manage | CLOUDHOST247',
    'description' => 'Every domain service in one place: live domain search, transfers, the full extension '
        . 'directory, auctions, appraisals, the Discount Domain Club, WHOIS lookup and bulk search.',
    'canonical'   => 'domains.php',
    'og_title'    => 'CLOUDHOST247 Domain Services',
    'og_desc'     => 'Search, transfer, invest and manage domains — all backed by live systems.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        'name' => 'CLOUDHOST247 Domain Services',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

Landing::render($ca, 'chs-domains', [
    'chsServices' => [
        [
            'title' => 'Find a Domain',
            'items' => [
                [
                    'icon'  => 'fa-search',
                    'title' => 'Search for Domain Names',
                    'text'  => 'Live availability against the registry chain, with today\'s register, renew and transfer prices before you commit.',
                    'url'   => 'domain-search.php',
                    'cta'   => 'Search a domain',
                ],
                [
                    'icon'  => 'fa-exchange-alt',
                    'title' => 'Transfer Domain Names',
                    'text'  => 'Move a domain to CloudHost247 with an eligibility pre-check, an honest price quote and a tracked, visible status.',
                    'url'   => 'domain-transfer.php',
                    'cta'   => 'Start a transfer',
                ],
                [
                    'icon'  => 'fa-tags',
                    'title' => 'gTLD Domain Extensions',
                    'text'  => 'The full extension directory — every TLD we sell, database-driven pricing, badges and capabilities.',
                    'url'   => 'tld-directory.php',
                    'cta'   => 'Browse extensions',
                ],
            ],
        ],
        [
            'title' => 'Domain Investing',
            'items' => [
                [
                    'icon'  => 'fa-gavel',
                    'title' => 'Auctions for Domain Names',
                    'text'  => 'A real auction floor: bids, anti-sniping, reserve pricing and invoice-settled wins.',
                    'url'   => 'domain-auctions.php',
                    'cta'   => 'See the auctions',
                ],
                [
                    'icon'  => 'fa-chart-line',
                    'title' => 'Appraise Domain Name Value',
                    'text'  => 'A transparent, factor-by-factor valuation with an honest estimate — never a guaranteed sale price.',
                    'url'   => 'domain-valuation.php',
                    'cta'   => 'Appraise a domain',
                ],
                [
                    'icon'  => 'fa-percent',
                    'title' => 'Discount Domain Club',
                    'text'  => 'Membership pricing on eligible registrations and renewals, applied automatically at checkout.',
                    'url'   => 'discount-domain-club.php',
                    'cta'   => 'See membership plans',
                ],
            ],
        ],
        [
            'title' => 'Domain Tools & Services',
            'items' => [
                [
                    'icon'  => 'fa-user-secret',
                    'title' => 'Find a Domain Owner (WHOIS)',
                    'text'  => 'Live registry WHOIS with caching and rate limits — whatever the registry publishes, nothing invented.',
                    'url'   => 'whois-lookup.php',
                    'cta'   => 'Run a WHOIS lookup',
                ],
                [
                    'icon'  => 'fa-list',
                    'title' => 'Bulk Domain Search',
                    'text'  => 'Check a whole list — or keywords across every extension — in one pass, with worker-backed processing.',
                    'url'   => 'bulk-domain-search.php',
                    'cta'   => 'Bulk search',
                ],
            ],
        ],
    ],
    'chsPortalUrl' => 'index.php?m=cloudhost247services',
    'chsLoggedIn'  => \Chs\Core\Identity::clientId() !== null,
]);
