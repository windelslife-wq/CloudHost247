/**
 * CloudHost247 Tools - QR Scanner
 *
 * Route-split module: this file is loaded only on /tools/qr-scanner.
 * Execution: client.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "qr-scanner",
            exec: "client",
            fields: [{"name": "image", "label": "QR code image", "type": "file", "width": "full"}],
            run: function (v, page) {
              return { awaiting_image: true,
                note: 'Choose a QR code image. Decoding happens entirely in your browser \u2014 the image is never uploaded to CloudHost247.' };
            },
            render: function (d) {
              return CH.el('p', { class: 'ch247-prose', text: d.note });
            },
            mount: function (page) {
              var root = page.root;
              var input = root.querySelector('input[type="file"]');
              if (!input) { return; }
              var out = root.querySelector('[data-ch247-result]');
              input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) { return; }
                if (!/^image\//.test(file.type)) { page.showError('Choose an image file.'); return; }
                if (file.size > 8 * 1024 * 1024) { page.showError('Image is too large (limit 8 MB).'); return; }
                page.setLoading(true);
                var img = new Image();
                img.onload = function () {
                  var canvas = document.createElement('canvas');
                  var scale = Math.min(1, 1000 / Math.max(img.width, img.height));
                  canvas.width = Math.round(img.width * scale);
                  canvas.height = Math.round(img.height * scale);
                  var ctx = canvas.getContext('2d', { willReadFrequently: true });
                  ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                  CH.loadScript('/modules/addons/CloudHost247_tools/assets/js/vendor/qr-decoder.js')
                    .then(function () {
                      var data = ctx.getImageData(0, 0, canvas.width, canvas.height);
                      var result = window.CH247QRDecode(data.data, data.width, data.height);
                      page.setLoading(false);
                      if (!result) {
                        page.showError('No QR code could be read from that image. Try a sharper, higher-contrast, less rotated photo.');
                        return;
                      }
                      var parsed = { decoded_text: result, length: result.length };
                      if (/^WIFI:/i.test(result)) { parsed.detected_type = 'Wi-Fi network credentials'; }
                      else if (/^https?:\/\//i.test(result)) { parsed.detected_type = 'URL'; parsed.warning = 'Check the domain carefully before opening. QR codes are a common phishing vector because the destination is not visible until you scan.'; }
                      else if (/^mailto:/i.test(result)) { parsed.detected_type = 'Email address'; }
                      else if (/^tel:/i.test(result)) { parsed.detected_type = 'Phone number'; }
                      else if (/^BEGIN:VCARD/i.test(result)) { parsed.detected_type = 'Contact card (vCard)'; }
                      else { parsed.detected_type = 'Plain text'; }
                      parsed.note = 'Decoded entirely in your browser. The image was never uploaded to CloudHost247.';
                      page.showResult(parsed, {});
                    })
                    .catch(function () { page.setLoading(false); page.showError('The QR decoder failed to load.'); });
                };
                img.onerror = function () { page.setLoading(false); page.showError('That image could not be read.'); };
                img.src = URL.createObjectURL(file);
              });
            },
            submitLabel: "Run QR Scanner"
        });
    });
})(window, document);
