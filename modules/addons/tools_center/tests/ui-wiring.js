'use strict';

/**
 * UI wiring tests for the QR Scanner tool (Module 2).
 *
 * These run the real tools-center.js, qr-scanner.js and vendored jsQR in a jsdom
 * window, with the real toolDefinitions block taken from templates/tools/tool.tpl.
 * They check that the form, the local decode path and the result rendering work,
 * and that no request is sent to the server.
 *
 * Limitation: jsdom has no canvas or image decoder, so the file-reading step
 * (ToolsCenterQR.decodeFile) is replaced by a stub that feeds fixture pixels to
 * the real decodeImageData. Real file reading is not exercised here; it needs a
 * browser check (see docs).
 *
 * Requires the optional jsdom package (not a runtime dependency):
 *   cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
 */

module.exports = function buildUiTests(ctx) {
    const { read, moduleDir, fixtures, renderRgba } = ctx;
    const assert = require('node:assert/strict');
    const { JSDOM } = require('jsdom');

    const toolsTpl = read('templates/tools/tool.tpl');
    const start = toolsTpl.indexOf('var toolDefinitions = {');
    const end = toolsTpl.indexOf('\n};', start) + 3;
    const defsSource = toolsTpl.slice(start, end);

    function makeWindow() {
        const dom = new JSDOM(`<!doctype html><html><body>
            <div id="toolForm"></div>
            <h3 id="currentToolName"></h3>
            <div id="toolResults" style="display:none"><div id="resultsContent"></div></div>
            <div id="toolModal"><h4 id="modalTitle"></h4><div id="modalBody"></div></div>
        </body></html>`, {
            runScripts: 'outside-only',
            url: 'https://client.example/index.php?m=tools_center&cat=productivity&tool=qrScanner'
        });
        const w = dom.window;
        w.__xhrCalls = 0;
        w.XMLHttpRequest = function () {
            w.__xhrCalls++;
            this.open = function () {};
            this.setRequestHeader = function () {};
            this.send = function () {};
        };
        w.eval(defsSource);
        // Same order as hooks.php loads them.
        w.eval(read('js/vendor/jsQR-1.4.0.js'));
        w.eval(read('js/vendor/qrcode-generator-2.0.4.js'));
        w.eval(read('js/vendor/qrcode-generator-2.0.4-utf8.js'));
        w.eval(read('js/qr-scanner.js'));
        w.eval(read('js/qr-generator.js'));
        w.eval(read('js/tools-center.js'));
        // Stub only the browser file-reading step; decodeImageData and validateFile stay real.
        w.ToolsCenterQR.decodeFile = function (file, callback) {
            const fixture = file.__fixture;
            const px = renderRgba(fixture.rows, 6, false);
            callback(w.ToolsCenterQR.decodeImageData(px.data, px.width, px.height));
        };
        return w;
    }

    function fakeFile(name, type, size, fixture) {
        return { name, type, size, __fixture: fixture };
    }

    function setFile(input, file) {
        Object.defineProperty(input, 'files', { value: file ? [file] : [], configurable: true });
    }

    function submit(w, form) {
        form.dispatchEvent(new w.Event('submit', { cancelable: true, bubbles: true }));
    }

    const pagePng = () => fixtures[0];

    return [
        {
            name: 'UI: QR Scanner form has a file input (PNG/JPG/GIF/WebP/BMP) and no URL field',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                const file = form.querySelector('input[name="image"]');
                assert.ok(file, 'image input missing');
                assert.equal(file.type, 'file');
                assert.match(file.accept, /image\/png/);
                assert.equal(form.querySelector('input[name="url"]'), null);
            }
        },
        {
            name: 'UI: page path decodes a chosen QR image locally and shows the text (no request)',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                setFile(form.querySelector('input[name="image"]'), fakeFile('qr.png', 'image/png', 4096, pagePng()));
                submit(w, form);
                const out = w.document.getElementById('resultsContent').textContent;
                assert.ok(out.includes(pagePng().text), 'decoded text not shown: ' + out.slice(0, 200));
                assert.equal(w.__xhrCalls, 0, 'no XHR request may be sent for the QR tool');
            }
        },
        {
            name: 'UI: decoded HTML-like text is shown as text, not rendered as markup',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                const fixture = fixtures[1];
                setFile(form.querySelector('input[name="image"]'), fakeFile('x.png', 'image/png', 4096, fixture));
                submit(w, form);
                const box = w.document.getElementById('resultsContent');
                assert.ok(box.textContent.includes(fixture.text), 'payload text missing');
                assert.equal(box.querySelector('img'), null, 'decoded text must not create an <img> element');
            }
        },
        {
            name: 'UI: an unsupported file type is rejected with a message and no request',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                setFile(form.querySelector('input[name="image"]'), fakeFile('a.txt', 'text/plain', 10, pagePng()));
                submit(w, form);
                assert.match(w.document.getElementById('resultsContent').textContent, /Unsupported file type/);
                assert.equal(w.__xhrCalls, 0);
            }
        },
        {
            name: 'UI: submitting with no file shows a clear message',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                submit(w, form);
                assert.match(w.document.getElementById('resultsContent').textContent, /Choose an image file first/);
                assert.equal(w.__xhrCalls, 0);
            }
        },
        {
            name: 'UI: an image with no QR code reports that clearly',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrScanner');
                const form = w.document.querySelector('#toolForm form');
                const blank = { rows: Array.from({ length: 21 }, () => '0'.repeat(21)) };
                setFile(form.querySelector('input[name="image"]'), fakeFile('blank.png', 'image/png', 4096, blank));
                submit(w, form);
                assert.match(w.document.getElementById('resultsContent').textContent, /No QR code was found/);
                assert.equal(w.__xhrCalls, 0);
            }
        },
        {
            name: 'UI: modal path (openTool) also decodes locally and shows the text',
            fn() {
                const w = makeWindow();
                w.openTool('productivity', 'qrScanner');
                const form = w.document.querySelector('#modalBody form');
                assert.ok(form, 'modal form missing');
                setFile(form.querySelector('input[name="image"]'), fakeFile('qr.png', 'image/png', 4096, pagePng()));
                submit(w, form);
                const out = w.document.getElementById('modalResults').textContent;
                assert.ok(out.includes(pagePng().text), 'modal did not show decoded text: ' + out.slice(0, 200));
                assert.equal(w.__xhrCalls, 0);
            }
        },
        {
            name: 'UI: QR Generator builds the code in the browser (SVG image + download link), no request',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrGenerator');
                const form = w.document.querySelector('#toolForm form');
                form.querySelector('input[name="data"]').value = 'https://cloudhost247.example/renew?service=42';
                form.querySelector('select[name="level"]').value = 'Q';
                submit(w, form);
                const box = w.document.getElementById('resultsContent');
                const img = box.querySelector('img');
                assert.ok(img, 'QR image missing');
                assert.ok(img.getAttribute('src').indexOf('data:image/svg+xml;charset=utf-8,') === 0, 'image must be a local SVG data URI');
                const link = box.querySelector('a[download]');
                assert.ok(link && link.getAttribute('download') === 'qr-code.svg', 'download link missing');
                assert.equal(w.__xhrCalls, 0, 'QR generation must not send a request');
            }
        },
        {
            name: 'UI: QR Generator reports empty data without a request',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'qrGenerator');
                const form = w.document.querySelector('#toolForm form');
                submit(w, form);
                assert.match(w.document.getElementById('resultsContent').textContent, /Data is required/);
                assert.equal(w.__xhrCalls, 0);
            }
        },
        {
            name: 'UI: a QR tool name that is not a local tool still uses the server path (no regression)',
            fn() {
                const w = makeWindow();
                w.renderToolForm('productivity', 'loremIpsum');
                const form = w.document.querySelector('#toolForm form');
                submit(w, form);
                assert.equal(w.__xhrCalls, 1, 'non-local tools must still POST to the server');
            }
        }
    ];
};
