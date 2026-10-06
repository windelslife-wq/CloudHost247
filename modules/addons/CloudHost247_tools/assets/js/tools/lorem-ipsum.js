/**
 * CloudHost247 Tools - Lorem Ipsum Generator
 *
 * Route-split module: this file is loaded only on /tools/lorem-ipsum.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "lorem-ipsum",
            exec: "client",
            fields: [{"name": "count", "label": "How many", "type": "number", "min": 1, "max": 100, "value": 5}, {"name": "unit", "label": "Unit", "type": "select", "value": "paragraphs", "options": [{"value": "paragraphs", "label": "paragraphs"}, {"value": "sentences", "label": "sentences"}, {"value": "words", "label": "words"}]}],
            run: function (v) {
              var WORDS = ('lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore ' +
                'et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea ' +
                'commodo consequat duis aute irure in reprehenderit voluptate velit esse cillum eu fugiat nulla pariatur ' +
                'excepteur sint occaecat cupidatat non proident sunt culpa qui officia deserunt mollit anim id est laborum ' +
                'perspiciatis unde omnis iste natus error voluptatem accusantium doloremque laudantium totam rem aperiam ' +
                'eaque ipsa quae ab illo inventore veritatis quasi architecto beatae vitae dicta explicabo').split(' ');
              var count = Math.max(1, Math.min(200, Number(v.count) || 3));
              var unit = v.unit || 'paragraphs';
              var rnd = function (n) { return Math.floor(Math.random() * n); };
              var word = function () { return WORDS[rnd(WORDS.length)]; };
              var sentence = function () {
                var n = 6 + rnd(10), parts = [];
                for (var i = 0; i < n; i++) { parts.push(word()); }
                var s = parts.join(' ');
                if (n > 10) { s = s.replace(/ (\w+) /, ', $1 '); }
                return s.charAt(0).toUpperCase() + s.slice(1) + '.';
              };
              var out;
              if (unit === 'words') {
                var w = []; for (var i = 0; i < count; i++) { w.push(word()); }
                w[0] = w[0].charAt(0).toUpperCase() + w[0].slice(1);
                out = [w.join(' ') + '.'];
              } else if (unit === 'sentences') {
                out = []; for (var j = 0; j < count; j++) { out.push(sentence()); }
                out = [out.join(' ')];
              } else {
                out = [];
                for (var k = 0; k < count; k++) {
                  var n = 3 + rnd(4), ss = [];
                  for (var l = 0; l < n; l++) { ss.push(sentence()); }
                  out.push(ss.join(' '));
                }
                if (out.length) { out[0] = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ' + out[0]; }
              }
              var text = out.join('\n\n');
              return {
                unit: unit, requested: count,
                words: text.trim().split(/\s+/).length, characters: text.length,
                text: text,
                html: out.map(function (p) { return '<p>' + p + '</p>'; }).join('\n')
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['unit','requested','words','characters']));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.text }));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'HTML' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.html }));
              return wrap;
            },
            copyText: function (d) { return d.text; },
            submitLabel: "Run Lorem Ipsum Generator"
        });
    });
})(window, document);
