<?php
/**
 * AI website-builder provider abstraction.
 *
 * A provider receives a structured brief and must return a complete site
 * outline. The module stores and renders whatever the provider produced —
 * no fabrication happens module-side.
 *
 * @package Chs\Providers\Ai
 */

namespace Chs\Providers\Ai;

interface AiProviderInterface
{
    public function providerId();

    /** True when the provider has the credentials and endpoint it needs. */
    public function isConfigured();

    /**
     * @param string $brief natural-language description of the desired site
     * @param array  $opts  industry, locale, pages (desired count)
     * @return array{
     *   site_title:string, tagline:string,
     *   pages:array<int,array{slug:string,title:string,purpose:string,sections:array}>,
     *   seo:array{title:string,description:string,keywords:string[]},
     *   design:array{palette:string[], mood:string, typography:string},
     *   images:array<int,string>
     * }
     */
    public function generateSiteOutline($brief, array $opts = []);
}
