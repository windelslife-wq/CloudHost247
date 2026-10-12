#!/usr/bin/env node
/** Test-only Smarty. Production uses WHMCS's installed engine, never this copy. */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const version = '4.5.6';
export const sha256 = 'f617b86983b3c7115222c44d63d874630f9d4b4bd88cf1ef603b3ab138c67079';
const here = path.dirname(fileURLToPath(import.meta.url));
const cache = path.join(here, '.cache');
const archive = path.join(cache, `smarty-${version}.tar.gz`);
export const smartyDirectory = path.join(cache, `smarty-${version}`, 'libs');

export async function prepareSmarty() {
    fs.mkdirSync(cache, { recursive: true });
    if (!fs.existsSync(archive)) {
        const url = `https://codeload.github.com/smarty-php/smarty/tar.gz/refs/tags/v${version}`;
        const response = await fetch(url);
        if (!response.ok) throw new Error(`Smarty download failed: HTTP ${response.status}`);
        fs.writeFileSync(archive, Buffer.from(await response.arrayBuffer()));
    }
    const digest = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
    if (digest !== sha256) throw new Error('Smarty dev archive checksum does not match the pinned release');
    if (!fs.existsSync(path.join(smartyDirectory, 'Smarty.class.php'))) {
        execFileSync('tar', ['-xzf', archive, '-C', cache], { stdio: 'inherit' });
    }
    return smartyDirectory;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    prepareSmarty().then(() => console.log(`Smarty ${version} ready (dev-only, SHA-256 verified)`))
        .catch((error) => { console.error(error.message); process.exitCode = 1; });
}
