/**
 * CloudHost247 Tools - HEX to Colortone
 *
 * Route-split module: this file is loaded only on /tools/hex-colortone.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "hex-colortone",
            exec: "client",
            fields: [{"name": "hex", "label": "Hex colour", "placeholder": "#1b6fe8", "value": "#1b6fe8", "required": true, "spellcheck": false}],
            run: function (v) {
            
              var toHex = function (r, g, b) {
                return '#' + [r, g, b].map(function (x) { return ('0' + Math.round(x).toString(16)).slice(-2); }).join('').toUpperCase();
              };
              var rgbToHsl = function (r, g, b) {
                r /= 255; g /= 255; b /= 255;
                var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
                var h = 0, s = 0, l = (mx + mn) / 2;
                if (d) {
                  s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
                  h = mx === r ? ((g - b) / d + (g < b ? 6 : 0)) : mx === g ? ((b - r) / d + 2) : ((r - g) / d + 4);
                  h *= 60;
                }
                return [Math.round(h), Math.round(s * 100), Math.round(l * 100)];
              };
              var rgbToHsv = function (r, g, b) {
                r /= 255; g /= 255; b /= 255;
                var mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
                var h = 0;
                if (d) {
                  h = mx === r ? ((g - b) / d + (g < b ? 6 : 0)) : mx === g ? ((b - r) / d + 2) : ((r - g) / d + 4);
                  h *= 60;
                }
                return [Math.round(h), Math.round(mx ? (d / mx) * 100 : 0), Math.round(mx * 100)];
              };
              var rgbToCmyk = function (r, g, b) {
                r /= 255; g /= 255; b /= 255;
                var k = 1 - Math.max(r, g, b);
                if (k === 1) { return [0, 0, 0, 100]; }
                return [Math.round(((1 - r - k) / (1 - k)) * 100), Math.round(((1 - g - k) / (1 - k)) * 100),
                        Math.round(((1 - b - k) / (1 - k)) * 100), Math.round(k * 100)];
              };
              var hsvToRgb = function (h, s, v) {
                h = ((h % 360) + 360) % 360; s /= 100; v /= 100;
                var c = v * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = v - c;
                var p = h < 60 ? [c,x,0] : h < 120 ? [x,c,0] : h < 180 ? [0,c,x] : h < 240 ? [0,x,c] : h < 300 ? [x,0,c] : [c,0,x];
                return p.map(function (n) { return Math.round((n + m) * 255); });
              };
              var hslToRgb = function (h, s, l) {
                h = ((h % 360) + 360) % 360; s /= 100; l /= 100;
                var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = l - c / 2;
                var p = h < 60 ? [c,x,0] : h < 120 ? [x,c,0] : h < 180 ? [0,c,x] : h < 240 ? [0,x,c] : h < 300 ? [x,0,c] : [c,0,x];
                return p.map(function (n) { return Math.round((n + m) * 255); });
              };
              var cmykToRgb = function (c, m, y, k) {
                c /= 100; m /= 100; y /= 100; k /= 100;
                return [Math.round(255 * (1 - c) * (1 - k)), Math.round(255 * (1 - m) * (1 - k)), Math.round(255 * (1 - y) * (1 - k))];
              };
              var relLum = function (r, g, b) {
                var f = [r, g, b].map(function (v) {
                  v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
              };
              var contrast = function (l1, l2) {
                var a = Math.max(l1, l2), b2 = Math.min(l1, l2);
                return (a + 0.05) / (b2 + 0.05);
              };
              var describe = function (r, g, b) {
                var hsl = rgbToHsl(r, g, b), hsv = rgbToHsv(r, g, b), cmyk = rgbToCmyk(r, g, b);
                var lum = relLum(r, g, b);
                var onWhite = contrast(lum, 1), onBlack = contrast(lum, 0);
                var h = hsl[0];
                var name = hsl[1] < 8 ? (hsl[2] < 20 ? 'near black' : hsl[2] > 85 ? 'near white' : 'grey')
                  : h < 15 || h >= 345 ? 'red' : h < 45 ? 'orange' : h < 70 ? 'yellow' : h < 160 ? 'green'
                  : h < 200 ? 'cyan' : h < 260 ? 'blue' : h < 290 ? 'violet' : h < 345 ? 'magenta' : 'red';
                return {
                  hex: toHex(r, g, b),
                  rgb: 'rgb(' + r + ', ' + g + ', ' + b + ')',
                  rgb_values: r + ', ' + g + ', ' + b,
                  hsl: 'hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)',
                  hsv: 'hsv(' + hsv[0] + ', ' + hsv[1] + '%, ' + hsv[2] + '%)',
                  cmyk: 'cmyk(' + cmyk.join('%, ') + '%)',
                  hue_family: name,
                  relative_luminance: lum.toFixed(4),
                  contrast_on_white: onWhite.toFixed(2) + ':1',
                  contrast_on_black: onBlack.toFixed(2) + ':1',
                  wcag_aa_normal_text: (onWhite >= 4.5 ? 'passes on white' : 'fails on white') + '; ' + (onBlack >= 4.5 ? 'passes on black' : 'fails on black'),
                  wcag_aaa_normal_text: (onWhite >= 7 ? 'passes on white' : 'fails on white') + '; ' + (onBlack >= 7 ? 'passes on black' : 'fails on black'),
                  best_text_colour: onWhite >= onBlack ? '#FFFFFF (white text)' : '#000000 (black text)',
                  complement: toHex.apply(null, hsvToRgb(hsv[0] + 180, hsv[1], hsv[2])),
                  triad: [toHex.apply(null, hsvToRgb(hsv[0] + 120, hsv[1], hsv[2])), toHex.apply(null, hsvToRgb(hsv[0] + 240, hsv[1], hsv[2]))].join(', '),
                  shades: [80, 60, 40, 20].map(function (p) { return toHex.apply(null, hsvToRgb(hsv[0], hsv[1], hsv[2] * p / 100)); }).join(', '),
                  tints: [20, 40, 60, 80].map(function (p) { return toHex.apply(null, hsvToRgb(hsv[0], hsv[1] * (1 - p / 100), hsv[2] + (100 - hsv[2]) * p / 100)); }).join(', ')
                };
              };
            
              var s = String(v.hex || '').trim().replace(/^#/, '');
              if (s.length === 3) { s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2]; }
              if (!/^[0-9a-fA-F]{6}$/.test(s)) { return { error: 'Enter a hex colour such as #1B6FE8 or #1BE.' }; }
              var r = parseInt(s.slice(0, 2), 16), g = parseInt(s.slice(2, 4), 16), b = parseInt(s.slice(4, 6), 16);
              return describe(r, g, b);
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('div', { style: 'display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:16px;' }, [
                CH.el('span', { style: 'display:inline-block;width:92px;height:62px;border-radius:8px;border:1px solid var(--ch247-border);background:' + d.hex,
                  role: 'img', 'aria-label': 'Colour swatch for ' + d.hex }),
                CH.el('div', {}, [
                  CH.el('strong', { style: 'font-size:1.2rem;', text: d.hex }),
                  CH.el('p', { style: 'margin:2px 0 0;color:var(--ch247-muted);', text: d.rgb + ' \u2014 ' + d.hue_family })
                ])
              ]));
              wrap.appendChild(CH.kvList(d, ['hex','rgb','hsl','hsv','cmyk','hue_family','relative_luminance',
                'contrast_on_white','contrast_on_black','wcag_aa_normal_text','wcag_aaa_normal_text','best_text_colour',
                'complement','triad','shades','tints']));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice',
                text: 'Contrast ratios follow WCAG 2.1. Normal text needs 4.5:1 for AA and 7:1 for AAA; large text (18pt, or 14pt bold) needs 3:1 for AA.' }));
              return wrap;
            },
            copyText: function (d) { return d.hex + '\n' + d.rgb + '\n' + d.hsl + '\n' + d.cmyk; },
            submitLabel: "Run HEX to Colortone"
        });
    });
})(window, document);
