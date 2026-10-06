/**
 * CloudHost247 Tools - Online Notepad
 *
 * Route-split module: this file is loaded only on /tools/notepad.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "notepad",
            exec: "client",
            fields: [{"name": "text", "label": "Notes", "type": "textarea", "width": "full"}],
            run: function (v) {
              var t = String(v.text || '');
              return { saved: true, characters: t.length,
                words: t.trim() ? t.trim().split(/\s+/).length : 0,
                lines: t.split('\n').length,
                note: 'Saved to this browser\u2019s local storage. Nothing is sent to CloudHost247. Clearing site data or using a different browser or device will lose these notes \u2014 do not use this as your only copy of anything important.' };
            },
            mount: function (page) {
              var KEY = 'ch247:tools:notepad';
              var area = page.root.querySelector('textarea');
              if (!area) { return; }
              var status = CH.el('p', { class: 'ch247-field__hint', role: 'status', 'aria-live': 'polite' });
              area.parentNode.appendChild(status);
              try { area.value = window.localStorage.getItem(KEY) || ''; } catch (e) {}
              var timer = null;
              var save = function () {
                try {
                  window.localStorage.setItem(KEY, area.value);
                  status.textContent = 'Saved locally at ' + new Date().toLocaleTimeString() +
                    ' \u2014 ' + area.value.length + ' characters.';
                } catch (e) { status.textContent = 'Could not save \u2014 browser storage is full or blocked.'; }
              };
              area.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(save, 600);
              });
              var bar = page.root.querySelector('[data-ch247-actions]') || page.root.querySelector('.ch247-actions');
              if (bar) {
                bar.appendChild(CH.el('button', { type: 'button', class: 'ch247-btn ch247-btn--ghost', text: 'Clear notes',
                  onclick: function () {
                    area.value = '';
                    try { window.localStorage.removeItem(KEY); } catch (e) {}
                    status.textContent = 'Notes cleared.';
                    area.focus();
                  } }));
              }
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
            submitLabel: "Run Online Notepad"
        });
    });
})(window, document);
