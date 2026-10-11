/**
 * CloudHost247 Tools - Image to Text Converter
 *
 * Route-split module: this file is loaded only on /tools/image-to-text.
 * Execution: server.
 *
 * The page POSTs JSON, so the picked file is read into a base64 data URL on
 * change and submitted through a hidden field the mount hook registers.
 * Nothing is uploaded until the visitor ticks the explicit opt-in checkbox:
 * validate() blocks the submit otherwise, and the server re-checks the flag.
 */
(function (window, document) {
    'use strict';
    var CH = window.CH247Tools;
    if (!CH || !CH.ToolPage) { return; }

    CH.ready(function () {
        new CH.ToolPage({
            slug: "image-to-text",
            exec: "server",
            sensitive: true,
            fields: [
                {"name": "image", "label": "Image", "type": "file", "width": "full", "required": true, "hint": "JPG, PNG, GIF, BMP or TIFF, up to 700 KB."},
                {"name": "server_ocr", "label": "I agree to send this image to the server-side OCR service", "type": "checkbox", "width": "full", "hint": "Without this, nothing is uploaded and the tool does nothing."}
            ],
            validate: function (v) {
                if (!v.image_base64) { return 'Choose an image file first.'; }
                if (!v.server_ocr) { return 'Server-side OCR needs your explicit opt-in. Tick "I agree to send this image to the server-side OCR service" and run again.'; }
                return null;
            },
            mount: function (page) {
                var form = page.formEl;
                var input = page.root && page.root.querySelector('input[type="file"]');
                if (!form || !input) { return; }
                if (form.querySelector('input[name="image_base64"]')) { return; }

                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'image_base64';
                hidden.value = '';
                form.appendChild(hidden);
                // values() only collects cfg.fields, so register the dynamic field.
                page.cfg.fields.push({ name: 'image_base64', label: 'Image data' });

                var status = document.createElement('p');
                status.className = 'ch247-field__hint';
                status.setAttribute('role', 'status');
                if (input.parentNode) { input.parentNode.appendChild(status); }

                input.addEventListener('change', function () {
                    hidden.value = '';
                    status.textContent = '';
                    var file = input.files && input.files[0];
                    if (!file) { return; }
                    // The server validates the bytes authoritatively; this is an early UX hint.
                    if (file.type && !/^image\/(jpeg|jpg|png|gif|bmp|tiff|x-ms-bmp)$/.test(file.type)) {
                        page.showError('Choose a JPG, PNG, GIF, BMP or TIFF image.');
                        return;
                    }
                    // Transport budget: base64 inflates ~4/3 and the JSON request cap is 1 MB.
                    if (file.size > 700 * 1024) {
                        page.showError('That image is larger than 700 KB, the limit for browser upload. Resize or compress it and try again.');
                        return;
                    }
                    if (file.size === 0) { page.showError('That file is empty.'); return; }
                    page.setLoading(true);
                    var reader = new FileReader();
                    reader.onload = function () {
                        page.setLoading(false);
                        hidden.value = String(reader.result || '');
                        if (!hidden.value) { page.showError('That image could not be read.'); return; }
                        status.textContent = 'Ready: ' + file.name + ' (' + Math.round(file.size / 1024) + ' KB). Tick the consent box, then run.';
                    };
                    reader.onerror = function () { page.setLoading(false); page.showError('That image could not be read.'); };
                    reader.readAsDataURL(file);
                });
            },
            render: function (d) {
                var wrap = CH.el('div');
                if (!d || typeof d !== 'object') {
                    wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: String(d) }));
                    return wrap;
                }
                if (typeof d.text === 'string' && d.text !== '') {
                    wrap.appendChild(CH.el('pre', { class: 'ch247-pre ch247-pre--wrap', text: d.text }));
                }
                var scalars = {};
                ['characters', 'words', 'lines', 'engine', 'processing_ms'].forEach(function (k) {
                    if (d[k] !== undefined && d[k] !== null) { scalars[k] = d[k]; }
                });
                if (Object.keys(scalars).length) { wrap.appendChild(CH.kvList(scalars)); }
                if (Array.isArray(d.checks) && d.checks.length) {
                    wrap.appendChild(CH.checksList(d.checks));
                }
                [].concat(d.note || [], d.notes || []).forEach(function (n) {
                    wrap.appendChild(CH.el('p', { class: 'ch247-notice', text: String(n) }));
                });
                return wrap;
            },
            copyText: function (d) { return d && typeof d.text === 'string' ? d.text : ''; },
            submitLabel: "Run Image to Text"
        });
    });
})(window, document);
