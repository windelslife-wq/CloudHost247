// Runs the Smtphosting PHP test suite and a whole-module syntax lint inside
// php-wasm, for environments with no local PHP binary.
//
// Usage (from the repository root):
//   mkdir -p /tmp/php-wasm-runner && cd /tmp/php-wasm-runner && npm init -y >/dev/null \
//     && npm install @php-wasm/node @php-wasm/universal
//   node /path/to/modules/servers/Smtphosting/tests/run-php-wasm.mjs 8.3 /path/to/modules/servers/Smtphosting
//
// Requires Node 18+ and the packages above, resolved from the current directory.
// The module tree (without vendor/) is copied into the PHP virtual file system
// at /mod, then tests/run.php and a TOKEN_PARSE syntax lint are executed.
//
// processId: php-wasm's Node loader needs an explicit process id outside of
// VITEST; 1 is used here for a single, non-concurrent run.

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

// Resolve php-wasm from the current working directory's node_modules
// (bare ESM imports would resolve relative to this script instead).
const nodeModules = path.join(process.cwd(), 'node_modules', '@php-wasm');
const { loadNodeRuntime } = await import(pathToFileURL(path.join(nodeModules, 'node', 'index.js')).href);
const { PHP } = await import(pathToFileURL(path.join(nodeModules, 'universal', 'index.js')).href);

const [, , phpVersion = '8.3', moduleDir] = process.argv;
if (!moduleDir) {
    console.error('usage: node run-php-wasm.mjs <php-version> <module-dir>');
    process.exit(2);
}

const LINT_SCRIPT = `<?php
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/mod', FilesystemIterator::SKIP_DOTS));
$total = 0; $bad = [];
foreach ($it as $f) {
  $p = $f->getPathname();
  if (substr($p, -4) !== '.php' || strpos($p, '/mod/vendor/') === 0) continue;
  $total++;
  try { token_get_all(file_get_contents($p), TOKEN_PARSE); }
  catch (ParseError $e) { $bad[] = $p . ' :: ' . $e->getMessage() . ' line ' . $e->getLine(); }
}
echo "LINT PHP_VERSION=" . PHP_VERSION . " files_checked=$total syntax_errors=" . count($bad) . "\\n";
foreach ($bad as $b) echo "  $b\\n";
exit(count($bad) === 0 ? 0 : 1);
`;

async function main() {
    const rt = await loadNodeRuntime(phpVersion, { emscriptenOptions: { processId: 1 } });
    const php = new PHP(rt);

    const copyDir = (src, dst) => {
        php.mkdir(dst);
        for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
            const s = path.join(src, entry.name);
            const d = `${dst}/${entry.name}`;
            if (entry.isDirectory()) copyDir(s, d);
            else if (entry.isFile()) php.writeFile(d, fs.readFileSync(s));
        }
    };

    php.mkdir('/mod');
    for (const entry of fs.readdirSync(moduleDir, { withFileTypes: true })) {
        if (entry.name === 'vendor') continue;
        const s = path.join(moduleDir, entry.name);
        const d = `/mod/${entry.name}`;
        if (entry.isDirectory()) copyDir(s, d);
        else if (entry.isFile()) php.writeFile(d, fs.readFileSync(s));
    }

    php.writeFile('/lint.php', LINT_SCRIPT);

    const results = [];
    for (const script of ['/mod/tests/run.php', '/lint.php']) {
        let text;
        let code;
        try {
            const r = await php.run({ scriptPath: script });
            text = r.text;
            code = r.exitCode;
        } catch (e) {
            text = e.response?.text ?? String(e.message);
            code = e.response?.exitCode ?? 1;
        }
        console.log(`--- ${script} (exit ${code})`);
        console.log(text.trimEnd());
        results.push(code);
    }
    process.exitCode = results.every((c) => c === 0) ? 0 : 1;
}

main()
    .catch((e) => {
        console.error(e);
        process.exitCode = 1;
    })
    .finally(() => process.exit(process.exitCode ?? 0));
