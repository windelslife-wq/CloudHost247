// Runs the PHP SSRF-guard tests on php-wasm (no PHP install needed).
// Uses the php-wasm runtime already vendored under cloudhost247services/node_modules.
import { loadNodeRuntime, createNodeFsMountHandler } from '../../cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../cloudhost247services/node_modules/@php-wasm/universal/index.js';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const id = await loadNodeRuntime('8.3', { withIntl: true, emscriptenOptions: { processId: new ProcessIdAllocator().claim() } });
const php = new PHP(id);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(root));
const result = await php.run({ scriptPath: '/app/tests/outbound-guard.php' });
process.stdout.write(result.text || '');
if (result.errors) process.stderr.write(result.errors);
const m = (result.text || '').match(/PASS=(\d+)\s+FAIL=(\d+)/);
const ok = m && Number(m[2]) === 0;
console.log(m ? `Outbound guard: ${m[1]} passed, ${m[2]} failed` : 'Outbound guard: no result');
process.exit(ok ? 0 : 1);
