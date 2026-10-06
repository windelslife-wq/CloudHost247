/**
 * CloudHost247 Tools - test runner.
 *
 * Executes the PHP test files in this directory against a real PHP 8.3
 * runtime (php-wasm) so the suite can run in environments without a native
 * PHP binary. With a native binary present, `php tests/SecurityTest.php`
 * works exactly the same way.
 *
 *   node tests/run.mjs            # run all tests
 *   node tests/run.mjs Security   # run one
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
const filter = process.argv[2] || '';

const files = fs.readdirSync(here)
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

for (const file of files) {
  const id = await loadNodeRuntime('8.3', {
    withIntl: true,
    emscriptenOptions: { processId: alloc.claim() },
  });
  const php = new PHP(id);
  try { php.mkdir('/app'); } catch (e) {}
  await php.mount('/app', createNodeFsMountHandler(moduleRoot));
  const res = await php.run({ scriptPath: '/app/tests/' + file });
  const out = res.text || '';
  console.log(`\n=== ${file} ===`);
  process.stdout.write(out);
  if (res.errors && res.errors.trim()) process.stderr.write(res.errors);
  const m = out.match(/PASS=(\d+)\s+FAIL=(\d+)/);
  if (m) {
    totalPass += Number(m[1]);
    totalFail += Number(m[2]);
  } else {
    console.log('(no PASS/FAIL summary emitted)');
  }
}

console.log(`\n──────────────────────────────\nTOTAL  PASS=${totalPass}  FAIL=${totalFail}`);
process.exit(totalFail > 0 ? 1 : 0);
