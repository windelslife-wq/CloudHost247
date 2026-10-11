// Runs the soyoustart server-module offline tests on php-wasm (no PHP install needed).
import { loadNodeRuntime, createNodeFsMountHandler } from '../../../addons/cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../../addons/cloudhost247services/node_modules/@php-wasm/universal/index.js';
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
  let result;
  try {
    result = await php.run({ scriptPath: '/app/modules/servers/soyoustart/tests/' + file });
  } catch (e) {
    const bytes = (e && e.response && e.response.bytes) || null;
    result = { text: bytes ? Buffer.from(bytes).toString('utf8') : '', errors: String((e && e.response && e.response.errors) || e).slice(0, 2000) };
  }
  process.stdout.write(`\n=== ${file} ===\n${result.text || ''}`);
  if (result.errors) process.stderr.write(result.errors);
  const m = (result.text || '').match(/FAIL=(\d+)/);
  if (!m || Number(m[1]) !== 0) fail++;
}
console.log(fail ? 'soyoustart: failures' : 'soyoustart: all suites passed');
process.exit(fail ? 1 : 0);
