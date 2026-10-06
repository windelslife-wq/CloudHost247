/**
 * CloudHost247 Tools - ROT13 Decoder / Encoder
 *
 * Route-split module: this file is loaded only on /tools/rot13.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "rot13",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "shift", "label": "Shift", "type": "number", "value": 13}],
            run: function (v) {
              var t = String(v.text || '');
              if (!t) { return { error: 'Enter some text.' }; }
              var shift = Number(v.shift); if (!isFinite(shift)) { shift = 13; }
              shift = ((shift % 26) + 26) % 26;
              var apply = function (s, k) {
                return s.replace(/[a-z]/gi, function (c) {
                  var base = c <= 'Z' ? 65 : 97;
                  return String.fromCharCode(((c.charCodeAt(0) - base + k) % 26) + base);
                });
              };
              var out = apply(t, shift);
              var all = [];
              for (var i = 1; i <= 25; i++) { all.push({ shift: i, text: apply(t, i).slice(0, 90) }); }
              return {
                input: t, shift: shift, result: out,
                reversible: shift === 13,
                note: shift === 13
                  ? 'ROT13 is its own inverse: applying it twice returns the original text. It is an obfuscation for hiding spoilers, not encryption \u2014 it provides no security whatsoever.'
                  : 'A Caesar shift of ' + shift + '. Apply a shift of ' + (26 - shift) + ' to reverse it. This is not encryption and provides no security.',
                all_shifts: all
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.result }));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              var details = CH.el('details', { class: 'ch247-section' });
              details.appendChild(CH.el('summary', { text: 'Show all 25 Caesar shifts (brute force)' }));
              details.appendChild(CH.dataTable([{ key: 'shift', label: 'Shift' }, { key: 'text', label: 'Result' }],
                d.all_shifts, 'Every possible Caesar shift'));
              wrap.appendChild(details);
              return wrap;
            },
            copyText: function (d) { return d.result; },
            submitLabel: "Run ROT13 Decoder / Encoder"
        });
    });
})(window, document);
