#!/usr/bin/env node
/** Browser verification of real rendered templates, not a WHMCS billing simulation. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const dependencies = process.env.BROWSER_DEPS || path.join(here, 'node_modules');
const require = createRequire(path.join(dependencies, 'package.json'));
const puppeteer = (await import(pathToFileURL(require.resolve('puppeteer-core')))).default;
const chromium = (await import(pathToFileURL(require.resolve('@sparticuz/chromium')))).default;
const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const base = process.env.PREVIEW_URL || 'http://127.0.0.1:4321';
let passed = 0;
const failures = [];
function check(label, condition, details = '') {
    if (condition) passed++;
    else { failures.push({ label, details }); console.error(`FAIL ${label}: ${details}`); }
}
// Minimal Linux sandboxes may have no NSS/NSPR. The pinned Chromium
// package includes those libraries; use its bundled copy rather than a
// system-wide apt install or an unverified external browser download.
let launchEnvironment = process.env;
if (!process.env.BROWSER_EXECUTABLE && process.platform === 'linux'
    && !fs.existsSync('/usr/lib/x86_64-linux-gnu/libnspr4.so')) {
    const directory = path.dirname(require.resolve('@sparticuz/chromium'));
    const { inflate } = await import(pathToFileURL(path.join(directory, 'lambdafs.js')));
    await inflate(path.join(directory, '..', 'bin', 'al2023.tar.br'));
    launchEnvironment = { ...process.env, LD_LIBRARY_PATH: `/tmp/al2023/lib:${process.env.LD_LIBRARY_PATH || ''}` };
}
const browser = await puppeteer.launch({
    executablePath: process.env.BROWSER_EXECUTABLE || await chromium.executablePath(),
    args: chromium.args.filter((arg) => !['--disable-web-security', '--allow-running-insecure-content'].includes(arg)),
    env: launchEnvironment,
    headless: true,
});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    for (const locale of ['en', 'fr', 'es', 'de', 'pt-BR']) {
        for (const width of [320, 375, 768, 1280]) {
            await page.setViewport({ width, height: 900 });
            await page.goto(`${base}/p/${locale}-eur-refund-policy`, { waitUntil: 'networkidle0' });
            check(`${locale}/${width}: no page overflow`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            for (const device of ['desktop', 'mobile']) {
                const prefix = `[data-device="${device}"]`;
                const language = `${prefix} [data-ch247-switcher-group="language"] button`;
                const currency = `${prefix} [data-ch247-switcher-group="currency"] button`;
                check(`${locale}/${width}/${device}: initialized collapsed state`, await page.$eval(language, (el) => el.getAttribute('aria-expanded') === 'false'));
                await page.focus(language);
                await page.keyboard.press('Enter');
                check(`${locale}/${width}/${device}: Enter opens and focuses an option`, await page.evaluate(() => document.activeElement.matches('[data-ch247-switcher-option]')));
                const bounds = await page.$eval(`${prefix} [data-ch247-switcher-group="language"] [data-ch247-switcher-panel]`, (el) => {
                    const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, width: innerWidth };
                });
                check(`${locale}/${width}/${device}: open panel fits viewport`, bounds.left >= -1 && bounds.right <= bounds.width + 1, JSON.stringify(bounds));
                await page.keyboard.press('End');
                check(`${locale}/${width}/${device}: End selects last option`, await page.evaluate(() => document.activeElement === document.activeElement.closest('ul')?.querySelector('li:last-child a')));
                await page.keyboard.press('Home');
                check(`${locale}/${width}/${device}: Home selects first option`, await page.evaluate(() => document.activeElement === document.activeElement.closest('ul')?.querySelector('li:first-child a')));
                await page.keyboard.press('ArrowDown');
                await page.keyboard.press('ArrowUp');
                check(`${locale}/${width}/${device}: arrow movement returns to first`, await page.evaluate(() => document.activeElement === document.activeElement.closest('ul')?.querySelector('li:first-child a')));
                await page.keyboard.press('Escape');
                check(`${locale}/${width}/${device}: Escape returns trigger focus`, await page.$eval(language, (el) => document.activeElement === el && el.getAttribute('aria-expanded') === 'false'));
                await page.focus(language);
                await page.keyboard.press('Space');
                check(`${locale}/${width}/${device}: Space opens without navigation`, await page.$eval(language, (el) => el.getAttribute('aria-expanded') === 'true'));
                await page.keyboard.press('Escape');
                check(`${locale}/${width}/${device}: focus ring visible`, await page.$eval(language, (el) => parseFloat(getComputedStyle(el).outlineWidth) >= 2));
                await page.click(language);
                check(`${locale}/${width}/${device}: mouse opens language`, await page.$eval(language, (el) => el.getAttribute('aria-expanded') === 'true'));
                await page.click(currency);
                check(`${locale}/${width}/${device}: mouse opens currency`, await page.$eval(currency, (el) => el.getAttribute('aria-expanded') === 'true'));
                check(`${locale}/${width}/${device}: opening currency closes language`, await page.$eval(language, (el) => el.getAttribute('aria-expanded') === 'false'));
                await page.click('.preview-bar');
                check(`${locale}/${width}/${device}: outside click closes`, await page.$eval(currency, (el) => el.getAttribute('aria-expanded') === 'false'));
                if (device === 'mobile') {
                    check(`${locale}/${width}: touch targets >=44px`, await page.$eval(currency, (el) => el.getBoundingClientRect().height >= 44));
                }
            }
            await page.addScriptTag({ content: axe });
            const audit = await page.evaluate(async () => (await axe.run('[data-ch247-switcher]', {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] },
            })).violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length })));
            check(`${locale}/${width}: switcher WCAG audit`, audit.length === 0, JSON.stringify(audit));
        }
    }
    await page.setViewport({ width: 375, height: 900 });
    await page.goto(`${base}/p/fr-eur-refund-policy`, { waitUntil: 'networkidle0' });
    await page.click('[data-device="mobile"] [data-ch247-switcher-group="language"] button');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-device="mobile"] [data-ch247-switcher-group="language"] a[href*="language=german"]'),
    ]);
    check('switching language preserves equivalent policy and EUR', page.url().endsWith('/p/de-eur-refund-policy'), page.url());
    check('real German body, not just the label', await page.evaluate(() => document.body.textContent.includes('Voraussetzungen für Rückerstattungen')));
    await page.click('[data-device="mobile"] [data-ch247-switcher-group="currency"] button');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-device="mobile"] [data-ch247-switcher-group="currency"] a[href*="currency=4"]'),
    ]);
    check('switching currency keeps German and the policy', page.url().endsWith('/p/de-gbp-refund-policy'), page.url());
    await page.goto(`${base}/refund-policy.php`, { waitUntil: 'networkidle0' });
    check('preferences persist on a plain revisit', page.url().endsWith('/p/de-gbp-refund-policy'), page.url());
    await page.goto(`${base}/refund-policy.php?language=invalid&currency=9999`, { waitUntil: 'networkidle0' });
    check('invalid selections preserve safe current pair', page.url().endsWith('/p/de-gbp-refund-policy'), page.url());

    /* -------------------------------------- automatic detection scenarios -- */

    // The detection pages are the real switcher rendered from a first visit:
    // no saved preference, the language from the browser header and the
    // currency from the visitor's country. They must be just as usable as the
    // hand-picked snapshots — and must always offer a way to change them.
    const detectionCases = [
        { name: 'detection-new-english', language: 'en', currency: 'NGN', detected: true },
        { name: 'detection-united-kingdom', language: 'en', currency: 'GBP', detected: true },
        { name: 'detection-euro-area', language: 'de', currency: 'EUR', detected: true },
        { name: 'detection-unmapped', language: 'en', currency: 'NGN', detected: true },
        { name: 'detection-returning', language: 'de', currency: 'GBP', detected: false },
    ];

    for (const scenario of detectionCases) {
        for (const width of [320, 375, 768, 1280]) {
            await page.setViewport({ width, height: 900 });
            await page.goto(`${base}/p/${scenario.name}`, { waitUntil: 'networkidle0' });
            const tag = `${scenario.name}/${width}`;

            check(`${tag}: no page overflow`, await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            check(`${tag}: language chip`, (await page.$eval('[data-device="desktop"] [data-ch247-switcher-group="language"] .ch247-switcher__label-text', (el) => el.textContent.trim())).length > 0);
            check(`${tag}: currency chip is ${scenario.currency}`, await page.$eval(
                '[data-device="desktop"] [data-ch247-switcher-group="currency"] .ch247-switcher__label-text',
                (el, expected) => el.textContent.trim() === expected,
                scenario.currency
            ));

            for (const device of ['desktop', 'mobile']) {
                const group = `[data-device="${device}"] [data-ch247-switcher-group="currency"]`;
                const button = `${group} button`;
                await page.click(button);
                check(`${tag}/${device}: opens`, await page.$eval(button, (el) => el.getAttribute('aria-expanded') === 'true'));
                check(`${tag}/${device}: every currency is a real link`, await page.$$eval(`${group} a[data-ch247-switcher-option]`, (els) => els.length >= 4 && els.every((a) => a.getAttribute('href').includes('currency='))));
                const bounds = await page.$eval(`${group} [data-ch247-switcher-panel]`, (el) => {
                    const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, width: innerWidth };
                });
                check(`${tag}/${device}: panel fits the viewport`, bounds.left >= -1 && bounds.right <= bounds.width + 1, JSON.stringify(bounds));
                await page.keyboard.press('Escape');
            }

            const noticeCount = await page.$$eval('[data-ch247-detection-notice]', (els) => els.length);
            check(`${tag}: ${scenario.detected ? 'an automatic choice is explained' : 'a saved choice is not called automatic'}`,
                scenario.detected ? noticeCount >= 2 : noticeCount === 0, String(noticeCount));
        }
    }

    // A detected currency is overridable in one click, on the same page.
    await page.setViewport({ width: 375, height: 900 });
    await page.goto(`${base}/p/detection-euro-area`, { waitUntil: 'networkidle0' });
    const overrideHref = await page.$eval('[data-device="mobile"] [data-ch247-switcher-group="currency"] a[data-ch247-switcher-option]', (el) => el.getAttribute('href'));
    check('the detected currency can be overridden from the switcher', overrideHref.includes('web-hosting.php?currency=') || overrideHref.includes('currency='), overrideHref);

    await page.setJavaScriptEnabled(false);
    await page.goto(`${base}/p/fr-eur-faqs`, { waitUntil: 'networkidle0' });
    check('no-JS panels are visible and links usable', await page.$eval('[data-device="mobile"] [data-ch247-switcher-panel]', (el) => getComputedStyle(el).display !== 'none'));
    check('no-JS expanded state matches exposed list', await page.$eval('[data-device="mobile"] button', (el) => el.getAttribute('aria-expanded') === 'true'));
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('[data-device="mobile"] a[href*="language=spanish"]'),
    ]);
    check('no-JS language selection navigates to real translated FAQ', page.url().endsWith('/p/es-eur-faqs'), page.url());
    check('actual Spanish questions rendered', await page.evaluate(() => document.body.textContent.includes('¿Qué servicios ofrecen?')));
    await page.setJavaScriptEnabled(true);
    await page.goto(`${base}/p/fr-eur-help-center`, { waitUntil: 'networkidle0' });
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    await page.click('[data-device="mobile"] [data-ch247-switcher-group="language"] button');
    check('reduced-motion preference still works', await page.$eval('[data-device="mobile"] [data-ch247-switcher-panel]', (el) => getComputedStyle(el).transitionDuration === '0s'));
    const cdp = await page.createCDPSession();
    await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'forced-colors', value: 'active' }] });
    await page.focus('[data-device="mobile"] [data-ch247-switcher-group="currency"] button');
    check('forced-colors retains a visible focus border', await page.$eval('[data-device="mobile"] [data-ch247-switcher-group="currency"] button', (el) => parseFloat(getComputedStyle(el).outlineWidth) >= 2));
    check('no browser JS errors in translated pages/switcher', errors.length === 0, errors.join('; '));
    const artifacts = path.resolve(here, '..', 'artifacts');
    fs.mkdirSync(artifacts, { recursive: true });
    fs.writeFileSync(path.join(artifacts, 'browser-results.json'), JSON.stringify({ passed, failures }, null, 2));
    console.log(`Browser checks: ${passed} passed, ${failures.length} failed`);
    process.exitCode = failures.length ? 1 : 0;
} finally {
    await browser.close();
}
