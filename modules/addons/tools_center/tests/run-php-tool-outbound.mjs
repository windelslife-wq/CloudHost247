// Runs host-directed Tools Center IP/WHOIS tests on php-wasm. All external
// transports and shell execution are mocked by tool-outbound-security.php.
import { loadNodeRuntime, createNodeFsMountHandler } from '../../cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../cloudhost247services/node_modules/@php-wasm/universal/index.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
const id = await loadNodeRuntime('8.3', {
    withIntl: true,
    emscriptenOptions: { processId: new ProcessIdAllocator().claim() }
});
const php = new PHP(id);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(moduleRoot));
const result = await php.run({ scriptPath: '/app/tests/tool-outbound-security.php' });
process.stdout.write(result.text || '');
if (result.errors) process.stderr.write(result.errors);
const match = (result.text || '').match(/PASS=(\d+)\s+FAIL=(\d+)/);
const ok = match && Number(match[2]) === 0;
console.log(match ? `Tools Center host-directed network security: ${match[1]} passed, ${match[2]} failed` : 'Tools Center host-directed network security: no result');
process.exit(ok ? 0 : 1);
