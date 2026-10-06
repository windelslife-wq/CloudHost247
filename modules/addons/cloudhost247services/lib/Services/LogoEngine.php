<?php
/**
 * Logo concept engine — pure, deterministic SVG generation.
 *
 * Given the company name and creative direction, it produces genuinely
 * custom vector concepts (monogram, wordmark, combination mark) from curated
 * palette, typography-stack, geometry and industry-motif libraries. Every
 * output is real SVG the browser renders and the client downloads — no
 * rasteriser or external service required.
 *
 * @package Chs\Services
 */

namespace Chs\Services;

class LogoEngine
{
    /* ------------------------------------------------------------ libraries -- */

    public static function palettes()
    {
        return [
            'ocean'      => ['bg' => '#0b1f3a', 'primary' => '#1e6fff', 'accent' => '#5cd6ff', 'text' => '#0b1f3a', 'light' => '#f4f8ff'],
            'forest'     => ['bg' => '#0c2b1d', 'primary' => '#15803d', 'accent' => '#86efac', 'text' => '#0c2b1d', 'light' => '#f0fdf4'],
            'sunset'     => ['bg' => '#3c1d0e', 'primary' => '#ea580c', 'accent' => '#fbbf24', 'text' => '#431407', 'light' => '#fff7ed'],
            'royal'      => ['bg' => '#1e1b4b', 'primary' => '#7c3aed', 'accent' => '#f0abfc', 'text' => '#1e1b4b', 'light' => '#faf5ff'],
            'crimson'    => ['bg' => '#3f0d12', 'primary' => '#dc2626', 'accent' => '#fca5a5', 'text' => '#450a0a', 'light' => '#fef2f2'],
            'slate'      => ['bg' => '#0f172a', 'primary' => '#334155', 'accent' => '#38bdf8', 'text' => '#0f172a', 'light' => '#f8fafc'],
            'emerald'    => ['bg' => '#022c22', 'primary' => '#059669', 'accent' => '#fcd34d', 'text' => '#022c22', 'light' => '#ecfdf5'],
            'mono'       => ['bg' => '#111111', 'primary' => '#111111', 'accent' => '#888888', 'text' => '#111111', 'light' => '#ffffff'],
        ];
    }

    public static function fontStacks()
    {
        return [
            'modern'  => ['family' => "Montserrat, 'Segoe UI', Arial, sans-serif",       'weight' => 700, 'spacing' => 0.5],
            'classic' => ['family' => "Georgia, 'Times New Roman', serif",               'weight' => 600, 'spacing' => 1.5],
            'bold'    => ['family' => "Arial, 'Helvetica Neue', sans-serif",             'weight' => 800, 'spacing' => 0],
            'elegant' => ['family' => "Palatino, 'Palatino Linotype', Georgia, serif",   'weight' => 500, 'spacing' => 3],
            'tech'    => ['family' => "'Courier New', Consolas, monospace",              'weight' => 700, 'spacing' => 2],
            'friendly'=> ['family' => "Verdana, Geneva, Tahoma, sans-serif",             'weight' => 600, 'spacing' => 0.5],
        ];
    }

    public static function industries()
    {
        return [
            'general'     => ['icon' => 'spark',    'label' => 'Other / General'],
            'tech'        => ['icon' => 'hex',      'label' => 'Technology'],
            'hosting'     => ['icon' => 'orbit',    'label' => 'Hosting & Cloud'],
            'commerce'    => ['icon' => 'bag',      'label' => 'Ecommerce & Retail'],
            'finance'     => ['icon' => 'bars',     'label' => 'Finance & Fintech'],
            'health'      => ['icon' => 'plus',     'label' => 'Health & Care'],
            'creative'    => ['icon' => 'brush',    'label' => 'Creative & Design'],
            'travel'      => ['icon' => 'plane',    'label' => 'Travel & Logistics'],
            'food'        => ['icon' => 'leaf',     'label' => 'Food & Beverage'],
            'education'   => ['icon' => 'cap',      'label' => 'Education'],
            'realestate'  => ['icon' => 'roof',     'label' => 'Real Estate'],
            'energy'      => ['icon' => 'bolt',     'label' => 'Energy'],
        ];
    }

    /* -------------------------------------------------------------- render -- */

    /**
     * Produce one concept as an SVG string.
     *
     * $concept: monogram | wordmark | combo | badge
     * $opts: company, industry, style, palette
     */
    public function render($concept, array $opts)
    {
        $company = trim(preg_replace('/\s+/u', ' ', (string) (isset($opts['company']) ? $opts['company'] : '')));
        if ($company === '') {
            $company = 'Your Company';
        }
        $industry = isset($opts['industry']) ? (string) $opts['industry'] : 'general';
        $style = isset($opts['style']) ? (string) $opts['style'] : 'modern';
        $paletteKey = isset($opts['palette']) ? (string) $opts['palette'] : 'ocean';

        $palettes = self::palettes();
        $fonts = self::fontStacks();
        $icons = self::industries();

        $palette = isset($palettes[$paletteKey]) ? $palettes[$paletteKey] : $palettes['ocean'];
        $font = isset($fonts[$style]) ? $fonts[$style] : $fonts['modern'];
        $iconKey = isset($icons[$industry]) ? $icons[$industry]['icon'] : 'spark';

        switch ($concept) {
            case 'monogram':
                return $this->monogram($company, $palette, $font);
            case 'combo':
                return $this->combo($company, $iconKey, $palette, $font);
            case 'badge':
                return $this->badge($company, $iconKey, $palette, $font);
            case 'wordmark':
            default:
                return $this->wordmark($company, $palette, $font);
        }
    }

    /** The four concept keys the studio offers. */
    public static function conceptKeys()
    {
        return ['wordmark', 'monogram', 'combo', 'badge'];
    }

    /* ------------------------------------------------------------- layouts -- */

    protected function monogram($company, array $p, array $f)
    {
        $initials = $this->initials($company);
        return $this->svg(240, 240,
            '<rect x="8" y="8" width="224" height="224" rx="56" fill="' . $p['primary'] . '"/>'
            . '<circle cx="120" cy="120" r="86" fill="none" stroke="' . $p['light'] . '" stroke-opacity="0.35" stroke-width="3"/>'
            . '<text x="50%" y="50%" dy="0.36em" text-anchor="middle"'
            . ' font-family="' . $this->esc($f['family']) . '" font-weight="' . (int) $f['weight'] . '"'
            . ' font-size="88" letter-spacing="' . (float) $f['spacing'] . '" fill="' . $p['light'] . '">'
            . $this->esc($initials) . '</text>'
        );
    }

    protected function wordmark($company, array $p, array $f)
    {
        $size = $this->fitSize($company, 640, 110);
        return $this->svg(640, 200,
            '<rect width="640" height="200" fill="' . $p['light'] . '"/>'
            . '<rect x="0" y="0" width="14" height="200" fill="' . $p['primary'] . '"/>'
            . '<rect x="14" y="0" width="14" height="200" fill="' . $p['accent'] . '"/>'
            . '<text x="330" y="52%" dy="0.35em" text-anchor="middle"'
            . ' font-family="' . $this->esc($f['family']) . '" font-weight="' . (int) $f['weight'] . '"'
            . ' font-size="' . $size . '" letter-spacing="' . (float) $f['spacing'] . '" fill="' . $p['text'] . '">'
            . $this->esc($company) . '</text>'
        );
    }

    protected function combo($company, $iconKey, array $p, array $f)
    {
        $size = $this->fitSize($company, 430, 84);
        return $this->svg(660, 220,
            '<rect width="660" height="220" rx="18" fill="' . $p['light'] . '"/>'
            . '<g transform="translate(46,34)">' . $this->icon($iconKey, $p, 152) . '</g>'
            . '<text x="230" y="47%" font-family="' . $this->esc($f['family']) . '"'
            . ' font-weight="' . (int) $f['weight'] . '" font-size="' . $size . '"'
            . ' letter-spacing="' . (float) $f['spacing'] . '" fill="' . $p['text'] . '">'
            . $this->esc($company) . '</text>'
            . '<rect x="232" y="128" width="84" height="7" rx="3.5" fill="' . $p['accent'] . '"/>'
        );
    }

    protected function badge($company, $iconKey, array $p, array $f)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($company, 'UTF-8') : strlen($company);
        $size = $len > 14 ? 26 : ($len > 8 ? 32 : 40);
        return $this->svg(300, 300,
            '<circle cx="150" cy="150" r="142" fill="' . $p['bg'] . '"/>'
            . '<circle cx="150" cy="150" r="126" fill="none" stroke="' . $p['accent'] . '" stroke-width="2" stroke-dasharray="4 7"/>'
            . '<g transform="translate(94,66) scale(0.736)">' . $this->icon($iconKey, $p, 152) . '</g>'
            . '<text x="50%" y="243" text-anchor="middle" font-family="' . $this->esc($f['family']) . '"'
            . ' font-weight="' . (int) $f['weight'] . '" font-size="' . $size . '"'
            . ' letter-spacing="' . (float) $f['spacing'] . '" fill="' . $p['light'] . '">'
            . $this->esc(strtoupper(html_entity_decode($company, ENT_QUOTES, 'UTF-8'))) . '</text>'
        );
    }

    /* -------------------------------------------------------------- pieces -- */

    /** Simple geometric motifs, drawn in a 152×152 box. */
    protected function icon($key, array $p, $box)
    {
        $fill = ' fill="' . $p['primary'] . '"';
        $accent = ' fill="' . $p['accent'] . '"';
        switch ($key) {
            case 'hex':
                return '<polygon points="76,4 140,40 140,112 76,148 12,112 12,40"' . $fill . '/>'
                    . '<polygon points="76,42 108,60 108,94 76,111 44,94 44,60"' . $accent . '/>';
            case 'orbit':
                return '<circle cx="76" cy="76" r="34"' . $fill . '/>'
                    . '<ellipse cx="76" cy="76" rx="68" ry="24" fill="none" stroke="' . $p['accent'] . '" stroke-width="7"/>'
                    . '<circle cx="136" cy="58" r="12"' . $accent . '/>';
            case 'bag':
                return '<rect x="22" y="52" width="108" height="84" rx="12"' . $fill . '/>'
                    . '<path d="M52 52 v-8 a24 24 0 0 1 48 0 v8" fill="none" stroke="' . $p['accent'] . '" stroke-width="10"/>';
            case 'bars':
                return '<rect x="22" y="88" width="26" height="44" rx="4"' . $fill . '/>'
                    . '<rect x="63" y="60" width="26" height="72" rx="4"' . $accent . '/>'
                    . '<rect x="104" y="28" width="26" height="104" rx="4"' . $fill . '/>';
            case 'plus':
                return '<rect x="18" y="18" width="116" height="116" rx="30"' . $fill . '/>'
                    . '<rect x="66" y="40" width="20" height="72" rx="5" fill="' . $p['light'] . '"/>'
                    . '<rect x="40" y="66" width="72" height="20" rx="5" fill="' . $p['light'] . '"/>'
                    . '<circle cx="112" cy="116" r="14"' . $accent . '/>';
            case 'brush':
                return '<path d="M18 118 L92 44 l20 20 -74 74 c-8 8 -22 14 -32 16 2-10 4-20 12-30z"' . $fill . '/>'
                    . '<circle cx="116" cy="34" r="16"' . $accent . '/>';
            case 'plane':
                return '<polygon points="14,86 138,22 100,138 82,96"' . $fill . '/>'
                    . '<polygon points="82,96 138,22 100,138"' . $accent . '/>';
            case 'leaf':
                return '<path d="M26 126 C26 58 90 22 134 22 c0 60-40 104-98 104z"' . $fill . '/>'
                    . '<path d="M40 112 C66 84 92 62 122 42" stroke="' . $accent . '" stroke-width="7" fill="none"/>';
            case 'cap':
                return '<polygon points="76,26 146,58 76,90 6,58"' . $fill . '/>'
                    . '<rect x="70" y="88" width="12" height="40"' . $accent . '/>'
                    . '<ellipse cx="76" cy="132" rx="30" ry="10"' . $accent . '/>';
            case 'roof':
                return '<polygon points="76,18 144,74 130,74 130,134 22,134 22,74 8,74"' . $fill . '/>'
                    . '<rect x="62" y="92" width="28" height="42"' . $accent . '/>';
            case 'bolt':
                return '<polygon points="84,8 34,88 68,88 56,144 118,56 82,56"' . $fill . '/>';
            case 'spark':
            default:
                return '<path d="M76 6 L94 58 L146 76 L94 94 L76 146 L58 94 L6 76 L58 58 Z"' . $fill . '/>'
                    . '<circle cx="76" cy="76" r="10"' . $accent . '/>';
        }
    }

    /* ------------------------------------------------------------ helpers -- */

    protected function svg($w, $h, $body)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $w . '" height="' . (int) $h
            . '" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" role="img">' . "\n"
            . $body . "\n" . '</svg>' . "\n";
    }

    protected function initials($company)
    {
        $clean = preg_replace('/[^\p{L}\p{N} ]+/u', '', $company);
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return '?';
        }
        $take = array_slice($words, 0, 2);
        $out = '';
        foreach ($take as $word) {
            $out .= function_exists('mb_substr') ? mb_substr($word, 0, 1, 'UTF-8') : substr($word, 0, 1);
        }
        return $this->toUpper($out);
    }

    protected function fitSize($company, $maxWidth, $base)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($company, 'UTF-8') : strlen($company);
        if ($len <= 8) {
            return $base;
        }
        // shrink linearly, never below 40% of base
        $ratio = max(0.4, 1.0 - ($len - 8) * 0.035);
        return (int) round($base * $ratio);
    }

    protected function toUpper($value)
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    /** Escape for XML text and attribute contexts (same safe set). */
    protected function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
