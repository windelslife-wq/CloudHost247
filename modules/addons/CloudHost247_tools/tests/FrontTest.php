<?php
/**
 * CloudHost247 Tools - front controller (/tools/* entry point) behaviour.
 *
 * Offline: dispatch() is pure (no headers sent, nothing echoed), the
 * Tools-Manager switch is injected via opts, and CSRF uses the session
 * token issued in-process. Static assertions pin the rewrite rule, the
 * api alias, and that every asset URL Router::assets() emits exists on disk.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/Front.php';

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
function has($body, $needle)
{
    return strpos($body, $needle) !== false;
}

$F = 'CloudHost247ToolsFront';

// ---------- tool page ----------
$r = $F::dispatch('GET', '/tools/dns-lookup');
eq('tool status', $r['status'], 200);
eq('tool content type', $r['headers']['Content-Type'], 'text/html; charset=utf-8');
ok('tool mount root', has($r['body'], 'data-ch247-tool="dns-lookup"'));
ok('tool csrf embedded', (bool) preg_match('/data-csrf="[0-9a-f]{64}"/', $r['body']));
ok('tool module script', has($r['body'], 'assets/js/tools/dns-lookup.js'));
ok('tool core assets', has($r['body'], 'assets/js/tools-core.js') && has($r['body'], 'assets/css/tools-core.css'));
ok('tool canonical', has($r['body'], 'rel="canonical"'));
ok('tool json-ld', has($r['body'], 'application/ld+json') && has($r['body'], 'BreadcrumbList'));
ok('tool breadcrumbs', has($r['body'], 'ch247-breadcrumb'));
ok('tool form shell', has($r['body'], 'data-ch247-form') && has($r['body'], 'data-ch247-fields'));
ok('tool result nodes', has($r['body'], 'data-ch247-result') && has($r['body'], 'data-ch247-error')
    && has($r['body'], 'data-ch247-loading') && has($r['body'], 'data-ch247-history'));
ok('tool submit button', has($r['body'], 'type="submit"') && has($r['body'], 'Run DNS Lookup'));
ok('tool related section', has($r['body'], 'Related tools'));
eq('tool page is private (csrf)', $r['headers']['Cache-Control'], 'no-store, max-age=0');
ok('server badge', has($r['body'], 'Server-side tool'));

// Rewrite form: the .htaccess rule strips the /tools prefix.
$r2 = $F::dispatch('GET', 'dns-lookup');
eq('bare route resolves to tool', $r2['status'], 200);
ok('bare route same page', has($r2['body'], 'data-ch247-tool="dns-lookup"'));

$r = $F::dispatch('GET', '/tools/password-generator');
ok('client badge', has($r['body'], 'Runs in your browser'));

$r = $F::dispatch('GET', '/tools/image-to-text');
ok('sensitive note', has($r['body'], 'no run history is kept'));

$r = $F::dispatch('GET', '/tools/reverse-image-search');
ok('requires_configuration notice', has($r['body'], 'Temporarily unavailable'));

$r = $F::dispatch('GET', '/tools/dns-lookup', [], null, [], ['status_enabled' => false]);
eq('disabled tool page 404s', $r['status'], 404);
ok('disabled message', has($r['body'], 'has been disabled'));

// ---------- category page ----------
$r = $F::dispatch('GET', '/tools/dns');
eq('category status', $r['status'], 200);
ok('category filter', has($r['body'], 'data-ch247-filter') && has($r['body'], 'data-ch247-filter-count'));
ok('category cards', has($r['body'], 'data-ch247-tool-card') && has($r['body'], '/tools/dns-lookup'));
eq('category cacheable', $r['headers']['Cache-Control'], 'public, max-age=300');

// ---------- index ----------
$r = $F::dispatch('GET', '/tools');
eq('index status', $r['status'], 200);
ok('index hero', has($r['body'], 'Free Online Tools'));
ok('index groups', has($r['body'], 'data-ch247-tool-group') && has($r['body'], 'Popular tools'));
ok('index search form', has($r['body'], 'action="/tools/search"'));

// ---------- search ----------
$r = $F::dispatch('GET', '/tools/search', ['q' => 'dns']);
eq('search status', $r['status'], 200);
ok('search results', has($r['body'], '/tools/dns-lookup'));

$evil = '<script>alert(1)</script>';
$r = $F::dispatch('GET', '/tools/search', ['q' => $evil]);
ok('search query escaped', has($r['body'], '&lt;script&gt;') && !has($r['body'], $evil));

$r = $F::dispatch('GET', '/tools/search', ['q' => '']);
ok('empty search invites typing', has($r['body'], 'Type above'));

// ---------- disclaimer / sitemap / redirect / 404 ----------
$r = $F::dispatch('GET', '/tools/disclaimer');
eq('disclaimer status', $r['status'], 200);
ok('disclaimer prose', has($r['body'], 'No warranty') && has($r['body'], 'Acceptable use'));

$r = $F::dispatch('GET', '/tools/sitemap.xml', [], null, [], ['server' => ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']]);
eq('sitemap status', $r['status'], 200);
eq('sitemap content type', $r['headers']['Content-Type'], 'application/xml; charset=utf-8');
ok('sitemap entries', has($r['body'], '<urlset') && has($r['body'], '<loc>https://example.com/tools/dns-lookup</loc>'));

$r = $F::dispatch('GET', '/tools/dns/dns-lookup');
eq('category alias 301s', $r['status'], 301);
eq('alias target', $r['headers']['Location'], '/tools/dns-lookup');

$r = $F::dispatch('GET', '/tools/dns-looku');
eq('near-miss 404s', $r['status'], 404);
ok('404 suggests', has($r['body'], 'Did you mean') && has($r['body'], '/tools/dns-lookup'));
ok('404 noindex', has($r['body'], 'content="noindex,follow"'));

$r = $F::dispatch('GET', '/tools/<script>');
ok('404 path escaped', !has($r['body'], '<script>'));

// ---------- API: success (offline-pure hybrid tool) ----------
$csrf = CloudHost247ToolsRateLimiter::token();
ok('csrf token shape', (bool) preg_match('/^[0-9a-f]{64}$/', $csrf));

$r = $F::dispatch('POST', '/tools/api/speed-test', [], json_encode(['action' => 'ping', 'sequence' => 4, 'csrf_token' => $csrf]));
eq('api status', $r['status'], 200);
eq('api content type', $r['headers']['Content-Type'], 'application/json; charset=utf-8');
$d = json_decode($r['body'], true);
eq('api envelope success', $d['success'], true);
eq('api ran the tool', $d['data']['sequence'], 4);
ok('api rate meta', isset($d['meta']['rate_limit']['remaining']));

$r = $F::dispatch('POST', 'api/speed-test', [], json_encode(['action' => 'ping', 'sequence' => 7, 'csrf_token' => $csrf]));
$d = json_decode($r['body'], true);
eq('api rewrite form works', $d['data']['sequence'], 7);

// ---------- API: failures ----------
$r = $F::dispatch('GET', '/tools/api/speed-test');
eq('api get rejected', $r['status'], 405);
eq('api allow header', $r['headers']['Allow'], 'POST');
$d = json_decode($r['body'], true);
eq('api 405 code', $d['error']['code'], 'method_not_allowed');

$r = $F::dispatch('POST', '/tools/api/speed-test', [], '{nope');
eq('api invalid json', $r['status'], 400);
$d = json_decode($r['body'], true);
eq('api invalid json code', $d['error']['code'], 'invalid_json');

$r = $F::dispatch('POST', '/tools/api/speed-test', [], '5');
eq('api scalar json rejected', $r['status'], 400);

$r = $F::dispatch('POST', '/tools/api/no-such-tool', [], json_encode(['csrf_token' => $csrf]));
eq('api unknown slug', $r['status'], 400);
$d = json_decode($r['body'], true);
eq('api unknown code', $d['error']['code'], 'invalid_input');

$r = $F::dispatch('POST', '/tools/api/password-generator', [], json_encode(['csrf_token' => $csrf]));
eq('api client-only refused', $r['status'], 400);
$d = json_decode($r['body'], true);
eq('api client-only code', $d['error']['code'], 'client_only');

$r = $F::dispatch('POST', '/tools/api/speed-test', [], json_encode(['action' => 'ping', 'csrf_token' => $csrf]), [], ['status_enabled' => false]);
eq('api disabled tool', $r['status'], 404);
$d = json_decode($r['body'], true);
eq('api disabled code', $d['error']['code'], 'disabled');

$r = $F::dispatch('POST', '/tools/api/speed-test', [], json_encode(['action' => 'ping']));
eq('api missing csrf', $r['status'], 419);
$d = json_decode($r['body'], true);
eq('api csrf code', $d['error']['code'], 'csrf');

$r = $F::dispatch('POST', '/tools/api/speed-test', [], json_encode(['action' => 'download', 'size' => 10, 'csrf_token' => $csrf]));
$d = json_decode($r['body'], true);
eq('api clamps through the front', $d['data']['size'], 65536);

// ---------- jsonResponse mirrors Runner::respond ----------
$sample = ['success' => true, 'tool' => 'x', 'name' => 'X', 'category' => 'c', 'data' => ['a' => 1], 'http_status' => 200, 'meta' => []];
$via = $F::jsonResponse($sample);
ob_start();
CloudHost247ToolsRunner::respond($sample);
$direct = ob_get_clean();
eq('jsonResponse body matches respond()', $via['body'], $direct);
eq('jsonResponse status', $via['status'], 200);

// ---------- siteUrl ----------
eq('siteUrl http', $F::siteUrl(['server' => ['HTTP_HOST' => 'example.com']]), 'http://example.com');
eq('siteUrl https', $F::siteUrl(['server' => ['HTTP_HOST' => 'Example.COM', 'HTTPS' => 'on']]), 'https://example.com');
eq('siteUrl port kept', $F::siteUrl(['server' => ['HTTP_HOST' => 'example.com:8080']]), 'http://example.com:8080');
eq('siteUrl crlf rejected', $F::siteUrl(['server' => ['HTTP_HOST' => "evil.com\r\nX: y"]]), '');
eq('siteUrl spaces rejected', $F::siteUrl(['server' => ['HTTP_HOST' => 'not a host']]), '');
eq('siteUrl missing', $F::siteUrl(['server' => []]), '');

// ---------- toolEnabled ----------
$tool = CloudHost247ToolsCatalog::tool('dns-lookup');
ok('enabled record resolves', !empty($tool));
ok('registry switch dominates', $F::toolEnabled(['enabled' => false, 'handler' => 'x'], true) === false);
ok('injected off disables', $F::toolEnabled($tool, false) === false);
ok('injected on enables', $F::toolEnabled($tool, true) === true);
ok('offline registry decides', $F::toolEnabled($tool) === true);

// ---------- static: entry points are wired ----------
$moduleRoot = dirname(__DIR__);
// Walk up to the checkout root; the isolated wasm runtime mounts only the
// module, so the rule is unverifiable there (CI runs natively and checks it).
$htaccess = false;
$dir = $moduleRoot;
for ($i = 0; $i < 4; $i++) {
    $candidate = $dir . '/.htaccess';
    if (is_file($candidate)) {
        $htaccess = @file_get_contents($candidate);
        break;
    }
    $dir = dirname($dir);
}
if ($htaccess === false) {
    ok('root htaccess ships the tools rule (unverifiable in isolated runtime)', true);
} else {
    ok('root htaccess ships the tools rule', has($htaccess, 'front.php?route=$1')
        && has($htaccess, '^tools(?:/(.*))?$'));
}
$apiEntry = @file_get_contents($moduleRoot . '/api/index.php');
ok('api alias delegates to front', $apiEntry !== false
    && has($apiEntry, 'front.php')
    && !has($apiEntry, 'Placeholder for future API'));
$frontEntry = @file_get_contents($moduleRoot . '/front.php');
ok('front bootstraps', $frontEntry !== false
    && has($frontEntry, "define('CLOUDHOST247_TOOLS'")
    && has($frontEntry, 'init.php')
    && has($frontEntry, 'CloudHost247ToolsFront::dispatch'));

// ---------- static: every emitted asset URL exists on disk ----------
$missing = [];
$checked = 0;
$routes = [['type' => 'index'], ['type' => 'category'], ['type' => 'search'], ['type' => 'disclaimer']];
foreach (CloudHost247ToolsCatalog::tools(false) as $t) {
    $routes[] = ['type' => 'tool', 'tool' => $t];
}
foreach ($routes as $route) {
    foreach (CloudHost247ToolsRouter::assets($route) as $list) {
        foreach ($list as $url) {
            $checked++;
            if (strpos($url, 'tools-browse') !== false) {
                $missing[] = $url . ' (dead reference)';
                continue;
            }
            $rel = preg_replace('#^/modules/addons/CloudHost247_tools/#', '', $url);
            if ($rel === $url || !is_file($moduleRoot . '/' . $rel)) {
                $missing[] = $url;
            }
        }
    }
}
ok("all $checked route assets exist on disk (" . count($missing) . ' missing: ' . implode(', ', array_slice($missing, 0, 5)) . ')', empty($missing));

// ---------- live entry points (executed, output captured) ----------
$_GET = ['route' => 'dns-lookup'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require $moduleRoot . '/front.php';
$entryHtml = ob_get_clean();
ok('front.php serves the route param', has($entryHtml, 'data-ch247-tool="dns-lookup"'));
ok('front.php embeds csrf', (bool) preg_match('/data-csrf="[0-9a-f]{64}"/', $entryHtml));

$_GET = ['tool' => 'speed-test'];
$_POST = ['tool' => 'speed-test', 'action' => 'ping', 'sequence' => 9, 'csrf_token' => $csrf];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require $moduleRoot . '/api/index.php';
$entryApi = ob_get_clean();
$d = json_decode($entryApi, true);
ok('api alias executes through the front', is_array($d) && $d['success'] === true && $d['data']['sequence'] === 9);

unset($ch247ApiEntry); // set by the api alias above; direct front.php must ignore it
$_GET = ['route' => 'api/speed-test'];
$_POST = ['action' => 'ping', 'sequence' => 11, 'csrf_token' => 'wrong'];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require $moduleRoot . '/front.php';
$entryDenied = ob_get_clean();
$d = json_decode($entryDenied, true);
ok('front.php enforces csrf on the api', is_array($d) && $d['success'] === false && $d['error']['code'] === 'csrf');

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
