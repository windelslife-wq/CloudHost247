/**
 * CloudHost247 Marketing — test runner.
 *
 * Executes every *Test.php file here against a real PHP 8.3 runtime
 * (php-wasm), so the suite runs in environments without a native PHP binary.
 * With a native binary each suite is a plain script (`php tests/01_...php`).
 *
 *   node tests/run.mjs             # run everything
 *   node tests/run.mjs Adversarial # only files whose name matches
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
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

function parseCounts(text) {
  const m = text.match(/PASS=(\d+)\s+FAIL=(\d+)/);
  return m ? { pass: parseInt(m[1], 10), fail: parseInt(m[2], 10) } : null;
}

for (const file of files) {
  const id = await loadNodeRuntime('8.3', {
    withIntl: true,
    emscriptenOptions: { processId: alloc.claim() },
  });
  const php = new PHP(id);
  try {
    php.mkdir('/repo');
  } catch (e) {
    /* already present */
  }
  // Mount the whole repo at /repo so the module sits at its real path —
  // CH247M_ROOT (three levels up from the module dir) then resolves to
  // /repo and the diagnostics wrappers find the sibling CloudHost247_tools
  // module exactly as in production.
  await php.mount('/repo', createNodeFsMountHandler(path.resolve(moduleRoot, '..', '..', '..')));

  let res;
  try {
    res = await php.run({ scriptPath: '/repo/modules/addons/cloudhost247marketing/tests/' + file });
  } catch (e) {
    res = e && e.response ? e.response : { text: '', errors: String(e) };
  }
  const out = res.text || '';
  const err = res.errors || '';
  const counts = parseCounts(out);
  if (!counts) {
    broken.push(file);
    console.log(`${file}  — crashed before summarising:\n${out}\n${err}`);
    continue;
  }
  totalPass += counts.pass;
  totalFail += counts.fail;
  console.log(
    `${file.padEnd(28)} PASS=${String(counts.pass).padStart(4)}  FAIL=${counts.fail}` +
      (counts.fail > 0 ? '\n' + out : '')
  );
}

console.log('────────────────────────────────────────');
console.log(`TOTAL                    PASS=${totalPass} FAIL=${totalFail}`);
if (broken.length) {
  console.log(`CRASHED: ${broken.join(', ')}`);
}
process.exit(totalFail > 0 || broken.length > 0 ? 1 : 0);
