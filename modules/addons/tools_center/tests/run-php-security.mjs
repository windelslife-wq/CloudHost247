// Runs deterministic Tools Center security, route-validation, and domain-search tests on php-wasm.
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

let allPassed = true;
for (const [script, label] of [
    ['/app/tests/auth-hardening.php', 'Tools Center auth/CSRF/rate limit and tool input checks'],
    ['/app/tests/domain-search-test.php', 'Tools Center domain search'],
    ['/app/tests/clientarea-route-test.php', 'Tools Center client-area route validation'],
]) {
    const result = await php.run({ scriptPath: script });
    process.stdout.write(result.text || '');
    if (result.errors) process.stderr.write(result.errors);
    const match = (result.text || '').match(/PASS=(\d+)\s+FAIL=(\d+)/);
    const ok = Boolean(match) && Number(match[2]) === 0;
    allPassed = allPassed && ok;
    console.log(match ? `${label}: ${match[1]} passed, ${match[2]} failed` : `${label}: no result`);
}
process.exit(allPassed ? 0 : 1);
