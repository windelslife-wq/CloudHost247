<?php
/**
 * sitemap.xml generator.
 *
 * Rewrites the site-root sitemap.xml from the canonical list of public pages
 * below. Runs from the module cron; honest by construction — a URL is only
 * emitted when the backing page genuinely exists in the install, so the
 * sitemap can never advertise a dead link.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

use Chs\Core\Clock;
use Chs\Core\Logger;
use Chs\Core\Settings;

class SitemapService
{
    /**
     * Public pages that deserve index coverage, path => priority.
     * Ordered by business importance, not alphabetically.
     *
     * @var array<string,string>
     */
    private const PAGES = [
        'index.php'                 => '1.0',
        'domains.php'               => '0.9',
        'domain-search.php'         => '0.9',
        'bulk-domain-search.php'    => '0.8',
        'domain-transfer.php'       => '0.8',
        'tld-directory.php'         => '0.8',
        'whois-lookup.php'          => '0.7',
        'domain-valuation.php'      => '0.8',
        'domain-auctions.php'       => '0.8',
        'domain-broker.php'         => '0.8',
        'discount-domain-club.php'  => '0.7',
        'website-builder.php'       => '0.8',
        'ai-website-builder.php'    => '0.8',
        'online-store.php'          => '0.7',
        'website-design.php'        => '0.7',
        'hire-an-expert.php'        => '0.8',
        'digital-marketing.php'     => '0.7',
        'logo-maker.php'            => '0.7',
        'unified-inbox.php'         => '0.6',
        'web-hosting.php'           => '0.8',
        'wordpress-hosting.php'     => '0.8',
        'cpanel-hosting.php'        => '0.7',
        'vps-hosting.php'           => '0.7',
        'dedicated-server.php'      => '0.6',
        'ssl-certificate.php'       => '0.6',
        'help-center.php'           => '0.5',
        'aboutus.php'               => '0.4',
        'terms-of-service.php'      => '0.3',
        'privacy-policy.php'        => '0.3',
        'acceptable-use-policy.php' => '0.3',
        'refund-policy.php'         => '0.3',
    ];

    /** Base site URL (scheme + host, no trailing slash). */
    public function baseUrl()
    {
        $base = trim(Settings::string('system_url', ''));
        if ($base === '') {
            // Fall back to the WHMCS SystemURL when the module table exists.
            try {
                $rows = \Chs\Core\Db::query(
                    "SELECT value FROM tblconfiguration WHERE setting = 'SystemURL'"
                );
                if ($rows) {
                    $base = trim((string) $rows[0]['value']);
                }
            } catch (\Throwable $e) {
                // leave empty — generator will fail loudly below
            }
        }
        return rtrim($base, '/');
    }

    /**
     * Compose the sitemap XML for pages that exist under $root.
     *
     * @return string pretty-printed XML document
     */
    public function build($baseUrl, $root)
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::PAGES as $page => $priority) {
            $file = rtrim($root, '/') . '/' . $page;
            if (!is_file($file)) {
                continue;
            }
            $x .= '  <url>' . "\n";
            $x .= '    <loc>' . chs_h($baseUrl . '/' . $page) . '</loc>' . "\n";
            $x .= '    <lastmod>' . gmdate('Y-m-d', (int) @filemtime($file)) . '</lastmod>' . "\n";
            $x .= '    <changefreq>' . ($this->changefreq($page)) . '</changefreq>' . "\n";
            $x .= '    <priority>' . $priority . '</priority>' . "\n";
            $x .= '  </url>' . "\n";
        }
        $x .= '</urlset>' . "\n";
        return $x;
    }

    private function changefreq($page)
    {
        if (in_array($page, ['domain-auctions.php', 'tld-directory.php'], true)) {
            return 'daily';
        }
        if (strpos($page, 'policy') !== false || strpos($page, 'terms') !== false) {
            return 'yearly';
        }
        return 'weekly';
    }

    /**
     * Regenerate {siteRoot}/sitemap.xml. Returns the number of URLs written,
     * or -1 when the base URL is not configured (explicit signal, never a
     * silent no-op).
     */
    public function regenerate()
    {
        $base = $this->baseUrl();
        if ($base === '') {
            Logger::warning('Sitemap regeneration skipped: system_url is not configured');
            return -1;
        }
        $root = rtrim((string) (defined('CHS_ROOT') ? CHS_ROOT : ''), '/');
        if ($root === '' || !is_dir($root)) {
            Logger::warning('Sitemap regeneration skipped: site root not resolvable');
            return -1;
        }
        $xml = $this->build($base, $root);
        $target = $root . '/sitemap.xml';
        $urls = substr_count($xml, '<url>');
        if (@file_put_contents($target, $xml) === false) {
            Logger::warning('Sitemap regeneration failed: cannot write ' . $target);
            return -1;
        }
        Logger::info('Sitemap regenerated', ['urls' => $urls]);
        return $urls;
    }
}
