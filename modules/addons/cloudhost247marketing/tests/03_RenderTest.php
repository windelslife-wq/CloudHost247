<?php
/** Block library, renderer, design normalisation, merge tags and the template library. */
require_once __DIR__ . '/bootstrap.php';

use Ch247Mkt\Campaign\BlockLibrary;
use Ch247Mkt\Campaign\Designer;
use Ch247Mkt\Campaign\Personalizer;
use Ch247Mkt\Campaign\Renderer;
use Ch247Mkt\Campaign\TemplateLibrary;
use Ch247Mkt\Campaign\TemplateService;
use Ch247Mkt\Core\Db;

ch247m_boot();
ch247m_as_super_admin();

/* ===================================================== block library == */

T::section('Block library');
$blocks = BlockLibrary::blocks();
foreach (['text', 'heading', 'image', 'button', 'divider', 'spacer', 'social', 'video', 'logo', 'html', 'product', 'coupon', 'pricing', 'footer'] as $type) {
    T::ok('block "' . $type . '" exists', isset($blocks[$type]));
}
$layouts = BlockLibrary::layouts();
foreach (['col-1', 'col-2', 'col-3', 'col-4', 'full-width', 'image-text', 'text-image'] as $layout) {
    T::ok('layout "' . $layout . '" exists', isset($layouts[$layout]));
}

T::section('Block normalisation');
$clean = BlockLibrary::normalizeBlock(['type' => 'button', 'props' => [
    'text' => 'Buy', 'radius' => '12', 'fullWidth' => '1', 'evil' => '<script>',
]]);
T::eq('known string prop kept', 'Buy', $clean['props']['text']);
T::eq('numeric prop cast', 12, $clean['props']['radius']);
T::eq('boolean prop cast', true, $clean['props']['fullWidth']);
T::ok('unknown prop dropped', !array_key_exists('evil', $clean['props']));
T::ok('missing props defaulted', array_key_exists('backgroundColor', $clean['props']));
T::eq('unknown block type rejected', null, BlockLibrary::normalizeBlock(['type' => 'iframe']));

/* ========================================================== designer == */

T::section('Design normalisation');
$design = Designer::normalize(['rows' => [
    ['layout' => 'col-2', 'cells' => [
        [['type' => 'text', 'props' => ['html' => '<p>Left</p>']]],
        [['type' => 'nope'], ['type' => 'button', 'props' => ['text' => 'Go']]],
    ]],
]]);
T::eq('one row survives', 1, count($design['rows']));
T::eq('two cells', 2, count($design['rows'][0]['cells']));
T::eq('unknown block silently dropped', 1, count($design['rows'][0]['cells'][1]));

$huge = ['rows' => []];
for ($i = 0; $i < 150; $i++) {
    $huge['rows'][] = ['layout' => 'col-1', 'cells' => [[['type' => 'spacer']]]];
}
T::throws('an oversized design is refused, not silently truncated', function () use ($huge) {
    Designer::normalize($huge);
}, \Ch247Mkt\Core\ValidationException::class);

$packed = ['rows' => [['layout' => 'col-1', 'cells' => [array_fill(0, 120, ['type' => 'spacer'])]]]];
T::eq('blocks per column are capped', Designer::MAX_BLOCKS_PER_CELL, count(Designer::normalize($packed)['rows'][0]['cells'][0]));

$wide = Designer::normalize(['rows' => [['layout' => 'col-4', 'cells' => [[], [], [], [], [], []]]]]);
T::ok('column count is capped', count($wide['rows'][0]['cells']) <= Designer::MAX_COLUMNS);

T::eq('garbage decodes to a blank design', Designer::blank(), Designer::decode('not json'));

/* ========================================================== renderer == */

T::section('Rendering');
$html = Renderer::render(ch247m_design(), ['subject' => 'Hello', 'preheader' => 'A short preview line']);
T::contains('produces a full document', '<!DOCTYPE', $html);
T::contains('sets a charset', 'charset=', $html);
T::contains('uses tables for layout', '<table', $html);
T::contains('heading text present', 'Hello {{first_name}}', $html);
T::contains('button text present', 'View plans', $html);
T::contains('preheader is included', 'A short preview line', $html);
T::contains('Outlook ghost table present', 'mso', $html);
T::contains('unsubscribe link in the footer', '{{unsubscribe_url}}', $html);

T::section('Renderer hardening');
$evil = [
    'settings' => [],
    'rows' => [[
        'layout' => 'col-1', 'cells' => [[
            ['type' => 'text', 'props' => ['html' => '<p onclick="steal()">Hi<script>alert(1)</script></p>']],
            ['type' => 'button', 'props' => ['text' => 'Click', 'href' => 'javascript:alert(1)']],
            ['type' => 'image', 'props' => ['src' => 'javascript:alert(1)', 'alt' => '"><script>x</script>']],
            ['type' => 'html', 'props' => ['html' => '<script>raw()</script>']],
        ]],
    ]],
];
$out = Renderer::render($evil, ['trusted' => false]);
T::notContains('script tag stripped from rich text', '<script', $out);
T::notContains('inline event handler stripped', 'onclick', $out);
T::notContains('javascript: href rejected', 'javascript:alert', $out);
T::notContains('raw HTML block dropped when untrusted', 'raw()', $out);

$trusted = Renderer::render($evil, ['trusted' => true]);
T::contains('raw HTML block kept for a trusted author', 'raw()', $trusted);
T::notContains('but rich text is still sanitised', 'alert(1)</script>', $trusted);

T::section('URL policy');
T::eq('https allowed', 'https://x.test/a', Renderer::url('https://x.test/a'));
T::eq('mailto allowed', 'mailto:a@b.test', Renderer::url('mailto:a@b.test'));
T::eq('root-relative allowed', '/cart', Renderer::url('/cart'));
T::eq('merge tag allowed', '{{unsubscribe_url}}', Renderer::url('{{unsubscribe_url}}'));
T::eq('javascript rejected', '', Renderer::url('javascript:alert(1)'));
T::eq('data uri rejected', '', Renderer::url('data:text/html;base64,PHN2Zz4='));
T::eq('vbscript rejected', '', Renderer::url('vbscript:msgbox'));
T::eq('whitespace-obfuscated javascript rejected', '', Renderer::url("java\nscript:alert(1)"));

/* ====================================================== personalizer == */

T::section('Merge tags');
foreach (['first_name', 'last_name', 'email', 'company', 'client_id', 'service_name', 'domain', 'invoice_number', 'renewal_date'] as $tag) {
    T::ok('tag {{' . $tag . '}} is supported', isset(Personalizer::TAGS[$tag]));
}

$ctx = ['first_name' => 'Ada', 'email' => 'ada@example.test', 'company' => 'Obi & Sons'];
T::eq('tag substituted', 'Hi Ada', Personalizer::apply('Hi {{first_name}}', $ctx, false));
T::eq('escaping applied in HTML mode', 'Obi &amp; Sons', Personalizer::apply('{{company}}', $ctx, true));
T::eq('no escaping in text mode', 'Obi & Sons', Personalizer::apply('{{company}}', $ctx, false));
T::eq('missing name falls back', 'Hi there', Personalizer::apply('Hi {{first_name}}', [], false));
T::eq('whitespace in braces tolerated', 'Hi Ada', Personalizer::apply('Hi {{ first_name }}', $ctx, false));
// An unresolved tag is blanked rather than printed: a recipient must never
// see "{{not_a_tag}}" in their inbox. Pre-flight is what warns the author.
T::eq('unknown tag is blanked, never shown to the recipient', 'x ', Personalizer::apply('x {{not_a_tag}}', $ctx, false));

$audit = Personalizer::audit('Hello {{first_name}}, your {{service_name}} renews {{renewal_date}}. {{bogus_tag}}', $ctx);
T::ok('audit flags the unknown tag', in_array('bogus_tag', $audit['unknown'], true));
T::ok('audit is not ok when a tag is unknown', !$audit['ok']);
T::ok('audit lists the tags used', in_array('first_name', $audit['used'], true));

$xss = Personalizer::apply('{{first_name}}', ['first_name' => '<img src=x onerror=alert(1)>'], true);
T::notContains('merge values cannot inject markup', '<img', $xss);

/* ==================================================== template library */

T::section('Template library');
$slugs = array_keys(TemplateLibrary::all());
foreach (['welcome', 'newsletter', 'hosting-promotion', 'domain-promotion', 'vps-promotion', 'rdp-promotion',
    'new-product', 'special-offer', 'discount-coupon', 'maintenance-notification', 'service-announcement',
    'holiday-campaign', 'abandoned-cart', 'blank'] as $slug) {
    T::ok('template "' . $slug . '" ships', in_array($slug, $slugs, true));
}

$installed = Db::count('templates');
T::ok('templates were seeded by the migration', $installed >= 14);
$result = TemplateService::seed();
T::eq('re-seeding installs nothing new', 0, $result['installed']);
T::eq('and does not duplicate', $installed, Db::count('templates'));

T::section('Every shipped template renders compliantly');
foreach (TemplateService::all() as $template) {
    if ($template['slug'] === 'blank') {
        continue;
    }
    $rendered = Renderer::render($template['design'], ['subject' => $template['name']]);
    T::contains($template['slug'] . ' has an unsubscribe link', '{{unsubscribe_url}}', $rendered);
    T::contains($template['slug'] . ' carries the postal address tag', '{{physical_address}}', $rendered);
    T::ok($template['slug'] . ' produces real content', strlen($rendered) > 800);
}

T::section('Custom templates');
$system = TemplateService::findBySlug('welcome');
$copy = TemplateService::duplicate((int) $system['id'], 1);
T::ok('a duplicate is editable', (int) $copy['is_system'] === 0);
T::ok('duplicate gets its own slug', $copy['slug'] !== $system['slug']);
TemplateService::update((int) $copy['id'], ['name' => 'My welcome'], 1);
T::eq('custom template can be renamed', 'My welcome', TemplateService::find((int) $copy['id'])['name']);

T::finish();
