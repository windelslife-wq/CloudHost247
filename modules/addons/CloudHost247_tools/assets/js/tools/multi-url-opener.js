/**
 * CloudHost247 Tools - Multi URL Opener
 *
 * Route-split module: this file is loaded only on /tools/multi-url-opener.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "multi-url-opener",
            exec: "client",
            fields: [{"name": "urls", "label": "URLs (one per line)", "type": "textarea", "placeholder": "https://example.com\nhttps://example.org", "width": "full", "required": true, "spellcheck": false}],
            run: function (v) {
              var lines = String(v.urls || '').split(/[\s,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
              if (!lines.length) { return { error: 'Enter at least one URL, one per line.' }; }
              if (lines.length > 50) { return { error: 'Please enter 50 URLs or fewer.' }; }
              var valid = [], invalid = [];
              lines.forEach(function (line) {
                var candidate = /^[a-z][a-z0-9+.-]*:\/\//i.test(line) ? line : 'https://' + line;
                try {
                  var u = new URL(candidate);
                  if (u.protocol !== 'http:' && u.protocol !== 'https:') { invalid.push(line + ' (only http and https are supported)'); }
                  else { valid.push(u.href); }
                } catch (e) { invalid.push(line + ' (not a valid URL)'); }
              });
              return { total: lines.length, valid: valid, invalid: invalid, list: valid.join('\n') };
            },
            render: function (d, page) {
              var wrap = CH.el('div');
              wrap.appendChild(CH.el('p', { class: 'ch247-prose',
                text: d.valid.length + ' valid URL(s) ready. Your browser may ask permission to open multiple tabs.' }));
              wrap.appendChild(CH.el('div', { class: 'ch247-actions' }, [
                CH.el('button', { type: 'button', class: 'ch247-btn',
                  text: 'Open ' + d.valid.length + ' tab(s)',
                  onclick: function () {
                    var blocked = 0;
                    d.valid.forEach(function (href) {
                      var w = window.open(href, '_blank', 'noopener,noreferrer');
                      if (!w) { blocked++; }
                    });
                    var live = document.getElementById('ch247-live');
                    if (live) { live.textContent = blocked ? blocked + ' tab(s) were blocked by your popup blocker.' : 'Opened ' + d.valid.length + ' tabs.'; }
                  } })
              ]));
              wrap.appendChild(CH.el('ul', { class: 'ch247-list' }, d.valid.map(function (href) {
                return CH.el('li', {}, [CH.el('a', { href: href, target: '_blank', rel: 'noopener noreferrer', text: href })]);
              })));
              if (d.invalid.length) {
                wrap.appendChild(CH.el('div', { class: 'ch247-alert ch247-alert--warn' }, [
                  CH.el('span', { class: 'ch247-alert__icon', 'aria-hidden': 'true', text: '\u26A0' }),
                  CH.el('div', {}, [
                    CH.el('strong', { class: 'ch247-alert__title', text: d.invalid.length + ' entry/entries skipped' }),
                    CH.el('ul', { class: 'ch247-list' }, d.invalid.map(function (s) { return CH.el('li', { text: s }); }))
                  ])
                ]));
              }
              return wrap;
            },
            submitLabel: "Run Multi URL Opener"
        });
    });
})(window, document);
