#!/usr/bin/env node
/**
 * CloudHost247 — i18n staging preview builder.
 *
 * Runs staging/i18n-preview/render.php and writes the rendered snapshots into
 * staging/i18n-preview/public/pages/.
 *
 * Two ways to run it, in preference order:
 *
 *   1. A native `php` binary, if the machine has one:
 *        node staging/i18n-preview/build.mjs
 *
 *   2. php-wasm, for machines and CI sandboxes with no PHP installed:
 *        node staging/i18n-preview/build.mjs --wasm
 *
 * The php-wasm path can use the services module dev installation under
 * the repo at modules/addons/cloudhost247services/node_modules (the module test
 * runners use it). Both paths execute the same PHP file, so the output is
 * identical — only the interpreter differs.
 */
import { prepareSmarty } from './prepare.mjs';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const renderPhp = path.join(here, 'render.php');
const wantWasm = process.argv.includes('--wasm');

/* ----------------------------------------------------------- native php --- */

function runNative() {
    const result = spawnSync('php', [renderPhp], { cwd: repoRoot, encoding: 'utf8' });
    if (result.error) {
        return null; // no php binary on PATH
    }
    process.stdout.write(result.stdout || '');
    process.stderr.write(result.stderr || '');
    return result.status === 0;
}

/* ------------------------------------------------------------ php-wasm ---- */

async function loadPhpWasm() {
    const vendored = path.join(
        repoRoot,
        'modules',
        'addons',
        'cloudhost247services',
        'node_modules'
    );

    const candidates = [
        [pathToFileURL(path.join(vendored, '@php-wasm', 'node', 'index.js')).href,
         pathToFileURL(path.join(vendored, '@php-wasm', 'universal', 'index.js')).href],
        ['@php-wasm/node', '@php-wasm/universal'],
    ];

    let lastError;
    for (const [nodeSpec, universalSpec] of candidates) {
        try {
            const node = await import(nodeSpec);
            const universal = await import(universalSpec);
            // loadNodeRuntime + createNodeFsMountHandler come from @php-wasm/node;
            // PHP + ProcessIdAllocator come from @php-wasm/universal.
            if (node && node.loadNodeRuntime && node.createNodeFsMountHandler
                && universal && universal.PHP && universal.ProcessIdAllocator) {
                return { node, universal };
            }
        } catch (error) {
            lastError = error;
        }
    }

    throw new Error(
        'php-wasm is not reachable. Tried the vendored copy under ' +
        'modules/addons/cloudhost247services/node_modules and bare specifiers.' +
        (lastError ? ` Last error: ${lastError.message}` : '')
    );
}

async function runWasm() {
    const { node, universal } = await loadPhpWasm();
    const { loadNodeRuntime, createNodeFsMountHandler } = node;
    const { PHP, ProcessIdAllocator } = universal;

    // The wrapper lives in /tmp so it can chdir() before requiring render.php,
    // which resolves its sibling smarty-lite.php relative to __DIR__ anyway.
    const wrapperDir = '/hosttmp/ch247-preview';
    const wrapperPath = `${wrapperDir}/_render.php`;
    const hostWrapperDir = path.join(os.tmpdir(), 'ch247-preview');

    // Inside php-wasm the repo exists only at its mount point, so the wrapper
    // must address render.php by its /repo path, not the host path.
    const repoRelative = path.relative(repoRoot, renderPhp).split(path.sep).join('/');
    const guestScript = '/repo/' + repoRelative;

    fs.mkdirSync(hostWrapperDir, { recursive: true });
    fs.writeFileSync(
        path.join(hostWrapperDir, '_render.php'),
        '<?php\n' +
            'chdir(' + JSON.stringify('/repo') + ');\n' +
            '$argv = array(' + JSON.stringify(guestScript) + ');\n' +
            '$argc = 1;\n' +
            '$_SERVER[\'argv\'] = $argv;\n' +
            '$_SERVER[\'argc\'] = $argc;\n' +
            'require ' + JSON.stringify(guestScript) + ';\n'
    );

    const id = await loadNodeRuntime('8.3', {
        withIntl: true,
        emscriptenOptions: { processId: new ProcessIdAllocator().claim() },
    });
    const php = new PHP(id);
    try {
        php.mkdir('/tmp');
    } catch (error) {
        /* already exists */
    }
    await php.mount('/repo', createNodeFsMountHandler(repoRoot));
    await php.mount('/hosttmp', createNodeFsMountHandler(os.tmpdir()));

    let result;
    try {
        result = await php.run({ scriptPath: wrapperPath });
    } catch (error) {
        result = error && error.response
            ? error.response
            : { text: '', errors: String(error), exitCode: 1 };
    }

    process.stdout.write(result.text || '');
    if (result.errors && String(result.errors).trim()) {
        process.stderr.write(String(result.errors));
    }

    return !(result.errors && String(result.errors).trim()) && (result.exitCode || 0) === 0;
}

/* ---------------------------------------------------------------- main ---- */

await prepareSmarty();

if (!fs.existsSync(renderPhp)) {
    console.error('render.php not found at ' + renderPhp);
    process.exit(1);
}

if (!wantWasm) {
    const ok = runNative();
    if (ok === null) {
        console.log('No native `php` binary on PATH; falling back to php-wasm…\n');
    } else {
        process.exit(ok ? 0 : 1);
    }
}

runWasm()
    .then((ok) => process.exit(ok ? 0 : 1))
    .catch((error) => {
        console.error('php-wasm render failed: ' + (error && error.message ? error.message : error));
        console.error('\nAlternative: install PHP and run');
        console.error('  php staging/i18n-preview/render.php');
        process.exit(1);
    });
