#!/usr/bin/env node
/**
 * Tools Center tests (Module 2): QR decoding and the hardening checks.
 *
 * Run:  node modules/addons/tools_center/tests/run-tests.js
 * Needs only Node.js (no npm install). Exit code 0 when every test passes.
 *
 * Decoding tests use real QR codes (tests/fixtures/qr-fixtures.json, generated
 * with the Python `qrcode` package) rendered to pixels and decoded by the
 * vendored jsQR library. Static checks read the PHP/template/JS sources.
 */
'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const moduleDir = path.resolve(__dirname, '..');
const read = (rel) => fs.readFileSync(path.join(moduleDir, rel), 'utf8');

const jsQRPath = path.join(moduleDir, 'js', 'vendor', 'jsQR-1.4.0.js');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'qr-fixtures.json'), 'utf8')).cases;

// Load the vendored decoder as a global, the same way the browser does.
global.jsQR = require(jsQRPath);
const QR = require(path.join(moduleDir, 'js', 'qr-scanner.js'));

/** Render a module matrix to RGBA pixels with a 4-module quiet zone. */
function renderRgba(rows, scale, invert) {
    const quiet = 4;
    const modules = rows.length + quiet * 2;
    const size = modules * scale;
    const data = new Uint8ClampedArray(size * size * 4);
    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const mx = Math.floor(x / scale) - quiet;
            const my = Math.floor(y / scale) - quiet;
            let dark = mx >= 0 && my >= 0 && mx < rows.length && my < rows.length && rows[my][mx] === '1';
            if (invert) dark = !dark;
            const v = dark ? 0 : 255;
            const i = (y * size + x) * 4;
            data[i] = v; data[i + 1] = v; data[i + 2] = v; data[i + 3] = 255;
        }
    }
    return { data, width: size, height: size };
}

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

// ---------- decoding ----------

fixtures.forEach((c, idx) => {
    test(`decodes fixture ${idx + 1} (ECC ${c.ecc}, version ${c.version}) to the exact text`, () => {
        const img = renderRgba(c.rows, 6, false);
        const res = QR.decodeImageData(img.data, img.width, img.height);
        assert.equal(res.ok, true, res.error);
        assert.equal(res.text, c.text);
    });
});

test('decodes an inverted (light-on-dark) QR code', () => {
    const c = fixtures[0];
    const img = renderRgba(c.rows, 6, true);
    const res = QR.decodeImageData(img.data, img.width, img.height);
    assert.equal(res.ok, true, res.error);
    assert.equal(res.text, c.text);
});

test('returns the raw payload unchanged (no HTML interpretation at decode time)', () => {
    const c = fixtures[1];
    const img = renderRgba(c.rows, 6, false);
    const res = QR.decodeImageData(img.data, img.width, img.height);
    assert.equal(res.text, '<img src=x onerror=alert(1)>');
});

test('a blank image reports that no QR code was found', () => {
    const w = 200, h = 200;
    const data = new Uint8ClampedArray(w * h * 4).fill(255);
    const res = QR.decodeImageData(data, w, h);
    assert.deepEqual(res, { ok: false, error: 'No QR code was found in this image.' });
});

test('invalid pixel data is rejected without calling the decoder', () => {
    assert.deepEqual(QR.decodeImageData(null, 10, 10), { ok: false, error: 'Invalid image data.' });
    assert.deepEqual(QR.decodeImageData(new Uint8ClampedArray(16), 0, 4), { ok: false, error: 'Invalid image data.' });
    assert.deepEqual(QR.decodeImageData(new Uint8ClampedArray(16), 4, 4), { ok: false, error: 'Invalid image data.' });
});

test('reports a clear error when the decoder library is not loaded', () => {
    const saved = global.jsQR;
    delete global.jsQR;
    try {
        const res = QR.decodeImageData(new Uint8ClampedArray(4 * 4 * 4), 4, 4);
        assert.equal(res.ok, false);
        assert.match(res.error, /not loaded/);
    } finally {
        global.jsQR = saved;
    }
});

// ---------- file validation ----------

test('validateFile rejects a missing file', () => {
    assert.equal(QR.validateFile(null), 'Choose an image file first.');
});

test('validateFile rejects non-image types', () => {
    assert.match(QR.validateFile({ type: 'text/plain', size: 10 }), /Unsupported file type/);
    assert.match(QR.validateFile({ type: '', size: 10 }), /Unsupported file type/);
    assert.match(QR.validateFile({ type: 'image/svg+xml', size: 10 }), /Unsupported file type/);
});

test('validateFile rejects files over 5 MB', () => {
    assert.match(QR.validateFile({ type: 'image/png', size: 5 * 1024 * 1024 + 1 }), /too large/);
});

test('validateFile accepts supported images within the limit (type is case-insensitive)', () => {
    assert.equal(QR.validateFile({ type: 'image/png', size: 1024 }), null);
    assert.equal(QR.validateFile({ type: 'IMAGE/JPEG', size: 1024 }), null);
    assert.equal(QR.validateFile({ type: 'image/webp', size: 5 * 1024 * 1024 }), null);
});

// ---------- vendored library integrity ----------

test('vendored jsQR matches the SHA-256 recorded in js/vendor/README.md', () => {
    const digest = crypto.createHash('sha256').update(fs.readFileSync(jsQRPath)).digest('hex');
    const recorded = /SHA-256 of `jsQR-1\.4\.0\.js`: `([0-9a-f]{64})`/.exec(read('js/vendor/README.md'));
    assert.ok(recorded, 'checksum line missing from vendor README');
    assert.equal(digest, recorded[1]);
});

test('vendored jsQR ships its Apache-2.0 licence text', () => {
    assert.match(read('js/vendor/jsQR-1.4.0.LICENSE'), /Apache License/);
});

test('fixtures are well-formed square module matrices', () => {
    fixtures.forEach((c) => {
        assert.equal(c.rows.length, c.size);
        c.rows.forEach((r) => assert.equal(r.length, c.size));
        assert.match(c.rows.join(''), /^[01]+$/);
    });
});

// ---------- hardening checks on the shipped code ----------

test('API token is not sent to the browser (not assigned to the client-area template)', () => {
    const src = read('clientarea.php');
    assert.equal(/assign\(\s*'apiToken'/.test(src), false, 'apiToken must not be assigned to the template');
});

test('outbound API call does not follow redirects (the token is never forwarded) and is HTTPS only', () => {
    const src = read('hooks.php');
    assert.equal(/CURLOPT_FOLLOWLOCATION\s*=>\s*true/.test(src), false, 'FOLLOWLOCATION must not be true');
    assert.ok(/CURLOPT_FOLLOWLOCATION\s*=>\s*false/.test(src), 'FOLLOWLOCATION must be false');
    assert.ok(/CURLOPT_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS/.test(src), 'CURLOPT_PROTOCOLS must be HTTPS only');
    assert.ok(/CURLOPT_REDIR_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS/.test(src), 'CURLOPT_REDIR_PROTOCOLS must be HTTPS only');
});

test('the tools page loads the vendored decoder before the tools script', () => {
    const src = read('hooks.php');
    const lib = src.indexOf('vendor/jsQR-1.4.0.js');
    const qr = src.indexOf('js/qr-scanner.js');
    const tools = src.indexOf('js/tools-center.js');
    assert.ok(lib > 0 && qr > lib && tools > qr, 'expected jsQR, then qr-scanner, then tools-center');
});

test('the QR scanner tool takes an uploaded file, not a URL, and does not post it to the server', () => {
    const tpl = read('templates/tools/tool.tpl');
    const def = /qrScanner:\s*\{[\s\S]*?\n    \}/.exec(tpl);
    assert.ok(def, 'qrScanner definition not found');
    assert.ok(/type:\s*'file'/.test(def[0]), 'qrScanner must use a file field');
    assert.equal(/QR Image URL/.test(def[0]), false, 'URL input must be removed');
});

test('both submit paths route qrScanner to the local decoder before any XHR request', () => {
    const js = read('js/tools-center.js');
    assert.ok(/LOCAL_TOOL_HANDLERS/.test(js), 'LOCAL_TOOL_HANDLERS map is missing');
    const submitIdx = js.indexOf('function submitToolForm');
    const pageIdx = js.indexOf('function runPageTool');
    const localChecks = [...js.matchAll(/var localHandler = getLocalHandler\(action\)/g)].map((m) => m.index);
    const xhrOpens = [...js.matchAll(/xhr\.open\('POST', apiUrl, true\);/g)].map((m) => m.index);
    assert.equal(localChecks.length, 2, 'expected one local-handler check in each submit path');
    assert.equal(xhrOpens.length, 2, 'expected the two existing XHR calls');
    assert.ok(localChecks[0] > submitIdx && localChecks[0] < xhrOpens[0], 'modal path must check before its XHR');
    assert.ok(localChecks[1] > pageIdx && localChecks[1] < xhrOpens[1], 'page path must check before its XHR');
});

test('the decoded QR text is shown through escaped rendering (no innerHTML of raw text)', () => {
    const js = read('js/tools-center.js');
    const fn = /function decodeQrFromForm[\s\S]*?\n    \}\n/.exec(js);
    assert.ok(fn, 'decodeQrFromForm not found');
    assert.ok(!/innerHTML/.test(fn[0]), 'decodeQrFromForm must not write raw text to innerHTML');
});

// ---------- UI wiring (optional: needs jsdom) ----------

let uiSkipReason = null;
try {
    require.resolve('jsdom');
} catch (e) {
    uiSkipReason = 'jsdom is not installed. Run: cd modules/addons/tools_center/tests && npm install --no-save jsdom@24';
}
if (!uiSkipReason) {
    const buildUiTests = require('./ui-wiring.js');
    buildUiTests({ read, moduleDir, fixtures, renderRgba }).forEach((t) => tests.push(t));
}

// ---------- runner ----------

let failed = 0;
for (const t of tests) {
    try {
        t.fn();
        console.log(`[PASS] ${t.name}`);
    } catch (err) {
        failed++;
        console.log(`[FAIL] ${t.name}\n       ${String(err && err.message || err).split('\n').join('\n       ')}`);
    }
}
if (uiSkipReason) {
    console.log(`[SKIP] UI wiring tests: ${uiSkipReason}`);
}
console.log(`\nTools Center tests: ${tests.length - failed}/${tests.length} passed, ${failed} failed` +
    (uiSkipReason ? ' (UI wiring tests skipped)' : ''));
process.exit(failed === 0 ? 0 : 1);
