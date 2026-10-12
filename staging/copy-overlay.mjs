#!/usr/bin/env node
/** Copy this change's deployment files into a licensed STAGING checkout, never delete core files. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
if (!args.includes('--staging') || !args[0] || args[0].startsWith('--')) {
    console.error('Usage: node staging/copy-overlay.mjs /licensed/staging/whmcs --staging [--dry-run]');
    process.exit(2);
}
const destination = fs.realpathSync(args[0]);
if (destination === root || !fs.existsSync(path.join(destination, 'init.php'))) {
    console.error('Destination must be a separate licensed WHMCS STAGING checkout containing init.php.');
    process.exit(2);
}
const files = fs.readdirSync(root).filter((file) => file.endsWith('.php'));
files.push(
    'includes/ch247_content.php', 'includes/hostx_page_functions.php',
    'includes/hooks/cloudhost247_i18n.php', 'includes/i18n', 'lang/site', 'lang/pages',
    'lang/overrides/english.php', 'lang/overrides/french.php', 'lang/overrides/spanish.php',
    'lang/overrides/german.php', 'lang/overrides/portuguese-br.php',
    'templates/hostx', 'modules/addons/cloudhost247_i18n', 'crons/currencyRateRefresh.php',
);
for (const relative of files) {
    console.log(`${args.includes('--dry-run') ? 'Would copy' : 'Copy'} ${relative}`);
    if (!args.includes('--dry-run')) {
        const target = path.join(destination, relative);
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.cpSync(path.join(root, relative), target, {
            recursive: true,
            filter: (source) => !['node_modules', 'cache', '.cache', 'tests'].includes(path.basename(source)),
        });
    }
}
console.log('No WHMCS core files were deleted. This helper is staging-only; production deployment is not authorized.');
