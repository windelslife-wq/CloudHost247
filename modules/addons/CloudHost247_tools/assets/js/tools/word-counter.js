/**
 * CloudHost247 Tools - Word Counter
 *
 * Route-split module: this file is loaded only on /tools/word-counter.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "word-counter",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}],
            run: function (v) {
              var t = String(v.text || '');
              if (!t.trim()) { return { error: 'Enter some text to analyse.' }; }
              var words = t.trim().split(/\s+/).filter(Boolean);
              var sentences = t.split(/[.!?\u2026]+(?:\s|$)/).filter(function (s) { return s.trim(); });
              var paragraphs = t.split(/\n\s*\n/).filter(function (s) { return s.trim(); });
              var freq = {};
              words.forEach(function (w) {
                var k = w.toLowerCase().replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
                if (k) { freq[k] = (freq[k] || 0) + 1; }
              });
              var top = Object.keys(freq).sort(function (a, b) { return freq[b] - freq[a] || a.localeCompare(b); })
                .slice(0, 10).map(function (k) { return { word: k, count: freq[k], share: ((freq[k] / words.length) * 100).toFixed(1) + '%' }; });
              var syllables = words.reduce(function (acc, w) {
                var s = w.toLowerCase().replace(/[^a-z]/g, '').replace(/e$/, '').match(/[aeiouy]{1,2}/g);
                return acc + Math.max(1, s ? s.length : 1);
              }, 0);
              var fk = words.length && sentences.length
                ? 206.835 - 1.015 * (words.length / sentences.length) - 84.6 * (syllables / words.length) : 0;
              return {
                characters: t.length,
                characters_no_spaces: t.replace(/\s/g, '').length,
                words: words.length,
                unique_words: Object.keys(freq).length,
                sentences: sentences.length,
                paragraphs: paragraphs.length,
                lines: t.split('\n').length,
                avg_word_length: words.length ? (words.join('').length / words.length).toFixed(1) : 0,
                avg_words_per_sentence: sentences.length ? (words.length / sentences.length).toFixed(1) : 0,
                reading_time: Math.max(1, Math.round(words.length / 225)) + ' min (at 225 wpm)',
                speaking_time: Math.max(1, Math.round(words.length / 130)) + ' min (at 130 wpm)',
                reading_ease: fk.toFixed(1) + ' (' + (fk >= 90 ? 'very easy' : fk >= 70 ? 'easy' : fk >= 60 ? 'standard' : fk >= 50 ? 'fairly difficult' : fk >= 30 ? 'difficult' : 'very difficult') + ')',
                top_words: top
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.kvList(d, ['characters','characters_no_spaces','words','unique_words','sentences',
                'paragraphs','lines','avg_word_length','avg_words_per_sentence','reading_time','speaking_time','reading_ease']));
              if (d.top_words && d.top_words.length) {
                wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Most frequent words' }));
                wrap.appendChild(CH.dataTable([
                  { key: 'word', label: 'Word' }, { key: 'count', label: 'Count' }, { key: 'share', label: 'Share' }
                ], d.top_words, 'Top 10 words by frequency'));
              }
              return wrap;
            },
            submitLabel: "Run Word Counter"
        });
    });
})(window, document);
