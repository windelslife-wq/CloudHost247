/**
 * CloudHost247 Tools - Time Card Calculator
 *
 * Route-split module: this file is loaded only on /tools/time-card.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "time-card",
            exec: "client",
            fields: [{"name": "entries", "label": "Time entries", "type": "textarea", "placeholder": "Mon 09:00 17:30\nTue 09:00 17:00", "width": "full", "required": true, "spellcheck": false}, {"name": "break_minutes", "label": "Unpaid break per entry (minutes)", "type": "number", "min": 0, "max": 480, "value": 0}, {"name": "rate", "label": "Hourly rate (optional)", "type": "number", "min": 0, "step": 0.01}],
            run: function (v) {
              var raw = String(v.entries || '').trim();
              if (!raw) { return { error: 'Enter at least one line, for example: Mon 09:00 17:30' }; }
              var rate = Number(v.rate) || 0;
              var breakMin = Number(v.break_minutes) || 0;
              var rows = [], total = 0, errors = [];
              raw.split('\n').forEach(function (line, i) {
                line = line.trim(); if (!line) { return; }
                var m = /^(.*?)\s*(\d{1,2}):(\d{2})\s*(?:-|to|\u2013)?\s*(\d{1,2}):(\d{2})$/i.exec(line);
                if (!m) { errors.push('Line ' + (i + 1) + ': could not read "' + line + '". Use: Label 09:00 17:30'); return; }
                var label = m[1].trim() || ('Day ' + (rows.length + 1));
                var sh = Number(m[2]), sm = Number(m[3]), eh = Number(m[4]), em = Number(m[5]);
                if (sh > 23 || eh > 23 || sm > 59 || em > 59) { errors.push('Line ' + (i + 1) + ': invalid time.'); return; }
                var start = sh * 60 + sm, end = eh * 60 + em;
                if (end < start) { end += 1440; } // shift crossing midnight
                var mins = end - start - breakMin;
                if (mins < 0) { mins = 0; }
                total += mins;
                rows.push({ label: label, start: m[2].padStart(2, '0') + ':' + m[3], end: m[4].padStart(2, '0') + ':' + m[5],
                  hours: (mins / 60).toFixed(2), duration: Math.floor(mins / 60) + 'h ' + (mins % 60) + 'm' });
              });
              if (!rows.length) { return { error: errors.join(' ') || 'No valid entries found.' }; }
              var totalHours = total / 60;
              var regular = Math.min(totalHours, 40), overtime = Math.max(0, totalHours - 40);
              return {
                entries: rows, errors: errors,
                total_time: Math.floor(total / 60) + 'h ' + (total % 60) + 'm',
                total_hours: totalHours.toFixed(2),
                regular_hours: regular.toFixed(2),
                overtime_hours: overtime.toFixed(2),
                break_deducted: breakMin ? breakMin + ' min per entry' : 'none',
                pay: rate > 0 ? (regular * rate + overtime * rate * 1.5).toFixed(2) + ' (at ' + rate + '/hr, overtime at 1.5x)' : 'no rate entered',
                note: 'Calculated in your browser. Overtime is assumed above 40 hours at 1.5x, which is the common US FLSA rule \u2014 your jurisdiction, contract or award may differ, so check before relying on these figures for payroll.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.dataTable([
                { key: 'label', label: 'Entry' }, { key: 'start', label: 'Start' },
                { key: 'end', label: 'End' }, { key: 'duration', label: 'Duration' }, { key: 'hours', label: 'Hours' }
              ], d.entries, 'Time entries'));
              wrap.appendChild(CH.kvList(d, ['total_time','total_hours','regular_hours','overtime_hours','break_deducted','pay']));
              if (d.errors && d.errors.length) {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                  CH.el('ul', { class: 'ch247-list' }, d.errors.map(function (e) { return CH.el('li', { text: e }); }))
                ]));
              }
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            submitLabel: "Run Time Card Calculator"
        });
    });
})(window, document);
