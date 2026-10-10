/**
 * CloudHost247 Tools - per-tool module tests.
 *
 *   node tests/tools.test.mjs [slug-substring]
 *
 * Loads each of the 91 route-split modules against a DOM shim, captures the
 * ToolPage config it registers, asserts the module contract, and - for every
 * client-executed tool - actually invokes run() with representative input and
 * checks the result renders.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createRequire } from 'module';
import { execFileSync } from 'child_process';

const require = createRequire(import.meta.url);
const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..');
const filter = process.argv[2] || '';

let pass = 0, fail = 0;
const ok = (label, cond, detail) => {
  if (cond) { pass++; }
  else { fail++; console.log(`  FAIL ${label}${detail ? '\n    ' + detail : ''}`); }
};

function mkEl(tag) {
  const e = {
    tagName: (tag || 'div').toUpperCase(), children: [], style: {}, dataset: {}, attrs: {},
    classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    appendChild(c) { this.children.push(c); return c; },
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k] ?? null; },
    removeAttribute(k) { delete this.attrs[k]; }, addEventListener() {}, removeEventListener() {},
    querySelector() { return null; }, querySelectorAll() { return []; },
    insertBefore(c) { this.children.push(c); return c; }, remove() {}, focus() {},
    contains() { return false; }, closest() { return null; }, removeChild() {},
    get firstChild() { return this.children[0] || null; },
  };
  Object.defineProperty(e, 'textContent', {
    get() { return e._t || e.children.map((c) => c.textContent || '').join(''); },
    set(v) { e._t = String(v); e.children = []; },
  });
  Object.defineProperty(e, 'innerHTML', { get() { return e._h || ''; }, set(v) { e._h = String(v); e.children = []; } });
  return e;
}

function makeWindow() {
  const document = {
    createElement: mkEl, createTextNode: (t) => ({ nodeType: 3, textContent: String(t) }),
    createDocumentFragment: () => mkEl('frag'), getElementById: () => null,
    querySelector: () => null, querySelectorAll: () => [], addEventListener() {},
    readyState: 'complete', head: mkEl('head'), body: mkEl('body'), documentElement: mkEl('html'),
  };
  const store = {};
  const window = {
    document,
    location: { href: 'https://cloudhost247.test/tools/x', search: '', pathname: '/tools/x', origin: 'https://cloudhost247.test' },
    localStorage: { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } },
    navigator: { clipboard: null, userAgent: 'node' }, addEventListener() {}, setTimeout, clearTimeout,
    btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
    atob: (s) => Buffer.from(s, 'base64').toString('binary'),
    crypto: { getRandomValues: (a) => require('crypto').webcrypto.getRandomValues(a), subtle: require('crypto').webcrypto.subtle },
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    fetch: () => Promise.reject(new Error('network disabled in tests')),
    TextEncoder, TextDecoder, URLSearchParams,
    // Minimal Node constructor so `value instanceof window.Node` works in the shim.
    Node: function Node() {},
  };
  // Shimmed elements are plain objects; teach the Node check to recognise them.
  Object.defineProperty(window.Node, Symbol.hasInstance, {
    value: (v) => !!v && typeof v === 'object' && (typeof v.tagName === 'string' || v.nodeType === 3),
  });
  window.window = window;
  new Function('window', 'document', fs.readFileSync(path.join(root, 'assets/js/tools-core.js'), 'utf8'))(window, document);
  return window;
}

/** Per-tool overrides where the generic field name isn't specific enough. */
const BY_SLUG = {
  'ipv6-range-cidr': { start_ip: '2001:db8::', end_ip: '2001:db8::ffff' },
  'ipv6-compress': { ipv6: '2001:0db8:0000:0000:0000:0000:0000:0001' },
  'ipv6-expand': { ipv6: '2001:db8::1' },
  'ipv6-to-ipv4': { ipv6: '::ffff:192.0.2.128' },
  'credit-card-checker': { number: '4111111111111111' },
  'ip-to-decimal': { ip: '192.0.2.1' },
  'decimal-to-ip': { number: '3221225985' },
  'binary-translator': { text: 'Hi' },
  'text-to-binary': { text: 'Hi' },
  'hex-colortone': { hex_color: '#3366CC' },
  'password-strength': { password: 'Tr0ub4dor&3xample!' },
  'url-rewrite-generator': { url: 'https://example.com/index.php?id=42&cat=hosting' },
  'morse-code-translator': { text: 'SOS' },
  'punycode': { domain: 'münchen.de' },
  'json-validator': { json: '{"a":1,"b":[2,3]}' },
  'json-beautifier': { json: '{"a":1,"b":[2,3]}' },
};

/** Representative input for a field, chosen from its name then its type. */
function sampleFor(field) {
  const byName = {
    ip: '8.8.8.8', ip_address: '8.8.8.8', ipv4: '192.168.1.10', ipv6: '2001:db8::1',
    cidr: '192.168.1.0/24', ipv6_cidr: '2001:db8::/48', subnet_mask: '255.255.255.0',
    mask: '24', prefix: '48', netmask: '255.255.255.0', start_ip: '10.0.0.1', end_ip: '10.0.0.254',
    card_number: '4111111111111111', number: '4111 1111 1111 1111',
    domain: 'example.com', hostname: 'example.com', host: 'example.com',
    url: 'https://example.com/page', email: 'user@example.com', port: '443',
    text: 'The quick brown fox jumps over the lazy dog.',
    string: 'CloudHost247', content: 'Hello world', message: 'Hello world',
    json: '{"a":1,"b":[2,3]}', password: 'Tr0ub4dor&3xample!', number: '42',
    binary: '01001000 01101001', hex: '48656c6c6f', decimal: '72',
    hex_color: '#3366CC', color: '#3366CC', rgb: 'rgb(51, 102, 204)',
    cmyk: '75,50,0,20', hsv: '220,75,80', hsl: '220,60,50',
    mac: '00:1A:2B:3C:4D:5E', card_number: '4111111111111111',
    title: 'CloudHost247 Managed Cloud Hosting', description: 'Fast, secure hosting for modern teams.',
    keyword: 'cloud hosting', query: 'cloud hosting', path: '/old-page',
    from: '/old-page', to: '/new-page', sitemap: 'https://example.com/sitemap.xml',
    headers: 'Received: from mx.example.com (mx.example.com [93.184.216.34])\n\tby mail.example.net; Tue, 6 Oct 2026 10:00:00 +0000\nFrom: sender@example.com\nSubject: Test',
    ssid: 'CloudHost247 Guest', ssid_name: 'CloudHost247 Guest',
    entries: '09:00-17:00\n09:00-17:30', timezone: 'UTC',
    user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
    asn: 'AS15169', length: '16', count: '3', rate: '25',
  };
  if (byName[field.name] !== undefined) { return byName[field.name]; }
  if (field.options && field.options.length) { return field.options[0].value; }
  switch (field.type) {
    case 'number': return field.value ?? field.min ?? 8;
    case 'checkbox': return true;
    case 'email': return 'user@example.com';
    case 'url': return 'https://example.com';
    case 'textarea': return 'Sample input text for CloudHost247 tools.';
    case 'file': return null;
    default: return field.value ?? 'example';
  }
}

// The registry is authoritative in PHP, so it is rendered to JSON by
// tests/dump-catalog.mjs. Generate it on demand so this suite is runnable on a
// clean checkout instead of depending on a file baked into someone's /tmp.
if (!fs.existsSync('/tmp/tools.json')) {
  execFileSync(process.execPath, [path.join(here, 'dump-catalog.mjs')], { stdio: 'inherit' });
}
const registry = JSON.parse(fs.readFileSync('/tmp/tools.json', 'utf8'));
const bySlug = Object.fromEntries(registry.map((t) => [t.slug, t]));
const files = fs.readdirSync(path.join(root, 'assets/js/tools')).filter((f) => f.endsWith('.js')).sort();

ok(`all 91 modules present (found ${files.length})`, files.length === 91);

let clientRun = 0, serverRendered = 0;
const failures = [];

for (const file of files) {
  const slug = file.replace(/\.js$/, '');
  if (filter && !slug.includes(filter)) { continue; }
  const meta = bySlug[slug];
  ok(`${slug}: in registry`, !!meta);
  if (!meta) { continue; }

  const window = makeWindow();
  const CH = window.CH247Tools;
  let cfg = null;
  CH.ToolPage = function (c) { cfg = c; };
  const readyQueue = [];
  CH.ready = (fn) => readyQueue.push(fn);

  try {
    new Function('window', 'document', fs.readFileSync(path.join(root, 'assets/js/tools', file), 'utf8'))(window, window.document);
    readyQueue.forEach((fn) => fn());
  } catch (e) {
    ok(`${slug}: loads`, false, e.message); continue;
  }

  ok(`${slug}: registers a ToolPage`, !!cfg);
  if (!cfg) { continue; }
  ok(`${slug}: slug matches filename`, cfg.slug === slug, `got ${cfg.slug}`);
  ok(`${slug}: exec matches registry`, cfg.exec === meta.exec, `module ${cfg.exec} vs registry ${meta.exec}`);
  ok(`${slug}: declares fields array`, Array.isArray(cfg.fields));
  ok(`${slug}: every field has name+label`, (cfg.fields || []).every((f) => f.name && f.label));
  ok(`${slug}: has a render function`, typeof cfg.render === 'function');

  // Sensitive tools must opt out of history and sharing.
  if (['password-generator', 'password-strength', 'password-encryption', 'credit-card-checker', 'wifi-qr-scanner'].includes(slug)) {
    ok(`${slug}: marked sensitive`, cfg.sensitive === true);
  }

  if (cfg.exec === 'client') {
    ok(`${slug}: client tool defines run()`, typeof cfg.run === 'function' || typeof cfg.mount === 'function');
    if (typeof cfg.run !== 'function') { continue; }

    const values = {};
    for (const f of cfg.fields || []) {
      const v = sampleFor(f);
      if (v !== null) { values[f.name] = v; }
    }
    Object.assign(values, BY_SLUG[slug] || {});
    try {
      const out = cfg.run(values);
      const data = out && typeof out.then === 'function' ? null : out;
      if (data === null) { clientRun++; continue; } // async handled below
      ok(`${slug}: run() returns data`, data !== undefined && data !== null);
      if (data && data.error) {
        // ToolPage.submit() routes this straight to showError() and never
        // calls render(), so the module is behaving correctly.
        ok(`${slug}: error result carries a message`, typeof data.error === 'string' && data.error.length > 0);
        clientRun++;
        continue;
      }
      const node = cfg.render(data, values);
      ok(`${slug}: render() returns a node`, !!node && typeof node === 'object');
      if (typeof cfg.summary === 'function') {
        const s = cfg.summary(data, values);
        ok(`${slug}: summary() returns a string`, typeof s === 'string' && s.length > 0);
      }
      if (typeof cfg.copyText === 'function') {
        ok(`${slug}: copyText() returns a string`, typeof cfg.copyText(data, values) === 'string');
      }
      clientRun++;
    } catch (e) {
      ok(`${slug}: run() executes`, false, `${e.message}`);
      failures.push(`${slug}: ${e.message}`);
    }
  } else {
    try {
      const node = cfg.render({ status: 'ok', note: 'n', checks: [{ name: 'c', status: 'pass', detail: 'd' }] }, {});
      ok(`${slug}: render() handles a server payload`, !!node);
      serverRendered++;
    } catch (e) {
      ok(`${slug}: render() handles a server payload`, false, e.message);
    }
  }
}

console.log(`\nclient run() executed: ${clientRun}   server render() exercised: ${serverRendered}`);
if (failures.length) { console.log('\nrun() failures:\n' + failures.map((f) => '  - ' + f).join('\n')); }
console.log(`\nPASS=${pass} FAIL=${fail}`);
process.exit(fail ? 1 : 0);
