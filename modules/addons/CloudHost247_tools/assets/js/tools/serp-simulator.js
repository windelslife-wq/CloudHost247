/**
 * CloudHost247 Tools - Google SERP Simulator
 *
 * Route-split module: this file is loaded only on /tools/serp-simulator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "serp-simulator",
            exec: "client",
            fields: [{"name": "title", "label": "Page title", "placeholder": "Your page title", "width": "full", "required": true}, {"name": "url", "label": "URL", "type": "url", "placeholder": "https://example.com/page", "width": "full", "required": true, "spellcheck": false}, {"name": "description", "label": "Meta description", "type": "textarea", "placeholder": "Your meta description", "rows": 3, "width": "full"}],
            run: function (v) {
              var title = String(v.title || '').trim();
              var url = String(v.url || '').trim();
              var desc = String(v.description || '').trim();
              if (!title && !desc) { return { error: 'Enter at least a title or a meta description.' }; }
              // Google truncates on pixel width, not character count. These averages
              // approximate Arial 20px for titles and 14px for descriptions.
              var pxWidth = function (s, perChar) {
                var w = 0;
                for (var i = 0; i < s.length; i++) {
                  var c = s[i];
                  w += /[iljt.,:;'!|]/.test(c) ? perChar * 0.4 : /[mwMW]/.test(c) ? perChar * 1.4
                    : /[A-Z]/.test(c) ? perChar * 1.15 : perChar;
                }
                return Math.round(w);
              };
              var titlePx = pxWidth(title, 9.6), descPx = pxWidth(desc, 7.1);
              var TITLE_LIMIT = 580, DESC_LIMIT = 920;
              return {
                title: title, url: url, description: desc,
                title_characters: title.length,
                title_pixels: titlePx + 'px of ~' + TITLE_LIMIT + 'px',
                title_status: !title ? 'fail' : titlePx > TITLE_LIMIT ? 'warn' : titlePx < 200 ? 'warn' : 'pass',
                title_verdict: !title ? 'Missing title' : titlePx > TITLE_LIMIT ? 'Likely truncated on desktop' : titlePx < 200 ? 'Very short \u2014 you are wasting available space' : 'Fits',
                description_characters: desc.length,
                description_pixels: descPx + 'px of ~' + DESC_LIMIT + 'px',
                description_status: !desc ? 'warn' : descPx > DESC_LIMIT ? 'warn' : descPx < 400 ? 'warn' : 'pass',
                description_verdict: !desc ? 'Missing description \u2014 Google will invent one from the page' : descPx > DESC_LIMIT ? 'Likely truncated' : descPx < 400 ? 'Short \u2014 room to add detail' : 'Fits',
                note: 'Google truncates by pixel width, not character count, so a title of capital letters truncates sooner than one of lowercase. These widths are a close approximation, not Google\u2019s exact renderer. Google also frequently rewrites titles and descriptions regardless of what you set.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              var preview = CH.el('div', { style: 'border:1px solid var(--ch247-border);border-radius:8px;padding:16px;background:#fff;max-width:600px;' });
              preview.appendChild(CH.el('div', { style: 'color:#202124;font-size:12px;line-height:18px;', text: d.url || 'https://example.com' }));
              preview.appendChild(CH.el('div', { style: 'color:#1a0dab;font-size:20px;line-height:26px;font-family:arial,sans-serif;margin:2px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:580px;', text: d.title || '(no title)' }));
              preview.appendChild(CH.el('div', { style: 'color:#4d5156;font-size:14px;line-height:22px;font-family:arial,sans-serif;', text: d.description || '(no description)' }));
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Desktop preview' }));
              wrap.appendChild(preview);
              wrap.appendChild(CH.checksList([
                { name: 'Title', status: d.title_status, detail: d.title_characters + ' characters, ' + d.title_pixels + '. ' + d.title_verdict },
                { name: 'Meta description', status: d.description_status, detail: d.description_characters + ' characters, ' + d.description_pixels + '. ' + d.description_verdict }
              ]));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
              return wrap;
            },
            submitLabel: "Run Google SERP Simulator"
        });
    });
})(window, document);
