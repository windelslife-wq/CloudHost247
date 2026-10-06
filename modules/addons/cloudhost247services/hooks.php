<?php
/**
 * CloudHost247 Services Suite — WHMCS hooks.
 *
 * The suite plugs into the platform instead of shadowing it: navigation
 * entries beside WHMCS' own menus, assets only on its own pages, club member
 * pricing injected into the real order form, and billing events (InvoicePaid)
 * driving membership activation and auction settlement.
 *
 * @package Chs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use Chs\Core\Audit;
use Chs\Core\Logger;
use Chs\Core\Settings;
use Chs\Services\AuctionService;
use Chs\Services\ClubService;

/* ------------------------------------------------------------- assets ---- */

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $module = isset($_GET['m']) ? (string) $_GET['m'] : '';
    $template = isset($vars['templatefile']) ? (string) $vars['templatefile'] : '';

    static $suiteTemplates = [
        'chs-domain-search', 'chs-bulk-search', 'chs-domain-transfer', 'chs-tld-directory',
        'chs-valuation', 'chs-auctions', 'chs-discount-club', 'chs-whois',
        'chs-website-builder', 'chs-ai-builder', 'chs-online-store', 'chs-hire-expert',
        'chs-marketing', 'chs-logo-maker', 'chs-unified-inbox',
    ];

    if ($module !== 'cloudhost247services' && !in_array($template, $suiteTemplates, true)) {
        return '';
    }

    // SEO: landing PHP files declare their meta payload via Landing::seo();
    // client-portal pages must stay out of the search index either way.
    if ($module === 'cloudhost247services') {
        \Chs\Http\Landing::seo(['noindex' => true]);
    }
    $seoOut = \Chs\Http\Landing::headMarkup($vars, '');

    $base = rtrim(isset($vars['WEB_ROOT']) ? $vars['WEB_ROOT'] : '', '/');
    $out = '<link rel="stylesheet" href="' . $base . '/modules/addons/cloudhost247services/assets/css/client.css">';
    if ($module === 'cloudhost247services' || in_array($template, $suiteTemplates, true)) {
        $out .= '<script src="' . $base . '/modules/addons/cloudhost247services/assets/js/suite.js" defer></script>';
    }
    if ($module === 'cloudhost247services' && isset($_GET['action']) && $_GET['action'] === 'logostudio') {
        $out .= '<script src="' . $base . '/modules/addons/cloudhost247services/assets/js/logo-studio.js" defer></script>';
    }
    return $seoOut . $out;
});

/* --------------------------------------------------------- navigation ---- */

add_hook('ClientAreaPrimaryNavbar', 1, function ($navbar) {
    if (!Settings::bool('service_enabled', true)) {
        return;
    }

    // "Domains" exists in stock WHMCS; add our investing/tooling entries beside it.
    $domains = $navbar->getChild('Domains');
    if ($domains) {
        if (!$domains->getChild('chs-valuation')) {
            $domains->addChild('chs-valuation', [
                'label' => 'Domain Valuation',
                'uri'   => 'domain-valuation.php',
                'order' => 40,
            ]);
        }
        if (!$domains->getChild('chs-auctions')) {
            $domains->addChild('chs-auctions', [
                'label' => 'Domain Auctions',
                'uri'   => 'domain-auctions.php',
                'order' => 41,
            ]);
        }
        if (!$domains->getChild('chs-broker')) {
            $domains->addChild('chs-broker', [
                'label' => 'Domain Broker',
                'uri'   => 'domain-broker.php',
                'order' => 42,
            ]);
        }
        if (!$domains->getChild('chs-club')) {
            $domains->addChild('chs-club', [
                'label' => 'Discount Domain Club',
                'uri'   => 'discount-domain-club.php',
                'order' => 43,
            ]);
        }
        if (!$domains->getChild('chs-tlds')) {
            $domains->addChild('chs-tlds', [
                'label' => 'Domain Extensions',
                'uri'   => 'tld-directory.php',
                'order' => 44,
            ]);
        }
    }

    // A compact suite entry for everything else.
    if (!$navbar->getChild('chs-suite')) {
        $navbar->addChild('chs-suite', [
            'label' => 'Services',
            'order' => 60,
        ]);
        $suite = $navbar->getChild('chs-suite');
        $entries = [
            ['ai-builder', 'AI Website Builder', 'ai-website-builder.php', 10],
            ['logo-maker', 'Logo Maker', 'logo-maker.php', 20],
            ['hire-expert', 'Hire an Expert', 'hire-an-expert.php', 30],
            ['marketing', 'Digital Marketing', 'digital-marketing.php', 40],
            ['inbox', 'Unified Inbox', 'index.php?m=cloudhost247services&action=inbox', 50],
            ['dashboard', 'My Services Dashboard', 'index.php?m=cloudhost247services', 60],
        ];
        foreach ($entries as $entry) {
            $suite->addChild('chs-' . $entry[0], [
                'label' => $entry[1],
                'uri'   => $entry[2],
                'order' => $entry[3],
            ]);
        }
    }
});

/* -------------------------------------------------------- club pricing --- */

/**
 * Inject member pricing into WHMCS' own domain order form. Returning a price
 * here is authoritative — the order is charged the returned amount, so the
 * discount the customer sees is exactly the discount they pay.
 */
add_hook('OrderDomainPricingOverride', 1, function ($vars) {
    try {
        $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
        if ($clientId <= 0) {
            return '';
        }
        $type = isset($vars['type']) ? (string) $vars['type'] : '';
        if (!in_array($type, ['register', 'renew', 'transfer'], true)) {
            return '';
        }
        return (new ClubService())->orderPriceOverride(
            $type,
            isset($vars['domain']) ? (string) $vars['domain'] : '',
            $clientId,
            isset($vars['price']) ? $vars['price'] : ''
        );
    } catch (\Throwable $e) {
        Logger::warning('Club price override failed', ['message' => $e->getMessage()]);
        return '';
    }
});

/* --------------------------------------------------------- billing events  */

add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
    if ($invoiceId <= 0) {
        return;
    }
    try {
        (new ClubService())->invoicePaid($invoiceId);
        (new AuctionService())->invoicePaid($invoiceId);
    } catch (\Throwable $e) {
        Logger::error('InvoicePaid handling failed', ['invoice' => $invoiceId, 'message' => $e->getMessage()]);
    }
});

/* ---------------------------------------------------- housekeeping hooks -- */

add_hook('DailyCronJob', 1, function () {
    try {
        (new ClubService())->expireDue();
        (new AuctionService())->lapseOverdueInvoices();

        // Cache floors: lookups and whois responses.
        if (\Chs\Core\Db::tableExists('lookup_cache')) {
            \Chs\Core\Db::exec(
                'DELETE FROM ' . \Chs\Core\Db::t('lookup_cache') . ' WHERE expires_at < ?',
                [\Chs\Core\Clock::now()]
            );
        }
        if (\Chs\Core\Db::tableExists('whois_cache')) {
            \Chs\Core\Db::exec(
                'DELETE FROM ' . \Chs\Core\Db::t('whois_cache') . ' WHERE expires_at < ?',
                [\Chs\Core\Clock::now()]
            );
        }
        \Chs\Core\RateLimiter::purge();
    } catch (\Throwable $e) {
        Logger::error('Daily housekeeping failed', ['message' => $e->getMessage()]);
    }
});
