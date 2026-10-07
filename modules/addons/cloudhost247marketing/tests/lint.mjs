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
const repoRoot = path.resolve(moduleRoot, '..', '..', '..');

const alloc = new ProcessIdAllocator();
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
// Real repo layout so relative CH247M_ROOT resolution works (see run.mjs).
await php.mount('/repo', createNodeFsMountHandler(repoRoot));

let res;
try {
  res = await php.run({ scriptPath: '/repo/modules/addons/cloudhost247marketing/tests/lint.php' });
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
process.exit(0);
