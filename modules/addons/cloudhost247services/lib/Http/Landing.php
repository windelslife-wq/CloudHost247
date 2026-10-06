<?php
/**
 * Landing-page runtime shared by the fifteen public CLOUDHOST247 pages.
 *
 * One responsibility: give each root-level PHP landing a single line to
 *   1. initialise the WHMCS ClientArea page shell consistently,
 *   2. attach module data + SEO to Smarty,
 *   3. render with the suite's chrome settings.
 *
 * The module may be mid-upgrade when a landing loads, so every public
 * landing degrades to an explicit "service is being prepared" notice
 * instead of a fatal — silent navigation to a dead page is not acceptable.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

use Chs\Core\I18n;
use Chs\Core\Settings;

class Landing
{
    /** @var array pending SEO payload for the head hook */
    private static $seo = [];

    /** @var \WHMCS\ClientArea|null */
    private static $area;

    /**
     * Standard opener for a public landing page.
     *
     * @param string $templateFile e.g. 'chs-valuation'
     * @param string $title        browser/tab + breadcrumb title
     * @param string $scriptName   e.g. 'domain-valuation.php'
     * @return \WHMCS\ClientArea
     */
    public static function start($templateFile, $title, $scriptName)
    {
        $titleKeys = [
            'chs-ai-builder' => 'ai_website_builder',
            'chs-discount-club' => 'discount_domain_club',
            'chs-auctions' => 'domain_auctions',
            'chs-valuation' => 'domain_valuation',
            'chs-unified-inbox' => 'unified_inbox',
        ];
        if (isset($titleKeys[$templateFile])) {
            $title = I18n::text($titleKeys[$templateFile], $title);
        }
        $ca = new \WHMCS\ClientArea();
        $ca->setPageTitle($title);
        $ca->addToBreadCrumb('index.php', \WHMCS\Language\Lang::trans('globalsystemname'));
        $ca->addToBreadCrumb($scriptName, $title);
        $ca->initPage();
        $ca->assign('sidebarHostxRemove', 'true');
        $ca->assign('chsMetaTitle', $title);
        self::$area = $ca;
        return $ca;
    }

    /** Register SEO markup the head hook will inject into <head>. */
    public static function seo(array $seo)
    {
        self::$seo = array_merge(self::$seo, $seo);
    }

    /**
     * Called from ClientAreaHeadOutput: emitted meta description / OG /
     * canonical for suite landings + client pages (theme does not know
     * about dynamic suite content).
     *
     * @param array $vars WHMCS hook vars
     * @return string
     */
    public static function headMarkup(array $vars, $canonicalPath = '')
    {
        $out = [];
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        if (!empty(self::$seo['description'])) {
            $out[] = '<meta name="description" content="' . $esc(self::$seo['description']) . '">';
        }
        if (!empty(self::$seo['robots'])) {
            $out[] = '<meta name="robots" content="' . $esc(self::$seo['robots']) . '">';
        }
        $ogTitle = !empty(self::$seo['og_title']) ? self::$seo['og_title']
            : (!empty(self::$seo['title']) ? self::$seo['title'] : '');
        if ($ogTitle !== '') {
            $out[] = '<meta property="og:title" content="' . $esc($ogTitle) . '">';
            $out[] = '<meta property="og:type" content="website">';
            $out[] = '<meta property="og:site_name" content="CLOUDHOST247">';
        }
        if (!empty(self::$seo['og_desc'])) {
            $out[] = '<meta property="og:description" content="' . $esc(self::$seo['og_desc']) . '">';
        }
        if (!empty(self::$seo['og_image'])) {
            $out[] = '<meta property="og:image" content="' . $esc(self::$seo['og_image']) . '">';
        }
        if (!empty(self::$seo['noindex'])) {
            $out[] = '<meta name="robots" content="noindex,follow">';
        }
        if ($canonicalPath === '' && !empty(self::$seo['canonical'])) {
            $canonicalPath = (string) self::$seo['canonical'];
        }
        if ($canonicalPath !== '') {
            $systemUrl = rtrim(Settings::string('system_url', rtrim((string) (isset($vars['systemurl']) ? $vars['systemurl'] : ''), '/')), '/');
            if ($systemUrl !== '') {
                $out[] = '<link rel="canonical" href="' . $esc($systemUrl . '/' . ltrim($canonicalPath, '/')) . '">';
                $out[] = '<meta property="og:url" content="' . $esc($systemUrl . '/' . ltrim($canonicalPath, '/')) . '">';
            }
        }
        if (!empty(self::$seo['jsonld']) && is_array(self::$seo['jsonld'])) {
            $out[] = '<script type="application/ld+json">'
                . json_encode(self::$seo['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '</script>';
        }
        return implode("\n", $out);
    }

    /**
     * Finish: assign variables and render.
     *
     * @param \WHMCS\ClientArea $ca
     * @param string            $templateFile
     * @param array             $vars
     */
    public static function render($ca, $templateFile, array $vars = [])
    {
        foreach ($vars as $k => $v) {
            $ca->assign($k, $v);
        }
        $ca->setTemplate($templateFile);
        $ca->output();
    }

    /** True when the module layer is operational for the current request. */
    public static function moduleReady()
    {
        try {
            return Settings::bool('service_enabled', true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** One canonical "service not ready" payload used across landings. */
    public static function unavailableVars($featureTitle)
    {
        return [
            'chsUnavailable'     => true,
            'chsUnavailableWhy'  => $featureTitle . ' is being prepared on this host. '
                . 'If you are the site administrator, enable the cloudhost247services addon '
                . 'and complete its setup; visitors can already reach every other service.',
            'chsSupportLink'     => 'submitticket.php',
        ];
    }
}
