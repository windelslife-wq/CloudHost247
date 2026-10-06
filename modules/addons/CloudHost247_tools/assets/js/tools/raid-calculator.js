/**
 * CloudHost247 Tools - RAID Calculator
 *
 * Route-split module: this file is loaded only on /tools/raid-calculator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "raid-calculator",
            exec: "client",
            fields: [{"name": "disks", "label": "Number of disks", "type": "number", "min": 1, "max": 256, "value": 4, "required": true}, {"name": "disk_size", "label": "Size of each disk (TB)", "type": "number", "min": 0.1, "max": 1000, "step": 0.1, "value": 4, "required": true}, {"name": "raid_level", "label": "RAID level", "type": "select", "value": "5", "options": [{"value": "0", "label": "0"}, {"value": "1", "label": "1"}, {"value": "5", "label": "5"}, {"value": "6", "label": "6"}, {"value": "10", "label": "10"}, {"value": "50", "label": "50"}, {"value": "60", "label": "60"}]}],
            run: function (v) {
              var n = Number(v.disks), size = Number(v.disk_size), level = String(v.raid_level || '5');
              if (!(n >= 1) || !(size > 0)) { return { error: 'Enter a disk count and a disk size greater than zero.' }; }
              var min = { '0': 2, '1': 2, '5': 3, '6': 4, '10': 4, '50': 6, '60': 8 }[level];
              if (n < min) { return { error: 'RAID ' + level + ' needs at least ' + min + ' disks. You entered ' + n + '.' }; }
              if ((level === '10' || level === '50' || level === '60') && n % 2 !== 0) {
                return { error: 'RAID ' + level + ' requires an even number of disks.' };
              }
              var usable, fault, readMult, writePenalty, desc;
              switch (level) {
                case '0': usable = n * size; fault = 0; readMult = n; writePenalty = 1;
                  desc = 'Striping with no redundancy. Fast, but a single disk failure loses the entire array.'; break;
                case '1': usable = size * Math.floor(n / 2) || size; usable = size; fault = n - 1; readMult = n; writePenalty = 2;
                  desc = 'Mirroring. Every disk holds the same data, so capacity equals one disk.'; break;
                case '5': usable = (n - 1) * size; fault = 1; readMult = n - 1; writePenalty = 4;
                  desc = 'Striping with single distributed parity. Survives one disk failure.'; break;
                case '6': usable = (n - 2) * size; fault = 2; readMult = n - 2; writePenalty = 6;
                  desc = 'Striping with double distributed parity. Survives two simultaneous disk failures.'; break;
                case '10': usable = (n / 2) * size; fault = 1; readMult = n; writePenalty = 2;
                  desc = 'Mirrored pairs, then striped. Survives at least one failure, and more if they fall in different mirrors.'; break;
                case '50': usable = (n - (n / 3 >= 2 ? 2 : 1)) * size; usable = (n - 2) * size; fault = 2; readMult = n - 2; writePenalty = 4;
                  desc = 'Two or more RAID 5 groups, striped. Survives one failure per group.'; break;
                default: usable = (n - 4) * size; fault = 4; readMult = n - 4; writePenalty = 6;
                  desc = 'Two or more RAID 6 groups, striped. Survives two failures per group.';
              }
              var raw = n * size;
              return {
                raid_level: 'RAID ' + level,
                description: desc,
                disks: n,
                disk_size: size + ' TB',
                raw_capacity: raw.toFixed(2) + ' TB',
                usable_capacity: usable.toFixed(2) + ' TB',
                capacity_for_redundancy: (raw - usable).toFixed(2) + ' TB',
                efficiency: Math.round((usable / raw) * 100) + '%',
                fault_tolerance: fault === 0 ? 'None \u2014 any disk failure destroys the array' : fault + ' disk failure(s)',
                read_performance: 'up to ' + readMult + 'x a single disk',
                write_penalty: writePenalty + 'x (I/O operations per write)',
                note: 'Capacity figures use decimal TB as printed on the drive. Formatted capacity will be roughly 7-9% lower once the filesystem is created. RAID is not a backup: it protects against disk failure, not deletion, corruption or ransomware.'
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
            submitLabel: "Run RAID Calculator"
        });
    });
})(window, document);
