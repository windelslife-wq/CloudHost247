/**
 * CloudHost247 Tools - Binary Translator
 *
 * Route-split module: this file is loaded only on /tools/binary-translator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "binary-translator",
            exec: "client",
            fields: [{"name": "binary", "label": "Binary", "type": "textarea", "placeholder": "01001000 01101001", "width": "full", "required": true, "spellcheck": false}, {"name": "base", "label": "Base", "type": "select", "options": [{"value": "binary", "label": "binary"}, {"value": "octal", "label": "octal"}, {"value": "decimal", "label": "decimal"}, {"value": "hexadecimal", "label": "hexadecimal"}], "value": "binary"}],
            run: function (v) {
              var raw = String(v.binary || '').trim();
              if (!raw) { return { error: 'Enter binary (or other base) values to decode.' }; }
              var base = { binary: 2, octal: 8, decimal: 10, hexadecimal: 16 }[v.base || 'binary'];
              var valid = { 2: /^[01]+$/, 8: /^[0-7]+$/, 10: /^\d+$/, 16: /^[0-9a-fA-F]+$/ }[base];
              var tokens = raw.split(/[\s,]+/).filter(Boolean);
              var bytes = [];
              for (var i = 0; i < tokens.length; i++) {
                var tk = tokens[i].replace(/^0[xbo]/i, '');
                if (!valid.test(tk)) { return { error: 'Value "' + tokens[i] + '" at position ' + (i + 1) + ' is not valid ' + (v.base || 'binary') + '.' }; }
                var n = parseInt(tk, base);
                if (n > 255) { return { error: 'Value "' + tokens[i] + '" (' + n + ') is outside the byte range 0-255.' }; }
                bytes.push(n);
              }
              var text;
              try { text = new TextDecoder('utf-8', { fatal: true }).decode(new Uint8Array(bytes)); }
              catch (e) { text = null; }
              return {
                base: v.base || 'binary', values: tokens.length, bytes: bytes.length,
                text: text === null ? '(not valid UTF-8)' : text,
                valid_utf8: text !== null,
                hex: bytes.map(function (b) { return ('0' + b.toString(16)).slice(-2).toUpperCase(); }).join(' '),
                decimal: bytes.join(' ')
              };
            },
            copyText: function (d) { return d.text; },
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
            submitLabel: "Run Binary Translator"
        });
    });
})(window, document);
