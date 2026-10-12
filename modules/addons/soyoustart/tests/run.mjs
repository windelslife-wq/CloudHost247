// Runs the addon API hardening regression suite on php-wasm without WHMCS or network access.
import { loadNodeRuntime, createNodeFsMountHandler } from '../../cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../cloudhost247services/node_modules/@php-wasm/universal/index.js';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..', '..', '..');
const id = await loadNodeRuntime('8.3', {
  withIntl: true,
  emscriptenOptions: { processId: new ProcessIdAllocator().claim() },
});
const php = new PHP(id);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(root));
let result;
try {
  result = await php.run({ scriptPath: '/app/modules/addons/soyoustart/tests/ApiCallSecurityTest.php' });
} catch (error) {
  const bytes = (error && error.response && error.response.bytes) || null;
  result = {
    text: bytes ? Buffer.from(bytes).toString('utf8') : '',
    errors: String((error && error.response && error.response.errors) || error).slice(0, 2000),
  };
}
process.stdout.write(result.text || '');
if (result.errors) process.stderr.write(result.errors);
const match = (result.text || '').match(/PASS=(\d+) FAIL=(\d+)/);
const failed = !match || Number(match[2]) !== 0;
console.log(failed ? 'soyoustart addon: failures' : `soyoustart addon: ${match[1]} checks passed`);
process.exit(failed ? 1 : 0);
