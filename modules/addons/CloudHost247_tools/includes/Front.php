<?php
/**
 * CloudHost247 Tools - front controller core for the /tools/* surface.
 *
 * Pure dispatch plus page rendering. dispatch() never emits anything: it
 * returns ['status' => int, 'headers' => [...], 'body' => string] so the
 * whole surface is unit-testable offline. The web entry points
 * (front.php, api/index.php) bootstrap WHMCS, call dispatch(), and emit
 * the response.
 *
 * Route types come from CloudHost247ToolsRouter::resolve(); tool execution
 * goes through CloudHost247ToolsRunner::run(), so every protection the
 * Runner provides (request cap, tiers, exec=client refusal, redaction,
 * generic errors, timeouts, security headers) applies to this surface.
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Security.php';

class CloudHost247ToolsFront
{
    /**
     * Dispatch a request without emitting anything.
     *
     * @param string $method   HTTP method.
     * @param string $path     Request path ("/tools/dns-lookup", "api/dns-lookup", ...).
     * @param array  $query    Query string parameters.
     * @param string|null $rawBody Raw request body for API calls (JSON), or null.
     * @param array  $post     Form/multipart fields for API calls.
     * @param array  $opts     ['status_enabled' => bool|null, 'ip' => string|null,
     *                         'server' => array] - the Tools-Manager switch override
     *                         (null = live check), client IP, and $_SERVER slice.
     *
     * @return array{status:int, headers:array, body:string}
     */
    public static function dispatch($method, $path, array $query = [], $rawBody = null, array $post = [], array $opts = [])
    {
        $path = substr(trim((string) $path), 0, 512);
        $route = CloudHost247ToolsRouter::resolve($path, $query);

        switch ($route['type']) {
            case 'api':
                return self::serveApi($method, $route, $rawBody, $post, $opts);
            case 'redirect':
                return [
                    'status' => (int) $route['status'],
                    'headers' => ['Location' => $route['to'], 'Cache-Control' => 'public, max-age=86400'],
                    'body' => '',
                ];
            case 'sitemap':
                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'application/xml; charset=utf-8',
                        'X-Content-Type-Options' => 'nosniff',
                        'Cache-Control' => 'public, max-age=3600',
                    ],
                    'body' => CloudHost247ToolsRouter::sitemapXml(self::siteUrl($opts)),
                ];
            case 'tool':
                if (!self::toolEnabled($route['tool'], array_key_exists('status_enabled', $opts) ? $opts['status_enabled'] : null)) {
                    return self::htmlResponse(self::disabledPage($route['tool']), 404, $route, $opts);
                }

                return self::htmlResponse(self::toolPage($route, $opts), 200, $route, $opts, true);
            case 'category':
            case 'index':
            case 'search':
            case 'disclaimer':
                $pages = [
                    'category' => 'categoryPage',
                    'index' => 'indexPage',
                    'search' => 'searchPage',
                    'disclaimer' => 'disclaimerPage',
                ];
                $fn = $pages[$route['type']];

                return self::htmlResponse(self::$fn($route, $opts), 200, $route, $opts);
            case 'notfound':
            default:
                return self::htmlResponse(self::notFoundPage($route), 404, $route, $opts);
        }
    }

    // -----------------------------------------------------------------
    //  API endpoint
    // -----------------------------------------------------------------

    protected static function serveApi($method, array $route, $rawBody, array $post, array $opts)
    {
        $slug = $route['slug'];

        if (strtoupper((string) $method) !== 'POST') {
            $res = self::jsonResponse([
                'success' => false,
                'tool' => $slug,
                'name' => $slug,
                'category' => 'unknown',
                'error' => ['code' => 'method_not_allowed', 'message' => 'The API accepts POST requests only.'],
                'http_status' => 405,
                'meta' => ['duration_ms' => 0.0, 'exec' => 'server', 'cached' => false],
            ]);
            $res['headers']['Allow'] = 'POST';

            return $res;
        }

        $tool = CloudHost247ToolsCatalog::tool($slug);
        if (!$tool) {
            $tool = CloudHost247ToolsCatalog::byHandler($slug);
        }
        if ($tool && !self::toolEnabled($tool, array_key_exists('status_enabled', $opts) ? $opts['status_enabled'] : null)) {
            return self::jsonResponse([
                'success' => false,
                'tool' => $tool['slug'],
                'name' => $tool['name'],
                'category' => $tool['category'],
                'error' => ['code' => 'disabled', 'message' => 'Tool not found or disabled.'],
                'http_status' => 404,
                'meta' => ['duration_ms' => 0.0, 'exec' => $tool['exec'], 'cached' => false],
            ]);
        }

        $input = [];
        if ($rawBody !== null && trim((string) $rawBody) !== '') {
            $input = json_decode((string) $rawBody, true);
            if (!is_array($input)) {
                return self::jsonResponse([
                    'success' => false,
                    'tool' => $slug,
                    'name' => $slug,
                    'category' => 'unknown',
                    'error' => ['code' => 'invalid_json', 'message' => 'The request body was not a valid JSON object.'],
                    'http_status' => 400,
                    'meta' => ['duration_ms' => 0.0, 'exec' => 'server', 'cached' => false],
                ]);
            }
        } else {
            // Form/multipart input passes through untouched, exactly like the
            // legacy endpoint: handlers ignore keys they do not read, and
            // some tools (speed-test) legitimately use 'action' as input.
            $input = $post;
        }

        $envelope = CloudHost247ToolsRunner::run($slug, $input, [
            'require_csrf' => true,
            'csrf' => isset($input['csrf_token']) ? $input['csrf_token'] : '',
            'ip' => isset($opts['ip']) ? $opts['ip'] : CloudHost247ToolsSecurity::clientIp(),
        ]);

        return self::jsonResponse($envelope);
    }

    /**
     * Convert a Runner envelope to a response triplet.
     *
     * Mirrors CloudHost247ToolsRunner::respond() exactly (status mapping,
     * headers, JSON flags) without emitting, so dispatch() stays pure.
     */
    public static function jsonResponse(array $envelope)
    {
        $status = !empty($envelope['success']) ? 200 : (int) ($envelope['http_status'] ?? 400);
        unset($envelope['http_status']);

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        if (isset($envelope['retry_after'])) {
            $headers['Retry-After'] = (string) (int) $envelope['retry_after'];
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    // -----------------------------------------------------------------
    //  Enablement
    // -----------------------------------------------------------------

    /**
     * Whether a tool is live on this surface.
     *
     * Both switches must agree: the registry 'enabled' flag (admin
     * overrides) and the Tools-Manager switch in mod_CloudHost247_tools_status
     * (legacy handler ids). Without a database the registry decides alone.
     *
     * @param array $tool
     * @param bool|null $statusEnabled Injectable Tools-Manager answer (tests).
     */
    public static function toolEnabled(array $tool, $statusEnabled = null)
    {
        if (empty($tool['enabled'])) {
            return false;
        }
        if ($statusEnabled !== null) {
            return (bool) $statusEnabled;
        }
        if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            return true;
        }
        if (!function_exists('CloudHost247_tools_is_tool_enabled')) {
            $fn = __DIR__ . '/functions.php';
            if (is_file($fn)) {
                require_once $fn;
            }
        }
        if (!function_exists('CloudHost247_tools_is_tool_enabled')) {
            return true;
        }
        try {
            return CloudHost247_tools_is_tool_enabled($tool['handler']);
        } catch (\Throwable $e) {
            // Fail closed like the legacy path: an unreadable switch must not
            // silently re-enable a tool an administrator switched off.
            return false;
        }
    }

    // -----------------------------------------------------------------
    //  Site URL (absolute canonicals, JSON-LD, sitemap)
    // -----------------------------------------------------------------

    /**
     * Absolute site base, or '' when none can be established safely.
     *
     * Prefers the WHMCS SystemURL setting (trusted); otherwise derives it
     * from the request host after strict validation, so a poisoned Host
     * header can only degrade URLs to relative, never inject markup.
     */
    public static function siteUrl(array $opts = [])
    {
        if (class_exists('\Illuminate\Database\Capsule\Manager')) {
            try {
                $sys = \Illuminate\Database\Capsule\Manager::table('tblconfiguration')
                    ->where('setting', 'SystemURL')
                    ->value('value');
                if (is_string($sys) && preg_match('#^https?://[^/\s]+#i', $sys)) {
                    return rtrim($sys, '/');
                }
            } catch (\Throwable $e) {
                // Fall through to the request-derived URL.
            }
        }

        $server = isset($opts['server']) && is_array($opts['server']) ? $opts['server'] : $_SERVER;
        $host = isset($server['HTTP_HOST']) ? trim((string) $server['HTTP_HOST']) : '';
        if ($host === '' || !preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/i', $host)) {
            return '';
        }
        $https = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';

        return ($https ? 'https://' : 'http://') . strtolower($host);
    }

    // -----------------------------------------------------------------
    //  Page shell
    // -----------------------------------------------------------------

    protected static function htmlResponse($bodyHtml, $status, array $route, array $opts, $private = false)
    {
        $meta = CloudHost247ToolsRouter::meta($route);
        $site = self::siteUrl($opts);
        $assets = CloudHost247ToolsRouter::assets($route);
        $e = [__CLASS__, 'e'];

        $canonical = $site . $meta['canonical'];
        $jsonLd = json_encode(
            CloudHost247ToolsRouter::structuredData($route, $site),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $head = '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . call_user_func($e, $meta['title']) . '</title>' . "\n"
            . '<meta name="description" content="' . call_user_func($e, $meta['description']) . '">' . "\n"
            . '<meta name="robots" content="' . call_user_func($e, $meta['robots']) . '">' . "\n"
            . '<link rel="canonical" href="' . call_user_func($e, $canonical) . '">' . "\n"
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:title" content="' . call_user_func($e, $meta['title']) . '">' . "\n"
            . '<meta property="og:description" content="' . call_user_func($e, $meta['description']) . '">' . "\n"
            . '<meta property="og:url" content="' . call_user_func($e, $canonical) . '">' . "\n";
        foreach ($assets['css'] as $css) {
            $head .= '<link rel="stylesheet" href="' . call_user_func($e, $css) . '">' . "\n";
        }
        $head .= '<script type="application/ld+json">' . $jsonLd . '</script>';

        $scripts = '';
        foreach ($assets['js'] as $js) {
            $scripts .= '<script src="' . call_user_func($e, $js) . '" defer></script>' . "\n";
        }

        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n" . $head . "\n" . '</head>' . "\n"
            . '<body>' . "\n"
            . self::siteHeader($route)
            . '<main id="ch247-main">' . "\n" . $bodyHtml . "\n" . '</main>' . "\n"
            . self::siteFooter()
            . $scripts
            . '</body>' . "\n"
            . '</html>';

        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Tool pages embed a per-session CSRF token, so they must never
            // be cached. Browse pages carry no session data.
            'Cache-Control' => $private ? 'no-store, max-age=0' : 'public, max-age=300',
        ];

        return ['status' => $status, 'headers' => $headers, 'body' => $html];
    }

    protected static function siteHeader(array $route)
    {
        $e = [__CLASS__, 'e'];
        $cats = CloudHost247ToolsCatalog::categories();
        $active = $route['type'] === 'category' ? $route['category']['id'] : '';

        $menu = '';
        foreach ($cats as $id => $cat) {
            $menu .= '<li><a href="' . call_user_func($e, $cat['route']) . '"'
                . ($id === $active ? ' aria-current="page"' : '') . '>'
                . call_user_func($e, $cat['name'])
                . ' <span class="ch247-group__count">(' . (int) $cat['count'] . ')</span></a></li>' . "\n";
        }

        return '<a class="ch247-skip" href="#ch247-main">Skip to content</a>' . "\n"
            . '<header class="ch247-hero ch247-hero--nav">' . "\n"
            . '<div class="ch247-wrap">' . "\n"
            . '<nav aria-label="Tools">' . "\n"
            . '<a class="ch247-hero__title" href="/tools">CloudHost247 Tools</a>' . "\n"
            . '<button type="button" class="ch247-btn ch247-btn--ghost" data-ch247-megamenu-trigger aria-expanded="false" aria-controls="ch247-mega">Categories</button>' . "\n"
            . '<a class="ch247-btn ch247-btn--ghost" href="/tools/search">Search</a>' . "\n"
            . '<a class="ch247-btn ch247-btn--ghost" href="/tools/disclaimer">Disclaimer</a>' . "\n"
            . '</nav>' . "\n"
            . '<div class="ch247-mega" data-ch247-megamenu id="ch247-mega" hidden>' . "\n"
            . '<ul class="ch247-mega__list">' . "\n" . $menu . '</ul>' . "\n"
            . '</div>' . "\n"
            . '</div>' . "\n"
            . '</header>' . "\n";
    }

    protected static function siteFooter()
    {
        $e = [__CLASS__, 'e'];
        $links = '';
        foreach (CloudHost247ToolsCatalog::categories() as $cat) {
            $links .= '<li><a href="' . call_user_func($e, $cat['route']) . '">'
                . call_user_func($e, $cat['name']) . '</a></li>' . "\n";
        }

        return '<footer>' . "\n"
            . '<div class="ch247-wrap">' . "\n"
            . '<ul class="ch247-footer-tools">' . "\n" . $links . '</ul>' . "\n"
            . '<p class="ch247-prose"><a href="/tools">Tools home</a> · '
            . '<a href="/tools/search">Search</a> · '
            . '<a href="/tools/disclaimer">Disclaimer</a> · '
            . '<a href="/tools/sitemap.xml">Sitemap</a></p>' . "\n"
            . '<p class="ch247-prose">Free online tools by CloudHost247. Measurements reflect the public internet path between our servers and your target.</p>' . "\n"
            . '</div>' . "\n"
            . '</footer>' . "\n";
    }

    protected static function breadcrumbs(array $route)
    {
        $e = [__CLASS__, 'e'];
        $items = '';
        $crumbs = CloudHost247ToolsRouter::breadcrumbs($route);
        $last = count($crumbs) - 1;
        foreach ($crumbs as $i => $crumb) {
            $name = call_user_func($e, $crumb['name']);
            if ($i === $last) {
                $items .= '<li aria-current="page">' . $name . '</li>' . "\n";
            } else {
                $items .= '<li><a href="' . call_user_func($e, $crumb['url']) . '">' . $name . '</a></li>' . "\n";
            }
        }

        return '<nav aria-label="Breadcrumb"><ol class="ch247-breadcrumb">' . "\n" . $items . '</ol></nav>' . "\n";
    }

    /**
     * One tool card. The data-search haystack drives the live filter.
     */
    protected static function toolCard(array $tool)
    {
        $e = [__CLASS__, 'e'];
        $haystack = $tool['name'] . ' ' . $tool['description'] . ' ' . $tool['category'];
        $badge = ($tool['exec'] ?? 'server') === 'client' ? 'Browser' : 'Server';

        return '<article class="ch247-card" data-ch247-tool-card data-search="' . call_user_func($e, $haystack) . '">' . "\n"
            . '<h3 class="ch247-card__name"><a href="' . call_user_func($e, $tool['route']) . '">'
            . call_user_func($e, $tool['name']) . '</a></h3>' . "\n"
            . '<p class="ch247-card__desc">' . call_user_func($e, $tool['description']) . '</p>' . "\n"
            . '<p class="ch247-card__meta"><span class="ch247-chip ch247-chip--info">' . $badge . '</span></p>' . "\n"
            . '</article>' . "\n";
    }

    protected static function filterBox($count)
    {
        $e = [__CLASS__, 'e'];

        return '<div class="ch247-filter">' . "\n"
            . '<label class="ch247-sr-only" for="ch247-filter">Filter tools</label>' . "\n"
            . '<input class="ch247-input" type="search" id="ch247-filter" data-ch247-filter placeholder="Filter tools…" autocomplete="off">' . "\n"
            . '<p class="ch247-filter__count" data-ch247-filter-count role="status">' . (int) $count . ' tools</p>' . "\n"
            . '</div>' . "\n";
    }

    // -----------------------------------------------------------------
    //  Pages
    // -----------------------------------------------------------------

    protected static function toolPage(array $route, array $opts)
    {
        $e = [__CLASS__, 'e'];
        $tool = $route['tool'];
        $csrf = CloudHost247ToolsRateLimiter::token();
        $cat = CloudHost247ToolsCatalog::category($tool['category']);

        if (($tool['exec'] ?? 'server') === 'client') {
            $badge = '<p><span class="ch247-chip ch247-chip--pass">Runs in your browser</span></p>'
                . '<p class="ch247-prose">This tool executes entirely on your device. Nothing you enter is transmitted to CloudHost247.</p>';
        } else {
            $badge = '<p><span class="ch247-chip ch247-chip--info">Server-side tool</span></p>';
            if (!empty($tool['sensitive'])) {
                $badge .= '<p class="ch247-prose">Private tool: your input is processed for this request only and no run history is kept in your browser.</p>';
            }
        }

        $statusNote = '';
        if (($tool['status'] ?? 'active') === 'requires_configuration') {
            $statusNote = '<div class="ch247-alert ch247-alert--warn" role="note"><div><strong class="ch247-alert__title">Temporarily unavailable</strong>'
                . '<p class="ch247-alert__body">This tool needs a search provider configured by the site administrator before it can run.</p></div></div>';
        } elseif (($tool['status'] ?? 'active') === 'maintenance') {
            $statusNote = '<div class="ch247-alert ch247-alert--warn" role="note"><div><strong class="ch247-alert__title">Temporarily unavailable</strong>'
                . '<p class="ch247-alert__body">This tool is down for maintenance. Please try again later.</p></div></div>';
        }

        $related = '';
        foreach ($route['related'] as $rel) {
            $related .= self::toolCard($rel);
        }
        $catName = $cat ? $cat['name'] : ucfirst($tool['category']);
        $catRoute = $cat ? $cat['route'] : '/tools';

        return '<div class="ch247-wrap">' . "\n"
            . self::breadcrumbs($route)
            . '<div class="ch247-hero">' . "\n"
            . '<p class="ch247-hero__eyebrow"><a href="' . call_user_func($e, $catRoute) . '">'
            . call_user_func($e, $catName) . ' Tools</a></p>' . "\n"
            . '<h1 class="ch247-hero__title">' . call_user_func($e, $tool['name']) . '</h1>' . "\n"
            . '<p class="ch247-hero__lede">' . call_user_func($e, $tool['description']) . '</p>' . "\n"
            . $badge . "\n"
            . '</div>' . "\n"
            . $statusNote . "\n"
            . '<div class="ch247-layout">' . "\n"
            . '<div class="ch247-panel" data-ch247-tool="' . call_user_func($e, $tool['slug']) . '" data-csrf="' . call_user_func($e, $csrf) . '">' . "\n"
            . '<p class="ch247-quota" data-ch247-quota hidden></p>' . "\n"
            . '<form data-ch247-form method="post" action="' . call_user_func($e, '/tools/api/' . $tool['slug']) . '">' . "\n"
            . '<div class="ch247-fields" data-ch247-fields></div>' . "\n"
            . '<div class="ch247-actions"><button type="submit" class="ch247-btn">Run '
            . call_user_func($e, $tool['name']) . '</button></div>' . "\n"
            . '</form>' . "\n"
            . '<div class="ch247-loading" data-ch247-loading hidden><div class="ch247-spinner" role="status"><span class="ch247-sr-only">Working…</span></div></div>' . "\n"
            . '<div data-ch247-result hidden></div>' . "\n"
            . '<div data-ch247-error hidden></div>' . "\n"
            . '<div data-ch247-history hidden></div>' . "\n"
            . '</div>' . "\n"
            . '<aside class="ch247-aside">' . "\n"
            . '<div class="ch247-panel"><h2 class="ch247-section__title">About this tool</h2>' . "\n"
            . '<p class="ch247-prose">' . call_user_func($e, $tool['description']) . '</p>' . "\n"
            . '<p class="ch247-prose">Category: <a href="' . call_user_func($e, $catRoute) . '">'
            . call_user_func($e, $catName) . '</a></p></div>' . "\n"
            . '</aside>' . "\n"
            . '</div>' . "\n"
            . '<section class="ch247-related" aria-labelledby="ch247-related-h">' . "\n"
            . '<h2 class="ch247-section__title" id="ch247-related-h">Related tools</h2>' . "\n"
            . '<div class="ch247-grid ch247-grid--4">' . "\n" . $related . '</div>' . "\n"
            . '</section>' . "\n"
            . '</div>' . "\n";
    }

    protected static function categoryPage(array $route, array $opts)
    {
        $e = [__CLASS__, 'e'];
        $cat = $route['category'];

        $cards = '';
        foreach ($cat['tools'] as $tool) {
            $cards .= self::toolCard($tool);
        }
        if ($cards === '') {
            $cards = '<p class="ch247-prose">No tools in this category are currently enabled.</p>' . "\n";
        }

        return '<div class="ch247-wrap">' . "\n"
            . self::breadcrumbs($route)
            . '<div class="ch247-hero">' . "\n"
            . '<h1 class="ch247-hero__title">' . call_user_func($e, $cat['name']) . ' Tools</h1>' . "\n"
            . '<p class="ch247-hero__lede">' . call_user_func($e, $cat['description']) . '</p>' . "\n"
            . '</div>' . "\n"
            . self::filterBox($cat['count'])
            . '<div class="ch247-group" data-ch247-tool-group>' . "\n"
            . '<div class="ch247-grid">' . "\n" . $cards . '</div>' . "\n"
            . '</div>' . "\n"
            . '</div>' . "\n";
    }

    protected static function indexPage(array $route, array $opts)
    {
        $e = [__CLASS__, 'e'];
        $total = CloudHost247ToolsCatalog::count();

        $popular = '';
        foreach (CloudHost247ToolsCatalog::popular(12) as $tool) {
            $popular .= self::toolCard($tool);
        }

        $groups = '';
        foreach (CloudHost247ToolsCatalog::categories() as $id => $cat) {
            if ($cat['count'] === 0) {
                continue;
            }
            $cards = '';
            foreach ($cat['tools'] as $tool) {
                $cards .= self::toolCard($tool);
            }
            $groups .= '<section class="ch247-group" data-ch247-tool-group aria-labelledby="ch247-g-' . call_user_func($e, $id) . '">' . "\n"
                . '<div class="ch247-group__head"><h2 class="ch247-group__title" id="ch247-g-' . call_user_func($e, $id) . '">'
                . '<a href="' . call_user_func($e, $cat['route']) . '">' . call_user_func($e, $cat['name']) . '</a>'
                . ' <span class="ch247-group__count">(' . (int) $cat['count'] . ')</span></h2>' . "\n"
                . '<a class="ch247-group__all" href="' . call_user_func($e, $cat['route']) . '">View all</a></div>' . "\n"
                . '<div class="ch247-grid ch247-grid--4">' . "\n" . $cards . '</div>' . "\n"
                . '</section>' . "\n";
        }

        return '<div class="ch247-wrap">' . "\n"
            . '<div class="ch247-hero">' . "\n"
            . '<p class="ch247-hero__eyebrow">Free online utilities</p>' . "\n"
            . '<h1 class="ch247-hero__title">' . (int) $total . ' Free Online Tools</h1>' . "\n"
            . '<p class="ch247-hero__lede">DNS, IP, developer, SEO, network and security utilities. No sign-up required.</p>' . "\n"
            . '<form role="search" method="get" action="/tools/search"><label class="ch247-sr-only" for="ch247-q">Search tools</label>'
            . '<input class="ch247-input" type="search" id="ch247-q" name="q" placeholder="Search tools…" autocomplete="off">'
            . '<button type="submit" class="ch247-btn">Search</button></form>' . "\n"
            . '</div>' . "\n"
            . self::filterBox($total)
            . '<section aria-labelledby="ch247-popular-h">' . "\n"
            . '<h2 class="ch247-section__title" id="ch247-popular-h">Popular tools</h2>' . "\n"
            . '<div class="ch247-grid ch247-grid--4">' . "\n" . $popular . '</div>' . "\n"
            . '</section>' . "\n"
            . $groups
            . '</div>' . "\n";
    }

    protected static function searchPage(array $route, array $opts)
    {
        $e = [__CLASS__, 'e'];
        $q = $route['query'];

        if ($q === '') {
            $body = '<p class="ch247-prose">Type above to search all tools by name or description.</p>' . "\n";
            $title = 'Search tools';
        } else {
            $cards = '';
            foreach ($route['results'] as $tool) {
                $cards .= self::toolCard($tool);
            }
            if ($cards === '') {
                $cards = '<p class="ch247-prose">No tools match &ldquo;' . call_user_func($e, $q) . '&rdquo;. Try fewer or different words.</p>' . "\n";
            }
            $n = count($route['results']);
            $title = $n . ($n === 1 ? ' result for ' : ' results for ') . '“' . $q . '”';
            $body = '<div class="ch247-grid">' . "\n" . $cards . '</div>' . "\n";
        }

        return '<div class="ch247-wrap">' . "\n"
            . self::breadcrumbs($route)
            . '<div class="ch247-hero">' . "\n"
            . '<h1 class="ch247-hero__title">' . call_user_func($e, $title) . '</h1>' . "\n"
            . '<form role="search" method="get" action="/tools/search"><label class="ch247-sr-only" for="ch247-q">Search tools</label>'
            . '<input class="ch247-input" type="search" id="ch247-q" name="q" value="' . call_user_func($e, $q) . '" placeholder="Search tools…" autocomplete="off">'
            . '<button type="submit" class="ch247-btn">Search</button></form>' . "\n"
            . '</div>' . "\n"
            . $body
            . '</div>' . "\n";
    }

    protected static function disclaimerPage(array $route, array $opts)
    {
        return '<div class="ch247-wrap">' . "\n"
            . self::breadcrumbs($route)
            . '<div class="ch247-hero">' . "\n"
            . '<h1 class="ch247-hero__title">Tools Disclaimer</h1>' . "\n"
            . '<p class="ch247-hero__lede">How the CloudHost247 online tools work, what they measure, and their limitations.</p>' . "\n"
            . '</div>' . "\n"
            . '<div class="ch247-prose">' . "\n"
            . '<h2 class="ch247-section__title">Measurements are point-in-time</h2>' . "\n"
            . '<p>Diagnostics such as DNS lookups, propagation checks, ping, blacklists and header inspections reflect the public internet path between our servers and your target at the moment you run them. Results can differ by location, resolver and network conditions.</p>' . "\n"
            . '<h2 class="ch247-section__title">Estimates, not guarantees</h2>' . "\n"
            . '<p>Scores, grades and verdicts (password strength, SEO checks, deliverability hints) are heuristics to guide investigation. They are not guarantees of security, ranking or inbox placement.</p>' . "\n"
            . '<h2 class="ch247-section__title">Your data</h2>' . "\n"
            . '<p>Tools marked “Runs in your browser” execute entirely on your device and transmit nothing to CloudHost247. Server-side tools process your input to produce the result; sensitive values are redacted before anything is logged, and request logs are retained for operations only.</p>' . "\n"
            . '<h2 class="ch247-section__title">Acceptable use</h2>' . "\n"
            . '<p>Use the scanners and network tools only against systems you own or are authorised to test. Automated or abusive use may be rate limited.</p>' . "\n"
            . '<h2 class="ch247-section__title">No warranty</h2>' . "\n"
            . '<p>The tools are provided as-is, without warranty of any kind. CloudHost247 is not liable for actions taken on the basis of tool output.</p>' . "\n"
            . '</div>' . "\n"
            . '</div>' . "\n";
    }

    protected static function disabledPage(array $tool)
    {
        $e = [__CLASS__, 'e'];

        return '<div class="ch247-wrap">' . "\n"
            . '<div class="ch247-hero">' . "\n"
            . '<h1 class="ch247-hero__title">' . call_user_func($e, $tool['name']) . ' is unavailable</h1>' . "\n"
            . '<p class="ch247-hero__lede">This tool has been disabled by the site administrator.</p>' . "\n"
            . '</div>' . "\n"
            . '<p class="ch247-prose"><a href="/tools">Browse all tools</a> · <a href="/tools/search">Search tools</a></p>' . "\n"
            . '</div>' . "\n";
    }

    protected static function notFoundPage(array $route)
    {
        $e = [__CLASS__, 'e'];
        $suggestions = '';
        foreach ($route['suggestions'] as $tool) {
            if (is_array($tool) && isset($tool['route'], $tool['name'])) {
                $suggestions .= self::toolCard($tool);
            }
        }
        if ($suggestions !== '') {
            $suggestions = '<h2 class="ch247-section__title">Did you mean…</h2>' . "\n"
                . '<div class="ch247-grid">' . "\n" . $suggestions . '</div>' . "\n";
        }

        return '<div class="ch247-wrap">' . "\n"
            . '<div class="ch247-hero">' . "\n"
            . '<p class="ch247-hero__eyebrow">404</p>' . "\n"
            . '<h1 class="ch247-hero__title">Tool not found</h1>' . "\n"
            . '<p class="ch247-hero__lede">Nothing lives at &ldquo;' . call_user_func($e, $route['route']) . '&rdquo;. It may have been renamed or removed.</p>' . "\n"
            . '</div>' . "\n"
            . $suggestions
            . '<p class="ch247-prose"><a href="/tools">Browse all tools</a> · <a href="/tools/search">Search tools</a></p>' . "\n"
            . '</div>' . "\n";
    }

    /**
     * HTML-escape a value for page output.
     */
    public static function e($value)
    {
        return CloudHost247ToolsSecurity::e($value);
    }
}
