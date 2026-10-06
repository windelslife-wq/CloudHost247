/**
 * CloudHost247 Tools - Minecraft Color Codes
 *
 * Route-split module: this file is loaded only on /tools/minecraft-color-codes.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "minecraft-color-codes",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}],
            run: function (v) {
              var COLORS = [
                ['0','Black','#000000'],['1','Dark Blue','#0000AA'],['2','Dark Green','#00AA00'],['3','Dark Aqua','#00AAAA'],
                ['4','Dark Red','#AA0000'],['5','Dark Purple','#AA00AA'],['6','Gold','#FFAA00'],['7','Gray','#AAAAAA'],
                ['8','Dark Gray','#555555'],['9','Blue','#5555FF'],['a','Green','#55FF55'],['b','Aqua','#55FFFF'],
                ['c','Red','#FF5555'],['d','Light Purple','#FF55FF'],['e','Yellow','#FFFF55'],['f','White','#FFFFFF']
              ].map(function (r) { return { code: '\u00A7' + r[0], amp: '&' + r[0], name: r[1], hex: r[2] }; });
              var FORMATS = [
                ['k','Obfuscated','Randomly cycling characters'],['l','Bold','Bold text'],
                ['m','Strikethrough','Struck-through text'],['n','Underline','Underlined text'],
                ['o','Italic','Italic text'],['r','Reset','Clears all colour and formatting']
              ].map(function (r) { return { code: '\u00A7' + r[0], amp: '&' + r[0], name: r[1], effect: r[2] }; });
            
              var t = String(v.text || '');
              var preview = [], plain = t.replace(/[\u00A7&][0-9a-fk-or]/gi, '');
              var byCode = {}; COLORS.forEach(function (c) { byCode[c.amp[1]] = c; });
              var re = /[\u00A7&]([0-9a-fk-or])/gi, last = 0, cur = { color: '#FFFFFF', bold: false, italic: false, under: false, strike: false }, m;
              var push = function (text, st) {
                if (text) { preview.push({ text: text, color: st.color, bold: st.bold, italic: st.italic, under: st.under, strike: st.strike }); }
              };
              while ((m = re.exec(t)) !== null) {
                push(t.slice(last, m.index), cur);
                var c = m[1].toLowerCase();
                if (byCode[c]) { cur = { color: byCode[c].hex, bold: false, italic: false, under: false, strike: false }; }
                else if (c === 'l') { cur = Object.assign({}, cur, { bold: true }); }
                else if (c === 'o') { cur = Object.assign({}, cur, { italic: true }); }
                else if (c === 'n') { cur = Object.assign({}, cur, { under: true }); }
                else if (c === 'm') { cur = Object.assign({}, cur, { strike: true }); }
                else if (c === 'r') { cur = { color: '#FFFFFF', bold: false, italic: false, under: false, strike: false }; }
                last = m.index + m[0].length;
              }
              push(t.slice(last), cur);
            
              return {
                input: t, plain_text: plain, segments: preview,
                section_form: t.replace(/&([0-9a-fk-or])/gi, '\u00A7$1'),
                ampersand_form: t.replace(/\u00A7/g, '&'),
                colors: COLORS, formats: FORMATS,
                note: 'The section sign \u00A7 is the in-game code. Most server config files and plugins accept & instead and translate it. Bedrock Edition does not support \u00A7n (underline) or \u00A7m (strikethrough).'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              if (d.segments.length) {
                var pre = CH.el('div', { class: 'ch247-pre',
                  style: 'background:#2b2b2b;color:#fff;font-family:var(--ch247-mono);padding:16px;' });
                d.segments.forEach(function (s) {
                  var style = 'color:' + s.color + ';';
                  if (s.bold) { style += 'font-weight:700;'; }
                  if (s.italic) { style += 'font-style:italic;'; }
                  var deco = [];
                  if (s.under) { deco.push('underline'); }
                  if (s.strike) { deco.push('line-through'); }
                  if (deco.length) { style += 'text-decoration:' + deco.join(' ') + ';'; }
                  pre.appendChild(CH.el('span', { style: style, text: s.text }));
                });
                wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Preview' }));
                wrap.appendChild(pre);
              }
              wrap.appendChild(CH.kvList(d, ['plain_text', 'section_form', 'ampersand_form']));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Colour codes' }));
              wrap.appendChild(CH.dataTable([
                { key: 'code', label: 'Code' }, { key: 'amp', label: 'Config form' },
                { key: 'name', label: 'Name' },
                { label: 'Swatch', value: function (r) {
                    return CH.el('span', { style: 'display:inline-block;width:40px;height:18px;border:1px solid #888;background:' + r.hex,
                      role: 'img', 'aria-label': r.name + ' ' + r.hex }); } },
                { key: 'hex', label: 'Hex' }
              ], d.colors, 'Minecraft colour codes'));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Formatting codes' }));
              wrap.appendChild(CH.dataTable([
                { key: 'code', label: 'Code' }, { key: 'amp', label: 'Config form' },
                { key: 'name', label: 'Name' }, { key: 'effect', label: 'Effect' }
              ], d.formats, 'Minecraft formatting codes'));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.section_form; },
            submitLabel: "Run Minecraft Color Codes"
        });
    });
})(window, document);
