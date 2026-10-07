<?php
/**
 * Compiles builder block JSON into table-based, inline-styled HTML email.
 *
 * Design notes that matter for real inboxes:
 *  - Nested <table> layout, no flexbox/grid, no <style> dependence for layout.
 *  - Every visual property is an inline style attribute.
 *  - A single <style> block carries only progressive enhancement (mobile
 *    stacking), which clients that ignore it degrade from gracefully.
 *  - Outlook needs the ghost-table conditional around constrained widths.
 *
 * The renderer is pure: no database, no settings, no clock. That keeps it
 * trivially testable and means a migration can safely call it while seeding.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

class Renderer
{
    /** Tags allowed inside rich-text blocks. */
    private const TEXT_TAGS = '<p><br><strong><b><em><i><u><s><a><ul><ol><li><span><h1><h2><h3><h4><h5><h6><blockquote><small><sup><sub>';

    /**
     * @param array $design  normalised design array (see Designer::normalize)
     * @param array $options ['trusted' => bool] — false strips raw-HTML blocks
     * @return string complete HTML document
     */
    public static function render(array $design, array $options = [])
    {
        $trusted = !array_key_exists('trusted', $options) || (bool) $options['trusted'];
        $settings = self::settings($design);
        $width = (int) $settings['contentWidth'];

        $rowsHtml = '';
        foreach (self::rows($design) as $row) {
            $rowsHtml .= self::renderRow($row, $settings, $trusted);
        }

        $preheader = isset($options['preheader']) ? trim((string) $options['preheader']) : '';
        $title = isset($options['subject']) ? (string) $options['subject'] : '';

        $out  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
        $out .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="en"><head>' . "\n";
        $out .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\n";
        $out .= '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
        $out .= '<meta name="x-apple-disable-message-reformatting" />' . "\n";
        $out .= '<title>' . self::esc($title) . '</title>' . "\n";
        $out .= '<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->' . "\n";
        $out .= '<style type="text/css">' . "\n"
            . "body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}\n"
            . "table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;}\n"
            . "img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;display:block;}\n"
            . "a{color:" . self::esc($settings['linkColor']) . ";}\n"
            . "@media only screen and (max-width:620px){\n"
            . "  .ch247m-wrap{width:100%!important;}\n"
            . "  .ch247m-col{display:block!important;width:100%!important;max-width:100%!important;}\n"
            . "  .ch247m-pad{padding-left:16px!important;padding-right:16px!important;}\n"
            . "  .ch247m-img{width:100%!important;height:auto!important;}\n"
            . "}\n"
            . '</style>' . "\n";
        $out .= '</head>' . "\n";
        $out .= '<body style="margin:0;padding:0;background-color:' . self::esc($settings['backgroundColor']) . ';">' . "\n";

        if ($preheader !== '') {
            // Hidden preview text, padded so clients do not pull body copy in.
            $out .= '<div style="display:none;font-size:1px;color:' . self::esc($settings['backgroundColor'])
                . ';line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
                . self::esc($preheader) . str_repeat('&#847;&zwnj;&nbsp;', 60) . '</div>' . "\n";
        }

        $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . self::esc($settings['backgroundColor']) . ';">' . "\n";
        $out .= '<tr><td align="center" style="padding:0;">' . "\n";
        $out .= '<!--[if mso]><table role="presentation" width="' . $width . '" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->' . "\n";
        $out .= '<table role="presentation" class="ch247m-wrap" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $width . 'px;max-width:' . $width . 'px;">' . "\n";
        $out .= $rowsHtml;
        $out .= '</table>' . "\n";
        $out .= '<!--[if mso]></td></tr></table><![endif]-->' . "\n";
        $out .= '</td></tr></table>' . "\n";
        $out .= '</body></html>';

        return $out;
    }

    /* ----------------------------------------------------------- rows ---- */

    protected static function renderRow(array $row, array $settings, $trusted)
    {
        $props = array_merge(BlockLibrary::ROW_DEFAULTS, isset($row['props']) && is_array($row['props']) ? $row['props'] : []);
        $cells = isset($row['cells']) && is_array($row['cells']) ? array_values($row['cells']) : [];
        if ($cells === []) {
            return '';
        }
        $weights = isset($row['widths']) && is_array($row['widths']) && count($row['widths']) === count($cells)
            ? array_map('floatval', $row['widths'])
            : array_fill(0, count($cells), 1.0);
        $total = array_sum($weights);
        if ($total <= 0) {
            $total = count($cells);
            $weights = array_fill(0, count($cells), 1.0);
        }

        $style = 'background-color:' . self::cssColor($props['backgroundColor'], '#ffffff') . ';'
            . 'padding:' . (int) $props['paddingTop'] . 'px ' . (int) $props['paddingRight'] . 'px '
            . (int) $props['paddingBottom'] . 'px ' . (int) $props['paddingLeft'] . 'px;';

        $out = '<tr><td class="ch247m-pad" style="' . $style . '">' . "\n";
        $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . "\n";

        $count = count($cells);
        foreach ($cells as $i => $blocks) {
            $pct = round(($weights[$i] / $total) * 100, 4);
            $gap = (int) $props['columnGap'];
            $padLeft  = ($i === 0 || $gap === 0) ? 0 : (int) floor($gap / 2);
            $padRight = ($i === $count - 1 || $gap === 0) ? 0 : (int) ceil($gap / 2);
            $out .= '<td class="ch247m-col" width="' . $pct . '%" valign="top" style="width:' . $pct . '%;vertical-align:top;'
                . 'padding-left:' . $padLeft . 'px;padding-right:' . $padRight . 'px;">' . "\n";
            $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . "\n";
            foreach ((array) $blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $out .= self::renderBlock($block, $settings, $trusted);
            }
            $out .= '</table>' . "\n";
            $out .= '</td>' . "\n";
        }

        $out .= '</tr></table>' . "\n";
        $out .= '</td></tr>' . "\n";
        return $out;
    }

    /* --------------------------------------------------------- blocks ---- */

    protected static function renderBlock(array $block, array $settings, $trusted)
    {
        $type = isset($block['type']) ? (string) $block['type'] : '';
        if (!BlockLibrary::has($type)) {
            return '';
        }
        $p = array_merge(BlockLibrary::defaults($type), isset($block['props']) && is_array($block['props']) ? $block['props'] : []);

        switch ($type) {
            case 'heading':  return self::cell(self::heading($p, $settings), $p);
            case 'text':     return self::cell(self::text($p, $settings), $p);
            case 'image':    return self::cell(self::image($p), $p);
            case 'logo':     return self::cell(self::image($p), $p);
            case 'button':   return self::cell(self::button($p, $settings), $p);
            case 'divider':  return self::cell(self::divider($p), $p);
            case 'spacer':   return self::spacer($p);
            case 'social':   return self::cell(self::social($p), $p);
            case 'video':    return self::cell(self::video($p), $p);
            case 'html':     return self::cell($trusted ? (string) $p['html'] : '<!-- custom HTML omitted in untrusted preview -->', ['paddingTop' => 0, 'paddingBottom' => 0]);
            case 'product':  return self::cell(self::product($p, $settings), $p);
            case 'coupon':   return self::cell(self::coupon($p, $settings), $p);
            case 'pricing':  return self::cell(self::pricing($p, $settings), $p);
            case 'footer':   return self::cell(self::footer($p, $settings), $p);
        }
        return '';
    }

    /** Wrap block output in a padded row. */
    protected static function cell($html, array $p)
    {
        if ($html === '') {
            return '';
        }
        $top = isset($p['paddingTop']) ? (int) $p['paddingTop'] : 0;
        $bottom = isset($p['paddingBottom']) ? (int) $p['paddingBottom'] : 0;
        return '<tr><td style="padding:' . $top . 'px 0 ' . $bottom . 'px 0;">' . $html . '</td></tr>' . "\n";
    }

    protected static function heading(array $p, array $settings)
    {
        $level = in_array($p['level'], ['h1', 'h2', 'h3', 'h4'], true) ? $p['level'] : 'h2';
        $color = self::cssColor($p['color'], $settings['textColor']);
        $size = (int) $p['fontSize'] > 0 ? (int) $p['fontSize'] : 26;
        return '<' . $level . ' style="margin:0;font-family:' . self::esc($settings['fontFamily'])
            . ';font-size:' . $size . 'px;line-height:1.3;font-weight:bold;color:' . $color
            . ';text-align:' . self::align($p['align']) . ';">' . self::inlineText($p['text']) . '</' . $level . '>';
    }

    protected static function text(array $p, array $settings)
    {
        $color = self::cssColor($p['color'], $settings['textColor']);
        $size = (int) $p['fontSize'] > 0 ? (int) $p['fontSize'] : (int) $settings['fontSize'];
        $html = self::sanitizeRichText((string) $p['html']);
        return '<div style="font-family:' . self::esc($settings['fontFamily']) . ';font-size:' . $size
            . 'px;line-height:' . self::esc($settings['lineHeight']) . ';color:' . $color
            . ';text-align:' . self::align($p['align']) . ';">' . $html . '</div>';
    }

    protected static function image(array $p)
    {
        $src = self::url($p['src']);
        if ($src === '') {
            return '';
        }
        $width = (int) $p['width'];
        $attrs = 'src="' . self::esc($src) . '" alt="' . self::esc($p['alt']) . '" class="ch247m-img" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;'
            . ($width > 0 ? 'width:' . $width . 'px;' : 'width:100%;') . '"'
            . ($width > 0 ? ' width="' . $width . '"' : '');
        $img = '<img ' . $attrs . ' />';
        $href = self::url($p['href']);
        if ($href !== '') {
            $img = '<a href="' . self::esc($href) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="' . self::align($p['align']) . '" style="padding:0;">' . $img . '</td></tr></table>';
    }

    protected static function button(array $p, array $settings)
    {
        $href = self::url($p['href']);
        $label = self::esc($p['text']);
        $bg = self::cssColor($p['backgroundColor'], '#1a73e8');
        $fg = self::cssColor($p['textColor'], '#ffffff');
        $radius = (int) $p['radius'];
        $px = (int) $p['paddingX'];
        $py = (int) $p['paddingY'];
        $size = (int) $p['fontSize'] > 0 ? (int) $p['fontSize'] : 15;
        $full = !empty($p['fullWidth']);

        // Bulletproof button: a table cell with the background, an <a> inside.
        $inner = '<a href="' . self::esc($href !== '' ? $href : '#') . '" target="_blank" style="display:' . ($full ? 'block' : 'inline-block')
            . ';font-family:' . self::esc($settings['fontFamily']) . ';font-size:' . $size . 'px;font-weight:bold;color:' . $fg
            . ';text-decoration:none;padding:' . $py . 'px ' . $px . 'px;border-radius:' . $radius . 'px;text-align:center;mso-padding-alt:0;">'
            . $label . '</a>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="' . self::align($p['align']) . '" style="padding:0;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" ' . ($full ? 'width="100%" ' : '') . 'style="' . ($full ? 'width:100%;' : '') . 'border-radius:' . $radius . 'px;background-color:' . $bg . ';">'
            . '<tr><td align="center" style="border-radius:' . $radius . 'px;background-color:' . $bg . ';">' . $inner . '</td></tr>'
            . '</table></td></tr></table>';
    }

    protected static function divider(array $p)
    {
        $w = max(1, min(100, (int) $p['width']));
        $style = in_array($p['style'], ['solid', 'dashed', 'dotted'], true) ? $p['style'] : 'solid';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="center" style="padding:0;"><table role="presentation" width="' . $w . '%" cellpadding="0" cellspacing="0" border="0" style="width:' . $w . '%;"><tr>'
            . '<td style="font-size:0;line-height:0;border-top:' . (int) $p['thickness'] . 'px ' . $style . ' ' . self::cssColor($p['color'], '#e3e6ea') . ';">&nbsp;</td>'
            . '</tr></table></td></tr></table>';
    }

    protected static function spacer(array $p)
    {
        $h = max(1, (int) $p['height']);
        return '<tr><td style="font-size:0;line-height:0;height:' . $h . 'px;">&nbsp;</td></tr>' . "\n";
    }

    protected static function social(array $p)
    {
        $links = is_array($p['links']) ? $p['links'] : [];
        $size = max(12, (int) $p['iconSize']);
        $cells = '';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $href = self::url(isset($link['href']) ? $link['href'] : '');
            if ($href === '') {
                continue;
            }
            $network = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($link['network'] ?? 'link')));
            $label = ucfirst($network === '' ? 'link' : $network);
            $cells .= '<td style="padding:0 6px;"><a href="' . self::esc($href) . '" target="_blank" '
                . 'style="display:inline-block;width:' . $size . 'px;height:' . $size . 'px;line-height:' . $size . 'px;'
                . 'border-radius:' . (int) round($size / 2) . 'px;background-color:#e8eaed;color:#3c4043;font-family:Arial,sans-serif;'
                . 'font-size:' . max(10, (int) round($size * 0.45)) . 'px;text-align:center;text-decoration:none;" title="' . self::esc($label) . '">'
                . self::esc(strtoupper(substr($label, 0, 1))) . '</a></td>';
        }
        if ($cells === '') {
            return '';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td align="' . self::align($p['align']) . '" style="padding:0;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $cells . '</tr></table>'
            . '</td></tr></table>';
    }

    protected static function video(array $p)
    {
        // Email clients do not play video; render a linked thumbnail.
        $thumb = self::url($p['thumbnail']);
        $href = self::url($p['href']);
        if ($thumb === '') {
            if ($href === '') {
                return '';
            }
            return '<div style="text-align:' . self::align($p['align']) . ';"><a href="' . self::esc($href) . '" target="_blank">' . self::esc($p['alt']) . '</a></div>';
        }
        return self::image(['src' => $thumb, 'alt' => $p['alt'], 'href' => $href, 'width' => (int) $p['width'], 'align' => $p['align']]);
    }

    protected static function product(array $p, array $settings)
    {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border:1px solid ' . self::cssColor($p['borderColor'], '#e3e6ea') . ';border-radius:6px;overflow:hidden;">';
        $image = self::url($p['image']);
        if ($image !== '') {
            $out .= '<tr><td style="padding:0;"><img src="' . self::esc($image) . '" alt="' . self::esc($p['title'])
                . '" class="ch247m-img" width="100%" style="display:block;width:100%;height:auto;border:0;" /></td></tr>';
        }
        $out .= '<tr><td style="padding:18px;font-family:' . self::esc($settings['fontFamily']) . ';color:' . self::esc($settings['textColor']) . ';">';
        $out .= '<div style="font-size:18px;font-weight:bold;margin:0 0 6px 0;">' . self::inlineText($p['title']) . '</div>';
        if (trim((string) $p['body']) !== '') {
            $out .= '<div style="font-size:14px;line-height:1.6;margin:0 0 10px 0;">' . self::inlineText($p['body']) . '</div>';
        }
        if (trim((string) $p['price']) !== '') {
            $out .= '<div style="font-size:20px;font-weight:bold;margin:0 0 12px 0;">' . self::inlineText($p['price']) . '</div>';
        }
        if (trim((string) $p['buttonText']) !== '') {
            $out .= self::button([
                'text' => $p['buttonText'], 'href' => $p['href'], 'align' => 'left',
                'backgroundColor' => $settings['linkColor'], 'textColor' => '#ffffff',
                'radius' => 4, 'paddingX' => 22, 'paddingY' => 11, 'fontSize' => 14, 'fullWidth' => false,
            ], $settings);
        }
        $out .= '</td></tr></table>';
        return $out;
    }

    protected static function coupon(array $p, array $settings)
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background-color:' . self::cssColor($p['backgroundColor'], '#fff8e1') . ';border:2px dashed '
            . self::cssColor($p['borderColor'], '#f0b429') . ';border-radius:6px;"><tr>'
            . '<td align="center" style="padding:20px;font-family:' . self::esc($settings['fontFamily']) . ';color:' . self::cssColor($p['textColor'], '#8a5a00') . ';">'
            . '<div style="font-size:14px;margin:0 0 8px 0;">' . self::inlineText($p['headline']) . '</div>'
            . '<div style="font-size:26px;font-weight:bold;letter-spacing:3px;margin:0 0 8px 0;">' . self::inlineText($p['code']) . '</div>'
            . '<div style="font-size:12px;opacity:0.85;">' . self::inlineText($p['subtext']) . '</div>'
            . '</td></tr></table>';
    }

    protected static function pricing(array $p, array $settings)
    {
        $accent = self::cssColor($p['accent'], '#1a73e8');
        $features = '';
        foreach (preg_split('/\r?\n/', (string) $p['features']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $features .= '<tr><td style="padding:4px 0;font-size:14px;color:' . self::esc($settings['textColor']) . ';">&#10003;&nbsp; ' . self::inlineText($line) . '</td></tr>';
        }
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e3e6ea;border-radius:6px;border-top:4px solid ' . $accent . ';">';
        $out .= '<tr><td align="center" style="padding:20px;font-family:' . self::esc($settings['fontFamily']) . ';">';
        $out .= '<div style="font-size:16px;font-weight:bold;color:' . self::esc($settings['textColor']) . ';margin:0 0 6px 0;">' . self::inlineText($p['plan']) . '</div>';
        $out .= '<div style="margin:0 0 14px 0;"><span style="font-size:34px;font-weight:bold;color:' . $accent . ';">' . self::inlineText($p['price'])
            . '</span><span style="font-size:14px;color:#8a9099;">' . self::inlineText($p['period']) . '</span></div>';
        if ($features !== '') {
            $out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 14px auto;">' . $features . '</table>';
        }
        if (trim((string) $p['buttonText']) !== '') {
            $out .= self::button([
                'text' => $p['buttonText'], 'href' => $p['href'], 'align' => 'center',
                'backgroundColor' => $accent, 'textColor' => '#ffffff',
                'radius' => 4, 'paddingX' => 26, 'paddingY' => 12, 'fontSize' => 15, 'fullWidth' => false,
            ], $settings);
        }
        $out .= '</td></tr></table>';
        return $out;
    }

    protected static function footer(array $p, array $settings)
    {
        $size = (int) $p['fontSize'] > 0 ? (int) $p['fontSize'] : 12;
        $color = self::cssColor($p['color'], '#8a9099');
        $parts = [];
        if (trim((string) $p['companyName']) !== '') {
            $parts[] = '<strong>' . self::esc($p['companyName']) . '</strong>';
        }
        if (trim((string) $p['address']) !== '') {
            $parts[] = nl2br(self::esc($p['address']));
        }
        $body = implode('<br />', $parts);
        if (!empty($p['showUnsubscribe'])) {
            // {{unsubscribe_url}} is substituted per recipient by the Personalizer.
            $body .= ($body !== '' ? '<br /><br />' : '')
                . '<a href="{{unsubscribe_url}}" style="color:' . $color . ';text-decoration:underline;">Unsubscribe</a>'
                . ' &nbsp;|&nbsp; '
                . '<a href="{{preferences_url}}" style="color:' . $color . ';text-decoration:underline;">Email preferences</a>';
        }
        if (trim((string) $p['extraHtml']) !== '') {
            $body .= '<br /><br />' . self::sanitizeRichText((string) $p['extraHtml']);
        }
        if ($body === '') {
            return '';
        }
        return '<div style="font-family:' . self::esc($settings['fontFamily']) . ';font-size:' . $size
            . 'px;line-height:1.6;color:' . $color . ';text-align:' . self::align($p['align']) . ';">' . $body . '</div>';
    }

    /* --------------------------------------------------------- helpers --- */

    public static function settings(array $design)
    {
        $given = isset($design['settings']) && is_array($design['settings']) ? $design['settings'] : [];
        $settings = array_merge(BlockLibrary::DESIGN_DEFAULTS, $given);
        $settings['contentWidth'] = max(320, min(900, (int) $settings['contentWidth']));
        $settings['fontSize'] = max(10, min(32, (int) $settings['fontSize']));
        return $settings;
    }

    public static function rows(array $design)
    {
        return isset($design['rows']) && is_array($design['rows']) ? $design['rows'] : [];
    }

    protected static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Escape, but keep merge tags and line breaks readable. */
    protected static function inlineText($value)
    {
        return nl2br(self::esc($value));
    }

    protected static function align($value)
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : 'left';
    }

    protected static function cssColor($value, $fallback)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return self::esc($fallback);
        }
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*[\d.\s,%]+\)$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^[a-zA-Z]{3,20}$/', $value) === 1) {
            return $value;
        }
        return self::esc($fallback);
    }

    /**
     * Only http(s), mailto and our own merge tags survive. This is what stops
     * a javascript: or data: URI reaching an inbox.
     */
    public static function url($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, '{{') === 0 || strpos($value, '{{') !== false && preg_match('/^\{\{[a-z0-9_]+\}\}$/i', $value) === 1) {
            return $value;
        }
        if (preg_match('#^(https?://|mailto:|/)#i', $value) === 1) {
            return $value;
        }
        if (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $value) === 1) {
            return 'https://' . $value;
        }
        return '';
    }

    /** Allowlist tags and strip every event handler / dangerous URI. */
    public static function sanitizeRichText($html)
    {
        $html = strip_tags((string) $html, self::TEXT_TAGS);
        // Drop on* handlers.
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        // Neutralise dangerous URI schemes in href/src.
        $html = preg_replace_callback('/\b(href|src)\s*=\s*("([^"]*)"|\'([^\']*)\')/i', function ($m) {
            $raw = $m[3] !== '' ? $m[3] : (isset($m[4]) ? $m[4] : '');
            $safe = self::url($raw);
            return $m[1] . '="' . htmlspecialchars($safe, ENT_QUOTES, 'UTF-8') . '"';
        }, (string) $html);
        return (string) $html;
    }
}
