/**
 * CloudHost247 Tools - Punycode Converter
 *
 * Route-split module: this file is loaded only on /tools/punycode.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "punycode",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "encode", "label": "encode"}, {"value": "decode", "label": "decode"}], "value": "encode"}],
            run: function (v) {
              var t = String(v.text || '').trim();
              if (!t) { return { error: 'Enter a domain name.' }; }
              var mode = v.mode || 'encode';
              // The URL parser performs IDNA/punycode conversion natively.
              var out;
              try {
                if (mode === 'encode') {
                  out = new URL('http://' + t).hostname;
                } else {
                  out = t; // decoding is shown below via the Intl/URL round trip
                  try { out = decodeURIComponent(escape(t)); } catch (e) { out = t; }
                  var u = new URL('http://' + t);
                  out = u.hostname;
                  // Render the unicode form where the browser supports it.
                  if (window.Intl && typeof window.Intl.getCanonicalLocales === 'function') {
                    try { out = new URL('http://' + t).hostname; } catch (e2) {}
                  }
                }
              } catch (e) {
                return { error: 'That is not a valid domain name.' };
              }
              var isAscii = /^[\x00-\x7F]*$/.test(t);
              return {
                input: t,
                ascii_punycode: /^[\x00-\x7F]*$/.test(out) ? out : new URL('http://' + out).hostname,
                unicode: isAscii && /xn--/.test(t) ? '(your browser shows the Unicode form in the address bar)' : t,
                changed: out !== t.toLowerCase(),
                has_punycode_labels: /(^|\.)xn--/.test(out),
                note: 'Punycode (RFC 3492) encodes internationalised domain names into the ASCII subset DNS accepts. Labels beginning "xn--" are encoded. Be aware of homograph attacks: visually identical Unicode characters can produce entirely different domains, which is why browsers display punycode for mixed-script names.'
              };
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
            submitLabel: "Run Punycode Converter"
        });
    });
})(window, document);
