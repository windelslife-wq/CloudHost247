/**
 * CloudHost247 Tools - Local IPv6 Address Generator
 *
 * Route-split module: this file is loaded only on /tools/ipv6-address-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ipv6-address-generator",
            exec: "client",
            fields: [{"name": "prefix_len", "label": "Prefix length", "type": "number", "min": 1, "max": 128, "value": 64}],
            run: function (v) {
              var prefixLen = Math.max(1, Math.min(128, Number(v.prefix_len) || 64));
              var buf = new Uint8Array(16);
              (window.crypto || window.msCrypto).getRandomValues(buf);
              // RFC 4193 unique local: fd00::/8 with a random 40-bit global id.
              buf[0] = 0xfd;
              var groups = [];
              for (var i = 0; i < 8; i++) { groups.push((buf[i * 2] << 8) | buf[i * 2 + 1]); }
              var big = CH.ip.v6ToBig(groups);
              var hostBits = BigInt(128 - prefixLen);
              var mask = hostBits === 0n ? (2n ** 128n - 1n) : ((2n ** 128n - 1n) >> hostBits) << hostBits;
              var network = big & mask;
              var samples = [];
              for (var j = 0; j < 5; j++) {
                var rb = new Uint8Array(16);
                (window.crypto || window.msCrypto).getRandomValues(rb);
                var rg = []; for (var k = 0; k < 8; k++) { rg.push((rb[k * 2] << 8) | rb[k * 2 + 1]); }
                var host = CH.ip.v6ToBig(rg) & (hostBits === 0n ? 0n : (2n ** hostBits - 1n));
                samples.push(CH.ip.compressV6(CH.ip.bigToV6(network | host)));
              }
              return {
                prefix: CH.ip.compressV6(CH.ip.bigToV6(network)) + '/' + prefixLen,
                type: 'Unique Local Address (RFC 4193, fd00::/8)',
                full_address: CH.ip.compressV6(groups),
                expanded: CH.ip.expandV6(groups),
                sample_addresses: samples,
                note: 'Generated with your browser\u2019s cryptographic random source. These are RFC 4193 unique local addresses, intended for private networks \u2014 they are not globally routable. For internet-facing use, request a prefix from your hosting provider or RIR.'
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
            submitLabel: "Run"
        });
    });
})(window, document);
