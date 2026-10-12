# Staging verification checklist

Print-and-tick list for the staging review. Every item maps to a requirement in
the implementation brief; none of them requires production access.

Record who checked, when, and the staging URL at the bottom.

---

## A. Language switching

| # | Check | Pass |
|---|---|---|
| A1 | Header switcher is present on desktop, tablet and mobile. | ☐ |
| A2 | It displays the *current* language, not a generic "Language" label. | ☐ |
| A3 | It lists all five shipped languages with readable native names. | ☐ |
| A4 | Selecting a language applies site-wide, not just on the current page. | ☐ |
| A5 | Selecting a language keeps you on the equivalent page, query string included. | ☐ |
| A6 | Localised slugs resolve both ways (`/web-hosting.php` ↔ `/hebergement-web.php`). | ☐ |
| A7 | Product codes, TLDs, domain names and technical identifiers are unchanged. | ☐ |
| A8 | The choice persists across navigation. | ☐ |
| A9 | The choice persists in a new session (cookie / session). | ☐ |
| A10 | A logged-in client's saved language preference wins. | ☐ |
| A11 | `?language=zz-invalid` falls back to the default without an error page. | ☐ |
| A12 | Nav, mega menu, announcement bar and footer are translated. | ☐ |
| A13 | Registration, login and checkout forms and validation messages are translated. | ☐ |
| A14 | Any key missing from a catalog renders English, never a blank or a raw key. | ☐ |

## B. Currency switching

| # | Check | Pass |
|---|---|---|
| B1 | Header switcher shows the current code **and** symbol. | ☐ |
| B2 | It lists every currency enabled in *Setup → Currencies*. | ☐ |
| B3 | Disabling a currency in WHMCS removes it from the switcher. | ☐ |
| B4 | Enabling a new currency in WHMCS adds it to the switcher. | ☐ |
| B5 | Prices update on product pages, listings, cart and checkout. | ☐ |
| B6 | The **amount** changes, not only the symbol. | ☐ |
| B7 | A converted price is clearly labelled indicative. | ☐ |
| B8 | The amount actually charged is shown in its own currency. | ☐ |
| B9 | A currency with no configured rate shows no price rather than a wrong one. | ☐ |
| B10 | An invoice keeps its original currency regardless of browsing currency. | ☐ |
| B11 | Historical invoices are unchanged after switching currency. | ☐ |
| B12 | Currency choice is independent of language choice (EN + EUR works). | ☐ |
| B13 | `?currency=999` falls back to the default without an error page. | ☐ |
| B14 | Rate source and last-updated timestamp are visible to an admin. | ☐ |
| B15 | Tax and billing behaviour is unchanged by the switcher. | ☐ |

## C. Accessibility

| # | Check | Pass |
|---|---|---|
| C1 | `Tab` reaches the language and currency triggers. | ☐ |
| C2 | `Enter` and `Space` open the panel. | ☐ |
| C3 | `↑` / `↓` move between options while open. | ☐ |
| C4 | `Home` / `End` jump to the first / last option. | ☐ |
| C5 | `Esc` closes and returns focus to the trigger. | ☐ |
| C6 | Opening one control closes the other. | ☐ |
| C7 | Clicking outside closes the panel. | ☐ |
| C8 | The focus ring is clearly visible on trigger and options. | ☐ |
| C9 | A screen reader announces the control's purpose. | ☐ |
| C10 | A screen reader announces which option is currently selected. | ☐ |
| C11 | Endonyms carry `lang=` so they are pronounced correctly. | ☐ |
| C12 | Works with JavaScript disabled: panels open, links navigate. | ☐ |
| C13 | Respects `prefers-reduced-motion`. | ☐ |
| C14 | Readable in Windows High Contrast / forced-colors mode. | ☐ |

## D. Responsive layout

| # | Check | Pass |
|---|---|---|
| D1 | No horizontal overflow at 320 px. | ☐ |
| D2 | No horizontal overflow at 375 px. | ☐ |
| D3 | No horizontal overflow at 768 px. | ☐ |
| D4 | No horizontal overflow at 1280 px and above. | ☐ |
| D5 | The mega menu still opens, is not clipped, and is not shifted. | ☐ |
| D6 | The account nav is not displaced or wrapped. | ☐ |
| D7 | Tap targets are at least 44 px on touch widths. | ☐ |
| D8 | A long language name ellipsises instead of stretching the header. | ☐ |
| D9 | The mobile panel does not create a second horizontal scroll region. | ☐ |

## E. Encoding

| # | Check | Pass |
|---|---|---|
| E1 | `python3 ci/check-encoding.py` reports `TOTAL=0`. | ☐ |
| E2 | The two documented exclusions still describe real files. | ☐ |
| E3 | No page shows a box, a blank or a stray glyph where a bullet should be. | ☐ |
| E4 | Legal and policy wording is byte-identical to the approved English text. | ☐ |

## F. Regressions

| # | Check | Pass |
|---|---|---|
| F1 | `ci/run-php.sh i18n` — all suites green. | ☐ |
| F2 | `ci/run-js.sh` — all suites green, including the switcher conformance suite. | ☐ |
| F3 | `ci/php-lint.sh` — no syntax errors introduced. | ☐ |
| F4 | `python3 ci/check-encoding.py` — clean. | ☐ |
| F5 | Cart and checkout complete successfully end to end. | ☐ |
| F6 | Existing invoice totals are unchanged. | ☐ |

---

## Sign-off

- Staging URL: ______________________________________
- Checked by: _______________________________________
- Date: _____________________________________________
- Notes: ____________________________________________

Approval to deploy to production is a separate, explicit step. Nothing in this
repository deploys itself.
