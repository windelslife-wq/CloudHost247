/**
 * Tools Center - QR code decoding (client-side).
 *
 * The image is decoded in the browser with the vendored jsQR library. Nothing
 * is uploaded and no paid service is used.
 *
 * Public API (window.ToolsCenterQR, or module.exports in Node tests):
 *   decodeImageData(data, width, height) -> { ok: true, text } | { ok: false, error }
 *   validateFile(file)                   -> null | error message string
 *   decodeFile(file, callback)           -> callback({ ok, text } | { ok: false, error })   (browser only)
 */
(function (root) {
    'use strict';

    var MAX_FILE_BYTES = 5 * 1024 * 1024;   // 5 MB upload limit
    var MAX_SIDE_PX = 1600;                 // larger images are downscaled before decoding
    var ALLOWED_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'
    ];

    function getJsQR() {
        if (typeof jsQR === 'function') return jsQR;
        if (root && typeof root.jsQR === 'function') return root.jsQR;
        return null;
    }

    /**
     * Decode a QR code from RGBA pixel data (as returned by getImageData).
     */
    function decodeImageData(data, width, height) {
        var decoder = getJsQR();
        if (!decoder) {
            return { ok: false, error: 'The QR decoder is not loaded. Reload the page and try again.' };
        }
        if (!data || !(width > 0) || !(height > 0) || data.length < width * height * 4) {
            return { ok: false, error: 'Invalid image data.' };
        }

        var code = decoder(data, width, height, { inversionAttempts: 'attemptBoth' });
        if (!code || typeof code.data !== 'string') {
            return { ok: false, error: 'No QR code was found in this image.' };
        }
        return { ok: true, text: code.data };
    }

    /**
     * Check a File (or File-like object) before reading it. Returns null when it is acceptable.
     */
    function validateFile(file) {
        if (!file) {
            return 'Choose an image file first.';
        }
        if (ALLOWED_TYPES.indexOf(String(file.type || '').toLowerCase()) === -1) {
            return 'Unsupported file type. Use PNG, JPG, GIF, WebP or BMP.';
        }
        if (typeof file.size === 'number' && file.size > MAX_FILE_BYTES) {
            return 'The image is too large. The limit is 5 MB.';
        }
        return null;
    }

    /**
     * Read an image file in the browser, downscale it if needed, and decode it.
     */
    function decodeFile(file, callback) {
        var problem = validateFile(file);
        if (problem) {
            callback({ ok: false, error: problem });
            return;
        }

        var url;
        try {
            url = (root.URL || root.webkitURL).createObjectURL(file);
        } catch (e) {
            callback({ ok: false, error: 'This browser cannot read the selected file.' });
            return;
        }

        var img = new root.Image();
        var finish = function (result) {
            try { (root.URL || root.webkitURL).revokeObjectURL(url); } catch (e) { /* ignore */ }
            callback(result);
        };

        img.onerror = function () {
            finish({ ok: false, error: 'The file could not be read as an image.' });
        };

        img.onload = function () {
            try {
                var scale = Math.min(1, MAX_SIDE_PX / Math.max(img.width, img.height));
                var w = Math.max(1, Math.round(img.width * scale));
                var h = Math.max(1, Math.round(img.height * scale));

                var canvas = root.document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);
                var pixels = ctx.getImageData(0, 0, w, h);
                finish(decodeImageData(pixels.data, w, h));
            } catch (e) {
                finish({ ok: false, error: 'The image could not be decoded.' });
            }
        };

        img.src = url;
    }

    var api = {
        MAX_FILE_BYTES: MAX_FILE_BYTES,
        ALLOWED_TYPES: ALLOWED_TYPES.slice(),
        decodeImageData: decodeImageData,
        validateFile: validateFile,
        decodeFile: decodeFile
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.ToolsCenterQR = api;
    }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
