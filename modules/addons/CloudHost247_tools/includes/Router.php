<?php
/**
 * CloudHost247 Tools - Router.
 *
 * Maps a request path under /tools to a page descriptor:
 *
 *   /tools                    -> index      (landing page, all 91 tools)
 *   /tools/disclaimer         -> disclaimer
 *   /tools/search?q=...       -> search
 *   /tools/{category}         -> category   (9 category pages)
 *   /tools/{slug}             -> tool       (91 individual tool pages)
 *   /tools/api/{slug}         -> api        (JSON execution endpoint)
 *
 * Category ids and tool slugs share one namespace, so categories are
 * matched first and the registry guarantees they never collide.
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Catalog.php';

class CloudHost247ToolsRouter
{
    /** Reserved first segments that are pages rather than tools. */
    const RESERVED = ['disclaimer', 'search', 'api', 'sitemap.xml'];

    /**
     * Resolve a request path to a route descriptor.
     *
     * @param string $path  e.g. "/tools/dns-lookup" or "tools/dns"
     * @param array  $query Query string parameters.
     *
     * @return array{type:string, ...}
     */
    public static function resolve($path, array $query = [])
    {
        $path = parse_url((string) $path, PHP_URL_PATH);
        $path = trim((string) $path, '/');

        // Strip an optional leading "tools" segment.
        $segments = $path === '' ? [] : explode('/', $path);
        if (isset($segments[0]) && $segments[0] === 'tools') {
            array_shift($segments);
        }
        $segments = array_values(array_filter($segments, function ($s) {
            return $s !== '';
        }));

        if (empty($segments)) {
            return ['type' => 'index', 'route' => '/tools'];
        }

        $first = strtolower($segments[0]);

        // --- API endpoint -------------------------------------------------
        if ($first === 'api') {
            $slug = isset($segments[1]) ? strtolower($segments[1]) : (string) ($query['tool'] ?? '');
            if ($slug === '') {
                return self::notFound('/tools/api');
            }

            return ['type' => 'api', 'slug' => $slug, 'route' => '/tools/api/' . $slug];
        }

        // --- Static pages ---------------------------------------------------
        if ($first === 'disclaimer') {
            return ['type' => 'disclaimer', 'route' => '/tools/disclaimer'];
        }

        if ($first === 'search') {
            $q = trim((string) ($query['q'] ?? ''));

            return [
                'type'    => 'search',
                'query'   => $q,
                'results' => $q === '' ? [] : CloudHost247ToolsCatalog::search($q),
                'route'   => '/tools/search',
            ];
        }

        if ($first === 'sitemap.xml') {
            return ['type' => 'sitemap', 'route' => '/tools/sitemap.xml'];
        }

        // --- Category page ----------------------------------------------
        $category = CloudHost247ToolsCatalog::category($first);
        if ($category) {
            // /tools/{category}/{slug} is a valid alias for /tools/{slug}
            if (isset($segments[1])) {
                $tool = CloudHost247ToolsCatalog::tool(strtolower($segments[1]));
                if ($tool && $tool['category'] === $first) {
                    return ['type' => 'redirect', 'to' => $tool['route'], 'status' => 301];
                }

                return self::notFound('/tools/' . $path);
            }

            return [
                'type'     => 'category',
                'category' => $category,
                'route'    => $category['route'],
            ];
        }

        // --- Individual tool page ----------------------------------------
        if (count($segments) === 1) {
            $tool = CloudHost247ToolsCatalog::tool($first);
            if ($tool) {
                return [
                    'type'    => 'tool',
                    'tool'    => $tool,
                    'related' => CloudHost247ToolsCatalog::related($tool['slug'], 4),
                    'route'   => $tool['route'],
                ];
            }

            // Helpful 404: suggest the closest matching tools.
            return self::notFound('/tools/' . $first, CloudHost247ToolsCatalog::suggest($first, 5));
        }

        return self::notFound('/tools/' . $path);
    }

    protected static function notFound($route, array $suggestions = [])
    {
        return [
            'type'        => 'notfound',
            'route'       => $route,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Breadcrumb trail for a resolved route (also drives BreadcrumbList).
     */
    public static function breadcrumbs(array $route)
    {
        $crumbs = [['name' => 'Home', 'url' => '/'], ['name' => 'Tools', 'url' => '/tools']];

        if ($route['type'] === 'category') {
            $crumbs[] = ['name' => $route['category']['name'], 'url' => $route['category']['route']];
        } elseif ($route['type'] === 'tool') {
            $cat = CloudHost247ToolsCatalog::category($route['tool']['category']);
            if ($cat) {
                $crumbs[] = ['name' => $cat['name'], 'url' => $cat['route']];
            }
            $crumbs[] = ['name' => $route['tool']['name'], 'url' => $route['tool']['route']];
        } elseif ($route['type'] === 'disclaimer') {
            $crumbs[] = ['name' => 'Disclaimer', 'url' => '/tools/disclaimer'];
        } elseif ($route['type'] === 'search') {
            $crumbs[] = ['name' => 'Search', 'url' => '/tools/search'];
        }

        return $crumbs;
    }

    /**
     * Page <title> and meta description for a resolved route.
     */
    public static function meta(array $route)
    {
        $total = CloudHost247ToolsCatalog::count();

        switch ($route['type']) {
            case 'tool':
                return [
                    'title'       => $route['tool']['seo_title'],
                    'description' => $route['tool']['seo_description'],
                    'canonical'   => $route['tool']['route'],
                    'robots'      => ($route['tool']['status'] ?? 'active') === 'active' ? 'index,follow' : 'noindex,follow',
                ];

            case 'category':
                return [
                    'title'       => $route['category']['name'] . ' Tools - ' . $route['category']['count'] . ' Free Online Utilities',
                    'description' => $route['category']['description'],
                    'canonical'   => $route['category']['route'],
                    'robots'      => 'index,follow',
                ];

            case 'disclaimer':
                return [
                    'title'       => 'Tools Disclaimer - CloudHost247',
                    'description' => 'How the CloudHost247 online tools work, what they measure, their limitations, and the terms of acceptable use.',
                    'canonical'   => '/tools/disclaimer',
                    'robots'      => 'index,follow',
                ];

            case 'search':
                return [
                    'title'       => 'Search Tools - CloudHost247',
                    'description' => 'Search ' . $total . ' free online tools for DNS, IP, development, SEO, networking and security.',
                    'canonical'   => '/tools/search',
                    'robots'      => 'noindex,follow',
                ];

            case 'notfound':
                return [
                    'title'       => 'Tool Not Found - CloudHost247',
                    'description' => 'That tool could not be found. Browse all ' . $total . ' free CloudHost247 online tools.',
                    'canonical'   => '/tools',
                    'robots'      => 'noindex,follow',
                ];

            default:
                return [
                    'title'       => $total . ' Free Online Tools - DNS, IP, Developer & SEO | CloudHost247',
                    'description' => 'Free online tools from CloudHost247: DNS lookup and propagation, IP and subnet utilities, developer converters, SEO and webmaster checks, network diagnostics and security scanners. No sign-up required.',
                    'canonical'   => '/tools',
                    'robots'      => 'index,follow',
                ];
        }
    }

    /**
     * JSON-LD structured data for a resolved route.
     *
     * Deliberately emits no aggregateRating / review markup: CloudHost247
     * collects no ratings for these tools, and publishing invented ones
     * would be fabricated data and a search-guideline violation.
     */
    public static function structuredData(array $route, $siteUrl = '')
    {
        $siteUrl = rtrim($siteUrl, '/');
        $abs = function ($path) use ($siteUrl) {
            return $siteUrl . $path;
        };

        $graph = [];

        $crumbs = self::breadcrumbs($route);
        $items  = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $abs($crumb['url']),
            ];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];

        if ($route['type'] === 'tool') {
            $tool = $route['tool'];
            $app  = [
                '@type'           => 'SoftwareApplication',
                'name'            => $tool['name'],
                'url'             => $abs($tool['route']),
                'description'     => $tool['description'],
                'applicationCategory' => 'DeveloperApplication',
                'operatingSystem' => 'Any (web browser)',
                'browserRequirements' => 'Requires JavaScript',
                'isAccessibleForFree' => true,
                'offers'          => [
                    '@type'        => 'Offer',
                    'price'        => '0',
                    'priceCurrency'=> 'USD',
                ],
                'provider'        => [
                    '@type' => 'Organization',
                    'name'  => 'CloudHost247',
                    'url'   => $siteUrl ?: '/',
                ],
            ];
            $graph[] = $app;

            if (!empty($tool['faq'])) {
                $faq = [];
                foreach ($tool['faq'] as $item) {
                    $faq[] = [
                        '@type'          => 'Question',
                        'name'           => $item['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                    ];
                }
                $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
            }
        } elseif ($route['type'] === 'category') {
            $elements = [];
            foreach ($route['category']['tools'] as $i => $tool) {
                $elements[] = [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $tool['name'],
                    'url'      => $abs($tool['route']),
                ];
            }
            $graph[] = [
                '@type'           => 'CollectionPage',
                'name'            => $route['category']['name'] . ' Tools',
                'url'             => $abs($route['category']['route']),
                'description'     => $route['category']['description'],
                'mainEntity'      => ['@type' => 'ItemList', 'itemListElement' => $elements],
            ];
        } elseif ($route['type'] === 'index') {
            $graph[] = [
                '@type'       => 'CollectionPage',
                'name'        => 'Free Online Tools',
                'url'         => $abs('/tools'),
                'description' => 'A suite of ' . CloudHost247ToolsCatalog::count() . ' free online tools for DNS, IP, development, SEO, networking and security.',
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * Sitemap entries for public tool pages only.
     *
     * Search, API and any non-active tool are excluded.
     */
    public static function sitemapEntries($siteUrl = '')
    {
        $siteUrl = rtrim($siteUrl, '/');
        $today   = gmdate('Y-m-d');
        $entries = [
            ['loc' => $siteUrl . '/tools', 'changefreq' => 'weekly', 'priority' => '0.9', 'lastmod' => $today],
        ];

        foreach (CloudHost247ToolsCatalog::categories() as $cat) {
            $entries[] = [
                'loc'        => $siteUrl . $cat['route'],
                'changefreq' => 'weekly',
                'priority'   => '0.8',
                'lastmod'    => $today,
            ];
        }

        foreach (CloudHost247ToolsCatalog::tools() as $tool) {
            if (($tool['status'] ?? 'active') !== 'active') {
                continue;
            }
            $entries[] = [
                'loc'        => $siteUrl . $tool['route'],
                'changefreq' => 'monthly',
                'priority'   => '0.7',
                'lastmod'    => $today,
            ];
        }

        $entries[] = [
            'loc'        => $siteUrl . '/tools/disclaimer',
            'changefreq' => 'yearly',
            'priority'   => '0.3',
            'lastmod'    => $today,
        ];

        return $entries;
    }

    /**
     * Render the sitemap entries as an XML urlset.
     */
    public static function sitemapXml($siteUrl = '')
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::sitemapEntries($siteUrl) as $entry) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($entry['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= '    <lastmod>' . $entry['lastmod'] . "</lastmod>\n";
            $xml .= '    <changefreq>' . $entry['changefreq'] . "</changefreq>\n";
            $xml .= '    <priority>' . $entry['priority'] . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Per-route asset list, so a tool page loads only its own JavaScript.
     */
    public static function assets(array $route)
    {
        $css = ['/modules/addons/CloudHost247_tools/assets/css/tools-core.css'];
        $js  = ['/modules/addons/CloudHost247_tools/assets/js/tools-core.js'];

        if ($route['type'] === 'tool') {
            $js[] = '/modules/addons/CloudHost247_tools/assets/js/tools/' . $route['tool']['slug'] . '.js';
        }
        // Browse pages (index/category/search) need no extra script: the live
        // filter, FAQ accordions and menus are initialised by tools-core.js
        // itself on every page. (A tools-browse.js URL was listed here before
        // A-6, but the file never existed, so it 404'd on every browse page.)

        return ['css' => $css, 'js' => $js];
    }
}
