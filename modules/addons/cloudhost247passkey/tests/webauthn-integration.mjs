import { generateKeyPairSync } from 'node:crypto';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..', '..', '..');
const phpVersion = process.env.PHP_WASM_VERSION || '8.3';
const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
const testPrivateKeyPem = privateKey.export({ type: 'pkcs8', format: 'pem' });
const testPrivateKeyPemBase64 = Buffer.from(testPrivateKeyPem, 'utf8').toString('base64');
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
  result = await php.run({
    scriptPath: '/repo/modules/addons/cloudhost247passkey/tests/webauthn-integration.php',
    env: { CH247PK_TEST_PRIVATE_PEM_B64: testPrivateKeyPemBase64 },
  });
} catch (error) {
  result = error && error.response ? error.response : { text: '', errors: String(error) };
}
const output = result.text || '';
const errors = result.errors || '';
process.stdout.write(output);
if (errors.trim()) {
  process.stderr.write(errors);
}
const match = output.match(/INTEGRATION_CHECKS=(\d+)\s+INTEGRATION_FAILURES=(\d+)/);
if (result.exitCode !== 0 || !match || match[2] !== '0' || !output.includes('PASSKEY_WEBAUTHN_INTEGRATION_OK')) {
  console.error('\nCloudHost247 Passkey WebAuthn library integration tests failed.');
  process.exit(1);
}
console.log(`PHP_WASM_VERSION=${phpVersion}`);
console.log(`PASSKEY_WEBAUTHN_INTEGRATION_OK checks=${match[1]}`);
process.exit(0);
