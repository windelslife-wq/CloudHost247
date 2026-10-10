/**
 * CloudHost247 Tools - write the registry dump the JavaScript suites read.
 *
 * tests/tools.test.mjs compares every route-split browser module against the
 * authoritative registry, which lives in PHP. This step renders it to JSON so
 * that suite can be run without a native PHP binary.
 *
 *   node tests/dump-catalog.mjs [outputPath]     # default /tmp/tools.json
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const moduleRoot = path.resolve(here, '..');
const out = process.argv[2] || '/tmp/tools.json';

const id = await loadNodeRuntime('8.3', {
  withIntl: true,
  emscriptenOptions: { processId: new ProcessIdAllocator().claim() },
});
const php = new PHP(id);
try { php.mkdir('/app'); } catch (e) {}
await php.mount('/app', createNodeFsMountHandler(moduleRoot));

const res = await php.run({ scriptPath: '/app/tests/dump-catalog.php' });
const json = (res.text || '').trim();

if (!json.startsWith('[')) {
  console.error('Catalog dump did not produce a JSON array.');
  if (res.errors) console.error(res.errors);
  process.exit(1);
}

const parsed = JSON.parse(json);
fs.writeFileSync(out, JSON.stringify(parsed, null, 0));
console.log(`wrote ${parsed.length} tools to ${out}`);
process.exit(0);
