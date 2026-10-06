/**
 * CloudHost247 Tools - IP Subnet Calculator
 *
 * Route-split module: this file is loaded only on /tools/ip-subnet-calculator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ip-subnet-calculator",
            exec: "client",
            fields: [{"name": "cidr", "label": "CIDR block", "placeholder": "2001:db8::/48", "required": true, "spellcheck": false}],
            run: function (v) {
              var s = (v.cidr || '').trim();
              var m = /^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})(?:\/(\d{1,2}))?$/.exec(s);
              if (!m || !CH.ip.isV4(m[1])) { return { error: 'Enter an IPv4 address with a prefix, such as 192.168.1.0/24.' }; }
              var prefix = m[2] === undefined ? 24 : Number(m[2]);
              if (prefix < 0 || prefix > 32) { return { error: 'Prefix length must be between 0 and 32.' }; }
              var addr = CH.ip.v4ToLong(m[1]) >>> 0;
              var mask = prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - prefix)) >>> 0);
              var network = (addr & mask) >>> 0;
              var broadcast = (network | (~mask >>> 0)) >>> 0;
              var total = Math.pow(2, 32 - prefix);
              var usable = prefix >= 31 ? (prefix === 31 ? 2 : 1) : total - 2;
              var privateRanges = [[ '10.0.0.0', 8 ], [ '172.16.0.0', 12 ], [ '192.168.0.0', 16 ]];
              var isPrivate = privateRanges.some(function (r) {
                var rm = (0xFFFFFFFF << (32 - r[1])) >>> 0;
                return ((CH.ip.v4ToLong(r[0]) & rm) >>> 0) === ((addr & rm) >>> 0);
              });
              return {
                input: s,
                network_address: CH.ip.longToV4(network) + '/' + prefix,
                subnet_mask: CH.ip.longToV4(mask),
                wildcard_mask: CH.ip.longToV4(~mask >>> 0),
                broadcast_address: prefix >= 31 ? 'n/a (point-to-point)' : CH.ip.longToV4(broadcast),
                first_usable: prefix >= 31 ? CH.ip.longToV4(network) : CH.ip.longToV4(network + 1),
                last_usable: prefix >= 31 ? CH.ip.longToV4(broadcast) : CH.ip.longToV4(broadcast - 1),
                total_addresses: total.toLocaleString(),
                usable_hosts: usable.toLocaleString(),
                prefix_length: '/' + prefix,
                mask_binary: [24,16,8,0].map(function (sh) { return ('0000000' + ((mask >>> sh) & 255).toString(2)).slice(-8); }).join('.'),
                ip_class: addr >>> 31 === 0 ? 'A' : (addr >>> 30) === 2 ? 'B' : (addr >>> 29) === 6 ? 'C' : (addr >>> 28) === 14 ? 'D (multicast)' : 'E (reserved)',
                scope: isPrivate ? 'Private (RFC 1918)' : 'Public',
                note: prefix >= 31 ? 'RFC 3021 /31 links and /32 host routes have no network or broadcast address.' : ''
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
            submitLabel: "Run IP Subnet Calculator"
        });
    });
})(window, document);
