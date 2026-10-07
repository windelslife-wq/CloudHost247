import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..', '..', '..');
const phpVersion = process.env.PHP_WASM_VERSION || '8.3';
const phpId = await loadNodeRuntime(phpVersion, {
  withIntl: true,
  emscriptenOptions: { processId: new ProcessIdAllocator().claim() },
});
const php = new PHP(phpId);
try {
  php.mkdir('/repo');
} catch (error) {
  // The PHP runtime already provides this mountpoint.
}
await php.mount('/repo', createNodeFsMountHandler(repoRoot));

let result;
try {
  result = await php.run({ scriptPath: '/repo/modules/addons/cloudhost247_cart_recovery/tests/run.php' });
} catch (error) {
  result = error && error.response ? error.response : { text: '', errors: String(error) };
}
const output = result.text || '';
const errors = result.errors || '';
process.stdout.write(output);
if (errors.trim()) {
  process.stderr.write(errors);
}
const failed = (output.match(/^not ok/mg) || []).length;
if (result.exitCode !== 0 || failed > 0 || !output.includes('All cart recovery tests passed.')) {
  console.error('\nCloudHost247 Cart Recovery behaviour tests failed.');
  process.exit(1);
}
const passed = (output.match(/^ok - /mg) || []).length;
console.log(`PHP_WASM_VERSION=${phpVersion}`);
console.log(`CART_RECOVERY_TESTS_OK passed=${passed}`);
process.exit(0);
