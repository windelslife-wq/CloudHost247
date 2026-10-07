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
  result = await php.run({ scriptPath: '/repo/modules/addons/cloudhost247passkey/tests/run.php' });
} catch (error) {
  result = error && error.response ? error.response : { text: '', errors: String(error) };
}
const output = result.text || '';
const errors = result.errors || '';
process.stdout.write(output);
if (errors.trim()) {
  process.stderr.write(errors);
}
const match = output.match(/CHECKS=(\d+)\s+FAILURES=(\d+)/);
if (result.exitCode !== 0 || !match || match[2] !== '0' || !output.includes('PASSKEY_PHASE3_CORE_OK') || !output.includes('PASSKEY_PHASE4_INTEGRATION_OK') || !output.includes('PASSKEY_PHASE5_MANAGEMENT_OK') || !output.includes('PASSKEY_PHASE6_SECURITY_OK') || !output.includes('PASSKEY_PHASE7_EXTERNAL_IDENTITY_OK') || !output.includes('PASSKEY_PHASE8_NOTIFICATIONS_OK') || !output.includes('PASSKEY_PHASE9_MAINTENANCE_OK') || !output.includes('PASSKEY_PHASE10_POLICY_ADMIN_OK')) {
  console.error('\nCloudHost247 Passkey Phase 3/4/5/6/7/8/9/10 core tests failed.');
  process.exit(1);
}
console.log(`PHP_WASM_VERSION=${phpVersion}`);
console.log(`PASSKEY_TESTS_OK checks=${match[1]}`);
process.exit(0);
