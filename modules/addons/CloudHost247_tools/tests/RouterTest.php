<?php
/**
 * CloudHost247 Tools - Router tests.
 */
define('CLOUDHOST247_TOOLS', true);
require_once __DIR__ . '/../includes/Router.php';

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL $label\n"; }
}
function eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n"; }
}
$R = 'CloudHost247ToolsRouter';

// ---------- index ----------
foreach (['/tools', 'tools', '/tools/', '', '/'] as $p) {
    eq("index for '$p'", CloudHost247ToolsRouter::resolve($p)['type'], 'index');
}

// ---------- categories ----------
foreach (['dns', 'ip', 'developer', 'designer', 'webmaster', 'network', 'security', 'productivity', 'gaming'] as $c) {
    $r = CloudHost247ToolsRouter::resolve('/tools/' . $c);
    eq("category $c resolves", $r['type'], 'category');
    eq("category $c route", $r['route'], '/tools/' . $c);
    ok("category $c lists tools", count($r['category']['tools']) === $r['category']['count'] && $r['category']['count'] > 0);
}

// ---------- every one of the 91 tool routes resolves ----------
require_once __DIR__ . '/../includes/Catalog.php';
$bad = [];
foreach (CloudHost247ToolsCatalog::tools() as $slug => $tool) {
    $r = CloudHost247ToolsRouter::resolve($tool['route']);
    if ($r['type'] !== 'tool' || $r['tool']['slug'] !== $slug) {
        $bad[] = $slug . ' -> ' . $r['type'];
    }
}
ok('all 91 tool routes resolve (' . implode(', ', array_slice($bad, 0, 5)) . ')', empty($bad));

// every tool page gets related tools
$noRelated = [];
foreach (CloudHost247ToolsCatalog::tools() as $slug => $tool) {
    $r = CloudHost247ToolsRouter::resolve($tool['route']);
    if (count($r['related']) < 1) { $noRelated[] = $slug; }
}
ok('every tool has related tools (' . implode(', ', array_slice($noRelated, 0, 5)) . ')', empty($noRelated));

// ---------- static pages ----------
eq('disclaimer', CloudHost247ToolsRouter::resolve('/tools/disclaimer')['type'], 'disclaimer');
eq('sitemap', CloudHost247ToolsRouter::resolve('/tools/sitemap.xml')['type'], 'sitemap');
$r = CloudHost247ToolsRouter::resolve('/tools/search', ['q' => 'dns']);
eq('search type', $r['type'], 'search');
ok('search returns results', count($r['results']) > 0);
$r = CloudHost247ToolsRouter::resolve('/tools/search', ['q' => '']);
eq('empty search no results', $r['results'], []);

// ---------- api ----------
$r = CloudHost247ToolsRouter::resolve('/tools/api/dns-lookup');
eq('api type', $r['type'], 'api');
eq('api slug', $r['slug'], 'dns-lookup');
eq('api via query', CloudHost247ToolsRouter::resolve('/tools/api', ['tool' => 'whois'])['slug'], 'whois');
eq('bare api 404s', CloudHost247ToolsRouter::resolve('/tools/api')['type'], 'notfound');

// ---------- category/slug alias redirects ----------
$r = CloudHost247ToolsRouter::resolve('/tools/dns/dns-lookup');
eq('alias redirects', $r['type'], 'redirect');
eq('alias target', $r['to'], '/tools/dns-lookup');
eq('alias is 301', $r['status'], 301);
eq('wrong category alias 404s', CloudHost247ToolsRouter::resolve('/tools/ip/dns-lookup')['type'], 'notfound');

// ---------- 404 ----------
$r = CloudHost247ToolsRouter::resolve('/tools/no-such-tool');
eq('unknown 404s', $r['type'], 'notfound');
$r = CloudHost247ToolsRouter::resolve('/tools/dns-lookupp');
ok('404 suggests close matches for a typo', count($r['suggestions']) > 0);
ok('typo suggestion is relevant',
    in_array('dns-lookup', array_column($r['suggestions'], 'slug'), true));
ok('404 suggests for a partial name', count(CloudHost247ToolsRouter::resolve('/tools/whois-lookup')['suggestions']) > 0);
ok('404 gives no suggestions for nonsense',
    count(CloudHost247ToolsRouter::resolve('/tools/qqqqzzzzxxxxwwww')['suggestions']) === 0);
eq('deep path 404s', CloudHost247ToolsRouter::resolve('/tools/a/b/c')['type'], 'notfound');

// ---------- case insensitivity ----------
eq('uppercase slug', CloudHost247ToolsRouter::resolve('/tools/DNS-LOOKUP')['type'], 'tool');
eq('uppercase category', CloudHost247ToolsRouter::resolve('/tools/DNS')['type'], 'category');

// ---------- breadcrumbs ----------
$b = CloudHost247ToolsRouter::breadcrumbs(CloudHost247ToolsRouter::resolve('/tools/dns-lookup'));
eq('tool breadcrumb depth', count($b), 4);
eq('breadcrumb home', $b[0]['name'], 'Home');
eq('breadcrumb tools', $b[1]['url'], '/tools');
eq('breadcrumb category', $b[2]['url'], '/tools/dns');
eq('breadcrumb leaf', $b[3]['url'], '/tools/dns-lookup');
eq('category breadcrumb depth', count(CloudHost247ToolsRouter::breadcrumbs(CloudHost247ToolsRouter::resolve('/tools/ip'))), 3);
eq('index breadcrumb depth', count(CloudHost247ToolsRouter::breadcrumbs(CloudHost247ToolsRouter::resolve('/tools'))), 2);

// ---------- meta ----------
$seen = [];
$dupes = [];
foreach (CloudHost247ToolsCatalog::tools() as $slug => $tool) {
    $m = CloudHost247ToolsRouter::meta(CloudHost247ToolsRouter::resolve($tool['route']));
    if (isset($seen[$m['title']])) { $dupes[] = $slug; }
    $seen[$m['title']] = true;
    if ($m['canonical'] !== $tool['route']) { $dupes[] = $slug . ' canonical'; }
}
ok('all 91 SEO titles unique (' . implode(', ', array_slice($dupes, 0, 5)) . ')', empty($dupes));
$m = CloudHost247ToolsRouter::meta(CloudHost247ToolsRouter::resolve('/tools/reverse-image-search'));
eq('requires_configuration tool is noindex', $m['robots'], 'noindex,follow');
eq('search page is noindex', CloudHost247ToolsRouter::meta(CloudHost247ToolsRouter::resolve('/tools/search'))['robots'], 'noindex,follow');
eq('404 is noindex', CloudHost247ToolsRouter::meta(CloudHost247ToolsRouter::resolve('/tools/zzz'))['robots'], 'noindex,follow');

// ---------- structured data ----------
$sd = CloudHost247ToolsRouter::structuredData(CloudHost247ToolsRouter::resolve('/tools/dns-lookup'), 'https://x.test');
$types = array_column($sd['@graph'], '@type');
ok('has BreadcrumbList', in_array('BreadcrumbList', $types, true));
ok('has SoftwareApplication', in_array('SoftwareApplication', $types, true));
$json = json_encode($sd, JSON_UNESCAPED_SLASHES);
ok('no fabricated aggregateRating', stripos($json, 'aggregateRating') === false);
ok('no fabricated review', stripos($json, '"review"') === false);
ok('absolute urls', strpos($json, 'https://x.test/tools/dns-lookup') !== false);
$sd = CloudHost247ToolsRouter::structuredData(CloudHost247ToolsRouter::resolve('/tools/dns'), 'https://x.test');
ok('category is CollectionPage', in_array('CollectionPage', array_column($sd['@graph'], '@type'), true));

// ---------- sitemap ----------
$entries = CloudHost247ToolsRouter::sitemapEntries('https://x.test');
// 1 index + 9 categories + 90 active tools (reverse-image-search excluded) + disclaimer
eq('sitemap entry count', count($entries), 101);
$locs = array_column($entries, 'loc');
ok('sitemap excludes unconfigured tool', !in_array('https://x.test/tools/reverse-image-search', $locs, true));
ok('sitemap excludes search', !in_array('https://x.test/tools/search', $locs, true));
ok('sitemap excludes api', count(array_filter($locs, function ($l) { return strpos($l, '/api/') !== false; })) === 0);
ok('sitemap locs unique', count(array_unique($locs)) === count($locs));
$xml = CloudHost247ToolsRouter::sitemapXml('https://x.test');
ok('sitemap is xml', strpos($xml, '<urlset') !== false && strpos($xml, '</urlset>') !== false);
eq('sitemap url count', substr_count($xml, '<url>'), 101);
ok('sitemap well-formed', simplexml_load_string($xml) !== false);

// ---------- assets are route split ----------
$a = CloudHost247ToolsRouter::assets(CloudHost247ToolsRouter::resolve('/tools/dns-lookup'));
ok('tool page loads its own js', in_array('/modules/addons/CloudHost247_tools/assets/js/tools/dns-lookup.js', $a['js'], true));
eq('tool page loads only 2 js files', count($a['js']), 2);
$a2 = CloudHost247ToolsRouter::assets(CloudHost247ToolsRouter::resolve('/tools/whois'));
ok('different tool loads different js', $a['js'] !== $a2['js']);
ok('no monolithic bundle', count(array_filter($a['js'], function ($j) { return strpos($j, 'CloudHost247-tools.js') !== false; })) === 0);

echo "\nPASS=$pass FAIL=$fail\n";
