/**
 * Domain Broker — static load check ("production build" gate).
 *
 * Loads every shipped PHP file through a real PHP 8.3 runtime. Because the
 * module's classes are side-effect free, including them proves three things
 * at once: the file parses, every parent/interface it names resolves through
 * the autoloader, and the class body itself is well formed (constant
 * expressions, typed properties, trait use).
 *
 *   node tests/lint.mjs
 *
 * Exits non-zero on the first problem found, listing every bad file.
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');

const alloc = new ProcessIdAllocator();
const id = await loadNodeRuntime('8.3', {
  withIntl: true,
  emscriptenOptions: { processId: alloc.claim() },
});
const php = new PHP(id);
try {
  php.mkdir('/app');
} catch (e) {
  /* already present */
}
await php.mount('/app', createNodeFsMountHandler(moduleRoot));

let res;
try {
  res = await php.run({ scriptPath: '/app/tests/lint.php' });
} catch (e) {
  res = e && e.response ? e.response : { text: '', errors: String(e) };
}
const out = res.text || '';
process.stdout.write(out);
if (res.errors && res.errors.trim()) {
  process.stderr.write(res.errors);
}

const m = out.match(/FILES=(\d+)\s+BAD=(\d+)/);
if (!m) {
  console.error('\nThe lint pass aborted before summarising.');
  process.exit(1);
}
process.exit(Number(m[2]) > 0 ? 1 : 0);
