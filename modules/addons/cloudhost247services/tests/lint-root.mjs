/**
 * Repo's root landing pages are outside the module; this gate parses each
 * one through the real runtime (no execution — init.php is a live-WHMCS
 * dependency), so a syntax slip in a landing page can never ship.
 *
 *   node tests/lint-root.mjs
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
import path from 'path';
import { fileURLToPath } from 'url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..', '..', '..');

const alloc = new ProcessIdAllocator();
const id = await loadNodeRuntime('8.3', {
  emscriptenOptions: { processId: alloc.claim() },
});
const php = new PHP(id);
try { php.mkdir('/app'); } catch (e) { /* exists */ }
await php.mount('/app', createNodeFsMountHandler(repoRoot));

const res = await php.run({ code: `<?php
$files = ['domain-search.php','bulk-domain-search.php','domain-transfer.php','tld-directory.php',
'domain-valuation.php','domain-auctions.php','discount-domain-club.php','whois-lookup.php',
'website-builder.php','ai-website-builder.php','online-store.php','hire-an-expert.php',
'digital-marketing.php','logo-maker.php','unified-inbox.php'];
$bad = 0;
foreach ($files as $f) {
    $path = '/app/' . $f;
    if (!is_file($path)) { echo "MISSING $f\\n"; $bad++; continue; }
    try { token_get_all(file_get_contents($path), TOKEN_PARSE); echo "OK   $f\\n"; }
    catch (ParseError $e) { echo "FAIL $f: " . $e->getMessage() . "\\n"; $bad++; }
}
echo "BAD=$bad\\n";
` });
process.stdout.write(res.text || '');
if (res.errors) process.stderr.write(res.errors);
const m = (res.text || '').match(/BAD=(\d+)/);
if (!m || m[1] !== '0') { console.error('Root landing gate failed.'); process.exit(1); }
console.log('ROOT_LINT_OK');
