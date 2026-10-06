<?php
/**
 * CLOUDHOST247 — AI Website Builder landing.
 *
 * Honest by construction: if the AI provider is configured for this host, the
 * page routes into the client portal's builder; otherwise it explains exactly
 * what's missing (named settings) and offers the real human-run alternatives.
 * No model silhouettes, no fake prompting UI.
 */

use Chs\Services\AiBuilderService;
use Chs\Http\Landing;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-ai-builder', 'AI Website Builder', 'ai-website-builder.php');

Landing::seo([
    'title'       => 'AI Website Builder — draft with AI, finish with humans | CLOUDHOST247',
    'description' => 'Describe your business and get a structured site draft — pages, sections, SEO meta and '
        . 'design cues — then hand it to our experts to finish, or build on it yourself.',
    'canonical'   => 'ai-website-builder.php',
    'og_title'    => 'AI Website Builder — CLOUDHOST247',
    'og_desc'     => 'AI-drafted structure, human-finished quality. Availability shown honestly per host.',
]);

$configured = false;
$missing = [];
if (Landing::moduleReady()) {
    try {
        $status = (new AiBuilderService())->status();
        $configured = !empty($status['configured']);
        $missing = $status['missing'];
    } catch (\Throwable $e) {
        $configured = false;
    }
}

$vars = [
    'chsConfigured' => $configured,
    'chsMissing'    => $missing,
    'chsAdminInstallUrl' => 'index.php?m=cloudhost247services',
    'chsBuilderUrl'   => 'index.php?m=cloudhost247services&action=aibuilder',
    'chsDesignUrl'    => 'website-design.php',
    'chsExpertUrl'    => 'hire-an-expert.php',
];
if (!$configured) {
    Landing::seo(['noindex' => true, 'robots' => 'noindex,follow']);
    $vars = array_merge($vars, []);
}
Landing::render($ca, 'chs-ai-builder', $vars);
