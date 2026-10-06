/**
 * CloudHost247 Tools - Small Text Generator
 *
 * Route-split module: this file is loaded only on /tools/small-text.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "small-text",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "style", "label": "Style", "type": "select", "options": [{"value": "superscript", "label": "superscript"}, {"value": "subscript", "label": "subscript"}, {"value": "smallcaps", "label": "smallcaps"}, {"value": "upsidedown", "label": "upsidedown"}], "value": "superscript"}],
            run: function (v) {
              var t = String(v.text || '');
              if (!t) { return { error: 'Enter some text.' }; }
              var SUP = { a:'\u1D43',b:'\u1D47',c:'\u1D9C',d:'\u1D48',e:'\u1D49',f:'\u1DA0',g:'\u1D4D',h:'\u02B0',i:'\u2071',
                j:'\u02B2',k:'\u1D4F',l:'\u02E1',m:'\u1D50',n:'\u207F',o:'\u1D52',p:'\u1D56',r:'\u02B3',s:'\u02E2',t:'\u1D57',
                u:'\u1D58',v:'\u1D5B',w:'\u02B7',x:'\u02E3',y:'\u02B8',z:'\u1DBB','0':'\u2070','1':'\u00B9','2':'\u00B2',
                '3':'\u00B3','4':'\u2074','5':'\u2075','6':'\u2076','7':'\u2077','8':'\u2078','9':'\u2079','+':'\u207A','-':'\u207B','=':'\u207C','(':'\u207D',')':'\u207E' };
              var SUB = { a:'\u2090',e:'\u2091',h:'\u2095',i:'\u1D62',j:'\u2C7C',k:'\u2096',l:'\u2097',m:'\u2098',n:'\u2099',
                o:'\u2092',p:'\u209A',r:'\u1D63',s:'\u209B',t:'\u209C',u:'\u1D64',v:'\u1D65',x:'\u2093','0':'\u2080','1':'\u2081',
                '2':'\u2082','3':'\u2083','4':'\u2084','5':'\u2085','6':'\u2086','7':'\u2087','8':'\u2088','9':'\u2089','+':'\u208A','-':'\u208B','=':'\u208C','(':'\u208D',')':'\u208E' };
              var CAPS = { a:'\u1D00',b:'\u0299',c:'\u1D04',d:'\u1D05',e:'\u1D07',f:'\uA730',g:'\u0262',h:'\u029C',i:'\u026A',
                j:'\u1D0A',k:'\u1D0B',l:'\u029F',m:'\u1D0D',n:'\u0274',o:'\u1D0F',p:'\u1D18',q:'\u01EB',r:'\u0280',s:'\uA731',
                t:'\u1D1B',u:'\u1D1C',v:'\u1D20',w:'\u1D21',x:'x',y:'\u028F',z:'\u1D22' };
              var FLIP = { a:'\u0250',b:'q',c:'\u0254',d:'p',e:'\u01DD',f:'\u025F',g:'\u0183',h:'\u0265',i:'\u1D09',j:'\u027E',
                k:'\u029E',l:'l',m:'\u026F',n:'u',o:'o',p:'d',q:'b',r:'\u0279',s:'s',t:'\u0287',u:'n',v:'\u028C',w:'\u028D',
                x:'x',y:'\u028E',z:'z','.':'\u02D9',',':'\u0027','?':'\u00BF','!':'\u00A1','(':')',')':'(','[':']',']':'[',
                '{':'}','}':'{','<':'>','>':'<','&':'\u214B','_':'\u203E','1':'\u0196','2':'\u1105','3':'\u0190','4':'\u3123',
                '5':'\u03DB','6':'9','7':'\u3125','9':'6' };
              var apply = function (map) {
                var missing = [];
                var out = t.toLowerCase().split('').map(function (c) {
                  if (c === ' ') { return ' '; }
                  if (map[c]) { return map[c]; }
                  missing.push(c); return c;
                }).join('');
                return { text: out, missing: Array.from(new Set(missing)) };
              };
              var style = v.style || 'superscript';
              var chosen = { superscript: SUP, subscript: SUB, smallcaps: CAPS, upsidedown: FLIP }[style];
              if (!chosen) { return { error: 'Choose superscript, subscript, small caps or upside down.' }; }
              var res = apply(chosen);
              if (style === 'upsidedown') { res.text = res.text.split('').reverse().join(''); }
              return {
                style: style, input: t, result: res.text,
                superscript: apply(SUP).text, subscript: apply(SUB).text,
                small_caps: apply(CAPS).text,
                upside_down: apply(FLIP).text.split('').reverse().join(''),
                unconverted: res.missing.length ? res.missing.join(' ') : 'none',
                note: 'These are real Unicode characters, not CSS styling, so they survive copy and paste into places that strip formatting. Not every letter exists in every style \u2014 unconverted characters are listed above. Screen readers often cannot read these characters correctly, so avoid them where accessibility matters.'
              };
            },
            copyText: function (d) { return d.result; },
            render: function (d) {
                var wrap = CH.el('div');
                if (!d || typeof d !== 'object') {
                    wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: String(d) }));
                    return wrap;
                }
                if (typeof d.score === 'number' && d.verdict) {
                    wrap.appendChild(CH.el('div', { class: 'ch247-score' }, [
                        CH.el('span', { class: 'ch247-score__value', text: String(d.score) }),
                        CH.el('span', { class: 'ch247-score__label', text: d.verdict })
                    ]));
                }

                var scalars = {};
                var tables = [];
                var lists = [];
                Object.keys(d).forEach(function (k) {
                    if (k === 'checks' || k === 'note' || k === 'notes' || k === 'raw'
                        || k === 'score' || k === 'verdict') { return; }
                    var v = d[k];
                    if (Array.isArray(v)) {
                        if (!v.length) { return; }
                        if (v[0] && typeof v[0] === 'object') { tables.push([k, v]); }
                        else { lists.push([k, v]); }
                    } else if (v && typeof v === 'object') {
                        tables.push([k, [v]]);
                    } else {
                        scalars[k] = v;
                    }
                });

                if (Object.keys(scalars).length) { wrap.appendChild(CH.kvList(scalars)); }

                tables.forEach(function (pair) {
                    var rows = pair[1];
                    var keys = {};
                    rows.forEach(function (r) { Object.keys(r || {}).forEach(function (k) { keys[k] = 1; }); });
                    var cols = Object.keys(keys).map(function (k) {
                        return { key: k, label: CH.humanLabel(k) };
                    });
                    wrap.appendChild(CH.dataTable(cols, rows, CH.humanLabel(pair[0])));
                });

                lists.forEach(function (pair) {
                    wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: CH.humanLabel(pair[0]) }));
                    var ul = CH.el('ul', { class: 'ch247-list' });
                    pair[1].forEach(function (item) {
                        ul.appendChild(CH.el('li', { text: String(item) }));
                    });
                    wrap.appendChild(ul);
                });

                if (Array.isArray(d.checks) && d.checks.length) {
                    wrap.appendChild(CH.checksList(d.checks));
                }

                if (d.raw) {
                    var det = CH.el('details', { class: 'ch247-section' });
                    det.appendChild(CH.el('summary', { text: 'Raw response' }));
                    det.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.raw }));
                    wrap.appendChild(det);
                }

                [].concat(d.note || [], d.notes || []).forEach(function (n) {
                    wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: n }));
                });

                return wrap;
            },
            submitLabel: "Run Small Text Generator"
        });
    });
})(window, document);
