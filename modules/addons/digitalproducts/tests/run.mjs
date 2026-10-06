import { loadNodeRuntime, createNodeFsMountHandler } from '../../cloudhost247services/node_modules/@php-wasm/node/index.js';
import { PHP, ProcessIdAllocator } from '../../cloudhost247services/node_modules/@php-wasm/universal/index.js';
import fs from 'fs'; import path from 'path'; import { fileURLToPath } from 'url';
const here = path.dirname(fileURLToPath(import.meta.url)); const root = path.resolve(here, '..');
const files = fs.readdirSync(here).filter(f => f.endsWith('Test.php')).sort(); const alloc = new ProcessIdAllocator(); let pass = 0; let fail = 0;
for (const file of files) { const id = await loadNodeRuntime('8.3', { withIntl: true, emscriptenOptions: { processId: alloc.claim() } }); const php = new PHP(id); php.mkdir('/app'); await php.mount('/app', createNodeFsMountHandler(root)); const result = await php.run({ scriptPath: '/app/tests/' + file }); const output = result.text || ''; process.stdout.write(`\n=== ${file} ===\n${output}`); if (result.errors) process.stderr.write(result.errors); const m = output.match(/PASS=(\d+)\s+FAIL=(\d+)/); if (m) { pass += Number(m[1]); fail += Number(m[2]); } else fail++; }
console.log(`TOTAL PASS=${pass} FAIL=${fail}`); process.exit(fail ? 1 : 0);
