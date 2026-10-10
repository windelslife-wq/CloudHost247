<?php
/**
 * CloudHost247 Tools - Catalog registry tests.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Catalog.php';

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
}

$reg = require __DIR__ . '/../config/cloudhost247-tools.php';

ok('registry has 91 tools (got ' . count($reg['tools']) . ')', count($reg['tools']) === 91);
ok('registry has 9 categories', count($reg['categories']) === 9);
ok('catalog count is 91', CloudHost247ToolsCatalog::count() === 91);

$expected = [
    'dns' => 15, 'ip' => 22, 'developer' => 14, 'designer' => 4,
    'webmaster' => 6, 'network' => 5, 'security' => 4,
    'productivity' => 20, 'gaming' => 1,
];
$cats = CloudHost247ToolsCatalog::categories();
foreach ($expected as $id => $n) {
    ok("category $id has $n tools (got " . (isset($cats[$id]) ? $cats[$id]['count'] : 'missing') . ')',
        isset($cats[$id]) && $cats[$id]['count'] === $n);
}
ok('category counts sum to 91', array_sum($expected) === 91);

// Every tool must be complete and well-formed.
$required = ['id', 'handler', 'slug', 'name', 'category', 'icon', 'description',
    'inputs', 'exec', 'route', 'status', 'seo_title', 'seo_description', 'related'];
$ids = [];
$slugs = [];
$routes = [];
$problems = [];
foreach ($reg['tools'] as $slug => $t) {
    foreach ($required as $f) {
        if (!array_key_exists($f, $t)) {
            $problems[] = "$slug missing field $f";
        }
    }
    if (($t['slug'] ?? null) !== $slug) {
        $problems[] = "$slug key/slug mismatch";
    }
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
        $problems[] = "$slug is not a clean kebab-case slug";
    }
    if (($t['route'] ?? '') !== '/tools/' . $slug) {
        $problems[] = "$slug route mismatch: " . ($t['route'] ?? '');
    }
    if (!isset($reg['categories'][$t['category'] ?? ''])) {
        $problems[] = "$slug has unknown category";
    }
    if (!in_array($t['exec'] ?? '', ['client', 'server', 'hybrid'], true)) {
        $problems[] = "$slug has invalid exec";
    }
    if (strlen($t['seo_title'] ?? '') < 10 || strlen($t['seo_title']) > 70) {
        $problems[] = "$slug seo_title length " . strlen($t['seo_title'] ?? '');
    }
    if (strlen($t['seo_description'] ?? '') < 50 || strlen($t['seo_description']) > 170) {
        $problems[] = "$slug seo_description length " . strlen($t['seo_description'] ?? '');
    }
    if (strlen($t['description'] ?? '') < 20) {
        $problems[] = "$slug description too short";
    }
    $ids[] = $t['id'] ?? null;
    $slugs[] = $slug;
    $routes[] = $t['route'] ?? '';
}
ok('all tools well-formed (' . count($problems) . ' problems: ' . implode('; ', array_slice($problems, 0, 6)) . ')',
    empty($problems));
ok('tool ids unique', count(array_unique($ids)) === 91);
ok('slugs unique', count(array_unique($slugs)) === 91);
ok('routes unique', count(array_unique($routes)) === 91);
ok('ids are 1..91', min($ids) === 1 && max($ids) === 91);

// Handlers must be unique too - one function per tool.
$handlers = array_column($reg['tools'], 'handler');
ok('handlers unique', count(array_unique($handlers)) === 91);

// Related tools must point at real, existing slugs.
$badRel = [];
foreach ($reg['tools'] as $slug => $t) {
    foreach ((array) $t['related'] as $rel) {
        if (!isset($reg['tools'][$rel])) {
            $badRel[] = "$slug -> $rel";
        }
        if ($rel === $slug) {
            $badRel[] = "$slug relates to itself";
        }
    }
}
ok('related slugs all resolve (' . implode(', ', array_slice($badRel, 0, 5)) . ')', empty($badRel));

// Categories must carry their own route and metadata.
foreach ($reg['categories'] as $id => $c) {
    ok("category $id route", ($c['route'] ?? '') === '/tools/' . $id);
    ok("category $id has name+description", !empty($c['name']) && !empty($c['description']));
}

// Route list used by the sitemap / verifier.
$routes = CloudHost247ToolsCatalog::routes();
ok('routes() returns 102 (1 index + 9 categories + 91 tools + 1 disclaimer) got ' . count($routes),
    count($routes) === 102);
ok('routes() all start with /tools', count(array_filter($routes, function ($r) {
    return strpos($r, '/tools') === 0;
})) === count($routes));

// Lookup helpers.
ok('tool() finds dns-lookup', CloudHost247ToolsCatalog::tool('dns-lookup') !== null);
ok('tool() misses bogus', CloudHost247ToolsCatalog::tool('no-such-tool') === null);
ok('byHandler() works', CloudHost247ToolsCatalog::byHandler('dns_lookup') !== null);
ok('related() returns 4', count(CloudHost247ToolsCatalog::related('dns-lookup', 4)) === 4);
ok('related() excludes self', !in_array('dns-lookup',
    array_column(CloudHost247ToolsCatalog::related('dns-lookup', 4), 'slug'), true));
ok('search finds by name', count(CloudHost247ToolsCatalog::search('propagation')) > 0);
ok('search finds by category', count(CloudHost247ToolsCatalog::search('dns')) > 0);
ok('search empty query', CloudHost247ToolsCatalog::search('') === []);
ok('popular returns 12', count(CloudHost247ToolsCatalog::popular(12)) === 12);
ok('recent returns 6', count(CloudHost247ToolsCatalog::recent(6)) === 6);
ok('url helper', CloudHost247ToolsCatalog::url('dns-lookup') === '/tools/dns-lookup');

// Exactly one tool may be in requires_configuration state.
$needCfg = array_keys(array_filter($reg['tools'], function ($t) {
    return ($t['status'] ?? '') === 'requires_configuration';
}));
ok('only reverse-image-search requires configuration (' . implode(',', $needCfg) . ')',
    $needCfg === ['reverse-image-search']);

// No "coming soon" placeholders anywhere.
$placeholder = array_keys(array_filter($reg['tools'], function ($t) {
    return stripos(json_encode($t), 'coming soon') !== false
        || ($t['status'] ?? '') === 'coming_soon';
}));
ok('no coming-soon placeholders (' . implode(',', $placeholder) . ')', empty($placeholder));

// Sensitive tools must execute client-side so data never leaves the device.
foreach (['credit-card-checker', 'password-generator', 'password-strength', 'notepad'] as $slug) {
    $t = $reg['tools'][$slug] ?? null;
    ok("$slug is client-side", $t && $t['exec'] === 'client');
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
