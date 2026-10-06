<?php
/**
 * CLOUDHOST247 — Logo Maker landing.
 *
 * The studio in the client portal is deterministic and local: it renders real
 * SVG from your inputs with palettes and layouts from the engine catalogue.
 * PNG export exposes Imagick availability truthfully instead of pretending.
 */

use Chs\Http\Landing;
use Chs\Services\LogoEngine;
use Chs\Services\LogoService;
use WHMCS\ClientArea;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/modules/addons/cloudhost247services/autoload.php';

$ca = Landing::start('chs-logo-maker', 'Logo Maker', 'logo-maker.php');

Landing::seo([
    'title'       => 'Logo Maker — deterministic SVG studio | CLOUDHOST247',
    'description' => 'Design a real, vector logo in seconds: four concept styles, curated palettes and fonts, '
        . 'saved projects and SVG export that scales everywhere. PNG only when the server can deliver it.',
    'canonical'   => 'logo-maker.php',
    'og_title'    => 'Logo Maker — CLOUDHOST247',
    'og_desc'     => 'Real vectors, four concepts, honest export formats.',
    'jsonld'      => [
        '@context' => 'https://schema.org', '@type' => 'WebApplication',
        'name' => 'CLOUDHOST247 Logo Maker',
        'applicationCategory' => 'DesignApplication',
        'operatingSystem' => 'Web',
        'isPartOf' => ['@type' => 'WebSite', 'name' => 'CLOUDHOST247'],
    ],
]);

$vars = [];
$errors = [];
if (!Landing::moduleReady()) {
    Landing::render($ca, 'chs-logo-maker', Landing::unavailableVars('The Logo Maker'));
    exit;
}

try {
    $vars['chsConcepts'] = LogoEngine::conceptKeys();
    $vars['chsPalettes'] = array_keys(LogoEngine::palettes());
    $vars['chsIndustries'] = array_keys(LogoEngine::industries());
    $vars['chsPngAvailable'] = (new LogoService())->pngAvailable();
} catch (\Throwable $e) {
    $errors = ['service' => 'The logo studio is temporarily unavailable.'];
}

$vars['chsErrors'] = $errors;
$vars['chsStudioUrl'] = 'index.php?m=cloudhost247services&action=logostudio';
$vars['chsLoggedIn'] = \Chs\Core\Identity::clientId() !== null;
Landing::render($ca, 'chs-logo-maker', $vars);
