#!/usr/bin/env node
/**
 * Announcement Bar tests (Module 10 / B-15).
 *
 * First automated harness for `templates/hostx/`: static conformance checks
 * for `includes/announcementbar.tpl` against `docs/Announcement Bar/Build.txt`
 * and the Module 10 record, plus regression pins for AB-1, AB-2 and AB-4 and
 * a guard on AB-3's "server-assigned data only" precondition.
 *
 * Run:  node templates/hostx/tests/announcementbar.test.mjs
 * Needs only Node.js (no npm install). Exit code 0 when every check passes.
 *
 * Smarty executes only inside WHMCS, so these checks are static by necessity:
 * they verify the source says what the audit record claims it says. The one
 * thing they cannot do is render pixels — the staging visual check in the
 * Module 10 record still stands.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const testsDir = path.dirname(fileURLToPath(import.meta.url));
const themeDir = path.resolve(testsDir, '..');
const repoRoot = path.resolve(themeDir, '..', '..');
const read = (p) => fs.readFileSync(p, 'utf8');

const barPath = path.join(themeDir, 'includes', 'announcementbar.tpl');
const bar = read(barPath);
const header = read(path.join(themeDir, 'header.tpl'));
const examples = read(path.join(themeDir, 'includes', 'announcementbar-examples.tpl'));
const integration = read(path.join(themeDir, 'includes', 'announcementbar-header-integration.tpl'));

const stripSmartyComments = (s) => s.replace(/\{\*[\s\S]*?\*\}/g, '');
const barCode = stripSmartyComments(bar);
const styleMatch = bar.match(/<style>([\s\S]*?)<\/style>/);
const css = styleMatch ? styleMatch[1] : '';
const cssCode = css.replace(/\/\*[\s\S]*?\*\//g, '');
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
const includes = (haystack, needle) => haystack.includes(needle) || `missing: ${needle}`;

// ---------------------------------------------------------------- files ---
check('bar template exists and is non-trivial', () => bar.length > 1000 || `length ${bar.length}`);
check('snippet files exist', () => (examples.length > 500 && integration.length > 500) || 'examples/integration too small');
check('header.tpl includes the bar', () =>
    includes(header, '{include file="$template/includes/announcementbar.tpl"}'));
check('include sits after <body>, above the menu layer', () => {
    const body = header.indexOf('<body');
    const inc = header.indexOf('includes/announcementbar.tpl');
    const mobile = header.indexOf('mobile-menu.tpl');
    const topmenu = header.indexOf('top-menu-header.tpl');
    return (body !== -1 && body < inc && inc < mobile && inc < topmenu) ||
        `order body=${body} include=${inc} mobile=${mobile} topmenu=${topmenu}`;
});
check('bar renders nothing without data (isset/is_array/count guard)', () =>
    includes(barCode, '{if isset($announcementBarMessages) && is_array($announcementBarMessages) && count($announcementBarMessages) > 0}'));
check('guard closes the file', () => bar.trimEnd().endsWith('{/if}') || 'file does not end with {/if}');
check('no PHP in the template', () => !bar.includes('<?php') || 'contains <?php');

// ------------------------------------------------- spec conformance (B) ---
check('no <script> (Smarty + HTML + CSS only)', () => !/<script/i.test(bar) || 'contains <script');
check('no javascript: literal', () => !/javascript:/i.test(bar) || 'contains javascript:');
check('messages iterate the namespaced array', () =>
    includes(barCode, '{foreach from=$announcementBarMessages item=announcement}'));
check('keyframes scroll 0 to -50%', () =>
    (includes(css, '@keyframes announcement-scroll') === true &&
        includes(css, 'translateX(0)') === true &&
        includes(css, 'translateX(-50%)') === true) || 'keyframes incomplete');
check('no marquee, no slider library, no static external URL', () => {
    if (/\b(marquee|swiper|slick|owl-carousel|splide|flickity|glide\.js)\b/i.test(bar)) return 'marquee/slider reference found';
    if (/https?:\/\//.test(bar)) return 'static external URL found';
    return true;
});
check('seamless loop: content duplicated, -50% translate', () =>
    (includes(barCode, '{section name=loop loop=2}') === true && includes(css, '-50%') === true) ||
    'loop=2 / -50% pairing broken');
check('pause on hover', () => {
    const sel = css.indexOf('.announcement-bar:hover');
    const paused = css.indexOf('animation-play-state: paused');
    return (sel !== -1 && paused > sel && paused - sel < 200) || `hover=${sel} paused=${paused}`;
});
check('clickable link branch and plain span branch', () =>
    (barCode.includes('<a href=') && barCode.includes('{else}') &&
        barCode.includes('<span class="announcement-bar__item"')) || 'branches incomplete');
check('responsive breakpoints (tablet + phone)', () =>
    (includes(css, '@media (max-width: 767.98px)') === true &&
        includes(css, '@media (max-width: 575.98px)') === true) || 'breakpoints missing');
check('overflow guards', () =>
    (includes(css, 'overflow: hidden') === true && includes(css, 'white-space: nowrap') === true &&
        includes(css, 'flex-shrink: 0') === true) || 'overflow guards missing');
check('keyframes are actually applied to the track', () =>
    /animation:\s*announcement-scroll\s+30s\s+linear\s+infinite/.test(css) || 'animation shorthand missing');

// ------------------------------------------------------- good practice ---
check('every interpolation is escaped (except $dup)', () => {
    const found = barCode.match(/\{\$[^}]+\}/g) || [];
    if (found.length === 0) return 'no interpolations found';
    const bad = found.filter((tag) => tag !== '{$dup}' && !tag.includes('|escape'));
    return bad.length === 0 || `unescaped: ${bad.join(', ')}`;
});
check('$dup value domain is pinned to two static literals', () =>
    (includes(barCode, "{assign var=dup value=' aria-hidden=\"true\"'}") === true &&
        includes(barCode, "{assign var=dup value=''}") === true &&
        count(barCode, '{$dup}') === 2) || '$dup domain/count drifted');
check('every target=_blank carries rel=noopener', () => {
    const blanks = count(barCode, 'target="_blank"');
    const rels = count(barCode, 'rel="noopener noreferrer"');
    return (blanks > 0 && blanks === rels) || `blank=${blanks} rel=${rels}`;
});
check('decorative dots and icons are aria-hidden', () => {
    if (!barCode.includes('<span class="announcement-bar__dot" aria-hidden="true">')) return 'dot not hidden';
    const icons = barCode.match(/<i[^>]*>/g) || [];
    if (icons.length === 0) return 'no icons found';
    const exposed = icons.filter((tag) => !tag.includes('aria-hidden="true"'));
    return exposed.length === 0 || `exposed icons: ${exposed.join(', ')}`;
});
check('region role + label', () =>
    (barCode.includes('role="region"') && barCode.includes('aria-label="Announcements"')) || 'region labelling missing');
check('prefers-reduced-motion disables the animation', () => {
    const media = css.indexOf('@media (prefers-reduced-motion: reduce)');
    const none = css.indexOf('animation: none');
    return (media !== -1 && none > media && none - media < 500) || `media=${media} none=${none}`;
});
check('dark-mode block present (theme consistency)', () =>
    includes(css, '@media (prefers-color-scheme: dark)'));
check('z-index stays below the modal layer', () => {
    const m = css.match(/z-index:\s*(\d+)/);
    if (!m) return 'no z-index found';
    return Number(m[1]) < 1050 || `z-index ${m[1]} reaches into modal territory`;
});
check('no !important (clean cascade)', () => !css.includes('!important') || 'contains !important');

// ------------------------------------------------- AB-1 / AB-2 regression --
check('AB-1: no ::after bullet rule (comments stripped)', () =>
    !cssCode.includes('::after') || '::after present outside comments');
check('AB-1: exactly one dot separator per loop pass', () => {
    const body = barCode.split('{foreach')[1].split('{/foreach}')[0];
    return count(body, 'announcement-bar__dot') === 1 || 'dot count drifted';
});
check('AB-2: second loop pass is gated on section index', () =>
    includes(barCode, '$smarty.section.loop.index > 0'));
check('AB-2: $dup applied to both the link and span branches', () =>
    (/<a[^>]*\{\$dup\}/.test(barCode) && /<span[^>]*\{\$dup\}/.test(barCode)) || '$dup missing on a branch');

// --------------------------------------------- AB-3 precondition (data) ---
check('AB-3: no request input reaches the bar template', () =>
    !/(\$_GET|\$_POST|\$_REQUEST|\$smarty\.(get|post|request))\b/.test(bar) || 'request input in template');
check('AB-3: snippet data is static (no request input)', () =>
    !/(\$_GET|\$_POST|\$_REQUEST|\$smarty\.(get|post|request))\b/.test(examples + integration) ||
    'request input in snippets');
check('AB-3: every shipped example URL uses a safe scheme', () => {
    const urls = [...(examples + integration).matchAll(/'url'\s*=>\s*'([^']+)'/g)].map((m) => m[1]);
    if (urls.length === 0) return 'no example URLs found';
    const bad = urls.filter((u) => !/^(\/|https:\/\/)/.test(u) || /javascript:/i.test(u));
    return bad.length === 0 || `unsafe example URLs: ${bad.join(', ')}`;
});
check('AB-3: href stays escaped', () =>
    includes(barCode, 'href="{$announcement.url|escape:\'html\'}"'));
check('AB-3: no PHP in the repo feeds the bar (tripwire)', () => {
    const hits = [];
    const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) { walk(full); continue; }
            if (!entry.name.endsWith('.php')) continue;
            if (read(full).includes('announcementBarMessages')) hits.push(path.relative(repoRoot, full));
        }
    };
    for (const dir of ['modules', 'includes', 'crons']) walk(path.join(repoRoot, dir));
    return hits.length === 0 ||
        `repo PHP now feeds the bar — audit its source before trusting it: ${hits.join(', ')}`;
});

// ------------------------------------------------------- AB-4 regression --
check('AB-4: bar reads the namespaced array, not core $announcements', () =>
    barCode.includes('from=$announcementBarMessages') || 'foreach source drifted');
check('AB-4: no bare $announcements outside comments', () =>
    !/\$announcements\b/.test(barCode) || 'bare $announcements in bar code');
check('AB-4: the name is assigned only in the four known theme files', () => {
    const hits = [];
    const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) { walk(full); continue; }
            if (!entry.name.endsWith('.tpl')) continue;
            if (read(full).includes('announcementBarMessages')) hits.push(path.relative(themeDir, full));
        }
    };
    walk(themeDir);
    const expected = [
        'header.tpl',
        path.join('includes', 'announcementbar.tpl'),
        path.join('includes', 'announcementbar-examples.tpl'),
        path.join('includes', 'announcementbar-header-integration.tpl'),
    ].sort();
    const actual = hits.sort();
    return JSON.stringify(actual) === JSON.stringify(expected) || `scope drift: ${actual.join(', ')}`;
});
check('AB-4: core announcements page still owns $announcements (collision partner)', () =>
    includes(read(path.join(themeDir, 'announcements.tpl')), '{foreach from=$announcements item=announcement}'));

// ------------------------------------------------------------- structure --
check('CSS braces balance', () => {
    const open = count(cssCode, '{');
    const close = count(cssCode, '}');
    return (open > 0 && open === close) || `{=${open} }=${close}`;
});
check('Smarty block tags balance', () => {
    for (const tag of ['if', 'foreach', 'section']) {
        const open = (barCode.match(new RegExp(`\\{${tag}[}\\s]`, 'g')) || []).length;
        const close = count(barCode, `{/${tag}}`);
        if (open === 0 || open !== close) return `${tag}: open=${open} close=${close}`;
    }
    return true;
});
check('exactly one style block', () =>
    (count(bar, '<style>') === 1 && count(bar, '</style>') === 1) || 'style block count drifted');

console.log('──────────────────────────────');
console.log(`PASS=${pass} FAIL=${failures.length}`);
process.exit(failures.length === 0 ? 0 : 1);
