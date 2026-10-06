/**
 * CloudHost247 Tools - IPv6 CIDR to Range
 *
 * Route-split module: this file is loaded only on /tools/ipv6-cidr-range.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ipv6-cidr-range",
            exec: "client",
            fields: [{"name": "cidr", "label": "CIDR block", "placeholder": "2001:db8::/48", "required": true, "spellcheck": false}],
            run: function (v) {
              var s = (v.cidr || '').trim();
              var m = /^(.+)\/(\d{1,3})$/.exec(s);
              if (!m) { return { error: 'Enter a CIDR block such as 2001:db8::/48.' }; }
              var g = CH.ip.parseV6(m[1]), prefix = Number(m[2]);
              if (!g) { return { error: 'The address part is not a valid IPv6 address.' }; }
              if (prefix < 0 || prefix > 128) { return { error: 'Prefix length must be between 0 and 128.' }; }
              var big = CH.ip.v6ToBig(g);
              var hostBits = BigInt(128 - prefix);
              var mask = hostBits === 0n ? (2n ** 128n - 1n) : ((2n ** 128n - 1n) >> hostBits) << hostBits;
              var first = big & mask;
              var last = first | (hostBits === 0n ? 0n : (2n ** hostBits - 1n));
              var total = 2n ** hostBits;
              return {
                cidr: CH.ip.compressV6(CH.ip.bigToV6(first)) + '/' + prefix,
                prefix_length: prefix,
                first_address: CH.ip.compressV6(CH.ip.bigToV6(first)),
                last_address: CH.ip.compressV6(CH.ip.bigToV6(last)),
                first_expanded: CH.ip.expandV6(CH.ip.bigToV6(first)),
                last_expanded: CH.ip.expandV6(CH.ip.bigToV6(last)),
                total_addresses: total.toString(),
                total_readable: hostBits > 62n ? '2^' + hostBits + ' addresses' : total.toLocaleString(),
                subnets_64: prefix <= 64 ? (2n ** BigInt(64 - prefix)).toString() : 'n/a (prefix is longer than /64)',
                network_was_normalised: CH.ip.compressV6(CH.ip.bigToV6(first)) !== CH.ip.compressV6(g)
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
            submitLabel: "Run IPv6 CIDR to Range"
        });
    });
})(window, document);
