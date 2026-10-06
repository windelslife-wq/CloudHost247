/**
 * CloudHost247 Tools - QR Code Generator
 *
 * Route-split module: this file is loaded only on /tools/qr-generator.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "qr-generator",
            exec: "client",
            fields: [{"name": "qr_type", "label": "QR type", "type": "select", "value": "text", "options": [{"value": "text", "label": "text"}, {"value": "url", "label": "url"}, {"value": "email", "label": "email"}, {"value": "phone", "label": "phone"}, {"value": "sms", "label": "sms"}, {"value": "wifi", "label": "wifi"}, {"value": "vcard", "label": "vcard"}]}, {"name": "content", "label": "Content", "type": "textarea", "placeholder": "Text or URL to encode", "width": "full", "required": true}],
            run: function (v) {
              var type = v.qr_type || 'text';
              var c = String(v.content || '').trim();
              if (!c) { return { error: 'Enter the content to encode.' }; }
              var payload = c;
              if (type === 'url') {
                if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(c)) { payload = 'https://' + c; }
                try { new URL(payload); } catch (e) { return { error: 'That is not a valid URL.' }; }
              } else if (type === 'email') {
                if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(c)) { return { error: 'Enter a valid email address.' }; }
                payload = 'mailto:' + c;
              } else if (type === 'phone') { payload = 'tel:' + c.replace(/[^\d+]/g, ''); }
              else if (type === 'sms')     { payload = 'sms:' + c.replace(/[^\d+]/g, ''); }
              if (payload.length > 2000) { return { error: 'Content is too long for a reliable QR code (limit 2000 characters).' }; }
              return {
                type: type, payload: payload, length: payload.length,
                estimated_version: payload.length < 25 ? '1-2 (21-25 modules)' : payload.length < 100 ? '3-5' : payload.length < 400 ? '6-12' : '13+ (large, needs a bigger print size)',
                note: 'The QR image is drawn in your browser with a canvas \u2014 the content is never sent to CloudHost247. Use error-correction level M or higher if the code will be printed, and keep a quiet zone of at least 4 modules around it.'
              };
            },
            render: function (d) {
              var wrap = CH.el('div');
              var holder = CH.el('div', { style: 'display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;' });
              var canvas = document.createElement('canvas');
              canvas.width = canvas.height = 320;
              canvas.setAttribute('role', 'img');
              canvas.setAttribute('aria-label', 'QR code encoding: ' + d.payload);
              canvas.style.cssText = 'border:1px solid var(--ch247-border);border-radius:8px;background:#fff;max-width:100%;height:auto;';
              holder.appendChild(canvas);
              holder.appendChild(CH.el('div', { style: 'flex:1;min-width:220px;' }, [CH.kvList(d, ['type','length','estimated_version'])]));
              wrap.appendChild(holder);
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Encoded payload' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.payload }));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
            
              // The QR matrix is produced by the lazily-loaded encoder in qr-encoder.js.
              CH.loadScript('/modules/addons/CloudHost247_tools/assets/js/vendor/qr-encoder.js')
                .then(function () {
                  var m = window.CH247QR.encode(d.payload);
                  var ctx = canvas.getContext('2d');
                  var n = m.length, scale = Math.floor(288 / n), pad = Math.round((320 - n * scale) / 2);
                  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 320, 320);
                  ctx.fillStyle = '#000';
                  for (var y = 0; y < n; y++) {
                    for (var x = 0; x < n; x++) { if (m[y][x]) { ctx.fillRect(pad + x * scale, pad + y * scale, scale, scale); } }
                  }
                  var dl = CH.el('div', { class: 'ch247-actions' }, [
                    CH.el('button', { type: 'button', class: 'ch247-btn ch247-btn--ghost', text: 'Download PNG',
                      onclick: function () {
                        canvas.toBlob(function (blob) {
                          var url = URL.createObjectURL(blob);
                          var a = CH.el('a', { href: url, download: 'qr-code.png' });
                          document.body.appendChild(a); a.click(); document.body.removeChild(a);
                          setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                        });
                      } })
                  ]);
                  holder.appendChild(dl);
                })
                .catch(function () {
                  var ctx = canvas.getContext('2d');
                  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 320, 320);
                  ctx.fillStyle = '#b32020'; ctx.font = '14px sans-serif';
                  ctx.fillText('QR encoder failed to load.', 20, 160);
                });
              return wrap;
            },
            copyText: function (d) { return d.payload; },
            submitLabel: "Run QR Code Generator"
        });
    });
})(window, document);
