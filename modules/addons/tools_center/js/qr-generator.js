/**
 * Tools Center - QR code generation (client-side).
 *
 * The code is built in the browser with the vendored qrcode-generator library.
 * The user's data is never sent to a third-party service.
 *
 * Public API (window.ToolsCenterQRGen, or module.exports in Node tests):
 *   generate(text, ecc, size) -> { ok: true, svg, modules, error_correction, size_px, characters }
 *                              | { ok: false, error }
 */
(function (root) {
    'use strict';

    // Byte-mode capacity at ECC M is about 2300 bytes (version 40). 2000 characters
    // fits for ASCII and most text. Longer or multi-byte input fails at encode time
    // with an explicit error.
    var MAX_DATA_CHARS = 2000;
    var MIN_SIZE_PX = 100;
    var MAX_SIZE_PX = 1000;
    var DEFAULT_SIZE_PX = 300;
    var QUIET_ZONE_MODULES = 4;
    var ECC_LEVELS = ['L', 'M', 'Q', 'H'];

    function getLib() {
        if (typeof qrcode === 'function') return qrcode;
        if (root && typeof root.qrcode === 'function') return root.qrcode;
        return null;
    }

    function clampSize(value) {
        var n = parseInt(value, 10);
        if (!isFinite(n)) n = DEFAULT_SIZE_PX;
        return Math.min(MAX_SIZE_PX, Math.max(MIN_SIZE_PX, n));
    }

    function normaliseEcc(value) {
        var level = String(value || 'M').toUpperCase();
        return ECC_LEVELS.indexOf(level) === -1 ? 'M' : level;
    }

    /**
     * Build the QR code for `text`. The SVG contains only numbers and fixed
     * element names, so it is safe to embed as an image.
     */
    function generate(text, ecc, size) {
        var lib = getLib();
        if (!lib) {
            return { ok: false, error: 'The QR generator is not loaded. Reload the page and try again.' };
        }
        if (typeof text !== 'string' || text.length === 0) {
            return { ok: false, error: 'Data is required.' };
        }
        if (text.length > MAX_DATA_CHARS) {
            return { ok: false, error: 'Data too long (max ' + MAX_DATA_CHARS + ' characters).' };
        }

        var level = normaliseEcc(ecc);
        var sizePx = clampSize(size);

        var qr;
        try {
            qr = lib(0, level);          // 0 = choose the smallest version that fits
            qr.addData(text);
            qr.make();
        } catch (e) {
            return { ok: false, error: 'The data could not be encoded as a QR code.' };
        }

        var count = qr.getModuleCount();
        var cell = Math.max(1, Math.floor(sizePx / (count + QUIET_ZONE_MODULES * 2)));
        var margin = QUIET_ZONE_MODULES * cell;
        var svg = qr.createSvgTag({ cellSize: cell, margin: margin, alt: 'QR code' });

        var modules = [];
        for (var r = 0; r < count; r++) {
            var row = '';
            for (var c = 0; c < count; c++) {
                row += qr.isDark(r, c) ? '1' : '0';
            }
            modules.push(row);
        }

        return {
            ok: true,
            svg: svg,
            modules: modules,
            error_correction: level,
            size_px: count * cell + margin * 2,
            characters: text.length
        };
    }

    var api = {
        MAX_DATA_CHARS: MAX_DATA_CHARS,
        generate: generate,
        clampSize: clampSize,
        normaliseEcc: normaliseEcc
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.ToolsCenterQRGen = api;
    }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
