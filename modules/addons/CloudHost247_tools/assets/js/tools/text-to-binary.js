/**
 * CloudHost247 Tools - Text to Binary
 *
 * Route-split module: this file is loaded only on /tools/text-to-binary.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "text-to-binary",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "encode", "label": "encode"}, {"value": "decode", "label": "decode"}], "value": "encode"}, {"name": "base", "label": "Base", "type": "select", "options": [{"value": "binary", "label": "binary"}, {"value": "octal", "label": "octal"}, {"value": "decimal", "label": "decimal"}, {"value": "hexadecimal", "label": "hexadecimal"}], "value": "binary"}],
            run: function (v) {
              var t = String(v.text || '');
              if (!t) { return { error: 'Enter some text to convert.' }; }
              var mode = v.mode || 'encode';
              var baseName = v.base || 'binary';
              var cfg = { binary: [2, 8], octal: [8, 3], decimal: [10, 0], hexadecimal: [16, 2] }[baseName];
              if (!cfg) { return { error: 'Choose binary, octal, decimal or hexadecimal.' }; }
            
              if (mode === 'decode') {
                var valid = { 2: /^[01]+$/, 8: /^[0-7]+$/, 10: /^\d+$/, 16: /^[0-9a-fA-F]+$/ }[cfg[0]];
                var tokens = t.trim().split(/[\s,]+/).filter(Boolean), bytes = [];
                for (var i = 0; i < tokens.length; i++) {
                  var tk = tokens[i].replace(/^0[xbo]/i, '');
                  if (!valid.test(tk)) { return { error: 'Value "' + tokens[i] + '" at position ' + (i + 1) + ' is not valid ' + baseName + '.' }; }
                  var n = parseInt(tk, cfg[0]);
                  if (n > 255) { return { error: 'Value "' + tokens[i] + '" is outside the byte range 0-255.' }; }
                  bytes.push(n);
                }
                var decoded;
                try { decoded = new TextDecoder('utf-8', { fatal: true }).decode(new Uint8Array(bytes)); }
                catch (e) { decoded = null; }
                return { mode: 'decode', base: baseName, result: decoded === null ? '(not valid UTF-8)' : decoded,
                         valid_utf8: decoded !== null, bytes: bytes.length };
              }
            
              var enc = new TextEncoder().encode(t);
              var toks = Array.from(enc).map(function (b) {
                var s = b.toString(cfg[0]);
                if (cfg[1]) { s = ('0'.repeat(cfg[1]) + s).slice(-cfg[1]); }
                return cfg[0] === 16 ? s.toUpperCase() : s;
              });
              var chars = Array.from(t);
              return {
                mode: 'encode', base: baseName, result: toks.join(' '),
                characters: chars.length, bytes: enc.length,
                breakdown: chars.slice(0, 200).map(function (c) {
                  var cb = Array.from(new TextEncoder().encode(c));
                  return {
                    char: c === ' ' ? '(space)' : c,
                    codepoint: 'U+' + ('000' + c.codePointAt(0).toString(16).toUpperCase()).slice(-4),
                    bytes: cb.length,
                    binary: cb.map(function (b) { return ('0000000' + b.toString(2)).slice(-8); }).join(' ')
                  };
                }),
                note: 'Text is encoded as UTF-8 first, so characters outside ASCII correctly produce multiple bytes.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.result }));
              wrap.appendChild(CH.kvList(d, ['mode','base','characters','bytes','valid_utf8']));
              if (d.breakdown && d.breakdown.length) {
                wrap.appendChild(CH.dataTable([
                  { key: 'char', label: 'Character' }, { key: 'codepoint', label: 'Code point' },
                  { key: 'bytes', label: 'Bytes' }, { key: 'binary', label: 'Binary' }
                ], d.breakdown, 'Per-character breakdown'));
              }
              if (d.note) { wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note })); }
              return wrap;
            },
            copyText: function (d) { return d.result; },
            submitLabel: "Run Text to Binary"
        });
    });
})(window, document);
