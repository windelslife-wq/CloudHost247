/**
 * CloudHost247 App Cloud — test runner.
 *
 * Executes every *Test.php here against a real PHP 8.3 runtime (php-wasm), so
 * the suite runs in environments without a native PHP binary. With a native
 * binary each suite is a plain script (`php tests/01_CoreTest.php`).
 *
 *   node tests/run.mjs             # run everything
 *   node tests/run.mjs Deployment  # only files whose name matches
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
const cloudflareRoot = path.resolve(moduleRoot, '../cloudhost247cloudflare');
const soyoustartRoot = path.resolve(moduleRoot, '../soyoustart');
const filter = process.argv[2] || '';

const files = fs
  .readdirSync(here)
  .filter((f) => f.endsWith('Test.php'))
  .filter((f) => !filter || f.toLowerCase().includes(filter.toLowerCase()))
  .sort();

if (!files.length) {
  console.error('No test files matched.');
  process.exit(1);
}

const alloc = new ProcessIdAllocator();
let totalPass = 0;
let totalFail = 0;
const broken = [];

for (const file of files) {
  const id = await loadNodeRuntime('8.3', {
    withIntl: true,
    emscriptenOptions: { processId: alloc.claim() },
  });
  const php = new PHP(id);
  try {
    php.mkdir('/tmp');
  } catch (e) {
    /* already present */
  }
  await php.mount('/app', createNodeFsMountHandler(moduleRoot));
  await php.mount('/cloudflare', createNodeFsMountHandler(cloudflareRoot));
  await php.mount('/soyoustart', createNodeFsMountHandler(soyoustartRoot));

  // php.run() rejects when the script exits non-zero; the response is still
  // attached to the error, and a failing suite is exactly that case.
  let res;
  try {
    res = await php.run({ scriptPath: '/app/tests/' + file });
  } catch (e) {
    res = e && e.response ? e.response : { text: '', errors: String(e) };
  }
  const out = res.text || '';
  console.log(`\n=== ${file} ===`);
  process.stdout.write(out);
  if (res.errors && res.errors.trim()) {
    process.stderr.write(res.errors);
  }

  const m = out.match(/PASS=(\d+)\s+FAIL=(\d+)/);
  if (m) {
    totalPass += Number(m[1]);
    totalFail += Number(m[2]);
  } else {
    broken.push(file);
    console.log('(no PASS/FAIL summary emitted — the file aborted)');
  }
}

if (broken.length) {
  console.log(`\nFiles that aborted before summarising: ${broken.join(', ')}`);
}
console.log(`\n──────────────────────────────\nTOTAL  PASS=${totalPass}  FAIL=${totalFail}`);
process.exit(totalFail > 0 || broken.length > 0 ? 1 : 0);
