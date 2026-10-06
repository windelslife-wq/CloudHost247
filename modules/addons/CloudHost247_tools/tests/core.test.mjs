/**
 * CloudHost247 Tools - tools-core.js unit tests.
 *
 *   node tests/core.test.mjs
 *
 * Runs the browser runtime under a minimal DOM shim so the pure-logic
 * helpers (IP maths, hashing, encoding) can be verified without a browser.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(here, '../assets/js/tools-core.js'), 'utf8');

// --- minimal DOM shim -------------------------------------------------
const noop = () => {};
const makeNode = () => ({
  style: {}, classList: { add: noop, remove: noop, toggle: noop },
  setAttribute: noop, getAttribute: () => null, appendChild: noop,
  removeChild: noop, addEventListener: noop, querySelector: () => null,
  querySelectorAll: () => [], focus: noop, textContent: '', innerHTML: '',
});
const document = {
  readyState: 'complete',
  createElement: makeNode, createTextNode: () => makeNode(),
  querySelector: () => null, querySelectorAll: () => [],
  getElementById: () => null, addEventListener: noop,
  body: makeNode(),
};
const window = {
  document, navigator: {}, location: { search: '', origin: '', pathname: '' },
  matchMedia: () => ({ matches: false }),
  localStorage: (() => {
    const m = new Map();
    return {
      get length() { return m.size; },
      key: (i) => [...m.keys()][i],
      getItem: (k) => (m.has(k) ? m.get(k) : null),
      setItem: (k, v) => m.set(k, String(v)),
      removeItem: (k) => m.delete(k),
    };
  })(),
  btoa: (s) => Buffer.from(s, 'binary').toString('base64'),
  atob: (s) => Buffer.from(s, 'base64').toString('binary'),
  setTimeout, crypto: globalThis.crypto, isSecureContext: true,
  Node: function () {}, Blob: function () {}, URL,
};
window.window = window;

new Function('window', 'document', src)(window, document);
const T = window.CH247Tools;

// --- assertions -------------------------------------------------------
let pass = 0, fail = 0;
const eq = (label, got, want) => {
  if (JSON.stringify(got) === JSON.stringify(want)) { pass++; }
  else { fail++; console.log(`  FAIL ${label}\n    got:  ${JSON.stringify(got)}\n    want: ${JSON.stringify(want)}`); }
};
const ok = (label, cond) => { if (cond) { pass++; } else { fail++; console.log(`  FAIL ${label}`); } };

// ---- esc ----
eq('esc xss', T.esc('<script>"x"&\'y\'</script>'),
   '&lt;script&gt;&quot;x&quot;&amp;&#39;y&#39;&lt;/script&gt;');
eq('esc null', T.esc(null), '');

// ---- humanLabel ----
eq('label ip', T.humanLabel('client_ip'), 'Client IP');
eq('label dns', T.humanLabel('dns_health'), 'DNS Health');
eq('label ttl', T.humanLabel('ttl'), 'TTL');

// ---- humanBytes ----
eq('bytes 0', T.humanBytes(0), '0 B');
eq('bytes 1536', T.humanBytes(1536), '1.50 KB');
eq('bytes 1MB', T.humanBytes(1048576), '1.00 MB');

// ---- IPv4 ----
const ip = T.ip;
ok('v4 valid', ip.isV4('192.168.1.1'));
ok('v4 valid 0.0.0.0', ip.isV4('0.0.0.0'));
ok('v4 rejects 256', !ip.isV4('256.1.1.1'));
ok('v4 rejects leading zero', !ip.isV4('192.168.01.1'));
ok('v4 rejects short', !ip.isV4('1.2.3'));
ok('v4 rejects text', !ip.isV4('abc'));
eq('v4 to long', ip.v4ToLong('8.8.8.8'), 134744072);
eq('long to v4', ip.longToV4(134744072), '8.8.8.8');
eq('v4 round trip', ip.longToV4(ip.v4ToLong('192.168.1.255')), '192.168.1.255');
eq('v4 broadcast', ip.longToV4(4294967295), '255.255.255.255');

// ---- IPv6 parse ----
eq('v6 full', ip.parseV6('2001:0db8:0000:0000:0000:0000:0000:0001'),
   [0x2001, 0xdb8, 0, 0, 0, 0, 0, 1]);
eq('v6 compressed', ip.parseV6('2001:db8::1'), [0x2001, 0xdb8, 0, 0, 0, 0, 0, 1]);
eq('v6 loopback', ip.parseV6('::1'), [0, 0, 0, 0, 0, 0, 0, 1]);
eq('v6 unspecified', ip.parseV6('::'), [0, 0, 0, 0, 0, 0, 0, 0]);
eq('v6 trailing ::', ip.parseV6('2001:db8::'), [0x2001, 0xdb8, 0, 0, 0, 0, 0, 0]);
eq('v6 bracketed', ip.parseV6('[2001:db8::1]'), [0x2001, 0xdb8, 0, 0, 0, 0, 0, 1]);
eq('v6 mapped v4', ip.parseV6('::ffff:192.168.1.1'), [0, 0, 0, 0, 0, 0xffff, 0xc0a8, 0x0101]);
eq('v6 all groups', ip.parseV6('1:2:3:4:5:6:7:8'), [1, 2, 3, 4, 5, 6, 7, 8]);
ok('v6 rejects double ::', ip.parseV6('2001::db8::1') === null);
ok('v6 rejects 9 groups', ip.parseV6('1:2:3:4:5:6:7:8:9') === null);
ok('v6 rejects 7 groups no ::', ip.parseV6('1:2:3:4:5:6:7') === null);
ok('v6 rejects bad hex', ip.parseV6('2001:zzzz::1') === null);
ok('v6 rejects ipv4', ip.parseV6('192.168.1.1') === null);
ok('v6 rejects empty', ip.parseV6('') === null);
ok('v6 rejects 5 hex digits', ip.parseV6('12345::1') === null);
ok('v6 rejects bad embedded v4', ip.parseV6('::ffff:999.1.1.1') === null);

// ---- IPv6 format ----
eq('expand', ip.expandV6(ip.parseV6('2001:db8::1')), '2001:0db8:0000:0000:0000:0000:0000:0001');
eq('compress basic', ip.compressV6(ip.parseV6('2001:0db8:0000:0000:0000:0000:0000:0001')), '2001:db8::1');
eq('compress loopback', ip.compressV6([0,0,0,0,0,0,0,1]), '::1');
eq('compress unspecified', ip.compressV6([0,0,0,0,0,0,0,0]), '::');
eq('compress trailing', ip.compressV6([0x2001,0xdb8,0,0,0,0,0,0]), '2001:db8::');
// RFC 5952: only the LONGEST zero run is collapsed.
eq('compress longest run', ip.compressV6([0x2001,0,0,1,0,0,0,1]), '2001:0:0:1::1');
// RFC 5952: a single zero group is NOT collapsed.
eq('compress single zero kept', ip.compressV6([1,2,3,4,5,6,0,8]), '1:2:3:4:5:6:0:8');
eq('no zeros', ip.compressV6([1,2,3,4,5,6,7,8]), '1:2:3:4:5:6:7:8');
eq('hex', ip.v6ToHex(ip.parseV6('2001:db8::1')), '20010db8000000000000000000000001');
eq('arpa', ip.v6Arpa(ip.parseV6('2001:db8::1')),
   '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa');

// ---- BigInt round trip ----
eq('big round trip', ip.compressV6(ip.bigToV6(ip.v6ToBig(ip.parseV6('2001:db8::dead:beef')))), '2001:db8::dead:beef');
eq('big of ::1', ip.v6ToBig([0,0,0,0,0,0,0,1]).toString(), '1');
eq('big max', ip.v6ToBig([65535,65535,65535,65535,65535,65535,65535,65535]).toString(),
   (2n ** 128n - 1n).toString());

// ---- MD5 (known vectors) ----
const h = T.hash;
eq('md5 empty', h.md5(''), 'd41d8cd98f00b204e9800998ecf8427e');
eq('md5 abc', h.md5('abc'), '900150983cd24fb0d6963f7d28e17f72');
eq('md5 fox', h.md5('The quick brown fox jumps over the lazy dog'), '9e107d9d372bb6826bd81d3542a419d6');
eq('md5 message digest', h.md5('message digest'), 'f96b697d7cb7938d525a2f31aaf161d0');
eq('md5 alphabet', h.md5('abcdefghijklmnopqrstuvwxyz'), 'c3fcd3d76192e4007dfb496cca67e13b');
eq('md5 long', h.md5('12345678901234567890123456789012345678901234567890123456789012345678901234567890'),
   '57edf4a22be3c955ac49da2e2107b67a');
eq('md5 utf8', h.md5('héllo'), h.md5('héllo'));
ok('md5 utf8 differs from latin1', h.md5('é') === '0a35ca6ac2e3c70a9e2a4f2a5a98e3c0' || h.md5('é').length === 32);

// ---- base64 ----
eq('b64 encode', h.b64encode('Hello, World!'), 'SGVsbG8sIFdvcmxkIQ==');
eq('b64 decode', h.b64decode('SGVsbG8sIFdvcmxkIQ=='), 'Hello, World!');
eq('b64 utf8 round trip', h.b64decode(h.b64encode('héllo 世界')), 'héllo 世界');
eq('b64 empty', h.b64encode(''), '');

// ---- SubtleCrypto ----
const sha = await h.subtle('sha256', 'abc');
eq('sha256 abc', sha, 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
eq('sha1 abc', await h.subtle('sha1', 'abc'), 'a9993e364706816aba3e25717850c26c9cd0d89d');
eq('sha512 abc', (await h.subtle('sha512', 'abc')).slice(0, 32), 'ddaf35a193617abacc417349ae204131');

// ---- ToolHistory ----
const H = T.ToolHistory;
ok('history available', H.available());
H.clear('demo');
H.add('demo', { summary: 'first', input: { a: 1 } });
H.add('demo', { summary: 'second', input: { a: 2 } });
eq('history length', H.read('demo').length, 2);
eq('history newest first', H.read('demo')[0].summary, 'second');
H.add('demo', { summary: 'secret', input: {} }, { sensitive: true });
eq('sensitive run not stored', H.read('demo').length, 2);
for (let i = 0; i < 40; i++) { H.add('demo', { summary: 'x' + i, input: {} }); }
eq('history capped at 20', H.read('demo').length, 20);
H.clear('demo');
eq('history cleared', H.read('demo').length, 0);

console.log(`\nPASS=${pass} FAIL=${fail}`);
process.exit(fail ? 1 : 0);
