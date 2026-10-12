# Real-template i18n preview

Developer/staging tooling only; not a WHMCS runtime or a production dependency.

```sh
npm ci --prefix staging/i18n-preview
node staging/i18n-preview/build.mjs          # native PHP, otherwise php-wasm
node staging/i18n-preview/server.mjs         # 0.0.0.0:4321
npm run test:browser --prefix staging/i18n-preview
```

`PORT=8081` changes the port. `--wasm` forces the PHP-wasm builder. Build downloads
Smarty **4.5.6** from its official GitHub release, checks its pinned SHA-256 and
extracts into gitignored `.cache/`. Production already has Smarty through WHMCS;
none of this dev engine or its compiler cache is deployed. The old custom
Smarty-subset parser was removed: real compiler semantics now test nested
conditions/loops, assignments, modifiers and escaping.

| File | Purpose |
|---|---|
| `prepare.mjs` | Hash-verified, dev-only Smarty preparation |
| `smarty-preview.php` | Tiny adapter around the real Smarty compiler |
| `render.php` | Real library + real template rendering against explicit fixtures |
| `build.mjs` | Native-PHP/PHP-wasm build runner |
| `server.mjs` | Static snapshot server, equivalent native-URI navigation and fixture preference cookie |
| `browser-test.mjs` | Chromium + axe responsive/keyboard/no-JS verification |
| `public/pages/` | 325 generated JSON snapshots (gitignored) |
| `../artifacts/` | Generated browser results (gitignored) |

The eight body templates are legal, FAQ, refund, backup, cybercrime, trademark,
help and terms. Other contexts exercise the switcher/pricing library, not a real
cart/account/invoice backend. NGN/USD/EUR/GBP rates are deterministic fixtures;
JPY intentionally has no rate. No prices or exchange rates are fetched live.

Browser tests use pinned dev dependencies from npm. To use an installed local
Chrome, set `BROWSER_EXECUTABLE=/path/to/chrome`; otherwise the packaged headless
Chromium is used. Minimal Linux sandboxes can use its bundled NSS libraries.
`PREVIEW_URL` changes the review origin. `BROWSER_DEPS` can point at a separate
node_modules directory to keep QA dependencies outside the checkout.

Current browser gate: 592 passing checks across all five locales at 320/375/768/
1280px, including WCAG 2/2.1 AA axe scans of the switcher. Real-Smarty render
checks separately cover all desktop layouts in public/account/cart contexts and
protect the approved English wording. See `docs/I18N_STATUS.md` for remaining
site-wide coverage and `staging/README.md` for licensed-WHMCS verification.
