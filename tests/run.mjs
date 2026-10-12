#!/usr/bin/env node
/** PHP-wasm fallback for developers without PHP. CI still uses native PHP. */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const options = process.argv.slice(2);
const version = options.find((a) => a.startsWith('--php-version='))?.split('=')[1] || '8.3';
const args = options.filter((a) => !a.startsWith('--php-version='));
const phpQuote = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

if (args[0] === '--suite=i18n') {
    const files = fs.readdirSync(path.join(root, 'tests', 'i18n')).filter((f) => f.endsWith('Test.php')).sort();
    files.push('../../ci/apply-override-translations.php');
    let failed = 0;
    for (const file of files) {
        const script = path.relative(root, path.resolve(root, 'tests', 'i18n', file));
        const cmd = [fileURLToPath(import.meta.url), `--php-version=${version}`, script];
        if (script.startsWith('ci/')) cmd.push('--check');
        console.log(`--- PHP ${version} ${script}`);
        const result = spawnSync(process.execPath, cmd, { cwd: root, encoding: 'utf8' });
        process.stdout.write(result.stdout || '');
        process.stderr.write(result.stderr || '');
        if (result.status !== 0 || /FAIL(?:URES)?=[1-9]|BAD=[1-9]|^\[FAIL\]/m.test(result.stdout || '')) failed++;
    }
    console.log(`RESULT: ${failed ? `${failed} failing suite(s)` : 'all executed suites passed'}`);
    process.exit(failed ? 1 : 0);
}

async function dependencies() {
    const candidates = [
        path.join(root, 'staging', 'i18n-preview', 'node_modules'),
        path.join(root, 'modules', 'addons', 'cloudhost247services', 'node_modules'),
    ];
    for (const directory of candidates) {
        try {
            const node = await import(pathToFileURL(path.join(directory, '@php-wasm/node/index.js')));
            const universal = await import(pathToFileURL(path.join(directory, '@php-wasm/universal/index.js')));
            return { ...node, ...universal };
        } catch { /* try the next dev installation */ }
    }
    throw new Error('Install the dev PHP runtime: npm install --prefix staging/i18n-preview');
}

try {
    const { PHP, loadNodeRuntime, createNodeFsMountHandler, ProcessIdAllocator } = await dependencies();
    const runtime = await loadNodeRuntime(version, {
        withIntl: process.env.PHP_WASM_INTL !== '0',
        emscriptenOptions: { processId: new ProcessIdAllocator().claim() },
    });
    const php = new PHP(runtime);
    await php.mount('/repo', createNodeFsMountHandler(root));
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'ch247-php-'));
    await php.mount('/runner', createNodeFsMountHandler(temp));
    let script;
    if (args[0] === '--lint') {
        const target = args[1] || '';
        script = `<?php
$root = '/repo'; $target = ${phpQuote(target)};
$base = $root . '/' . $target; $checked=0; $bad=0;
$files = is_file($base) ? [$base] : new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach ($files as $entry) {
    $file = is_string($entry) ? $entry : $entry->getPathname();
    if (!preg_match('/\\.php$/', $file) || preg_match('~/(?:node_modules|vendor|\\.cache|\\.git|whmcs)/|/modules/addons/(?:hostx|xtreme_currency_rates)/~', $file)) continue;
    $checked++;
    try { token_get_all(file_get_contents($file), TOKEN_PARSE); }
    catch (Throwable $e) { $bad++; echo "SYNTAX FAIL $file: " . $e->getMessage() . "\\n"; }
}
echo "checked=$checked syntax_errors=$bad\\n"; exit($bad ? 1 : 0);
`;
    } else {
        if (!args[0]) throw new Error('Usage: node tests/run.mjs [--php-version=7.4] <script.php> [args] | --suite=i18n | --lint [path]');
        const absolute = path.resolve(root, args[0]);
        if (!absolute.startsWith(root + path.sep) || !fs.existsSync(absolute)) throw new Error('Script must exist inside this checkout');
        const guest = '/repo/' + path.relative(root, absolute).split(path.sep).join('/');
        script = `<?php
chdir('/repo');
$argv = [${[guest, ...args.slice(1)].map(phpQuote).join(',')}];
$argc=count($argv); $_SERVER['argv']=$argv; $_SERVER['argc']=$argc;
require ${phpQuote(guest)};
`;
    }
    fs.writeFileSync(path.join(temp, 'run.php'), script);
    let response;
    try { response = await php.run({ scriptPath: '/runner/run.php' }); }
    catch (error) { response = error.response || { text: '', errors: String(error), exitCode: 1 }; }
    process.stdout.write(response.text || '');
    if (response.errors) process.stderr.write(String(response.errors));
    const failed = response.exitCode || /FAIL(?:URES)?=[1-9]|BAD=[1-9]|^\[FAIL\]/m.test(response.text || '');
    // Windows exit(1) and php-wasm CGI response codes differ; explicit suite
    // failure markers are authoritative, just as in ci/run-php.sh.
    process.exit(failed ? 1 : 0);
} catch (error) {
    console.error(error.stack || error.message);
    process.exit(1);
}
