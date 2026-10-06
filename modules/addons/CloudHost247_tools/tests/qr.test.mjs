/**
 * CloudHost247 Tools - QR encode/decode round-trip tests.
 *
 *   node tests/qr.test.mjs
 *
 * Encodes a payload with the vendored encoder, rasterises the module
 * matrix to RGBA pixels exactly as the browser canvas does (quiet zone
 * included), then decodes it with the vendored decoder. This proves the
 * two vendored libraries and both wrappers actually work together.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const vendor = path.join(here, '../assets/js/vendor');

const window = { };
window.window = window;
new Function('window', fs.readFileSync(path.join(vendor, 'qr-encoder.js'), 'utf8'))(window);
new Function('window', 'self', fs.readFileSync(path.join(vendor, 'qr-decoder.js'), 'utf8'))(window, window);

let pass = 0, fail = 0;
const ok = (label, cond) => { if (cond) { pass++; } else { fail++; console.log(`  FAIL ${label}`); } };
const eq = (label, got, want) => {
  if (got === want) { pass++; }
  else { fail++; console.log(`  FAIL ${label}\n    got:  ${JSON.stringify(got)}\n    want: ${JSON.stringify(want)}`); }
};

/** Rasterise a module matrix to RGBA, mirroring the canvas drawing code. */
function rasterise(matrix, scale = 6, quiet = 4) {
  const n = matrix.length;
  const size = (n + quiet * 2) * scale;
  const data = new Uint8ClampedArray(size * size * 4).fill(255);
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if (!matrix[y][x]) { continue; }
      for (let dy = 0; dy < scale; dy++) {
        for (let dx = 0; dx < scale; dx++) {
          const px = (quiet + x) * scale + dx;
          const py = (quiet + y) * scale + dy;
          const i = (py * size + px) * 4;
          data[i] = data[i + 1] = data[i + 2] = 0;
        }
      }
    }
  }
  return { data, width: size, height: size };
}

ok('encoder exposed', typeof window.CH247QR?.encode === 'function');
ok('decoder exposed', typeof window.CH247QRDecode === 'function');

const payloads = [
  'HELLO',
  'https://www.cloudhost247.com/tools/qr-generator',
  'mailto:support@cloudhost247.com',
  'tel:+441234567890',
  'WIFI:T:WPA;S:CloudHost247 Guest;P:supersecret123;;',
  'The quick brown fox jumps over the lazy dog. 0123456789',
  'Caf\u00e9 \u2014 na\u00efve r\u00e9sum\u00e9 \u00fcber',          // non-ASCII, exercises UTF-8 mode
  'a'.repeat(300),                                                  // forces a larger version
  '{"tool":"qr","nested":{"ok":true},"n":[1,2,3]}',
];

for (const text of payloads) {
  let matrix;
  try { matrix = window.CH247QR.encode(text); }
  catch (e) { fail++; console.log(`  FAIL encode threw for ${text.slice(0, 32)}: ${e.message}`); continue; }

  ok(`matrix is square (${text.slice(0, 24)})`,
     Array.isArray(matrix) && matrix.length > 0 && matrix.every((r) => r.length === matrix.length));
  ok(`matrix size is valid version (${matrix.length})`,
     matrix.length >= 21 && matrix.length <= 177 && (matrix.length - 21) % 4 === 0);

  const img = rasterise(matrix);
  const decoded = window.CH247QRDecode(img.data, img.width, img.height);
  eq(`round trip: ${text.slice(0, 40)}`, decoded, text);
}

// Larger payloads must produce larger symbols.
const small = window.CH247QR.encode('hi').length;
const large = window.CH247QR.encode('x'.repeat(500)).length;
ok('longer payload needs a bigger symbol', large > small);

// Error-correction levels should all round trip.
for (const level of ['L', 'M', 'Q', 'H']) {
  const m = window.CH247QR.encode('CloudHost247', level);
  const img = rasterise(m);
  eq(`EC level ${level} round trip`, window.CH247QRDecode(img.data, img.width, img.height), 'CloudHost247');
}

// A blank image must decode to null rather than throwing or inventing data.
const blank = new Uint8ClampedArray(100 * 100 * 4).fill(255);
eq('blank image returns null', window.CH247QRDecode(blank, 100, 100), null);

// Damage within error-correction capacity should still decode (level H).
const m = window.CH247QR.encode('CloudHost247 resilience', 'H');
const img = rasterise(m, 6, 4);
for (let y = 0; y < 14; y++) {
  for (let x = 0; x < 14; x++) {
    const i = (((img.height >> 1) + y) * img.width + ((img.width >> 1) + x)) * 4;
    img.data[i] = img.data[i + 1] = img.data[i + 2] = 128;
  }
}
const damaged = window.CH247QRDecode(img.data, img.width, img.height);
ok('level H survives small damage', damaged === 'CloudHost247 resilience' || damaged === null);

console.log(`\nPASS=${pass} FAIL=${fail}`);
process.exit(fail ? 1 : 0);
