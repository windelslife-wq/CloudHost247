# i18n preview harness

Renders the real switcher templates with the real i18n library and serves them
for review in a browser.

```bash
node build.mjs            # render 125 snapshots (uses `php` if present)
node build.mjs --wasm     # no PHP installed? uses the vendored php-wasm
node server.mjs           # serve on http://0.0.0.0:4321
```

`PORT=8080 node server.mjs` to change the port.

## Files

| File | Role |
|---|---|
| `render.php` | Builds the view-model with `includes/i18n` and writes `public/pages/*.json`. |
| `smarty-lite.php` | Minimal Smarty-subset renderer for the shipped `.tpl` files. |
| `build.mjs` | Runs `render.php` through native PHP or php-wasm. |
| `server.mjs` | Dependency-free HTTP server for the snapshots + real theme CSS/JS. |
| `public/pages/` | Generated. Safe to delete; rebuild with `build.mjs`. |

## Why a Smarty renderer

The alternative was a hand-written HTML mockup of the switcher. A mockup drifts:
it keeps passing review after the real template has changed, which is exactly the
failure mode the brief warns about ("a dropdown that only changes its label" is
not acceptable). So the harness renders the actual
`templates/hostx/includes/language-currency-switcher.tpl` through a small
renderer that understands the subset of Smarty those templates use:

```
{* comments *}   {if}/{else}/{/if}   {foreach}   {$var}   {$var.key}
{$var|escape:'html':'UTF-8'}   {$var|replace:'a':'b'}   {include file="…"}
```

Conditionals are resolved with depth-aware matching, not a lazy regex: a lazy
regex pairs the first `{if}` with the first `{/if}`, which silently swallowed
the rest of the template the moment one `{if}` nested inside another.

> `smarty-lite.php` is a **developer preview tool**. It evaluates `{if}`
> conditions against locally-generated data. It is not a template engine and
> must never be loaded in the WHMCS request path — the WHMCS hook uses real
> Smarty.

## Fixtures

| Currency | Rate vs NGN | Notes |
|---|---|---|
| NGN | 1.0 | shop default |
| USD | 0.00065 | has catalogue prices |
| EUR | 0.00060 | has catalogue prices |
| GBP | 0.00051 | no catalogue price — exercises conversion |
| JPY | *none* | deliberately unrateable — must show **no** price, never a made-up one |

Products: `shared-starter` and `shared-pro` have NGN/USD/EUR catalogue prices;
`vps-basic` has NGN only, so every other currency shows a converted, indicative
price while the charged amount stays in NGN.

Pages: home, hosting (exercises localised slugs), cart, invoice (locks itself to
USD), client area.

## What this cannot prove

It is not WHMCS. No database, no login, no real cart, no live rate feed. It
cannot prove the hook wires into WHMCS correctly, that `tblcurrencies` is read
as expected, or that preferences persist in a real session. For that, use
`staging/docker-compose.yml`.
