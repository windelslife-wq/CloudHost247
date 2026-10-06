<?php
/**
 * CloudHost247 Tools - Catalog
 *
 * Loads and queries the authoritative tool registry
 * (config/cloudhost247-tools.php). Admin overrides stored in
 * mod_CloudHost247_tools_registry are layered on top when available, so tool
 * metadata can be managed without code changes.
 *
 * @package CloudHost247\Tools
 */

if (!defined('WHMCS') && !defined('CLOUDHOST247_TOOLS')) {
    die('This file cannot be accessed directly');
}

class CloudHost247ToolsCatalog
{
    /** @var array|null */
    protected static $data = null;

    /** @var array|null */
    protected static $overrides = null;

    /**
     * Absolute path to the registry file.
     */
    public static function registryPath()
    {
        return dirname(__DIR__) . '/config/cloudhost247-tools.php';
    }

    /**
     * Load (and memoise) the registry, applying any admin overrides.
     */
    public static function load()
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $data = require self::registryPath();

        if (!is_array($data) || empty($data['tools']) || empty($data['categories'])) {
            throw new RuntimeException('CloudHost247 tools registry is missing or malformed.');
        }

        foreach ($data['tools'] as $slug => &$tool) {
            $tool['url']           = self::url($slug);
            $tool['category_name'] = isset($data['categories'][$tool['category']])
                ? $data['categories'][$tool['category']]['name']
                : ucfirst($tool['category']);
        }
        unset($tool);

        $overrides = self::overrides();
        foreach ($overrides as $slug => $row) {
            if (!isset($data['tools'][$slug])) {
                continue;
            }
            foreach (['name', 'description', 'icon', 'seo_title', 'seo_description', 'status'] as $field) {
                if (isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null) {
                    $data['tools'][$slug][$field] = $row[$field];
                }
            }
            if (isset($row['enabled'])) {
                $data['tools'][$slug]['enabled'] = (bool) $row['enabled'];
            }
            if (isset($row['sort_order'])) {
                $data['tools'][$slug]['sort_order'] = (int) $row['sort_order'];
            }
            if (isset($row['rate_limit']) && (int) $row['rate_limit'] > 0) {
                $data['tools'][$slug]['rate_limit'] = (int) $row['rate_limit'];
            }
        }

        foreach ($data['tools'] as &$t) {
            if (!array_key_exists('enabled', $t)) {
                $t['enabled'] = true;
            }
            if (!array_key_exists('sort_order', $t)) {
                $t['sort_order'] = $t['id'];
            }
        }
        unset($t);

        self::$data = $data;

        return self::$data;
    }

    /**
     * Admin overrides from the database (optional - table may not exist yet).
     */
    protected static function overrides()
    {
        if (self::$overrides !== null) {
            return self::$overrides;
        }

        self::$overrides = [];

        if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            return self::$overrides;
        }

        try {
            $rows = \Illuminate\Database\Capsule\Manager::table('mod_CloudHost247_tools_registry')->get();
            foreach ($rows as $row) {
                $row = (array) $row;
                if (!empty($row['slug'])) {
                    self::$overrides[$row['slug']] = $row;
                }
            }
        } catch (\Exception $e) {
            // Table not installed yet - registry defaults apply.
            self::$overrides = [];
        }

        return self::$overrides;
    }

    /**
     * Public URL for a tool slug.
     */
    public static function url($slug)
    {
        return '/tools/' . $slug;
    }

    /**
     * Every tool, keyed by slug.
     *
     * @param bool $enabledOnly
     */
    public static function tools($enabledOnly = true)
    {
        $data  = self::load();
        $tools = $data['tools'];

        if ($enabledOnly) {
            $tools = array_filter($tools, function ($t) {
                return !empty($t['enabled']);
            });
        }

        uasort($tools, function ($a, $b) {
            return $a['sort_order'] <=> $b['sort_order'];
        });

        return $tools;
    }

    /**
     * Categories, each with its tools attached.
     */
    public static function categories($enabledOnly = true)
    {
        $data  = self::load();
        $cats  = $data['categories'];
        $tools = self::tools($enabledOnly);

        foreach ($cats as $id => &$cat) {
            $cat['tools'] = array_values(array_filter($tools, function ($t) use ($id) {
                return $t['category'] === $id;
            }));
            $cat['count'] = count($cat['tools']);
        }
        unset($cat);

        return $cats;
    }

    public static function category($id, $enabledOnly = true)
    {
        $cats = self::categories($enabledOnly);

        return isset($cats[$id]) ? $cats[$id] : null;
    }

    /**
     * A single tool by slug.
     */
    public static function tool($slug)
    {
        $data = self::load();

        return isset($data['tools'][$slug]) ? $data['tools'][$slug] : null;
    }

    /**
     * Resolve a legacy handler id (e.g. "dns_lookup") to its tool.
     */
    public static function byHandler($handler)
    {
        foreach (self::load()['tools'] as $tool) {
            if ($tool['handler'] === $handler) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Related tools for a slug, resolved to full records.
     */
    public static function related($slug, $limit = 4)
    {
        $tool = self::tool($slug);
        if (!$tool) {
            return [];
        }

        $out = [];
        foreach ($tool['related'] as $rel) {
            $r = self::tool($rel);
            if ($r && !empty($r['enabled']) && $r['slug'] !== $slug) {
                $out[] = $r;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        // Backfill from the same category if the curated list came up short.
        if (count($out) < $limit) {
            foreach (self::tools() as $t) {
                if ($t['category'] === $tool['category'] && $t['slug'] !== $slug) {
                    $already = false;
                    foreach ($out as $o) {
                        if ($o['slug'] === $t['slug']) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $out[] = $t;
                    }
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Free-text search across name, description, slug and category.
     */
    public static function search($query, $limit = 50)
    {
        $query = trim(mb_strtolower((string) $query));
        if ($query === '') {
            return [];
        }

        $scored = [];
        foreach (self::tools() as $tool) {
            $name = mb_strtolower($tool['name']);
            $slug = mb_strtolower($tool['slug']);
            $desc = mb_strtolower($tool['description']);
            $cat  = mb_strtolower($tool['category_name']);

            $score = 0;
            if ($name === $query || $slug === $query) {
                $score = 100;
            } elseif (strpos($name, $query) === 0 || strpos($slug, $query) === 0) {
                $score = 80;
            } elseif (strpos($name, $query) !== false || strpos($slug, $query) !== false) {
                $score = 60;
            } elseif (strpos($cat, $query) !== false) {
                $score = 40;
            } elseif (strpos($desc, $query) !== false) {
                $score = 20;
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'tool' => $tool];
            }
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp($a['tool']['name'], $b['tool']['name']);
            }

            return $b['score'] <=> $a['score'];
        });

        return array_map(function ($s) {
            return $s['tool'];
        }, array_slice($scored, 0, $limit));
    }

    /**
     * Curated "popular" set for the landing page and mega menu.
     */
    public static function popular($limit = 12)
    {
        $slugs = [
            'dns-propagation-checker', 'dns-lookup', 'my-ip', 'ssl-checker',
            'whois' /* ignored if absent */, 'domain-whois', 'mx-lookup', 'ip-location',
            'http-headers', 'password-generator', 'qr-generator', 'domain-search',
            'json-tool', 'traceroute', 'port-checker', 'word-counter',
        ];

        $out = [];
        foreach ($slugs as $slug) {
            $t = self::tool($slug);
            if ($t && !empty($t['enabled'])) {
                $out[] = $t;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Most recently added tools (highest registry ids).
     */
    public static function recent($limit = 6)
    {
        $tools = self::tools();
        usort($tools, function ($a, $b) {
            return $b['id'] <=> $a['id'];
        });

        return array_slice($tools, 0, $limit);
    }

    public static function count($enabledOnly = true)
    {
        return count(self::tools($enabledOnly));
    }

    /**
     * Every public route exposed by the platform (for sitemap + verification).
     */
    public static function routes()
    {
        $routes = ['/tools'];
        foreach (self::load()['categories'] as $cat) {
            $routes[] = $cat['route'];
        }
        foreach (self::tools() as $tool) {
            $routes[] = $tool['route'];
        }
        $routes[] = '/tools/disclaimer';

        return $routes;
    }
}
