<?php
/**
 * CLOUDHOST247 — Website Builder hub.
 *
 * There is no single fake "builder" on this host: the page routes visitors to
 * the three build paths that genuinely exist — self-hosted platforms on our
 * hosting plans, the professionally delivered Website Design service, and the
 * AI-assisted draft flow (when its provider is configured by an admin — in
 * which case it says so, honestly).
 */

use Chs\Http\Landing;
use Chs\Services\AiBuilderService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-website-builder', 'Build Your Website', 'website-builder.php');

Landing::seo([
    'title'       => 'Build a Website — hosting, design or AI-assist | CLOUDHOST247',
    'description' => 'Three honest paths to a site on CLOUDHOST247: host and build it yourself, have our '
        . 'design service deliver it, or start from an AI-drafted structure reviewed by a human.',
    'canonical'   => 'website-builder.php',
    'og_title'    => 'Build your website — CLOUDHOST247',
    'og_desc'     => 'Self-host on our plans, get it designed for you, or start from an AI draft.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebPage',
        'name' => 'Build Your Website', 'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$aiConfigured = ['configured' => false, 'missing' => []];
try {
    if (Landing::moduleReady()) {
        $aiConfigured = (new AiBuilderService())->status();
    }
} catch (\Throwable $e) {
    /* the page works without module data */
}

Landing::render($ca, 'chs-website-builder', [
    'chsAiConfigured' => !empty($aiConfigured['configured']),
    'chsAiMissing'    => isset($aiConfigured['missing']) ? $aiConfigured['missing'] : [],
    'chsAiUrl'        => 'index.php?m=cloudhost247services&action=aibuilder',
    'chsHostingUrl'   => 'wordpress-hosting.php',
    'chsCpanelUrl'    => 'cpanel-hosting.php',
    'chsDesignUrl'    => 'website-design.php',
    'chsExpertUrl'    => 'hire-an-expert.php',
    'chsStoreUrl'     => 'online-store.php',
]);
