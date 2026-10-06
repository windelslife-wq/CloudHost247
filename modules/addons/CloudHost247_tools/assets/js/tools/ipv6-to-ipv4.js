/**
 * CloudHost247 Tools - IPv6 to IPv4
 *
 * Route-split module: this file is loaded only on /tools/ipv6-to-ipv4.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ipv6-to-ipv4",
            exec: "client",
            fields: [{"name": "ip", "label": "IP address", "placeholder": "8.8.8.8", "required": true, "spellcheck": false}],
            run: function (v) {
              var g = CH.ip.parseV6(v.ip || '');
              if (!g) { return { error: 'Enter a valid IPv6 address.' }; }
              var hex = CH.ip.v6ToHex(g);
              var quad = function (a, b) {
                return [(a >> 8) & 255, a & 255, (b >> 8) & 255, b & 255].join('.');
              };
              var out = { input: CH.ip.compressV6(g), expanded: CH.ip.expandV6(g), ipv4: null, mechanism: null };
              if (hex.indexOf('00000000000000000000ffff') === 0) {
                out.ipv4 = quad(g[6], g[7]); out.mechanism = 'IPv4-mapped IPv6 (::ffff:0:0/96)';
                out.explanation = 'Dual-stack sockets use this form to represent an IPv4 peer inside an IPv6 structure.';
              } else if (g[0] === 0x2002) {
                out.ipv4 = quad(g[1], g[2]); out.mechanism = '6to4 (2002::/16)';
                out.explanation = '6to4 embeds the IPv4 tunnel endpoint in the next 32 bits. Deprecated by RFC 7526.';
              } else if (hex.indexOf('0064ff9b0000000000000000') === 0) {
                out.ipv4 = quad(g[6], g[7]); out.mechanism = 'NAT64 well-known prefix (64:ff9b::/96)';
                out.explanation = 'NAT64 translators represent IPv4 destinations to IPv6-only clients with this prefix.';
              } else if (g[0] === 0x2001 && g[1] === 0x0000) {
                out.ipv4 = quad(g[6] ^ 0xffff, g[7] ^ 0xffff);
                out.mechanism = 'Teredo (2001:0::/32)';
                out.teredo_server = quad(g[2], g[3]);
                out.teredo_port = (g[5] ^ 0xffff);
                out.explanation = 'Teredo tunnels IPv6 over UDP/IPv4. The client address and port are stored bitwise-inverted.';
              } else if (hex.indexOf('000000000000000000000000') === 0 && hex !== '0'.repeat(32)) {
                out.ipv4 = quad(g[6], g[7]); out.mechanism = 'IPv4-compatible (deprecated, RFC 4291)';
                out.explanation = 'This format is deprecated and should not be used in new deployments.';
              } else {
                out.explanation = 'This is a native IPv6 address with no embedded IPv4. IPv4 and IPv6 are separate address spaces: a host is only reachable over IPv4 if it also publishes an A record or sits behind a translator.';
              }
              return out;
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
            submitLabel: "Run IPv6 to IPv4"
        });
    });
})(window, document);
