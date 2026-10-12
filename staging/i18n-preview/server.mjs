#!/usr/bin/env node
/**
 * CloudHost247 — i18n staging preview server.
 *
 * Serves the snapshots produced by build.mjs / render.php so the language and
 * currency switcher can be reviewed in a browser: desktop, tablet and phone
 * widths, keyboard navigation, focus rings and the real stylesheet and script
 * from templates/hostx/.
 *
 *   node staging/i18n-preview/server.mjs            # http://0.0.0.0:4321
 *   PORT=8080 node staging/i18n-preview/server.mjs
 *
 * No dependencies and no network access. This is a REVIEW harness: it renders
 * the real templates against fixture data. It is not WHMCS, it has no database,
 * and it must never be pointed at production. See README.md and the
 * docker-compose recipe for a full WHMCS staging environment.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(here, '..', '..');
const themeDir = path.join(repoRoot, 'templates', 'hostx');
const publicDir = path.join(here, 'public');
const pagesDir = path.join(publicDir, 'pages');

const PORT = Number(process.env.PORT || 4321);
const HOST = process.env.HOST || '0.0.0.0';

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.woff2': 'font/woff2',
};

const escapeHtml = (value) =>
    String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

/* ------------------------------------------------------------------ pages -- */

function readPageIndex() {
    const indexFile = path.join(pagesDir, 'index.json');
    if (!fs.existsSync(indexFile)) {
        return [];
    }
    return JSON.parse(fs.readFileSync(indexFile, 'utf8'));
}

function readPage(name) {
    // name is user-supplied: keep it to safe characters and resolve inside pagesDir.
    if (!/^[A-Za-z0-9._-]+$/.test(name)) {
        return null;
    }
    const file = path.join(pagesDir, `${name}.json`);
    if (!fs.existsSync(file)) {
        return null;
    }
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/* ------------------------------------------------------------------- html -- */

function layout(title, body, lang = 'en', dir = 'ltr') {
    return `<!doctype html>
<html lang="${escapeHtml(lang)}" dir="${escapeHtml(dir)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>${escapeHtml(title)}</title>
<link rel="stylesheet" href="/assets/theme/css/language-currency-switcher.css">
<link rel="stylesheet" href="/assets/preview.css">
</head>
<body>
${body}
<script src="/assets/theme/js/language-currency-switcher.js" defer></script>
</body>
</html>`;
}

function indexHtml() {
    const pages = readPageIndex();
    if (!pages.length) {
        return layout('No snapshots', `<main class="wrap"><h1>No snapshots yet</h1>
<p>Run <code>node staging/i18n-preview/build.mjs</code> first.</p></main>`);
    }

    const locales = [...new Set(pages.map((p) => p.locale))];
    const currencies = [...new Set(pages.map((p) => p.currency))];
    const pageKeys = [...new Set(pages.map((p) => p.page))];

    const table = pages
        .map(
            (p) => `<tr>
<td><a href="/p/${escapeHtml(p.name)}">${escapeHtml(p.locale_name)}</a></td>
<td>${escapeHtml(p.locale)}</td>
<td>${escapeHtml(p.currency)}</td>
<td>${escapeHtml(p.page_title)}</td>
<td>${p.dir === 'rtl' ? 'RTL' : 'LTR'}</td>
</tr>`
        )
        .join('\n');

    return layout(
        'CloudHost247 — i18n staging preview',
        `<header class="preview-bar">
  <strong>CloudHost247</strong> · language &amp; currency switcher · staging preview
</header>
<main class="wrap">
  <h1>Switcher snapshots</h1>
  <p class="lede">
    ${pages.length} combinations of <strong>${locales.join(', ')}</strong> ×
    <strong>${currencies.join(', ')}</strong> ×
    <strong>${pageKeys.join(', ')}</strong>, rendered from the real templates
    (<code>templates/hostx/includes/language-currency-switcher.tpl</code>) with
    the real <code>includes/i18n</code> library and fixture currencies.
  </p>
  <p class="note">
    This is a review harness, not WHMCS: no database, no live rates. Prices come
    from a deterministic fixture. JPY is deliberately left without a rate so the
    "no rate — do not convert, do not invent one" path is visible.
  </p>
  <table class="grid">
    <thead><tr><th>Language</th><th>Code</th><th>Currency</th><th>Page</th><th>Dir</th></tr></thead>
    <tbody>
${table}
    </tbody>
  </table>
${detectionTable(pagesDir)}
</main>`
    );
}

function detectionTable(dir) {
    const file = path.join(dir, 'detection.json');
    if (!fs.existsSync(file)) {
        return '';
    }
    const scenarios = JSON.parse(fs.readFileSync(file, 'utf8'));
    if (!scenarios.length) {
        return '';
    }

    const rows = scenarios
        .map(
            (s) => `<tr>
<td><a href="/p/${escapeHtml(s.name)}">${escapeHtml(s.label)}</a></td>
<td><code>${escapeHtml(s.accept_language ?? '')}</code></td>
<td><code>${escapeHtml(s.ip ?? '')}</code></td>
<td><code>${escapeHtml(String(s.country ?? 'unknown'))}</code></td>
<td><strong>${escapeHtml(s.language)}</strong> <span class="muted">(${escapeHtml(s.source_language)})</span></td>
<td><strong>${escapeHtml(s.currency)}</strong> <span class="muted">(${escapeHtml(s.source_currency)})</span></td>
<td>${escapeHtml(s.expect)}</td>
</tr>`
        )
        .join('\n');

    return `
  <h2 style="margin-top:28px">Automatic detection scenarios</h2>
  <p class="lede">
    Each row is the same first page view, rendered by the real
    <code>Detector</code>: the language comes from the browser, the currency from
    the visitor's country filtered through the currencies WHMCS has enabled.
    A saved preference wins over both.
  </p>
  <table class="grid">
    <thead><tr><th>Scenario</th><th>Accept-Language</th><th>IP</th><th>Country</th><th>Language</th><th>Currency</th><th>Expected</th></tr></thead>
    <tbody>
${rows}
    </tbody>
  </table>`;
}

function pageHtml(name) {
    const page = readPage(name);
    if (!page) {
        return layout('Not found', `<main class="wrap"><h1>Not found</h1><p><a href="/">Back to the index</a></p></main>`);
    }

    const backHref = '/';
    const priceRows = Object.values(page.prices || {})
        .map((p) => {
            if (!p.available) {
                return `<tr>
<td><code>${escapeHtml(p.slug)}</code></td>
<td colspan="3"><em>no rate available — nothing is shown rather than a made-up number</em></td>
</tr>`;
            }
            const badge = p.indicative
                ? `<span class="badge badge-indicative">indicative</span>`
                : `<span class="badge badge-exact">exact</span>`;
            const converted = p.converted_from
                ? ` <span class="muted">converted from ${escapeHtml(p.converted_from)}</span>`
                : '';
            return `<tr>
<td><code>${escapeHtml(p.slug)}</code></td>
<td><strong>${escapeHtml(p.display)}</strong> ${badge}${converted}</td>
<td>${escapeHtml(p.transaction)}</td>
<td class="muted">${escapeHtml(p.transaction_code)}</td>
</tr>`;
        })
        .join('\n');

    const renderedBody = page.body ? `<section class="panel actual-page"><h2>Actual translated page template</h2>${page.body}</section>` : '';
    const detectionPanel = page.scenario
        ? `<section class="panel">
    <h2>Automatic detection</h2>
    <p class="lede">${escapeHtml(page.label)}</p>
    <table class="grid">
      <tbody>
        <tr><th>Accept-Language</th><td><code>${escapeHtml(page.accept_language)}</code></td></tr>
        <tr><th>IP (fixture)</th><td><code>${escapeHtml(page.ip)}</code></td></tr>
        <tr><th>Country resolved</th><td><code>${escapeHtml(String(page.country ?? 'unknown'))}</code></td></tr>
        <tr><th>Language chosen</th><td><strong>${escapeHtml(page.language_name)} (${escapeHtml(page.language)})</strong> <span class="muted">from ${escapeHtml(page.language_source)}</span></td></tr>
        <tr><th>Currency chosen</th><td><strong>${escapeHtml(page.currency)}</strong> <span class="muted">from ${escapeHtml(page.currency_source)}</span></td></tr>
        <tr><th>Expected</th><td>${escapeHtml(page.expect)}</td></tr>
      </tbody>
    </table>
    ${page.message ? `<p class="note">${escapeHtml(page.message)}</p>` : ''}
    <p class="hint">${page.detected
        ? 'Both settings were detected automatically. Every option below is a real link that overrides them.'
        : 'Nothing was detected as new: a saved preference or the default is in use, so no automatic-choice notice is shown.'}</p>
  </section>`
        : '';
    const body = `
<header class="preview-bar">
  <a href="${backHref}">← all snapshots</a>
  <span class="crumb">${escapeHtml(page.locale_name)} (${escapeHtml(page.locale)}) ·
  ${escapeHtml(page.currency)} · ${escapeHtml(page.page_title)}</span>
  <span class="spacer"></span>
  <span class="crumb muted">canonical: <code>${escapeHtml(page.canonical)}</code></span>
</header>

<main class="wrap">

  ${renderedBody}
  ${detectionPanel}
  <section class="panel">
    <h2>Desktop header</h2>
    <div class="device" data-device="desktop">
      <div class="fake-header">
        <div class="fake-logo">CloudHost247</div>
        <nav class="fake-nav" aria-label="Example navigation">
          <a href="#">Hosting</a><a href="#">Domains</a><a href="#">Tools</a><a href="#">Support</a>
        </nav>
        <div class="fake-right">
          ${page.desktop}
        </div>
      </div>
    </div>
    <p class="hint">
      The switcher sits in the header's right-hand group, next to the account
      links. Its width is capped and the label ellipsises, so a long endonym
      cannot stretch the header or shift the mega menu.
    </p>
  </section>

  <section class="panel">
    <h2>Mobile / off-canvas</h2>
    <div class="device" data-device="mobile">
      <div class="fake-drawer">
        <div class="drawer-title">Menu</div>
        ${page.mobile}
        <nav class="drawer-nav" aria-label="Example mobile navigation">
          <a href="#">Hosting</a><a href="#">Domains</a><a href="#">Tools</a><a href="#">Support</a>
        </nav>
      </div>
    </div>
    <p class="hint">
      Same component, full width, 44&nbsp;px tap targets, panel clamped to the
      viewport so nothing can cause horizontal overflow at 320&nbsp;px.
    </p>
  </section>

  <section class="panel">
    <h2>Prices in this locale and currency</h2>
    <table class="grid">
      <thead><tr><th>Product</th><th>Displayed</th><th>Charged</th><th>Charge currency</th></tr></thead>
      <tbody>
${priceRows}
      </tbody>
    </table>
    <p class="hint">
      A price with no catalogue entry in the selected currency is converted and
      labelled <strong>indicative</strong>; the amount actually charged stays in
      the currency that has a real price. Where no rate exists, nothing is shown
      at all — no rate is ever invented.
    </p>
    ${page.transaction_currency ? `<p class="note">This page is an <strong>invoice</strong>: its own currency (${escapeHtml(page.transaction_currency)}) is preserved regardless of the browsing currency.</p>` : ''}
  </section>

  <section class="panel">
    <h2>Locale-aware formatting</h2>
    <dl class="samples">
      <dt>Number</dt><dd><code>${escapeHtml(page.sample?.number ?? 'n/a')}</code></dd>
      <dt>Decimal</dt><dd><code>${escapeHtml(page.sample?.decimal ?? 'n/a')}</code></dd>
      <dt>Percent</dt><dd><code>${escapeHtml(page.sample?.percent ?? 'n/a')}</code></dd>
      <dt>Date (medium)</dt><dd><code>${escapeHtml(page.sample?.date_medium ?? 'n/a')}</code></dd>
      <dt>Date (full)</dt><dd><code>${escapeHtml(page.sample?.date_full ?? 'n/a')}</code></dd>
      <dt>Rate source</dt><dd><code>${escapeHtml(Object.values(page.prices || {})[0]?.rate_source || 'n/a')}</code></dd>
    </dl>
  </section>

  <section class="panel">
    <h2>Keyboard checks</h2>
    <ol class="steps">
      <li><kbd>Tab</kbd> to the language button, then <kbd>Enter</kbd> or <kbd>Space</kbd> to open it.</li>
      <li><kbd>↓</kbd> / <kbd>↑</kbd> move between options; <kbd>Home</kbd> / <kbd>End</kbd> jump to first / last.</li>
      <li><kbd>Esc</kbd> closes and returns focus to the button.</li>
      <li>Opening one control closes the other; clicking outside closes it.</li>
      <li>Disable JavaScript and reload: the panels stay open and the links still work.</li>
    </ol>
  </section>

</main>`;

    return layout(
        `${page.locale_name} · ${page.currency} · ${page.page_title}`,
        body,
        page.lang,
        page.dir
    );
}

/* ----------------------------------------------------------------- server -- */

const server = http.createServer((req, res) => {
    let pathname, requestUrl;
    try {
        requestUrl = new URL(req.url, 'http://preview.invalid');
        pathname = decodeURIComponent(requestUrl.pathname);
    } catch (error) {
        res.writeHead(400).end('Bad request');
        return;
    }

    // Static theme assets, served straight out of templates/hostx so the
    // preview can never drift from what actually ships.
    if (pathname.startsWith('/assets/theme/')) {
        const relative = pathname.slice('/assets/theme/'.length);
        const file = path.join(themeDir, relative);
        if (!file.startsWith(themeDir + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
            res.writeHead(404).end('Not found');
            return;
        }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
        fs.createReadStream(file).pipe(res);
        return;
    }

    if (pathname === '/assets/preview.css') {
        res.writeHead(200, { 'Content-Type': MIME['.css'] });
        res.end(previewCss());
        return;
    }

    if (pathname === '/' || pathname === '/index.html') {
        res.writeHead(200, { 'Content-Type': MIME['.html'] });
        res.end(indexHtml());
        return;
    }

    const index = readPageIndex();
    const cookies = Object.fromEntries((req.headers.cookie || '').split(';').filter(Boolean).map((part) => {
        const at = part.indexOf('='); return [part.slice(0, at).trim(), part.slice(at + 1)];
    }));
    let previous;
    try { previous = JSON.parse(decodeURIComponent(cookies.ch247_preview_state || '{}')); } catch { previous = {}; }
    const refererName = (req.headers.referer || '').match(/\/p\/([A-Za-z0-9._-]+)/)?.[1];
    const referred = refererName ? index.find((p) => p.name === refererName) : null;
    const candidate = referred || previous || {};
    const state = {
        locale: typeof candidate.locale === 'string' && index.some((p) => p.locale === candidate.locale) ? candidate.locale : 'en',
        currency: typeof candidate.currency === 'string' && index.some((p) => p.currency === candidate.currency) ? candidate.currency : index[0]?.currency,
    };
    const nativePage = index.find((p) => new URL(p.uri || '/', 'http://preview.invalid').pathname === pathname);
    if (nativePage) {
        const requestedLanguage = (requestUrl.searchParams.get('language') || state.locale || 'en').toLowerCase();
        const language = index.find((p) => p.locale.toLowerCase() === requestedLanguage || p.language_value === requestedLanguage)?.locale
            || state.locale || 'en';
        const requestedCurrency = requestUrl.searchParams.get('currency') || state.currency || index[0].currency;
        const currency = index.find((p) => String(p.currency_id) === requestedCurrency || p.currency === requestedCurrency.toUpperCase())?.currency
            || state.currency || index[0].currency;
        const destination = index.find((p) => p.page === nativePage.page && p.locale === language && p.currency === currency) || nativePage;
        res.writeHead(302, { Location: `/p/${destination.name}` });
        res.end();
        return;
    }

    if (pathname.startsWith('/p/')) {
        const name = pathname.slice(3);
        const snapshot = readPage(name);
        const headers = { 'Content-Type': MIME['.html'] };
        if (snapshot) {
            headers['Set-Cookie'] = `ch247_preview_state=${encodeURIComponent(JSON.stringify({ locale: snapshot.locale, currency: snapshot.currency }))}; Path=/; HttpOnly; SameSite=Lax`;
        }
        res.writeHead(snapshot ? 200 : 404, headers);
        res.end(pageHtml(name));
        return;
    }

    res.writeHead(404, { 'Content-Type': MIME['.html'] });
    res.end(layout('Not found', '<main class="wrap"><h1>Not found</h1></main>'));
});

function previewCss() {
    return `
:root { --ink:#17494d; --red:#f51322; --line:#e3e6e8; --muted:#6b7280; }
* { box-sizing: border-box; }
body { margin:0; font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:#404040; background:#f4f6f7; }
.preview-bar { display:flex; flex-wrap:wrap; overflow-wrap:anywhere; align-items:center; gap:12px; padding:10px 18px; background:var(--ink); color:#fff; font-size:13px; }
.preview-bar a { color:#fff; text-decoration:none; border-bottom:1px solid rgba(255,255,255,.4); }
.preview-bar .spacer { flex:1; }
.preview-bar .crumb a { color:#fff; }
.wrap { max-width:1100px; margin:0 auto; padding:24px 18px 64px; }
h1 { font-size:24px; color:var(--ink); margin:0 0 8px; }
h2 { font-size:17px; color:var(--ink); margin:0 0 12px; }
.lede { color:#525152; margin:0 0 12px; }
.note, .hint { color:var(--muted); font-size:13px; }
.note { background:#fff8e6; border-left:3px solid #f0ad4e; padding:8px 12px; margin:12px 0; }
.panel { background:#fff; border:1px solid var(--line); border-radius:10px; padding:18px; margin:0 0 18px; }
.device { overflow:visible; border:1px dashed #c9ced2; border-radius:8px; padding:12px; background:#fafbfb; }
.device[data-device="desktop"] { max-width:100%; }
.device[data-device="mobile"] { max-width:360px; }
.fake-header { display:flex; flex-wrap:wrap; align-items:center; gap:18px; padding:10px 14px; background:#fff; border-bottom:1px solid var(--line); min-width:0; }
.fake-logo { font-weight:800; color:var(--red); font-size:17px; white-space:nowrap; }
.fake-nav { display:flex; gap:14px; flex:1; }
.fake-nav a { color:#404040; text-decoration:none; font-size:14px; }
.fake-right { display:flex; align-items:center; gap:10px; }
.fake-drawer { background:#fff; }
.drawer-title { font-weight:700; color:var(--ink); padding:6px 2px 10px; }
.drawer-nav { display:flex; flex-direction:column; }
.drawer-nav a { padding:10px 2px; border-top:1px solid #f1f3f4; color:#404040; text-decoration:none; }
table.grid { overflow-wrap:anywhere; width:100%; border-collapse:collapse; font-size:14px; }
table.grid th, table.grid td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--line); }
table.grid th { color:var(--ink); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.badge { display:inline-block; padding:1px 7px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
.badge-indicative { background:#fff3cd; color:#8a4b00; }
.badge-exact { background:#e3f3e6; color:#1d6b2c; }
.muted { color:var(--muted); }
dl.samples { display:grid; grid-template-columns:minmax(0, max-content) minmax(0, 1fr); gap:6px 18px; margin:0; font-size:14px; }
dl.samples dt { color:var(--muted); }
dl.samples dd { margin:0; }
ol.steps { margin:0; padding-left:20px; font-size:14px; }
ol.steps li { margin-bottom:5px; }
.actual-page { overflow-wrap:anywhere; }
.actual-page .container { width:100%; max-width:100%; padding-inline:12px; }
kbd { background:#f1f3f4; border:1px solid #d8dcde; border-bottom-width:2px; border-radius:4px; padding:1px 5px; font:12px ui-monospace,SFMono-Regular,Menlo,monospace; }
code { overflow-wrap:anywhere; word-break:break-word; background:#f1f3f4; padding:1px 5px; border-radius:4px; font:13px ui-monospace,SFMono-Regular,Menlo,monospace; }
`;
}

server.listen(PORT, HOST, () => {
    const count = readPageIndex().length;
    console.log(`CloudHost247 i18n preview: http://localhost:${PORT}/  (${count} snapshots)`);
    if (!count) {
        console.log('No snapshots yet — run: node staging/i18n-preview/build.mjs');
    }
});
