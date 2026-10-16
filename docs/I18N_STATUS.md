# Internationalization implementation status

**Status: in progress; staging review only. Do not merge/deploy as a completed
site-wide translation rollout.** This corrects the earlier PR summary: key parity
in `lang/site` alone did not prove that hard-coded page bodies were translated.

## Verified translation coverage

English is the reference/default. French, Spanish, German and Portuguese (Brazil)
have 100% nonempty key coverage **for the catalogs listed below**, with automated
checks for placeholders, markup, links, technical identifiers and numeric policy
obligations. This is not yet 100% of all application content.

| Layer | Reference coverage | Gate |
|---|---|---|
| Site/UI catalogs (`lang/site`) | 565 keys per locale, including all 62 root-page titles | `CatalogIntegrityTest.php` |
| Page-array catalogs (`lang/pages`) | 290 translatable fields across 6 real controllers per locale | `PageCatalogIntegrityTest.php` |
| WHMCS custom overrides | 700/700 English keys in fr/es/de/pt-BR | `ci/apply-override-translations.php --check` |
| Real Smarty page rendering | All 8 translated body templates, 3 header layouts and 9 automatic-detection scenarios in public/account/cart contexts | `tests/staging/RenderedTemplatesTest.php` |
| Fallback footers | 43 link/heading labels in `footer_block.tpl` + `footer_block_latest.tpl`, no literal copy left | `tests/i18n/FooterTemplateTest.php` |
| Cart recovery pages | 15 keys x 5 locales in `lang/site/<locale>/cart-recovery.php`; `<html lang>`/dir from the active locale; no literal copy left in `recover.php` | `cloudhost247_cart_recovery/tests/run.php` + `tests/test_static.py` |

Translated page bodies: **legal index, FAQs, refund policy, backup policy,
cybercrime policy, trademark/copyright policy, Help Center, Terms & Conditions**.
The reference English arrays remain inline. Original English visible wording is
hash-gated against the base version; no legal wording was rewritten as cleanup.
URLs, company names, email addresses, ids and icons are preserved. Operational
email subjects such as `Trademark Claim` remain unchanged in translations.

Site catalogs use page-specific fragments (`lang/site/<locale>/*.php`) instead
of a growing monolithic file. Page-array catalogs mirror only translatable
fields in `lang/pages/<locale>/<page>.php`. New languages add catalogs, not new
page implementations. Missing/empty values fall back to English.

Metadata explicitly distinguishes `status_scope=declared_catalogs` from the
non-English `site_status=partial`. The admin report likewise warns that catalog
parity is not a site-wide sign-off.

## Currency and real-app integration

- Currency options are discovered from WHMCS `tblcurrencies`. There is **no
  hard-coded enabled-currency list**. The live list cannot be reported from this
  overlay without the installed WHMCS database.
- Rates come only from `tblcurrencies.rate`, maintained by configured WHMCS
  currency automation. The file cache has a six-hour TTL. A cache retrieval
  timestamp is not presented as a provider update timestamp.
- The addon/optional cron **reload configured rates only**. There is no guessed
  `UpdateCurrencyRates` API call; native WHMCS automation owns feed updates.
- Native `?language=french|spanish|german|portuguese-br|english` links preserve the
  actual page and query. Previously invented translated-slug links were removed
  because those routes did not exist.
- The native guest `Language`/`currency` session keys are synchronized, so real
  WHMCS forms, cart and checkout use the selected language/currency.
- Account language is stored with the WHMCS filename. Account browsing currency
  is separate (`mod_cloudhost247_i18n_preferences.display_currency`). **Never
  write `tblclients.currency` when browsing**: historical invoice currency is
  tied to that billing field. Activate the addon to create the additive table.
- Eleven product controllers now bind GetProducts' actual per-currency price
  matrix through the shared resolver/presenter. Disabled `-1` prices are not
  displayed. Missing rates do not create a number. Reference prices state the
  billing-currency base amount, with tax/setup fees/discounts left to checkout.
  Billing-cycle order links carry the actual charge currency explicitly.
- A configured foreign catalog amount is not mislabeled as a charged amount in
  another currency. Cross-pair rate metadata uses `to/from`, not the base rate.
- Existing invoices/account billing are unchanged. The standalone invoice shell
  includes the switcher and locale attributes; cart/checkout/document notices
  explain that the document's transaction currency remains authoritative.

**Preview currencies only:** NGN, USD, EUR, GBP, plus deliberately unrateable JPY.
All preview exchange rates are labeled deterministic fixtures, not live quotes.

## Automatic language & currency detection

Automatic localisation is **not** a dropdown. It is a server-side decision made
once per request, before anything is rendered, and it is recorded with its
provenance so a template, an admin report or a test can say *why* the visitor
is seeing what they see.

**One precedence rule, applied to language and currency independently:**

```
1. an explicit choice on this request          ?language= / ?currency=
2. the customer's account preference            mod_cloudhost247_i18n_preferences
3. a choice remembered from a previous visit    HttpOnly, SameSite=Lax cookie
4. automatic detection                          Accept-Language / IP country
5. the configured platform default
```

Detection is only consulted when 1–3 produce nothing, and a *detected* value is
stored under its own key (`detected_language` / `detected_currency`), separate
from an *explicit* one. That is what makes "never silently overwrite a customer's
explicit choice with a browser or IP guess" true by construction rather than by
convention. Selecting a language in the switcher clears the detected value, so
detection cannot put it back on a later visit.

**Language** — `Accept-Language` is parsed by quality, matched against the
locales that actually have a complete catalog (an unfinished locale is never
selected), and falls back to English. Region tags with no catalog of their own
(`de-AT`) resolve to their base language.

**Currency** — the visitor's country, mapped through an ISO 4217 table, then
**filtered through the currencies WHMCS actually has enabled**. A country whose
currency this shop does not sell in gets the default currency; it is never
mapped to a neighbour's. The mapping is data, not code:
`includes/i18n/data/country-currency.php` (generated from the public
`datasets/currency-codes` and `datasets/country-codes` tables) and an optional
admin override box (`NG=NGN, DE=EUR`). Nothing in the table hard-codes the
examples from the brief — it follows the live WHMCS currency configuration.

**Privacy and trust**

- No browser geolocation, no location permission, no GPS. Ever.
- Geolocation is **opt-in**: with no endpoint configured, no request leaves the
  server and the default currency is used. A configured endpoint is checked
  against a host allowlist before it is called, so a changed setting cannot turn
  this feature into an outbound proxy.
- `X-Forwarded-For`, `CF-Connecting-IP` and `CF-IPCountry` are read **only** when
  `REMOTE_ADDR` is inside the configured trusted-proxy CIDRs (IPv4 + IPv6). With
  no proxies configured — the default — every forwarded header is ignored. The
  chain is walked from the right and stops at the first address we did not put
  there ourselves.
- Logs and the admin report carry a truncated salted SHA-256 of the IP, never
  the address. The country cache is keyed the same way.
- `?country=`, `?lang=`, `?cur=` and every other client-supplied parameter are
  ignored. Only `?language=` and `?currency=` are honoured, and only after
  validation against the server-side allowlists.

**Failure is a supported outcome.** No IP, no header, an unknown country, a
country with no enabled currency, a provider that times out (2s default) or
throws — each produces the next-best answer, and the last one is the platform
default. `Detector` never throws and never blocks a page load. Detection is
applied to the current response; there is no redirect, so there is no loop.

**Verified scenarios** (`tests/i18n/AutoDetectionTest.php`, 219 assertions, plus
9 of them re-rendered through real Smarty in `RenderedTemplatesTest.php`):

| Scenario | Result |
|---|---|
| English browser, no saved preference | English + default currency |
| French browser, no saved preference | French + default currency |
| Visitor in the UK / US / euro area | GBP / USD / EUR, source `geolocation` |
| Country with no currency in the table | default currency, reason `no_currency_for_country` |
| Country whose currency is not enabled | default currency, reason `no_enabled_currency_for_country` |
| Returning visitor with a saved preference | saved preference, `detected: false` |
| Logged-in preference differing from browser + IP | the account preference wins |
| Manual switch, then navigate | the choice survives; detection is cleared |
| Geolocation unavailable / broken | default currency, page still renders |
| Unsupported language, invalid currency, forged headers | ignored, validated, rejected |

An admin "Automatic detection" report shows the active settings, the geolocation
source, whether any proxy is trusted, the resolved country and how many entries
of the country table this shop can actually act on.

## Browser/a11y verification

The preview renders with hash-pinned **Smarty 4.5.6**, not a custom regex parser.
It contains 325 locale × currency × page snapshots. Tests use the real shipped
CSS/JS and real page templates, but fixture data, not a WHMCS login or order.

793 browser checks pass at 320, 375, 768 and 1280px for all five locales. They
cover Enter/Space, arrows/Home/End/Escape, mouse opening, outside closing,
viewport bounds, visible focus, 44px touch targets, independent navigation,
persistence, invalid selections, no-JS links, reduced motion and forced colors,
plus the automatic-detection surfaces: the "we set this automatically" notice is
present when a value was detected and absent when a preference was saved, the
panel never overflows the viewport, and a one-click override replaces a detected
language/currency rather than sitting alongside it.
Axe WCAG 2/2.1 AA checks on the switcher report no violations in these fixtures.

Real render/browser testing found and fixed regressions missed by static tests:
blank panel headings; duplicate latest-header ids; Enter unexpectedly activating
a newly focused link; trigger clicks opening then immediately closing while
bubbling; panels extending off the left edge; and the HTML `hidden` attribute
silently defeating the no-JS fallback.

## Encoding

The requested `shared/site/content/legal/*.txt` paths do not exist here.
There are zero accidental U+F0B7 occurrences in project text. Nothing was changed
as content cleanup. The guard now includes hidden config/CI files and BOM-marked
UTF-16/32 text. Word separators are detected before line splitting consumes them.

Exceptions: compressed PNG bytes in `templates/hostx/images/www-img.png`, optional
dev ICU data, guard/negative-test fixtures, and exactly one hash-pinned upstream
comment line in each of the two Smtphosting `layers-ui.js` copies (three U+FFFD
characters each). The rest of those bundles is scanned. Required stale
exceptions fail the guard. Third-party/build/runtime trees have explicit
exclusions in `ci/check-encoding.py`; they are never rewritten wholesale.

## Remaining acceptance work — not marked complete

1. **Older static policy bodies:** privacy, cookies, acceptable use, fair usage,
   data deletion/protection/consent, domain agreement/renewal/addendum, legal
   notice, and the older refund-and-cancellation template (contains legacy
   placeholder copy). Translate in place, retaining approved English meaning.
2. **Service/marketing bodies:** the `chs-*` public templates, domain-services
   cards/SEO, broker landing copy, and any other hard-coded customer-facing text.
3. **Business Tools/utilities and addon portals:** authored labels, help text,
   validation/errors and relevant email templates. Preserve technical codes and
   user-supplied/domain/WHOIS/provider content; do not auto-translate it.
   *Partially addressed:* the `/tools/*` portal now declares the customer's
   language (`<html lang>` / `dir`) and carries the real language/currency
   switcher, built from the same library and the same validated URLs as the
   theme, so a choice made on a tool page is the same choice everywhere. Its own
   body copy is still English.
   *Also addressed:* the abandoned-cart recovery and unsubscribe pages
   (`modules/addons/cloudhost247_cart_recovery/recover.php`). They render their
   own document — no client-area hook runs there — so `<html lang="en">` was
   hardcoded and the page copy sat inline in PHP. A customer reading the whole
   site in French got an English page from the one email this module sends. The
   page now resolves the customer's language through the shared library (same
   precedence: explicit `?language=` > account preference > remembered cookie >
   default) and reads its strings from `lang/site/<locale>/cart-recovery.php`,
   so translating the module is adding a file to an existing locale. Detection
   is deliberately off on that endpoint: a token link opens from a mailbox, and
   a language guessed from whatever network the customer is on would beat the
   one they actually chose. Its reminder/unsubscribe *emails* were already
   admin-editable in `tblemailtemplates`.
4. **Installed database content:** product/group descriptions, HostX page blocks,
   menus, announcements, knowledge-base articles and email templates require
   curated translations in the existing WHMCS/HostX translation infrastructure.
   The database and commercial core are not in this checkout. A fixture harness
   cannot certify their coverage.
5. **Licensed WHMCS staging:** actual session/account persistence, enabled
   currencies, taxes, discounts, billing cycles, order completion, historical
   invoices, complete mega-menu/account/footer layouts and email rendering need
   the checklist in `staging/VERIFICATION.md`. Docker recipe is provided but not
   executed here. Human linguistic/legal review remains required before release.

## Verification commands and current caveats

```sh
ci/run-php.sh i18n
node tests/run.mjs --suite=i18n                 # no-native-PHP fallback
PHP_WASM_INTL=0 node tests/run.mjs --php-version=7.4 --suite=i18n
node staging/i18n-preview/prepare.mjs
php tests/staging/RenderedTemplatesTest.php
ci/run-js.sh
node staging/i18n-preview/build.mjs
node staging/i18n-preview/server.mjs
npm run test:browser --prefix staging/i18n-preview
node staging/i18n-preview/build.mjs --wasm      # adds the 9 detection scenarios
python3 ci/test-encoding.py
python3 ci/check-encoding.py
```

The preview index also lists the nine automatic-detection scenarios. Each
`/p/detection-<key>` page shows, above the rendered switcher, the
`Accept-Language` header and client IP that were fed in, the resolved country,
and the chosen language and currency with the source each came from — next to
the result the scenario is expected to produce.

- i18n: **1,423 assertions, 0 failures** on PHP 8.3 (12 suites); **1,378/0** on
  PHP 7.4 without Intl (ICU cross-checks skipped, deterministic fallbacks
  tested). `tests/i18n/AutoDetectionTest.php` alone contributes 219.
- Real Smarty render: **411/0**; switcher JS conformance: **64/0**; fallback
  footer rendering: **44/0**.
- Browser: **793/0**; guard regression tests: **13/0**.
- Whole-tree PHP token syntax sweep: **2,005 files, 0 errors** at the recorded
  run; rerun after subsequent additions. Plain PHP/JS overlay: no TypeScript
  target or standalone WHMCS production build exists in this repository.
- Full PHP driver executed 188 files. The sole sandbox failure was the passkey
  vendor dependency preflight (Composer dependencies absent locally). CI installs
  them. All executed substantive suites passed.
- **2026-10-16 PHP 7.4 Tools compatibility fixed:** `productivity_tools.php` now casts integer BIN array keys to string before `strpos()` (`HandlerTest.php` 146/146 on PHP 7.4), `Security.php` provides a pure-PHP RFC 3492 Punycode fallback when `ext-intl` is absent (`SecurityTest.php` 118/118 on PHP 7.4 without Intl), and `CloudHost247_tools/tests/run.mjs` mounts the repository root for i18n switcher checks (`935/935` across all 14 suites).
- Tools Center: 12/12 with jsdom UI wiring enabled; remaining JS suites pass.

No production deployment or PR merge is authorized or performed.
