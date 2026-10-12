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

    function parseWifiPayload(payload) {
      if (payload.slice(0, 5).toUpperCase() !== 'WIFI:' || payload.slice(-2) !== ';;') { return null; }
      var body = payload.slice(5, -2), fields = [], current = '', escaped = false;
      for (var i = 0; i < body.length; i++) {
        var ch = body.charAt(i);
        if (escaped) { current += '\\' + ch; escaped = false; }
        else if (ch === '\\') { escaped = true; }
        else if (ch === ';') {
          if (!current) { return null; }
          fields.push(current); current = '';
        } else { current += ch; }
      }
      if (escaped || !current) { return null; }
      fields.push(current);

      var parsed = {};
      for (var j = 0; j < fields.length; j++) {
        var colon = fields[j].indexOf(':');
        if (colon < 1) { return null; }
        var key = fields[j].slice(0, colon).toUpperCase();
        if (['T', 'S', 'P', 'H'].indexOf(key) === -1 || Object.prototype.hasOwnProperty.call(parsed, key)) { return null; }
        parsed[key] = fields[j].slice(colon + 1);
      }
      if (!parsed.T || !parsed.S || !parsed.S.trim()) { return null; }
      if (!/^(?:WPA|WEP|nopass)$/i.test(parsed.T)) { return null; }
      if (parsed.T.toLowerCase() !== 'nopass' && !parsed.P) { return null; }
      if (parsed.H !== undefined && !/^(?:true|false)$/i.test(parsed.H)) { return null; }
      return parsed;
    }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "qr-generator",
            exec: "client",
            fields: [{"name": "qr_type", "label": "QR content type", "type": "select", "value": "text", "options": [{"value": "text", "label": "Plain text"}, {"value": "url", "label": "HTTP or HTTPS URL"}, {"value": "email", "label": "Email address"}, {"value": "phone", "label": "Phone number"}, {"value": "sms", "label": "SMS number"}, {"value": "wifi", "label": "Wi-Fi payload (WIFI: format)"}, {"value": "vcard", "label": "vCard payload"}]}, {"name": "content", "label": "Content or payload", "type": "textarea", "placeholder": "Text or URL to encode", "hint": "Wi-Fi and vCard require preformatted payloads. Wi-Fi payloads support WPA, WEP, or open networks; use the separate Wi-Fi QR tool for guided fields.", "width": "full", "required": true}],
            run: function (v) {
              var type = v.qr_type || 'text';
              var c = String(v.content || '').trim();
              var supported = ['text', 'url', 'email', 'phone', 'sms', 'wifi', 'vcard'];
              if (supported.indexOf(type) === -1) { return { error: 'Choose a supported QR content type.' }; }
              if (!c) { return { error: 'Enter the content to encode.' }; }
              var payload = c;
              if (type === 'url') {
                if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(c)) { payload = 'https://' + c; }
                try {
                  var parsedUrl = new URL(payload);
                  if ((parsedUrl.protocol !== 'http:' && parsedUrl.protocol !== 'https:') || parsedUrl.username || parsedUrl.password) {
                    return { error: 'Only HTTP or HTTPS URLs without embedded credentials can be encoded as links.' };
                  }
                  payload = parsedUrl.href;
                } catch (e) { return { error: 'That is not a valid HTTP or HTTPS URL.' }; }
              } else if (type === 'email') {
                if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(c)) { return { error: 'Enter a valid email address.' }; }
                payload = 'mailto:' + c;
              } else if (type === 'phone' || type === 'sms') {
                var phone = c.replace(/[^\d+]/g, '');
                if (!/^\+?\d{3,20}$/.test(phone)) { return { error: 'Enter a phone number with 3–20 digits, optionally starting with +.' }; }
                payload = (type === 'phone' ? 'tel:' : 'sms:') + phone;
              } else if (type === 'wifi') {
                if (!parseWifiPayload(c)) {
                  return { error: 'Enter a formatted Wi-Fi payload such as WIFI:T:WPA;S:NetworkName;P:Password;;. Escape special characters as required. Use the Wi-Fi QR tool for a guided form.' };
                }
              } else if (type === 'vcard') {
                if (!/^BEGIN:VCARD(?:\r?\n|$)/i.test(c) || !/\r?\nEND:VCARD\s*$/i.test(c)) {
                  return { error: 'Enter a vCard payload beginning with BEGIN:VCARD and ending with END:VCARD.' };
                }
              }
              var byteLength;
              try {
                byteLength = typeof TextEncoder === 'function'
                  ? new TextEncoder().encode(payload).length
                  : unescape(encodeURIComponent(payload)).length;
              } catch (e) { return { error: 'Content contains text that cannot be encoded as UTF-8.' }; }
              if (byteLength > 2000) { return { error: 'Content is too long for a reliable QR code (limit 2000 UTF-8 bytes).' }; }
              return {
                type: type, payload: payload, length: payload.length, bytes: byteLength,
                estimated_version: byteLength < 25 ? '1-2 (21-25 modules)' : byteLength < 100 ? '3-5' : byteLength < 400 ? '6-12' : '13+ (large, needs a bigger print size)',
                note: 'The QR code is generated entirely in your browser; the content is never sent to CloudHost247. SVG is vector, and PNG exports use a high-resolution module grid. Keep a quiet zone of at least 4 modules around the code when printing.'
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
              holder.appendChild(CH.el('div', { style: 'flex:1;min-width:220px;' }, [CH.kvList(d, ['type','length','bytes','estimated_version'])]));
              wrap.appendChild(holder);
              wrap.appendChild(CH.el('h3', { class: 'ch247-section__title', text: 'Encoded payload' }));
              wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.payload }));
              wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: d.note }));
            
              // The QR matrix is produced by the lazily-loaded encoder in qr-encoder.js.
              CH.loadScript('/modules/addons/CloudHost247_tools/assets/js/vendor/qr-encoder.js')
                .then(function () {
                  var m = window.CH247QR.encode(d.payload);
                  var ctx = canvas.getContext('2d');
                  var n = m.length, scale = Math.max(1, Math.floor(320 / (n + 8)));
                  var pad = Math.floor((320 - (n + 8) * scale) / 2) + 4 * scale;
                  ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 320, 320);
                  ctx.fillStyle = '#000';
                  for (var y = 0; y < n; y++) {
                    for (var x = 0; x < n; x++) { if (m[y][x]) { ctx.fillRect(pad + x * scale, pad + y * scale, scale, scale); } }
                  }
                  var downloadStatus = CH.el('p', { class: 'ch247-notice', role: 'status', 'aria-live': 'polite' });
                  function downloadBlob(blob, filename) {
                    if (!blob) { downloadStatus.textContent = 'The browser could not create this download. Try SVG or a smaller QR payload.'; return; }
                    var url = null, anchor = null;
                    try {
                      url = URL.createObjectURL(blob);
                      anchor = CH.el('a', { href: url, download: filename, class: 'ch247-sr-only' });
                      document.body.appendChild(anchor);
                      anchor.click();
                      document.body.removeChild(anchor);
                    } catch (e) {
                      if (anchor && anchor.parentNode) { anchor.parentNode.removeChild(anchor); }
                      if (url) { try { URL.revokeObjectURL(url); } catch (ignore) {} }
                      downloadStatus.textContent = 'The browser could not prepare the download.';
                      return;
                    }
                    downloadStatus.textContent = filename + ' download started.';
                    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                  }
                  function toSvg(matrix) {
                    var n = matrix.length, quiet = 4, size = n + quiet * 2, path = [];
                    for (var y = 0; y < n; y++) {
                      for (var x = 0; x < n; x++) {
                        if (matrix[y][x]) { path.push('M' + (x + quiet) + ' ' + (y + quiet) + 'h1v1h-1z'); }
                      }
                    }
                    return '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' + path.join('') + '" fill="#000"/></svg>';
                  }
                  function toPngBlob(matrix, callback) {
                    var n = matrix.length, quiet = 4, modulePixels = 10;
                    var size = (n + quiet * 2) * modulePixels;
                    var exportCanvas = document.createElement('canvas');
                    exportCanvas.width = exportCanvas.height = size;
                    var exportCtx = exportCanvas.getContext('2d');
                    if (!exportCtx) { callback(null); return; }
                    exportCtx.fillStyle = '#fff'; exportCtx.fillRect(0, 0, size, size);
                    exportCtx.fillStyle = '#000';
                    for (var y = 0; y < n; y++) {
                      for (var x = 0; x < n; x++) {
                        if (matrix[y][x]) {
                          exportCtx.fillRect((x + quiet) * modulePixels, (y + quiet) * modulePixels, modulePixels, modulePixels);
                        }
                      }
                    }
                    if (typeof exportCanvas.toBlob === 'function') {
                      try { exportCanvas.toBlob(callback, 'image/png'); } catch (e) { callback(null); }
                    } else { callback(null); }
                  }
                  var dl = CH.el('div', { class: 'ch247-actions' }, [
                    CH.el('button', { type: 'button', class: 'ch247-btn ch247-btn--ghost', text: 'Download PNG',
                      onclick: function () {
                        toPngBlob(m, function (blob) { downloadBlob(blob, 'qr-code.png'); });
                      } }),
                    CH.el('button', { type: 'button', class: 'ch247-btn ch247-btn--ghost', text: 'Download SVG',
                      onclick: function () {
                        try { downloadBlob(new Blob([toSvg(m)], { type: 'image/svg+xml;charset=utf-8' }), 'qr-code.svg'); }
                        catch (e) { downloadStatus.textContent = 'The browser could not create the SVG download.'; }
                      } })
                  ]);
                  holder.appendChild(dl);
                  wrap.appendChild(downloadStatus);
                })
                .catch(function (error) {
                  var message = error && /too long/i.test(error.message)
                    ? 'This payload exceeds the QR encoder capacity. Shorten it and try again.'
                    : 'The QR code could not be generated. Retry or shorten the content.';
                  canvas.setAttribute('aria-label', message);
                  var ctx = canvas.getContext('2d');
                  if (ctx) {
                    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 320, 320);
                    ctx.fillStyle = '#b32020'; ctx.font = '14px sans-serif';
                    ctx.fillText('QR generation failed.', 20, 160);
                  }
                  wrap.appendChild(CH.el('p', { class: 'ch247-notice', role: 'alert', text: message }));
                });
              return wrap;
            },
            copyText: function (d) { return d.payload; },
            submitLabel: "Run QR Code Generator"
        });
    });
})(window, document);
