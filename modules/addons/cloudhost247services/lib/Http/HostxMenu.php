<?php
/**
 * HostX mega-menu contributions.
 *
 * The HostX theme renders its top mega menu (desktop variants AND the mobile
 * off-canvas drawer) from the Smarty variable $topMenusData, which its own
 * (encrypted) addon assembles from the theme's menu settings. We cannot edit
 * that pipeline — so instead we merge well-formed entries into the same
 * variable after the theme has produced it, exactly once per request and
 * only when the variable exists (i.e. the theme is rendering its nav).
 *
 * Entry shape mirrors what top-mega-menu-default.tpl / mobile-menu.tpl
 * consume: menutype 3 (grouped mega menu) with submenu groups each holding
 * childsubmenu link rows. Item names may carry a Bootstrap badge span — the
 * templates output names verbatim.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

class HostxMenu
{
    /**
     * Full set of suite mega-menu entries (four top categories).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function entries()
    {
        return [
            self::top('Domains', 'Everything for the name you build on: search, transfers, '
                . 'WHOIS, valuation, auctions and a club that actually lowers per-domain cost.',
                'domain-search.php', 'Find a domain', [
                self::group('Register & manage', [
                    self::item('Domain Search', 'domain-search.php', 'fa fa-search', 'POPULAR'),
                    self::item('Bulk Domain Search', 'bulk-domain-search.php', 'fa fa-list'),
                    self::item('Transfer a Domain', 'domain-transfer.php', 'fa fa-exchange-alt'),
                    self::item('Domain Extensions & Pricing', 'tld-directory.php', 'fa fa-tags'),
                    self::item('WHOIS Lookup', 'whois-lookup.php', 'fa fa-id-card'),
                ]),
                self::group('Invest & save', [
                    self::item('Domain Valuation', 'domain-valuation.php', 'fa fa-chart-line', 'NEW'),
                    self::item('Domain Auctions', 'domain-auctions.php', 'fa fa-gavel'),
                    self::item('Domain Broker', 'domain-broker.php', 'fa fa-handshake'),
                    self::item('Discount Domain Club', 'discount-domain-club.php', 'fa fa-percent'),
                ]),
            ]),
            self::top('Websites & Builders', 'Three honest paths to launch — self-hosted builds, '
                . 'AI-assisted drafting, or a professionally designed delivery.',
                'website-builder.php', 'Build a website', [
                self::group('Do it yourself', [
                    self::item('Website Builder', 'website-builder.php', 'fa fa-tools'),
                    self::item('AI Website Builder', 'ai-website-builder.php', 'fa fa-magic', 'NEW'),
                    self::item('Online Store', 'online-store.php', 'fa fa-shopping-bag'),
                    self::item('WordPress Hosting', 'wordpress-hosting.php', 'fa fa-wordpress'),
                ]),
                self::group('Done for you', [
                    self::item('Website Design Service', 'website-design.php', 'fa fa-drafting-compass'),
                    self::item('Hire an Expert', 'hire-an-expert.php', 'fa fa-user-tie', 'POPULAR'),
                    self::item('Logo Maker', 'logo-maker.php', 'fa fa-signature', 'NEW'),
                ]),
            ]),
            self::top('Marketing', 'Campaigns, identity and inbox — measured against your own '
                . 'analytics, not a slideshow.',
                'digital-marketing.php', 'Grow traffic', [
                self::group('Growth services', [
                    self::item('Digital Marketing', 'digital-marketing.php', 'fa fa-bullhorn', 'POPULAR'),
                    self::item('SEO & Technical Audits', 'digital-marketing.php#seo', 'fa fa-search-plus'),
                    self::item('Logo Maker', 'logo-maker.php', 'fa fa-signature', 'NEW'),
                ]),
                self::group('Operate', [
                    self::item('Unified Inbox', 'unified-inbox.php', 'fa fa-inbox'),
                    self::item('SSL Certificates', 'ssl-certificate.php', 'fa fa-lock'),
                ]),
            ]),
            self::top('Hosting & Services', 'From cPanel to dedicated — hosting with the same '
                . 'behind-the-scenes team that runs this site.',
                'web-hosting.php', 'Compare plans', [
                self::group('Hosting', [
                    self::item('Web Hosting', 'web-hosting.php', 'fa fa-server', 'POPULAR'),
                    self::item('cPanel Hosting', 'cpanel-hosting.php', 'fa fa-cogs'),
                    self::item('WordPress Hosting', 'wordpress-hosting.php', 'fa fa-wordpress'),
                    self::item('VPS Hosting', 'vps-hosting.php', 'fa fa-hdd'),
                    self::item('Dedicated Servers', 'dedicated-server.php', 'fa fa-microchip'),
                ]),
                self::group('Care', [
                    self::item('Hire an Expert', 'hire-an-expert.php', 'fa fa-user-tie'),
                    self::item('Help Center', 'help-center.php', 'fa fa-life-ring'),
                ]),
            ]),
        ];
    }

    /** @param array<int,array<string,mixed>> $groups */
    private static function top($name, $description, $captionUrl, $captionLabel, array $groups)
    {
        return [
            'name'                => $name,
            'url'                 => '#',
            'menutype'            => 3,
            'menuthirdparty'      => 0,
            'menunewtab'          => 0,
            'description'         => $description,
            'caption_button_name' => $captionLabel,
            'menu_caption_url'    => $captionUrl,
            'submenu'             => $groups,
        ];
    }

    /** Menu group column with childsubmenu rows. */
    private static function group($name, array $children)
    {
        return [
            'name'           => $name,
            'url'            => '#',
            'icon'           => '',
            'menuthirdparty' => 0,
            'menunewtab'     => 0,
            'childsubmenu'   => $children,
        ];
    }

    /** Link row; $badge renders 'NEW'/'POPULAR' pill after the label. */
    private static function item($label, $url, $icon, $badge = '')
    {
        $name = $label;
        if ($badge === 'NEW') {
            $name .= ' <span class="badge badge-danger chs-nav-badge">NEW</span>';
        } elseif ($badge === 'POPULAR') {
            $name .= ' <span class="badge badge-primary chs-nav-badge">POPULAR</span>';
        }
        return [
            'name'           => $name,
            'url'            => $url,
            'icon'           => $icon,
            'menuthirdparty' => 0,
            'menunewtab'     => 0,
        ];
    }

    /**
     * Merge entries into a rendered $topMenusData array (idempotent by name).
     *
     * @param array<int,mixed> $menus
     * @return array<int,mixed>
     */
    public static function merge(array $menus)
    {
        $existing = [];
        foreach ($menus as $top) {
            if (is_array($top) && isset($top['name'])) {
                $existing[strtolower(strip_tags((string) $top['name']))] = true;
            }
        }
        foreach (self::entries() as $entry) {
            if (!isset($existing[strtolower($entry['name'])])) {
                $menus[] = $entry;
            }
        }
        return $menus;
    }
}
