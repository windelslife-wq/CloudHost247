import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
const id = await loadNodeRuntime('8.3', { withIntl: true, emscriptenOptions: { processId: new ProcessIdAllocator().claim() } });
const php = new PHP(id);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(moduleRoot));
const r = await php.run({ scriptPath: '/app/tests/lint.php' });
const out = r.text || '';
process.stdout.write(out);
if (r.errors && r.errors.trim()) process.stderr.write(r.errors);
const m = out.match(/BAD=(\d+)/);
process.exit(!m || Number(m[1]) > 0 ? 1 : 0);
