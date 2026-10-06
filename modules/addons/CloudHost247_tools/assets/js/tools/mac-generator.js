/**
 * CloudHost247 Tools - MAC Address Generator
 *
 * Route-split module: this file is loaded only on /tools/mac-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "mac-generator",
            exec: "client",
            fields: [{"name": "count", "label": "How many", "type": "number", "min": 1, "max": 100, "value": 5}, {"name": "format", "label": "Format", "type": "select", "value": "colon", "options": [{"value": "colon", "label": "colon"}, {"value": "hyphen", "label": "hyphen"}, {"value": "dot", "label": "dot"}, {"value": "none", "label": "none"}]}],
            run: function (v) {
              var count = Math.max(1, Math.min(100, Number(v.count) || 5));
              var fmt = v.format || 'colon';
              var joiner = { colon: ':', hyphen: '-', dot: '.', none: '' }[fmt];
              var out = [];
              for (var i = 0; i < count; i++) {
                var buf = new Uint8Array(6);
                (window.crypto || window.msCrypto).getRandomValues(buf);
                buf[0] = (buf[0] | 0x02) & 0xfe; // locally administered, unicast
                var bytes = Array.prototype.map.call(buf, function (b) { return ('0' + b.toString(16)).slice(-2); });
                var hex = bytes.join('');
                out.push(fmt === 'dot' ? (hex.match(/.{4}/g).join('.')).toUpperCase()
                                       : bytes.join(joiner).toUpperCase());
              }
              return {
                count: count, format: fmt, addresses: out, list: out.join('\n'),
                note: 'Every generated address has the locally-administered bit set and the multicast bit cleared, so it can never collide with a real vendor OUI or be mistaken for a multicast address. Generated with your browser\u2019s cryptographic random source.'
              };
            },
            copyText: function (d) { return d.list; },
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
            submitLabel: "Run MAC Address Generator"
        });
    });
})(window, document);
