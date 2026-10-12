#!/usr/bin/env node
/**
 * Language / currency switcher tests.
 *
 * Static conformance checks for the switcher shipped by this task:
 *
 *   templates/hostx/includes/language-currency-switcher.tpl        (desktop)
 *   templates/hostx/hostx_includes/language-currency-switcher-mobile.tpl
 *   templates/hostx/css/language-currency-switcher.css
 *   templates/hostx/js/language-currency-switcher.js
 *
 * plus the header templates that mount it, and the WHMCS hook that feeds it.
 *
 * Run:  node templates/hostx/tests/i18n-switcher.test.mjs
 * Needs only Node.js (no npm install). Exit code 0 when every check passes.
 *
 * Smarty only executes inside WHMCS, so — as with announcementbar.test.mjs —
 * these checks are static: they assert the source carries the structure the
 * accessibility and responsive requirements demand. They deliberately do NOT
 * assert visual correctness; that is what the staging harness is for.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const testsDir = path.dirname(fileURLToPath(import.meta.url));
const themeDir = path.resolve(testsDir, '..');
const repoRoot = path.resolve(themeDir, '..', '..');
const read = (p) => fs.readFileSync(p, 'utf8');
const exists = (p) => fs.existsSync(p);

const desktopPath = path.join(themeDir, 'includes', 'language-currency-switcher.tpl');
const mobilePath = path.join(themeDir, 'hostx_includes', 'language-currency-switcher-mobile.tpl');
const cssPath = path.join(themeDir, 'css', 'language-currency-switcher.css');
const jsPath = path.join(themeDir, 'js', 'language-currency-switcher.js');

const desktop = read(desktopPath);
const mobile = read(mobilePath);
const css = read(cssPath);
const js = read(jsPath);

const hook = read(path.join(repoRoot, 'includes', 'hooks', 'cloudhost247_i18n.php'));

const stripComments = (s) => s.replace(/\{\*[\s\S]*?\*\}/g, '');
const desktopCode = stripComments(desktop);
const mobileCode = stripComments(mobile);

const includes = (haystack, needle) => haystack.includes(needle) || `missing: ${needle}`;
const notIncludes = (haystack, needle) => !haystack.includes(needle) || `unexpectedly present: ${needle}`;
const count = (s, sub) => s.split(sub).length - 1;

let pass = 0;
const failures = [];
function check(name, fn) {
    try {
        const ok = fn();
        if (ok !== true) throw new Error(typeof ok === 'string' ? ok : 'assertion returned false');
        pass++;
        console.log(`ok ${pass} - ${name}`);
    } catch (error) {
        failures.push(name);
        console.log(`NOT OK - ${name}: ${error.message}`);
    }
}

/* ------------------------------------------------------------- existence --- */

check('desktop template exists', () => desktop.length > 500 || `length ${desktop.length}`);
check('mobile template exists', () => mobile.length > 500 || `length ${mobile.length}`);
check('stylesheet exists', () => css.length > 1000 || `length ${css.length}`);
check('script exists', () => js.length > 1000 || `length ${js.length}`);

/* ------------------------------------------- the two controls are separate --- */

// The brief: "Users should be able to choose a language and a currency
// independently (for example, English with NGN, USD, EUR or GBP)."
check('desktop renders a language group and a currency group', () =>
    includes(desktopCode, 'data-ch247-switcher-group="language"') &&
    includes(desktopCode, 'data-ch247-switcher-group="currency"'));

check('mobile renders a language group and a currency group', () =>
    includes(mobileCode, 'data-ch247-switcher-group="language"') &&
    includes(mobileCode, 'data-ch247-switcher-group="currency"'));

check('the two triggers have distinct ids', () => {
    const ids = [...desktopCode.matchAll(/id="(ch247-[a-z]+-trigger)"/g)].map((m) => m[1]);
    return (ids.length === 2 && ids[0] !== ids[1]) || `ids: ${JSON.stringify(ids)}`;
});

check('mobile uses its own ids so both can coexist on one page', () => {
    const dIds = [...desktopCode.matchAll(/id="(ch247-[a-z]+-trigger)"/g)].map((m) => m[1]);
    const mIds = [...mobileCode.matchAll(/id="(ch247-[a-z]+-trigger-mobile)"/g)].map((m) => m[1]);
    return mIds.length === 2 && dIds.every((id) => !mIds.includes(id)) ||
        `desktop ${JSON.stringify(dIds)} mobile ${JSON.stringify(mIds)}`;
});

/* -------------------------------------------------------------- a11y ------- */

check('triggers are real <button> elements', () =>
    count(desktopCode, '<button type="button"') === 2 &&
    count(mobileCode, '<button type="button"') === 2);

check('each trigger declares aria-expanded', () =>
    count(desktopCode, 'aria-expanded=') >= 2 || 'fewer than two aria-expanded attributes');

check('each trigger declares aria-controls pointing at its panel', () => {
    const pairs = [...desktopCode.matchAll(/aria-controls="([^"]+)"/g)].map((m) => m[1]);
    return pairs.length === 2 && pairs.every((id) => desktopCode.includes(`id="${id}"`)) ||
        `aria-controls: ${JSON.stringify(pairs)}`;
});

check('panels are labelled by their trigger (no orphan menus)', () => {
    const labels = [...desktopCode.matchAll(/aria-labelledby="([^"]+)"/g)].map((m) => m[1]);
    return labels.every((id) => desktopCode.includes(`id="${id}"`)) ||
        `dangling aria-labelledby: ${JSON.stringify(labels)}`;
});

check('each option carries a real href (works without JS)', () => {
    const optionBlocks = [...desktopCode.matchAll(/<a class="ch247-switcher__option[\s\S]*?>/g)].map((m) => m[0]);
    return optionBlocks.length >= 2 && optionBlocks.every((b) => b.includes('href="')) ||
        `option blocks without href: ${optionBlocks.filter((b) => !b.includes('href="')).length}`;
});

check('the selected option is marked with aria-current', () =>
    includes(desktopCode, 'aria-current="true"') && includes(mobileCode, 'aria-current="true"'));

check('endonyms carry lang= so screen readers pronounce them', () =>
    includes(desktopCode, "lang=\"{$option.lang|escape:'html':'UTF-8'}\""));

check('RTL endonyms get dir="rtl"', () =>
    includes(desktopCode, "{if $option.direction eq 'rtl'}dir=\"rtl\"{/if}"));

check('decorative icons are hidden from assistive tech', () => {
    const svgs = count(desktopCode, '<svg');
    const hidden = count(desktopCode, 'aria-hidden="true"');
    return hidden >= svgs || `${svgs} svg vs ${hidden} aria-hidden`;
});

check('the selected state is announced, not only colour-coded', () =>
    includes(desktopCode, 'class="sr-only"'));

/* --------------------------------------------------------------- CSS ------- */

check('focus is visible on both trigger and options', () =>
    includes(css, '.ch247-switcher__trigger:focus-visible') &&
    includes(css, '.ch247-switcher__option:focus-visible'));

check('a non-:focus-visible fallback keeps focus visible on old browsers', () =>
    includes(css, '.ch247-switcher__trigger:focus {'));

check('the panel cannot force horizontal overflow', () =>
    includes(css, 'max-width: min(20rem, calc(100vw - 2rem))'));

check('the mobile panel is clamped to the viewport', () =>
    includes(css, '.ch247-switcher--mobile .ch247-switcher__panel') &&
    includes(css, 'max-width: calc(100vw - 24px)'));

check('the trigger text ellipsises instead of stretching the header', () =>
    includes(css, 'text-overflow: ellipsis') && includes(css, 'white-space: nowrap'));

check('the panel is only hidden once JS is live (no-JS fallback stays open)', () =>
    includes(css, '.ch247-js .ch247-switcher__panel[hidden]'));

check('tap targets are at least 44px in the mobile variant', () =>
    includes(css, '.ch247-switcher--mobile .ch247-switcher__trigger') &&
    includes(css, 'min-height: 44px'));

check('reduced motion is respected', () => includes(css, 'prefers-reduced-motion: reduce'));

check('forced-colors mode is respected', () => includes(css, 'forced-colors: active'));

check('there are responsive breakpoints', () =>
    includes(css, '@media (max-width: 767px)') && includes(css, '@media (max-width: 479px)'));

/* ---------------------------------------------------------------- JS ------- */

check('script has no external dependencies', () =>
    notIncludes(js, 'jQuery') && notIncludes(js, "require(") && notIncludes(js, "import "));

check('script marks the document as JS-enabled (gates the CSS hiding)', () =>
    includes(js, "classList.add('ch247-js')"));

check('arrow keys move focus while open', () =>
    includes(js, "key === 'ArrowDown'") && includes(js, "key === 'ArrowUp'"));

check('Home / End jump to first / last option', () =>
    includes(js, "key === 'Home'") && includes(js, "key === 'End'"));

check('Escape closes and returns focus to the trigger', () => {
    const block = js.slice(js.indexOf("key === 'Escape'"), js.indexOf("key === 'Escape'") + 400);
    return includes(block, 'trigger.focus()');
});

check('Tab does not leave an orphan panel open', () => includes(js, "key === 'Tab'"));

check('opening one group closes the other', () => includes(js, 'closeOthers'));

check('clicking outside closes the panel', () =>
    includes(js, "document.addEventListener('click', onDocumentClick"));

check('a click on an option is allowed through as a navigation', () =>
    includes(js, 'SELECTORS.option') && includes(js, 'target.closest(SELECTORS.option)'));

check('later-injected switchers are re-enhanced (off-canvas / AJAX)', () =>
    includes(js, 'MutationObserver'));

check('script parses as a plain browser script', () => {
    // vm.Script compiles without executing: a real syntax check with no
    // side effects and no child process.
    try {
        new vm.Script(js, { filename: 'language-currency-switcher.js' });
        return true;
    } catch (error) {
        return `syntax error: ${error.message}`;
    }
});

check('script runs in strict mode', () => includes(js, "'use strict';"));

check('script guards against a missing document (runs before DOM ready)', () =>
    includes(js, "document.readyState === 'loading'"));

/* ---------------------------------------------------------- integration --- */

const headerFiles = [
    'hostx_includes/top-most-header.tpl',
    'hostx_includes/top-mega-menu-default.tpl',
    'hostx_includes/top-mega-menu-latest.tpl',
    'hostx_includes/top-menu-dropdown.tpl',
    'hostx_includes/mobile-menu.tpl',
];

for (const rel of headerFiles) {
    const file = path.join(themeDir, rel);
    check(`${rel} mounts the switcher`, () =>
        exists(file) && /\{include file="\$template\/(includes|hostx_includes)\/language-currency-switcher(-mobile)?\.tpl"\}/.test(read(file)));
}

check('every desktop mount point wraps the component in an <li>', () => {
    for (const rel of headerFiles.slice(0, 4)) {
        const src = read(path.join(themeDir, rel));
        if (!src.includes('ch247-switcher-item')) return `${rel} has no <li> wrapper`;
    }
    return true;
});

// The old control hid the currency selector from logged-in clients. That is the
// concrete bug behind "language and currency must be independent".
check('no header still hides the currency control behind !$loggedin', () => {
    for (const rel of headerFiles) {
        const src = read(path.join(themeDir, rel));
        if (/\{if[^\n]*!\$loggedin[^\n]*\$currencies/.test(src)) {
            return `${rel} still gates the currency on !$loggedin`;
        }
    }
    return true;
});

check('the old languageDiv / currencyDiv markup is gone from the headers', () => {
    for (const rel of headerFiles) {
        const src = stripComments(read(path.join(themeDir, rel)));
        if (src.includes('class="languageDiv"') || src.includes('class="currencyDiv"')) {
            return `${rel} still renders the legacy dropdowns`;
        }
    }
    return true;
});

/* ----------------------------------------------------------------- hook ---- */

check('hook registers ClientAreaPage', () => includes(hook, "add_hook('ClientAreaPage'"));
check('hook injects the stylesheet into <head>', () => includes(hook, "add_hook('ClientAreaHeadOutput'"));
check('hook injects the script at the end of the body', () => includes(hook, "add_hook('ClientAreaFooterOutput'"));
check('hook merges the catalog into $LANG', () => includes(hook, '$_LANG = array_merge($messages, $_LANG);'));
check('hook applies ?language= and ?currency= independently', () => includes(hook, '$instance->applyRequest($_GET);'));
check('hook never invents rates: it passes no currency source, so I18n reads WHMCS', () => {
    const i18n = read(path.join(repoRoot, 'includes', 'i18n', 'src', 'I18n.php'));
    return (notIncludes(hook, "'currency_source'") &&
        includes(i18n, 'new WhmcsCurrencySource(')) || 'hook supplies its own currency source, or I18n no longer defaults to WHMCS';
});
check('hook reuses the equivalent-page helper so switching keeps the page', () => includes(hook, 'EquivalentUrl::canonical'));
check('hook advertises hreflang alternates', () => includes(hook, 'ch247_i18n_alternates'));
check('hook refuses to rewrite an invoice or client currency', () =>
    includes(hook, "!empty($vars['invoice']['currency'])") &&
    includes(hook, "!empty($vars['clientsdetails']['currency'])"));

/* -------------------------------------------------------------- catalog ---- */

const catalogDir = path.join(repoRoot, 'lang', 'site');
const expected = ['en.php', 'fr.php', 'es.php', 'de.php', 'pt-BR.php'];
for (const file of expected) {
    check(`catalog ${file} exists`, () => exists(path.join(catalogDir, file)));
}

check('the repo has no hard-coded currency list in the catalogs', () => {
    for (const file of expected) {
        const src = read(path.join(catalogDir, file));
        if (/"(NGN|USD|EUR|GBP)"/.test(src)) {
            return `${file} hard-codes a currency code`;
        }
    }
    return true;
});

/* -------------------------------------------------------------- summary ---- */

console.log('');
if (failures.length) {
    console.log(`RESULT ${pass} passed, ${failures.length} failed`);
    console.log(failures.map((f) => `  - ${f}`).join('\n'));
    process.exit(1);
}
console.log(`RESULT ${pass} passed, 0 failed`);
