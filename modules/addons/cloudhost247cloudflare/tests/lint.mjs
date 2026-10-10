import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
const id = await loadNodeRuntime('8.3', { withIntl: true, emsittenOptions: undefined, emscriptenOptions: { processId: new ProcessIdAllocator().claim() } });
const php = new PHP(id); php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(process.cwd()));
const r = await php.run({ scriptPath: '/app/tests/lint.php' });
console.log(r.text); if (r.errors) console.error(r.errors); process.exit(0);
