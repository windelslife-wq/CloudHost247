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
const result = await php.run({ scriptPath: '/repo/modules/addons/cloudhost247passkey/tests/lint.php' });
process.stdout.write(result.text || '');
if (result.errors && result.errors.trim()) {
  process.stderr.write(result.errors);
}
if (result.exitCode !== 0) {
  process.exit(result.exitCode || 1);
}
console.log(`PHP_WASM_VERSION=${phpVersion}`);
console.log('PASSKEY_PHP_LINT_OK');
process.exit(0);
