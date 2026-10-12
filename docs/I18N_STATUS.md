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
| Site/UI catalogs (`lang/site`) | 519 keys per locale, including all 62 root-page titles | `CatalogIntegrityTest.php` |
| Page-array catalogs (`lang/pages`) | 290 translatable fields across 6 real controllers per locale | `PageCatalogIntegrityTest.php` |
| WHMCS custom overrides | 698/698 English keys in fr/es/de/pt-BR | `ci/apply-override-translations.php --check` |
| Real Smarty page rendering | All 8 translated body templates and 3 header layouts in public/account/cart contexts | `tests/staging/RenderedTemplatesTest.php` |

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

## Browser/a11y verification

The preview renders with hash-pinned **Smarty 4.5.6**, not a custom regex parser.
It contains 325 locale × currency × page snapshots. Tests use the real shipped
CSS/JS and real page templates, but fixture data, not a WHMCS login or order.

592 browser checks pass at 320, 375, 768 and 1280px for all five locales. They
cover Enter/Space, arrows/Home/End/Escape, mouse opening, outside closing,
viewport bounds, visible focus, 44px touch targets, independent navigation,
persistence, invalid selections, no-JS links, reduced motion and forced colors.
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
python3 ci/test-encoding.py
python3 ci/check-encoding.py
```

- i18n: **1,160 assertions, 0 failures** on PHP 8.3; **1,115/0** on PHP 7.4
  without Intl (ICU cross-checks skipped, deterministic fallbacks tested).
- Real Smarty render: **293/0**; switcher JS conformance: **64/0**.
- Browser: **592/0**; guard regression tests: **13/0**.
- Whole-tree PHP token syntax sweep: **1,979 files, 0 errors** at the recorded
  run; rerun after subsequent additions. Plain PHP/JS overlay: no TypeScript
  target or standalone WHMCS production build exists in this repository.
- Full PHP driver executed 188 files. The sole sandbox failure was the passkey
  vendor dependency preflight (Composer dependencies absent locally). CI installs
  them. All executed substantive suites passed.
- Pre-existing/environment-specific PHP 7.4 Tools failures were reproduced in
  unchanged `HandlerTest.php` (two BIN fallback metadata checks) and
  `SecurityTest.php` (IDN expectation); the previous PR commit already had a
  failing native 7.4 job. These are separate from the green i18n suites.
- Tools Center: 12/12 with jsdom UI wiring enabled; remaining JS suites pass.

No production deployment or PR merge is authorized or performed.
