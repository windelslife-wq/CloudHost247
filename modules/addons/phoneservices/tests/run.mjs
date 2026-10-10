// Runs the phoneservices offline tests on php-wasm (no PHP install needed).
import { loadNodeRuntime, createNodeFsMountHandler } from '../../cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../cloudhost247services/node_modules/@php-wasm/universal/index.js';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..', '..', '..');
const files = fs.readdirSync(here).filter((f) => f.endsWith('Test.php')).sort();
let fail = 0;
for (const file of files) {
  const id = await loadNodeRuntime('8.3', { withIntl: true, emscriptenOptions: { processId: new ProcessIdAllocator().claim() } });
  const php = new PHP(id);
  php.mkdir('/app');
  await php.mount('/app', createNodeFsMountHandler(root));
  const result = await php.run({ scriptPath: '/app/modules/addons/phoneservices/tests/' + file });
  process.stdout.write(`\n=== ${file} ===\n${result.text || ''}`);
  if (result.errors) process.stderr.write(result.errors);
  const m = (result.text || '').match(/FAIL=(\d+)/);
  if (!m || Number(m[1]) !== 0) fail++;
}
console.log(fail ? 'phoneservices: failures' : 'phoneservices: all suites passed');
process.exit(fail ? 1 : 0);
