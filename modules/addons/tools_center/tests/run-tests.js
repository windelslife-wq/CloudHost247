#!/usr/bin/env node
/**
 * Tools Center tests: hardening checks on the shipped code.
 *
 * Run:  node modules/addons/tools_center/tests/run-tests.js
 * Needs only Node.js (no npm install). Exit code 0 when every test passes.
 *
 * Static checks read the PHP/template/JS sources. The QR Code Generator and
 * QR Scanner were removed (F-3), so their tests and vendored libraries went
 * with them.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const moduleDir = path.resolve(__dirname, '..');
const read = (rel) => fs.readFileSync(path.join(moduleDir, rel), 'utf8');

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

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

test('the API error banner escapes data.error before writing it to innerHTML', () => {
    const js = read('js/tools-center.js');
    const fn = /function displayResults[\s\S]*?\n    \}\n/.exec(js);
    assert.ok(fn, 'displayResults not found');

    // The failure branch writes an alert containing data.error. The API reflects
    // user-controlled input into that string ("Tool category not found: <x>"),
    // so it must go through escapeHtml.
    const branch = /if\s*\(!data\.success\)\s*\{[\s\S]*?return;/.exec(fn[0]);
    assert.ok(branch, 'the !data.success branch was not found');
    assert.ok(
        /escapeHtml\(\s*data\.error/.test(branch[0]),
        'data.error must be escaped: it carries API-reflected user input'
    );
    assert.ok(
        !/innerHTML\s*=\s*['"][^'"]*'\s*\+\s*\(\s*data\.error/.test(branch[0]),
        'raw data.error must not be concatenated into innerHTML'
    );
});

test('the API does not echo internal exception messages to the caller', () => {
    const src = read('external-api/api.php');
    assert.ok(
        !/'Tool execution failed: '\s*\.\s*\$e->getMessage\(\)/.test(src),
        'exception text must not be returned to the client'
    );
    assert.ok(
        /error_log\([\s\S]*?\$e->getMessage\(\)/.test(src),
        'the detail must still be written to the server-side error log'
    );
    assert.ok(
        /'Tool execution failed\. The error has been logged\.'/.test(src),
        'the client must receive a generic message'
    );
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
    buildUiTests({ read, moduleDir }).forEach((t) => tests.push(t));
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
