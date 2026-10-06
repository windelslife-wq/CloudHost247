/**
 * CloudHost247 Tools - MD5 & Base64 Generator
 *
 * Route-split module: this file is loaded only on /tools/md5-base64.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "md5-base64",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}],
            run: function (v) {
              var t = String(v.text || '');
              if (!t) { return { error: 'Enter some text.' }; }
              return Promise.all([
                CH.hash.subtle('sha1', t).catch(function () { return 'unavailable (requires a secure context)'; }),
                CH.hash.subtle('sha256', t).catch(function () { return 'unavailable (requires a secure context)'; }),
                CH.hash.subtle('sha512', t).catch(function () { return 'unavailable (requires a secure context)'; })
              ]).then(function (r) {
                var b64 = CH.hash.b64encode(t);
                var out = {
                  input_length: t.length,
                  md5: CH.hash.md5(t),
                  sha1: r[0], sha256: r[1], sha512: r[2],
                  base64: b64,
                  base64url: b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
                  url_encoded: encodeURIComponent(t),
                  hex: Array.from(new TextEncoder().encode(t)).map(function (b) { return ('0' + b.toString(16)).slice(-2); }).join('')
                };
                try { out.base64_decoded = CH.hash.b64decode(t); } catch (e) { /* input is not base64 */ }
                out.note = 'Computed entirely in your browser. MD5 and SHA-1 are broken for security purposes \u2014 use them only for checksums and legacy compatibility, never for passwords or signatures. Base64 is an encoding, not encryption: anyone can reverse it.';
                return out;
              });
            },
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
            submitLabel: "Run MD5 & Base64 Generator"
        });
    });
})(window, document);
