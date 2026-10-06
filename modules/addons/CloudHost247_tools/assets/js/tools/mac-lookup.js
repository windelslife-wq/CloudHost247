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
            fields: [{"name": "mac", "label": "MAC address", "placeholder": "00:1A:2B:3C:4D:5E", "required": true, "spellcheck": false}],
            run: function (v) {
              var raw = (v.mac || '').trim();
              var hex = raw.replace(/[^0-9a-fA-F]/g, '').toLowerCase();
              if (hex.length < 6) { return { error: 'Enter at least the first 3 bytes of a MAC address, e.g. 00:1A:2B.' }; }
              if (hex.length > 12) { return { error: 'A MAC address has at most 12 hex digits.' }; }
              var oui = hex.slice(0, 6);
              var first = parseInt(hex.slice(0, 2), 16);
              var bytes = hex.match(/.{1,2}/g);
              return {
                input: raw,
                normalised: bytes.join(':').toUpperCase(),
                oui: oui.match(/.{2}/g).join(':').toUpperCase(),
                formats: {
                  colon: bytes.join(':').toUpperCase(),
                  hyphen: bytes.join('-').toUpperCase(),
                  dot: (hex.match(/.{1,4}/g) || []).join('.').toUpperCase(),
                  bare: hex.toUpperCase()
                },
                administration: (first & 0x02) ? 'Locally administered (not a registered vendor OUI)' : 'Universally administered (registered OUI)',
                cast: (first & 0x01) ? 'Multicast' : 'Unicast',
                is_broadcast: hex === 'ffffffffffff',
                note: (first & 0x02)
                  ? 'The locally-administered bit is set, so this address was assigned by software (a VM, container, or MAC randomisation on a phone). It has no vendor to look up.'
                  : 'Vendor attribution requires the IEEE OUI registry. CloudHost247 resolves the vendor server-side only when the OUI database is installed, and will say so rather than guess.'
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
