/**
 * CloudHost247 Tools - IPv6 Range to CIDR
 *
 * Route-split module: this file is loaded only on /tools/ipv6-range-cidr.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "ipv6-range-cidr",
            exec: "client",
            fields: [{"name": "start_ip", "label": "Start address", "placeholder": "2001:db8::", "required": true, "spellcheck": false}, {"name": "end_ip", "label": "End address", "placeholder": "2001:db8::ffff", "required": true, "spellcheck": false}],
            run: function (v) {
              var a = CH.ip.parseV6(v.start_ip || ''), b = CH.ip.parseV6(v.end_ip || '');
              if (!a) { return { error: 'The start address is not a valid IPv6 address.' }; }
              if (!b) { return { error: 'The end address is not a valid IPv6 address.' }; }
              var s = CH.ip.v6ToBig(a), e = CH.ip.v6ToBig(b);
              if (s > e) { return { error: 'The start address must not be greater than the end address.' }; }
              var out = [], guard = 0;
              while (s <= e) {
                if (++guard > 512) { return { error: 'That range needs more than 512 CIDR blocks. Please narrow it.' }; }
                // Largest aligned block starting at s that does not overrun e.
                var maxSize = 128;
                while (maxSize > 0) {
                  var step = 2n ** BigInt(128 - maxSize + 1);
                  if ((s % step) !== 0n || (s + step - 1n) > e) { break; }
                  maxSize--;
                }
                var blockEnd = s + (2n ** BigInt(128 - maxSize)) - 1n;
                out.push({
                  cidr: CH.ip.compressV6(CH.ip.bigToV6(s)) + '/' + maxSize,
                  first: CH.ip.compressV6(CH.ip.bigToV6(s)),
                  last: CH.ip.compressV6(CH.ip.bigToV6(blockEnd)),
                  addresses: (128 - maxSize) > 62 ? '2^' + (128 - maxSize) : (2n ** BigInt(128 - maxSize)).toLocaleString()
                });
                if (blockEnd >= e) { break; }
                s = blockEnd + 1n;
              }
              return {
                start: CH.ip.compressV6(a), end: CH.ip.compressV6(b),
                block_count: out.length, cidrs: out,
                cidr_list: out.map(function (c) { return c.cidr; }).join('\n'),
                note: 'This is the minimal set of CIDR blocks covering the range exactly, with no extra addresses.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['start', 'end', 'block_count']));
              wrap.appendChild(CH.dataTable([
                { key: 'cidr', label: 'CIDR' }, { key: 'first', label: 'First address' },
                { key: 'last', label: 'Last address' }, { key: 'addresses', label: 'Addresses' }
              ], d.cidrs, d.block_count + ' CIDR block(s)'));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            copyText: function (d) { return d.cidr_list; },
            submitLabel: "Run IPv6 Range to CIDR"
        });
    });
})(window, document);
