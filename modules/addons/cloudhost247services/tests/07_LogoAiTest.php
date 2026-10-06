<?php

require __DIR__ . '/bootstrap.php';

use Chs\Core\Db;
use Chs\Core\NotFoundException;
use Chs\Core\ProviderException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\Settings;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\ValidationException;
use Chs\Providers\Ai\HttpAiProvider;
use Chs\Services\AiBuilderService;
use Chs\Services\LogoEngine;
use Chs\Services\LogoService;

$gateway = chs_boot();
chs_seed_clients($gateway);
chs_freeze();

T::section('Logo engine — palette, font and industry libraries are real');
T::ok('palettes listed', count(LogoEngine::palettes()) >= 6);
T::ok('font stacks listed', count(LogoEngine::fontStacks()) >= 4);
T::ok('industries listed', count(LogoEngine::industries()) >= 8);
T::eq('four concepts offered', ['wordmark', 'monogram', 'combo', 'badge'], LogoEngine::conceptKeys());

$engine = new LogoEngine();
$opts = ['company' => 'Riverside Bakery', 'industry' => 'food', 'style' => 'modern', 'palette' => 'ocean'];
foreach (LogoEngine::conceptKeys() as $concept) {
    $svg = $engine->render($concept, $opts);
    T::ok("concept {$concept} renders an SVG",
        strpos($svg, '<svg') !== false && strpos($svg, '</svg>') !== false);
}

T::section('XSS-injected company names are escaped everywhere');
$evil = $engine->render('wordmark', [
    'company' => '<script>alert(1)</script>', 'industry' => 'general', 'style' => 'modern', 'palette' => 'ocean']);
T::ok('no script tag survives', strpos($evil, '<script>') === false);
T::ok('escaped entity present', strpos($evil, '&lt;script&gt;') !== false);

T::section('Monogram initials logic');
$mono = $engine->render('monogram', $opts);
T::ok('initials RB from Riverside Bakery', strpos($mono, 'RB') !== false);
$single = $engine->render('monogram', ['company' => 'Nimbus', 'industry' => 'tech', 'style' => 'modern', 'palette' => 'sunset']);
T::ok('single word initial N', strpos($single, '>N<') !== false);

T::section('Logo service — validate, save, ownership, export');
$svc = new LogoService();
T::throws('blank company rejected', function () use ($svc) {
    $svc->save(11, ['company' => '', 'concept' => 'wordmark']);
}, ValidationException::class);
T::throws('overlong company rejected', function () use ($svc) {
    $svc->save(11, ['company' => str_repeat('A', 60), 'concept' => 'wordmark']);
}, ValidationException::class);

$saved = $svc->save(11, [
    'company' => 'Riverside Bakery', 'industry' => 'food',
    'style' => 'modern', 'palette' => 'forest', 'concept' => 'combo',
]);
T::ok('project row returned', isset($saved['id'], $saved['svg']));
T::eq('concept stored', 'combo', $saved['concept_key']);
T::eq('palette stored', 'forest', $saved['palette']);
T::ok('svg stored with project', strpos($saved['svg'], '<svg') !== false);
T::eq('owned list contains project', 1, count($svc->listFor(11)));
T::throws('foreign project unreachable', function () use ($svc) {
    $svc->getFor(22, 1);
}, NotFoundException::class);
T::throws('deleting foreign project refused', function () use ($svc) {
    $svc->delete(22, 1);
}, NotFoundException::class);
T::ok('foreign list is empty', $svc->listFor(22) === []);

$export = $svc->export(11, (int) $saved['id'], 'svg');
T::eq('svg export mime', 'image/svg+xml', $export['mime']);
T::eq('export filename slugged', 'riverside-bakery-logo.svg', $export['filename']);
T::eq('export data is the stored svg', $saved['svg'], $export['data']);
T::eq('png availability reports the extension truth', extension_loaded('imagick'), $svc->pngAvailable());
T::ok('png export either works or refuses truthfully — never a crash', (function () use ($svc, $saved) {
    try {
        $out = $svc->export(11, (int) $saved['id'], 'png');
        return $out['mime'] === 'image/png' && $out['data'] !== '';
    } catch (ServiceUnavailableException $e) {
        return strpos($e->getMessage(), 'SVG download always works') !== false;
    }
})());
T::throws('unknown format rejected', function () use ($svc, $saved) {
    $svc->export(11, (int) $saved['id'], 'gif');
}, ValidationException::class);

T::section('Preview + reroll endpoints');
$pre = $svc->preview(['company' => 'Acme Cloud', 'industry' => 'tech', 'style' => 'bold', 'palette' => 'sunset']);
T::eq('preview returns all four concepts', 4, count($pre));
T::ok('preview kv structure', isset($pre['wordmark'], $pre['monogram'], $pre['combo'], $pre['badge']));
$one = $svc->renderOne(['company' => 'Acme Cloud', 'concept' => 'badge'], 'badge');
T::ok('single render works', strpos($one, '<svg') !== false);
T::throws('unknown concept rejected', function () use ($svc) {
    $svc->renderOne(['company' => 'X'], 'hologram');
}, ValidationException::class);

T::section('Saving limit is enforced');
Settings::override('logo_projects_limit', '2');
$svc->save(11, ['company' => 'Second', 'concept' => 'wordmark']);
T::throws('third save over cap refused', function () use ($svc) {
    $svc->save(11, ['company' => 'Third', 'concept' => 'wordmark']);
}, \Chs\Core\ChsException::class);
Settings::override('logo_projects_limit', null);
T::ok('delete frees the slot', (function () use ($svc) {
    $svc->delete(11, 1);
    return Db::count('logo_projects', ['client_id' => 11]) === 1;
})());

T::section('AI builder — honesty first');
$ai = new AiBuilderService();
$status = $ai->status();
T::eq('unconfigured by default', false, $status['configured']);
T::ok('missing list honest', in_array('ai_enabled', $status['missing'], true)
    && in_array('CHS_AI_API_KEY (environment variable)', $status['missing'], true));
T::throws('generate refuses to fake it', function () use ($ai) {
    $ai->generate(11, 'Booking website for a dental practice with insurance info');
}, ProviderNotConfiguredException::class);
T::throws('too-short brief rejected even when configured', function () {
    Settings::override('ai_enabled', '1');
    Settings::override('ai_endpoint', 'https://ai.example.test/v1');
    Settings::override('ai_model', 'gpt-4o-mini');
    Settings::override('ai_api_key', 'sk-test');
    try {
        (new \Chs\Services\AiBuilderService(new \Chs\Providers\Ai\HttpAiProvider(function () {
            return ['code' => 500, 'body' => '{}'];
        })))->generate(11, 'tiny');
    } finally {
        Settings::resetOverrides();
    }
}, ValidationException::class);

T::section('AI provider — OpenAI-compatible round trip through injected poster');
Settings::override('ai_enabled', '1');
Settings::override('ai_endpoint', 'https://ai.example.test/v1');
Settings::override('ai_model', 'gpt-4o-mini');
Settings::override('ai_api_key', 'sk-test');
$posted = [];
$outline = [
    'site_title' => 'Evergreen Dental', 'tagline' => 'Modern family dentistry',
    'pages' => [[
        'slug' => 'home', 'title' => 'Home',
        'purpose' => 'First impression and booking entry',
        'sections' => [
            ['type' => 'hero', 'heading' => 'Gentle dentistry for every family', 'body' => 'Book online in two minutes.', 'cta' => 'Book a visit'],
            ['type' => 'contact', 'heading' => 'Visit us', 'body' => '12 Lake Road. Mon–Sat.', 'cta' => null],
        ],
    ], [
        'slug' => 'services', 'title' => 'Treatments',
        'purpose' => 'Catalog of care',
        'sections' => [['type' => 'features', 'heading' => 'What we treat', 'body' => 'Checkups, whitening, implants.', 'cta' => null]],
    ]],
    'seo' => ['title' => 'Evergreen Dental — family dentistry', 'description' => 'Book gentle family dentistry online.', 'keywords' => ['dentist', 'family']],
    'design' => ['palette' => ['#0B3D91', '#F7FAFC'], 'mood' => 'calm', 'typography' => 'Modern sans'],
    'images' => ['hero-clinic.jpg'],
];
$poster = function ($url, $headers, $body, $timeout) use (&$posted, $outline) {
    $posted[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => "```json\n" . json_encode($outline) . "\n```"]]]])];
};
$ai2 = new AiBuilderService(new HttpAiProvider($poster));
$gen = $ai2->generate(11, 'Dental practice needs a confident, modern booking site with insurance details', ['industry' => 'healthcare', 'locale' => 'en', 'pages' => 4]);
T::ok('endpoint path appended', strpos($posted[0]['url'], 'https://ai.example.test/v1/chat/completions') === 0);
T::ok('auth header carries the key', in_array('Authorization: Bearer sk-test', $posted[0]['headers'], true));
T::ok('model from settings in payload', $posted[0]['body']['model'] === 'gpt-4o-mini');
T::ok('system prompt demands strict JSON', strpos($posted[0]['body']['messages'][0]['content'], 'STRICT JSON') !== false);
T::eq('site title normalised', 'Evergreen Dental', $gen['result']['site_title']);
T::eq('two pages retained', 2, count($gen['result']['pages']));
T::ok('sections capped + typed', count($gen['result']['pages'][0]['sections']) === 2
    && $gen['result']['pages'][0]['sections'][0]['type'] === 'hero');
T::ok('seo decoded', $gen['result']['seo']['title'] !== '');
T::eq('status complete', 'complete', $gen['status']);
T::eq('provider id on row', 'http', $gen['provider']);
T::eq('model recorded', 'gpt-4o-mini', Settings::string('ai_model', '') ? $gen['model'] : '-');
T::ok('history lists generation', count($ai2->history(11)) === 1);
T::throws('foreign generation unreachable', function () use ($ai2) {
    $ai2->getFor(22, 1);
}, NotFoundException::class);

T::section('AI provider — failure modes never fake');
$bad500 = new AiBuilderService(new HttpAiProvider(function () {
    return ['code' => 500, 'body' => '{"error":"boom"}'];
}));
T::throws('HTTP error surfaces truthfully', function () use ($bad500) {
    $bad500->generate(11, str_repeat('Booking engine for a climbing gym ', 2));
}, ProviderException::class);
$badJson = new AiBuilderService(new HttpAiProvider(function () {
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'sure thing boss']]]])];
}));
T::throws('non-JSON content rejected', function () use ($badJson) {
    $badJson->generate(11, str_repeat('Booking engine for a climbing gym ', 2));
}, ProviderException::class);
$emptyPages = new AiBuilderService(new HttpAiProvider(function () {
    return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => json_encode(['pages' => []])]]]])];
}));
T::throws('outline without pages rejected', function () use ($emptyPages) {
    $emptyPages->generate(11, str_repeat('Booking engine for a climbing gym ', 2));
}, ProviderException::class);
T::ok('failed attempts did NOT persist rows', Db::count('ai_generations') === 1);
Settings::resetOverrides();

T::finish();
