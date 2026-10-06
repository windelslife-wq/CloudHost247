/**
 * CloudHost247 Tools - Morse Code Translator
 *
 * Route-split module: this file is loaded only on /tools/morse-code-translator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "morse-code-translator",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "encode", "label": "encode"}, {"value": "decode", "label": "decode"}], "value": "encode"}],
            run: function (v) {
              var MAP = { 'a':'.-','b':'-...','c':'-.-.','d':'-..','e':'.','f':'..-.','g':'--.','h':'....','i':'..',
                'j':'.---','k':'-.-','l':'.-..','m':'--','n':'-.','o':'---','p':'.--.','q':'--.-','r':'.-.','s':'...',
                't':'-','u':'..-','v':'...-','w':'.--','x':'-..-','y':'-.--','z':'--..','0':'-----','1':'.----',
                '2':'..---','3':'...--','4':'....-','5':'.....','6':'-....','7':'--...','8':'---..','9':'----.',
                '.':'.-.-.-',',':'--..--','?':'..--..',"'":'.----.','!':'-.-.--','/':'-..-.','(':'-.--.',')':'-.--.-',
                '&':'.-...',':':'---...',';':'-.-.-.','=':'-...-','+':'.-.-.','-':'-....-','_':'..--.-','"':'.-..-.',
                '$':'...-..-','@':'.--.-.' };
              var REV = {}; Object.keys(MAP).forEach(function (k) { REV[MAP[k]] = k; });
              var t = String(v.text || '').trim();
              if (!t) { return { error: 'Enter text or Morse code.' }; }
            
              if ((v.mode || 'encode') === 'decode') {
                var words = t.split(/\s*\/\s*|\s{3,}/);
                var unknown = [];
                var plain = words.map(function (w) {
                  return w.trim().split(/\s+/).filter(Boolean).map(function (c) {
                    if (REV[c]) { return REV[c]; }
                    unknown.push(c); return '?';
                  }).join('');
                }).join(' ');
                return { mode: 'decode', input: t, result: plain,
                  unknown_symbols: unknown.length ? unknown.join(', ') : 'none',
                  note: 'Words are separated by "/" or a long gap; letters by a single space.' };
              }
            
              var unsupported = [];
              var morse = t.toLowerCase().split('').map(function (c) {
                if (c === ' ') { return '/'; }
                if (MAP[c]) { return MAP[c]; }
                unsupported.push(c); return '';
              }).filter(Boolean).join(' ');
              return {
                mode: 'encode', input: t, result: morse,
                unsupported: unsupported.length ? Array.from(new Set(unsupported)).join(' ') : 'none',
                note: 'International Morse Code (ITU-R M.1677-1). A dash is three times the length of a dot; letters are separated by one space and words by "/".'
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
            submitLabel: "Run Morse Code Translator"
        });
    });
})(window, document);
