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

test('API credential stays server-side and is sent only in the auth header', () => {
    const src = read('clientarea.php');
    const hooks = read('hooks.php');
    assert.equal(/assign\(\s*'apiToken'/.test(src), false, 'apiToken must not be assigned to the template');
    assert.ok(hooks.includes("'X-API-Token: ' . $token"), 'the WHMCS server must authenticate with the API-token header');
    assert.equal(/'api_token'\s*=>\s*\$token/.test(hooks), false, 'the shared secret must not be duplicated into the JSON body');
});

test('outbound API call does not follow redirects (the token is never forwarded) and is HTTPS only', () => {
    const src = read('hooks.php');
    assert.equal(/CURLOPT_FOLLOWLOCATION\s*=>\s*true/.test(src), false, 'FOLLOWLOCATION must not be true');
    assert.ok(/CURLOPT_FOLLOWLOCATION\s*=>\s*false/.test(src), 'FOLLOWLOCATION must be false');
    assert.ok(/CURLOPT_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS/.test(src), 'CURLOPT_PROTOCOLS must be HTTPS only');
    assert.ok(/CURLOPT_REDIR_PROTOCOLS\s*=>\s*CURLPROTO_HTTPS/.test(src), 'CURLOPT_REDIR_PROTOCOLS must be HTTPS only');
});

test('all 81 registered category actions map to public API methods', () => {
    const client = read('clientarea.php');
    const registryStart = client.indexOf('$toolCategories = [');
    const registryEnd = client.indexOf('\n];', registryStart);
    assert.ok(registryStart >= 0 && registryEnd > registryStart, 'tool registry array not found');
    const registry = client.slice(registryStart, registryEnd);
    const categories = [...registry.matchAll(/^    '([a-z][a-z0-9_]*)' => \[/gm)];
    assert.equal(categories.length, 10, 'all registered categories must be included');

    const actionsSeen = new Set();
    let actionCount = 0;
    for (let i = 0; i < categories.length; i++) {
        const category = categories[i][1];
        const blockStart = categories[i].index;
        const blockEnd = i + 1 < categories.length ? categories[i + 1].index : registry.length;
        const block = registry.slice(blockStart, blockEnd);
        const actions = [...block.matchAll(/\['action'\s*=>\s*'([A-Za-z][A-Za-z0-9]*)'\s*,\s*'name'\s*=>\s*'([^']+)'/g)];
        const api = read(`external-api/tools/${category}.php`);

        for (const match of actions) {
            const action = match[1];
            assert.equal(actionsSeen.has(action), false, `duplicate action key: ${action}`);
            actionsSeen.add(action);
            actionCount++;
            assert.ok(
                new RegExp(`public\\s+function\\s+${action}\\s*\\(`).test(api),
                `${category}/${action} has no corresponding public method in external-api/tools/${category}.php`
            );
        }
    }
    assert.equal(actionCount, 81, 'the registry/action crosswalk must cover all 81 actions');
});

test('IP, SSL, WHOIS and DNS propagation paths enforce outbound controls', () => {
    const ip = read('external-api/tools/ip.php');
    const network = read('external-api/tools/network.php');
    const security = read('external-api/tools/security.php');
    const dns = read('external-api/tools/dns.php');
    const outbound = read('external-api/outbound.php');
    assert.ok(/resolvePublicCommandTarget[\s\S]*?tc_resolve_public_ips\(\$host\)/.test(ip), 'ping and traceroute must resolve through the public-address guard');
    assert.ok(/escapeshellarg\(\$commandTarget\)/.test(ip), 'shell commands must use the pinned numeric address');
    assert.equal(/fsockopen\s*\(/.test(ip + network), false, 'WHOIS must not bypass the guarded socket helper');
    assert.ok(/tc_open_public_socket\(\$server, 43, 10/.test(ip) && /tc_open_public_socket\(\$server, 43, 10/.test(network), 'both WHOIS paths must use the guarded socket helper');
    assert.equal(/curl_init\s*\(/.test(ip), false, 'IP geolocation must not use an unguarded cURL request');
    assert.ok(/fetchGeoResponse[\s\S]*?tc_fetch_url\(\$url, \$options\)/.test(ip), 'geolocation must use the guarded fetch helper');
    assert.ok(/openPublicTlsSocket[\s\S]*?tc_open_public_tls_socket\(/.test(security), 'the SSL checker must use the guarded TLS socket helper');
    assert.equal(/stream_socket_client\s*\(/.test(security), false, 'SSL checker code must not open an unguarded TLS socket');
    assert.ok(/function tc_open_public_tls_socket[\s\S]*?tc_resolve_public_ips\(\$host\)[\s\S]*?stream_socket_client\(/.test(outbound), 'TLS sockets must pin the validated numeric address');
    assert.ok(security.includes("'grade' => 'Not assessed'") && !security.includes("'security_score' =>"), 'SSL checker must not invent a security grade or score');
    assert.ok(dns.includes("$validTypes = ['A', 'AAAA', 'MX', 'NS', 'TXT']") && dns.includes('dig +short +time=2 +tries=1'), 'DNS propagation must constrain query types and bound dig retries');
    assert.ok(/max\(1, min\(\(int\)\(\$params\['count'\]/.test(ip) && /max\(1, min\(\(int\)\(\$params\['max_hops'\]/.test(ip), 'count and hop controls must have positive lower bounds');
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
        /error_log\([\s\S]*?\$detail/.test(src) && /\$detail\s*=\s*get_class\(\$e\)\s*\.\s*': '\s*\.\s*\$e->getMessage\(\)/.test(src),
        'sanitized exception detail must still be written to the server-side error log'
    );
    assert.ok(
        /'Tool execution failed\. The error has been logged\.'/.test(src),
        'the client must receive a generic message'
    );
});

test('external API CORS is exact-origin allowlisted and methods are POST-only', () => {
    const api = read('external-api/api.php');
    assert.equal(api.includes("header('Access-Control-Allow-Origin: *')"), false, 'wildcard CORS must not be present');
    assert.ok(api.includes('in_array($origin, $allowedOrigins, true)'), 'Origin must match the configured allowlist exactly');
    assert.ok(api.includes("header('Access-Control-Allow-Methods: POST, OPTIONS')"), 'only POST and preflight are allowed');
    assert.ok(api.includes("if ($method !== 'POST')"), 'non-POST calls must be rejected');
    assert.equal(api.includes('Access-Control-Allow-Methods: POST, GET, OPTIONS'), false, 'GET must not be enabled');
});

test('external API ignores query/cookie credentials and shipped API secrets fail closed', () => {
    const api = read('external-api/api.php');
    const auth = read('external-api/auth.php');
    const config = read('external-api/config.php');
    assert.equal((api + auth).includes('$_REQUEST[') || (api + auth).includes('$_GET[') || (api + auth).includes('$_COOKIE['), false, 'auth and tool routing must not use merged request/query/cookie inputs');
    assert.ok(api.includes('$auth->validateRequest($input)'), 'only the parsed POST body should be passed to auth');
    assert.ok(config.includes("getenv('TOOLS_API_TOKEN') ?: ''"), 'unset static API token must be empty');
    assert.ok(config.includes("getenv('TOOLS_API_SECRET') ?: ''"), 'unset HMAC secret must be empty');
    assert.ok(auth.includes('consumeNonce') && auth.includes('timestamp'), 'signed credentials must have freshness and replay controls');
});

test('Tools Center AJAX verifies a session CSRF token before forwarding requests', () => {
    const client = read('clientarea.php');
    const hooks = read('hooks.php');
    const csrf = read('csrf.php');
    assert.ok(client.includes('tools_center_verify_csrf_token($csrfToken)'), 'AJAX must validate the supplied token');
    assert.ok(client.indexOf('tools_center_verify_csrf_token($csrfToken)') < client.indexOf('tools_center_api_request($ajaxCategory'), 'CSRF validation must precede outbound API work');
    assert.ok(hooks.includes('csrfToken'), 'the same-origin page must receive a session CSRF token');
    assert.ok(csrf.includes('hash_equals'), 'CSRF token comparison must be constant-time');
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
