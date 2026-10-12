/**
 * CloudHost247 Tools - MAC Address Lookup
 *
 * Route-split module: this file is loaded only on /tools/mac-lookup.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "mac-lookup",
            exec: "client",
            fields: [{"name": "mac", "label": "MAC address or OUI prefix", "placeholder": "00:1A:2B:3C:4D:5E", "hint": "A six-hex-digit prefix or full 12-digit MAC. Vendor lookup is not available without an approved OUI source.", "required": true, "spellcheck": false}],
            run: function (v) {
              var rawValue = v.mac || '';
              if (typeof rawValue !== 'string' || rawValue.length > 64) {
                return { error: 'Enter a MAC address or a six-hex-digit OUI prefix.' };
              }
              var raw = rawValue.trim();
              var isPrefix = /^(?:[0-9a-f]{6}|[0-9a-f]{2}(?::[0-9a-f]{2}){2}|[0-9a-f]{2}(?:-[0-9a-f]{2}){2})$/i.test(raw);
              var isFull = /^(?:[0-9a-f]{12}|[0-9a-f]{2}(?::[0-9a-f]{2}){5}|[0-9a-f]{2}(?:-[0-9a-f]{2}){5}|[0-9a-f]{4}(?:\.[0-9a-f]{4}){2})$/i.test(raw);
              if (!isPrefix && !isFull) {
                return { error: 'Use six hex digits for an OUI prefix, or a complete 12-digit MAC in colon, hyphen, dotted, or bare format.' };
              }
              var hex = raw.replace(/[:.-]/g, '').toLowerCase();
              var oui = hex.slice(0, 6);
              var first = parseInt(hex.slice(0, 2), 16);
              var bytes = hex.match(/.{1,2}/g);
              var local = (first & 0x02) !== 0;
              return {
                input: raw,
                normalised: bytes.join(':').toUpperCase(),
                oui: oui.match(/.{2}/g).join(':').toUpperCase(),
                vendor_lookup: 'Unavailable',
                lookup_source: 'No OUI registry dataset is configured in this browser tool.',
                coverage: 'Vendor is not inferred. No vendor result or OUI coverage is claimed.',
                formats: {
                  colon: bytes.join(':').toUpperCase(),
                  hyphen: bytes.join('-').toUpperCase(),
                  dot: (hex.match(/.{1,4}/g) || []).join('.').toUpperCase(),
                  bare: hex.toUpperCase()
                },
                administration: local ? 'Locally administered (not a registered vendor OUI)' : 'Universally administered (vendor registry data unavailable)',
                cast: (first & 0x01) ? 'Multicast' : 'Unicast',
                is_broadcast: hex === 'ffffffffffff',
                note: 'This tool checks MAC/OUI syntax and address bits only. Vendor lookup is unavailable because no OUI dataset or approved provider is configured. The address stays in your browser and is not sent to CloudHost247.'
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
            submitLabel: "Run MAC Address Lookup"
        });
    });
})(window, document);
