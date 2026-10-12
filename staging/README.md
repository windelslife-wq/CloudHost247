# Staging & verification

Two complementary ways to verify the internationalization, currency and encoding
work before anything reaches production.

| | Sandbox preview harness | WHMCS staging (docker-compose) |
|---|---|---|
| Path | `staging/i18n-preview/` | `staging/docker-compose.yml` |
| Needs | Node only (or PHP) | A licensed WHMCS checkout |
| Boots | ~2 seconds | ~2 minutes |
| Renders | the real templates, fixture data | real WHMCS, real DB, real `tblcurrencies` |
| Proves | markup, CSS, a11y, formatting, price logic | the WHMCS integration end to end |

Neither one is production. Do not deploy from either without following the
manual release steps at the bottom of this file.

---

## 1. Sandbox preview harness

Renders the **real** `templates/hostx/includes/language-currency-switcher.tpl`
with the **real** `includes/i18n` library, for every combination of
5 languages × 5 currencies × 5 page types = 125 snapshots, and serves them so
they can be clicked through in a browser.

```bash
node staging/i18n-preview/build.mjs          # render snapshots (uses `php` if present)
node staging/i18n-preview/build.mjs --wasm   # …or no PHP at all: uses the vendored php-wasm
node staging/i18n-preview/server.mjs         # serve on http://0.0.0.0:4321
```

`PORT=8080 node staging/i18n-preview/server.mjs` to change the port.

### What it shows

- **Desktop header** — the switcher sitting in the header's right-hand group.
- **Mobile / off-canvas** — the same component full width, 44 px tap targets.
- **Prices** — for the selected locale and currency, showing for each product:
  - the displayed amount,
  - whether it is **exact** (a catalogue price exists in that currency) or
    **indicative** (converted),
  - the amount and currency actually **charged**,
  - the rate and its source.
- **Locale-aware formatting** — number, decimal, percent, medium and full date.

### Deliberate fixtures

- **JPY has no rate.** Its rows show nothing at all rather than a made-up
  number. That is the requirement — "never invent exchange rates" — made
  visible instead of merely unit-tested.
- **The invoice page locks itself to USD.** Switching the browsing currency must
  not change what the invoice says is owed.
- Currencies come from a `StaticCurrencySource`, preferences from an
  `ArrayPreferenceStore`. Same code path as WHMCS, different data source — that
  is the point of the library's interface-based design.

### What it does not show

It is not WHMCS. There is no database, no login, no cart, and no rates fetched
from a live feed. It cannot prove that the hook wires into WHMCS correctly.
That is what the compose recipe is for.

---

## 2. WHMCS staging (docker-compose)

See `staging/docker-compose.yml` for the full annotated recipe. Summary:

```bash
# 1. Unzip a licensed WHMCS 8.x checkout into ./whmcs/
# 2. Rsync this repository over it (it is a WHMCS overlay)
rsync -a --exclude whmcs --exclude .git ./ ./whmcs/

# 3. Start
docker compose -f staging/docker-compose.yml up -d

# 4. Install at http://localhost:8080/install/
#    DB host `db`, database `whmcs`, user `whmcs`, password `whmcs`

# 5. Configure (admin area)
#    Setup -> Currencies                      enable NGN, USD, EUR, GBP
#    Setup -> General Settings -> Localisation set the default language
#    Setup -> Addon Modules                   activate "CloudHost247 Internationalization"
#    Utilities -> Automation Settings         enable "Update Currency Rates"

# 6. Re-run the offline suites against the deployed overlay
docker compose -f staging/docker-compose.yml run --rm tests
```

WHMCS is commercial software and cannot be downloaded by CI or redistributed in
an image, which is why step 1 is manual. Everything after it is scripted.

**Do not expose port 8080 publicly.** The credentials in the compose file are
well known by design.

---

## Verification checklist

Run through this on staging before approving a production deploy. It is also in
`staging/VERIFICATION.md` as a printable list.

**Language**

- [ ] Header switcher shows the current language and lists all five.
- [ ] Switching keeps you on the same page, including query strings.
- [ ] Localised slugs work in both directions (`/web-hosting.php` ↔ `/hebergement-web.php`).
- [ ] The choice survives navigation and a fresh session.
- [ ] A logged-in client's account preference is respected.
- [ ] `?language=xx-invalid` falls back to the default and does not error.
- [ ] Nav, mega menu, footer, forms and validation are all translated.

**Currency**

- [ ] Header switcher shows the current currency code and symbol.
- [ ] Every WHMCS-enabled currency appears; disabling one in WHMCS removes it.
- [ ] Prices change on product pages, listings, cart and checkout.
- [ ] A converted price is labelled indicative and states what you are charged.
- [ ] A currency with no rate shows no price rather than a wrong one.
- [ ] An invoice keeps its own currency regardless of the browsing currency.
- [ ] The choice is independent of the language choice.
- [ ] `?currency=999` falls back to the default and does not error.

**Accessibility & responsive**

- [ ] Keyboard: `Enter`/`Space` opens, `↑`/`↓` move, `Home`/`End` jump, `Esc` closes and returns focus.
- [ ] Focus ring is clearly visible on both the trigger and the options.
- [ ] Screen reader announces the control and the current selection.
- [ ] No horizontal overflow at 320 px, 375 px, 768 px and 1280 px.
- [ ] The mega menu still opens and is not clipped or shifted.
- [ ] Works with JavaScript disabled (panels stay open, links navigate).

**Encoding**

- [ ] `python3 ci/check-encoding.py` reports `TOTAL=0`.

---

## Deploying to production

Not automated, and intentionally so. The compose recipe and the harness are for
**verification only**.

```bash
# Dry run first — always.
rsync -a --dry-run -v \
  --exclude .git \
  --exclude whmcs \
  --exclude node_modules \
  ./ user@production:/path/to/whmcs/

# Then for real, once the dry run looks right.
rsync -a --delete -v \
  --exclude .git \
  --exclude whmcs \
  --exclude node_modules \
  ./ user@production:/path/to/whmcs/
```

After the rsync:

1. Activate **CloudHost247 Internationalization** in *Setup → Addon Modules*.
2. Check *Setup → Currencies* — the switcher reads whatever is enabled there.
3. Enable the *Update Currency Rates* automation task, or schedule
   `php crons/currencyRateRefresh.php` twice daily.
4. Visit a page in each language and confirm the completeness report in the
   addon shows 100%.
5. `templates_c` does not need clearing for the new templates, but clear it if
   a header change appears not to have taken effect.
