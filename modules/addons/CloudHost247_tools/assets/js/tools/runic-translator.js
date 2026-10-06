/**
 * CloudHost247 Tools - Runic Translator
 *
 * Route-split module: this file is loaded only on /tools/runic-translator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "runic-translator",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "alphabet", "label": "Alphabet", "type": "select", "options": [{"value": "elder_futhark", "label": "Elder Futhark"}, {"value": "younger_futhark", "label": "Younger Futhark"}, {"value": "anglo_saxon", "label": "Anglo Saxon"}], "value": "elder_futhark"}, {"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "encode", "label": "encode"}, {"value": "decode", "label": "decode"}], "value": "encode"}],
            run: function (v) {
              var SETS = {
                elder_futhark: { name: 'Elder Futhark', era: 'c. 150-800 CE', map: {
                  'th':'\u16A6','ng':'\u16DC','ei':'\u16C7','f':'\u16A0','u':'\u16A2','a':'\u16A8','r':'\u16B1','k':'\u16B2',
                  'g':'\u16B7','w':'\u16B9','h':'\u16BA','n':'\u16BE','i':'\u16C1','j':'\u16C3','p':'\u16C8','z':'\u16C9',
                  's':'\u16CA','t':'\u16CF','b':'\u16D2','e':'\u16D6','m':'\u16D7','l':'\u16DA','d':'\u16DE','o':'\u16DF',
                  'c':'\u16B2','q':'\u16B2','v':'\u16B9','x':'\u16B2\u16CA','y':'\u16C1' } },
                younger_futhark: { name: 'Younger Futhark (long-branch)', era: 'c. 800-1100 CE', map: {
                  'th':'\u16A6','f':'\u16A0','u':'\u16A2','a':'\u16AC','r':'\u16B1','k':'\u16B4','h':'\u16BC','n':'\u16BE',
                  'i':'\u16C1','s':'\u16CB','t':'\u16CF','b':'\u16D2','m':'\u16D8','l':'\u16DA','y':'\u16E6',
                  'c':'\u16B4','g':'\u16B4','q':'\u16B4','d':'\u16CF','e':'\u16C1','o':'\u16A2','p':'\u16D2',
                  'v':'\u16A0','w':'\u16A2','x':'\u16B4\u16CB','z':'\u16CB','j':'\u16C1' } },
                anglo_saxon: { name: 'Anglo-Saxon Futhorc', era: 'c. 400-1100 CE', map: {
                  'th':'\u16A6','ng':'\u16DD','ae':'\u16AB','ea':'\u16E0','st':'\u16E5','io':'\u16E1',
                  'f':'\u16A0','u':'\u16A2','o':'\u16A9','r':'\u16B1','c':'\u16B3','g':'\u16B7','w':'\u16B9','h':'\u16BB',
                  'n':'\u16BE','i':'\u16C1','j':'\u16C4','p':'\u16C8','x':'\u16C9','s':'\u16CB','t':'\u16CF','b':'\u16D2',
                  'e':'\u16D6','m':'\u16D7','l':'\u16DA','d':'\u16DE','a':'\u16AA','y':'\u16A3','k':'\u16B3',
                  'q':'\u16B3\u16B9','v':'\u16A0','z':'\u16CB' } }
              };
              var set = SETS[v.alphabet || 'elder_futhark'];
              if (!set) { return { error: 'Choose Elder Futhark, Younger Futhark or Anglo-Saxon Futhorc.' }; }
              var t = String(v.text || '');
              if (!t.trim()) { return { error: 'Enter some text to transliterate.' }; }
            
              if ((v.mode || 'encode') === 'decode') {
                var rev = {};
                Object.keys(set.map).forEach(function (k) { if (!rev[set.map[k]]) { rev[set.map[k]] = k; } });
                var runes = Object.keys(rev).sort(function (a, b) { return b.length - a.length; });
                var out = t;
                runes.forEach(function (r) { out = out.split(r).join(rev[r]); });
                out = out.replace(/[\u16EB\u16EC\u16ED]/g, ' ');
                return { mode: 'decode', alphabet: set.name, input: t, result: out,
                  note: 'Runic alphabets have fewer letters than the Latin alphabet, so several Latin letters share one rune. Decoding is approximate and cannot always recover the original spelling.' };
              }
            
              var keys = Object.keys(set.map).sort(function (a, b) { return b.length - a.length; });
              var lower = t.toLowerCase(), result = '', used = {}, i = 0;
              while (i < lower.length) {
                var hit = null;
                for (var k = 0; k < keys.length; k++) {
                  if (lower.startsWith(keys[k], i)) { hit = keys[k]; break; }
                }
                if (hit) { result += set.map[hit]; used[hit] = set.map[hit]; i += hit.length; }
                else { result += lower[i] === ' ' ? '\u16EB' : lower[i]; i++; }
              }
              return {
                mode: 'encode', alphabet: set.name, era: set.era, input: t,
                result: result, plain: result.replace(/\u16EB/g, ' '),
                runes_used: Object.keys(used).map(function (k) { return k + ' \u2192 ' + used[k]; }).join(', '),
                note: 'This is a transliteration, not a translation: the sounds are written with runic letters, the language stays the same. Historical runic writing used \u16EB as a word divider and had no upper and lower case.'
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
            submitLabel: "Run Runic Translator"
        });
    });
})(window, document);
