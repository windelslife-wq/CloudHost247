/**
 * CloudHost247 Tools - IP to Decimal
 *
 * Route-split module: this file is loaded only on /tools/ip-to-decimal.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ip-to-decimal",
            exec: "client",
            fields: [{"name": "ip", "label": "IP address", "placeholder": "8.8.8.8", "required": true, "spellcheck": false}],
            run: function (v) {
              var s = (v.ip || '').trim();
              if (CH.ip.isV4(s)) {
                var n = CH.ip.v4ToLong(s);
                var o = s.split('.').map(Number);
                return {
                  version: 'IPv4', input: s, decimal: n,
                  hexadecimal: '0x' + ('00000000' + n.toString(16)).slice(-8).toUpperCase(),
                  octal: '0' + n.toString(8),
                  binary: o.map(function (x) { return ('0000000' + x.toString(2)).slice(-8); }).join('.'),
                  binary_flat: ('0'.repeat(32) + n.toString(2)).slice(-32),
                  octets: o
                };
              }
              var g = CH.ip.parseV6(s);
              if (g) {
                var big = CH.ip.v6ToBig(g);
                return {
                  version: 'IPv6', input: CH.ip.compressV6(g), expanded: CH.ip.expandV6(g),
                  decimal: big.toString(), hexadecimal: '0x' + CH.ip.v6ToHex(g).toUpperCase(),
                  binary: g.map(function (x) { return ('000000000000000' + x.toString(2)).slice(-16); }).join(':')
                };
              }
              return { error: 'Enter a valid IPv4 or IPv6 address.' };
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
            submitLabel: "Run IP to Decimal"
        });
    });
})(window, document);
