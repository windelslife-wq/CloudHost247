/**
 * Static load check ("production build" gate) — loads every shipped PHP
 * file through a real PHP 8.3 runtime.
 *
 *   node tests/lint.mjs
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

const m = out.match(/BAD=(\d+)/);
if (!m || m[1] !== '0') {
  console.error('\nLint gate failed.');
  process.exit(1);
}
console.log('LINT_OK');
// The wasm runtime keeps the node event loop alive even after the gate has
// finished — without an explicit exit the runner idles forever (this already
// caused zombie lint processes on repeated runs).
process.exit(0);
