/**
 * CloudHost247 Tools - JSON Viewer, Beautifier & Minifier
 *
 * Route-split module: this file is loaded only on /tools/json-tool.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "json-tool",
            exec: "client",
            fields: [{"name": "json", "label": "JSON", "type": "textarea", "placeholder": "{\"hello\": \"world\"}", "width": "full", "required": true, "spellcheck": false}],
            run: function (v) {
              var raw = String(v.json || '').trim();
              if (!raw) { return { error: 'Paste some JSON.' }; }
              var parsed;
              try { parsed = JSON.parse(raw); }
              catch (e) {
                var m = /position (\d+)/.exec(e.message);
                var where = '';
                if (m) {
                  var pos = Number(m[1]);
                  var line = raw.slice(0, pos).split('\n').length;
                  var col = pos - raw.lastIndexOf('\n', pos - 1);
                  where = ' (line ' + line + ', column ' + col + ')';
                }
                return { error: 'Invalid JSON' + where + ': ' + e.message };
              }
              var stats = { objects: 0, arrays: 0, strings: 0, numbers: 0, booleans: 0, nulls: 0, maxDepth: 0, keys: 0 };
              (function walk(node, depth) {
                if (depth > stats.maxDepth) { stats.maxDepth = depth; }
                if (node === null) { stats.nulls++; }
                else if (Array.isArray(node)) { stats.arrays++; node.forEach(function (c) { walk(c, depth + 1); }); }
                else if (typeof node === 'object') {
                  stats.objects++;
                  Object.keys(node).forEach(function (k) { stats.keys++; walk(node[k], depth + 1); });
                }
                else if (typeof node === 'string') { stats.strings++; }
                else if (typeof node === 'number') { stats.numbers++; }
                else if (typeof node === 'boolean') { stats.booleans++; }
              })(parsed, 1);
              var pretty = JSON.stringify(parsed, null, 2);
              var mini = JSON.stringify(parsed);
              return {
                valid: true,
                root_type: Array.isArray(parsed) ? 'array' : parsed === null ? 'null' : typeof parsed,
                formatted: pretty, minified: mini,
                original_size: raw.length, minified_size: mini.length,
                saved: raw.length > mini.length ? (raw.length - mini.length) + ' bytes (' + Math.round((1 - mini.length / raw.length) * 100) + '%)' : '0 bytes',
                max_depth: stats.maxDepth, total_keys: stats.keys,
                counts: 'objects ' + stats.objects + ', arrays ' + stats.arrays + ', strings ' + stats.strings +
                        ', numbers ' + stats.numbers + ', booleans ' + stats.booleans + ', nulls ' + stats.nulls
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--info' }, [
                CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u2713' }),
                CH.el('div', {}, [CH.el('strong', { class: 'ch247-alert__title', text: 'Valid JSON' }),
                  CH.el('p', { class: 'ch247-alert__body', text: 'Root is a ' + d.root_type + ', ' + d.max_depth + ' levels deep, ' + d.total_keys + ' keys.' })])
              ]));
              wrap.appendChild(CH.kvList(d, ['root_type','max_depth','total_keys','counts','original_size','minified_size','saved']));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Formatted' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre', text: d.formatted }));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Minified' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.minified }));
              return wrap;
            },
            copyText: function (d) { return d.formatted; },
            submitLabel: "Run"
        });
    });
})(window, document);
