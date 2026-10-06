/**
 * CloudHost247 Tools - Invisible Character Tool
 *
 * Route-split module: this file is loaded only on /tools/invisible-character.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "invisible-character",
            exec: "client",
            fields: [{"name": "text", "label": "Text", "type": "textarea", "placeholder": "Paste or type your text here...", "width": "full", "required": true}, {"name": "mode", "label": "Mode", "type": "select", "options": [{"value": "generate", "label": "generate"}, {"value": "detect", "label": "detect"}], "value": "generate"}, {"name": "character", "label": "Character", "type": "select", "value": "U+200B", "options": [{"value": "U+200B", "label": "Zero Width Space"}, {"value": "U+200C", "label": "Zero Width Non-Joiner"}, {"value": "U+200D", "label": "Zero Width Joiner"}, {"value": "U+2060", "label": "Word Joiner"}, {"value": "U+3164", "label": "Hangul Filler"}, {"value": "U+2800", "label": "Braille Blank"}, {"value": "U+00A0", "label": "Non-Breaking Space"}, {"value": "U+2007", "label": "Figure Space"}]}, {"name": "count", "label": "How many", "type": "number", "min": 1, "max": 1000, "value": 1}],
            run: function (v) {
              var CAT = [
                ['Zero Width Space','U+200B','\u200B','Allows a line break without a visible gap.'],
                ['Zero Width Non-Joiner','U+200C','\u200C','Prevents two characters forming a ligature.'],
                ['Zero Width Joiner','U+200D','\u200D','Joins characters \u2014 used to build emoji sequences.'],
                ['Word Joiner','U+2060','\u2060','Prevents a line break, with zero width.'],
                ['Hangul Filler','U+3164','\u3164','Renders blank in most fonts.'],
                ['Braille Pattern Blank','U+2800','\u2800','A Braille cell with no raised dots.'],
                ['Non-Breaking Space','U+00A0','\u00A0','A space that never breaks across lines.'],
                ['Narrow No-Break Space','U+202F','\u202F','A narrow space that never breaks.'],
                ['En Quad','U+2000','\u2000','Fixed-width space equal to 1 en.'],
                ['Em Quad','U+2001','\u2001','Fixed-width space equal to 1 em.'],
                ['Three-Per-Em Space','U+2004','\u2004','One third of an em.'],
                ['Figure Space','U+2007','\u2007','As wide as a digit; aligns numbers in tables.'],
                ['Invisible Separator','U+2063','\u2063','Mathematical invisible comma.'],
                ['Invisible Times','U+2062','\u2062','Mathematical invisible multiplication sign.'],
                ['Left-to-Right Mark','U+200E','\u200E','Forces left-to-right text direction.'],
                ['Right-to-Left Mark','U+200F','\u200F','Forces right-to-left text direction.'],
                ['Soft Hyphen','U+00AD','\u00AD','Hyphen shown only if the word wraps there.'],
                ['Mongolian Vowel Separator','U+180E','\u180E','Zero-width in modern Unicode.']
              ].map(function (r) { return { name: r[0], code: r[1], char: r[2], use: r[3] }; });
            
              if ((v.mode || 'generate') === 'detect') {
                var t = String(v.text || '');
                if (!t) { return { error: 'Paste some text to scan for invisible characters.' }; }
                var byChar = {}; CAT.forEach(function (e) { byChar[e.char] = e; });
                var found = {}, chars = Array.from(t);
                chars.forEach(function (c, idx) {
                  var e = byChar[c]; if (!e) { return; }
                  if (!found[e.code]) { found[e.code] = { name: e.name, code: e.code, count: 0, positions: [] }; }
                  found[e.code].count++;
                  if (found[e.code].positions.length < 50) { found[e.code].positions.push(idx); }
                });
                var list = Object.keys(found).map(function (k) {
                  var f = found[k]; f.positions = f.positions.join(', '); return f;
                });
                var cleaned = chars.filter(function (c) { return !byChar[c]; }).join('');
                return { mode: 'detect', total_characters: chars.length, found: list,
                  hidden_count: list.reduce(function (a, f) { return a + f.count; }, 0),
                  cleaned: cleaned, is_clean: list.length === 0,
                  note: list.length ? 'Invisible characters often come from copying out of web pages or word processors and can break string comparisons, usernames and CSV imports. A cleaned copy is above.' : 'No invisible characters were found.' };
              }
            
              var code = (v.character || 'U+200B').toUpperCase();
              var count = Math.max(1, Math.min(1000, Number(v.count) || 1));
              var sel = CAT.filter(function (e) { return e.code === code; })[0];
              if (!sel) { return { error: 'Unknown character code. Pick one from the list.' }; }
              return {
                mode: 'generate', character: sel.name, code: sel.code, count: count,
                result: sel.char.repeat(count),
                html_entities: ('&#x' + sel.code.slice(2) + ';').repeat(count),
                bytes: new TextEncoder().encode(sel.char.repeat(count)).length,
                use: sel.use, catalogue: CAT.map(function (e) { return { name: e.name, code: e.code, use: e.use }; }),
                note: 'Use the copy button \u2014 selecting by hand will not work, because there is nothing visible to select. Many platforms strip or reject invisible characters, and using them to evade moderation usually violates the terms of service.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              if (d.mode === 'detect') {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--' + (d.is_clean ? 'info' : 'warn') }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: d.is_clean ? '\u2713' : '\u26A0' }),
                  CH.el('div', {}, [
                    CH.el('strong', { class: 'ch247-alert__title', text: d.is_clean ? 'No invisible characters found' : d.hidden_count + ' invisible character(s) found' }),
                    CH.el('p', { class: 'ch247-alert__body', text: d.note })
                  ])
                ]));
                if (d.found.length) {
                  wrap.appendChild(CH.dataTable([
                    { key: 'name', label: 'Character' }, { key: 'code', label: 'Code point' },
                    { key: 'count', label: 'Count' }, { key: 'positions', label: 'Positions' }
                  ], d.found, 'Invisible characters detected'));
                  wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Cleaned text' }));
                  wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.cleaned }));
                }
                return wrap;
              }
              wrap.appendChild(CH.kvList(d, ['character','code','count','bytes','use','html_entities']));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              wrap.appendChild(CH.dataTable([
                { key: 'name', label: 'Character' }, { key: 'code', label: 'Code point' }, { key: 'use', label: 'Typical use' }
              ], d.catalogue, 'Available invisible characters'));
              return wrap;
            },
            copyText: function (d) { return d.mode === 'detect' ? d.cleaned : d.result; },
            submitLabel: "Run"
        });
    });
})(window, document);
