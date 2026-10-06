# CloudHost247 — Platform Features

Rewritten to describe **what this repository actually ships**, with the evidence behind each
claim and the gaps that still have to be closed before the claim is fully true.

* **Part 1** — publishable copy. Drop-in replacement for the nine feature blocks.
* **Part 2** — evidence table: claim → file → verdict.
* **Part 3** — gaps, with the work needed to close each one.
* **Part 4** — wording to avoid until the code supports it.

Scope note: CloudHost247 is a **WHMCS overlay** (see `README.md`). Everything below describes
the CloudHost247 layer — theme, landing pages, modules, gateways — running on top of a
standard WHMCS 8.x install. Where a capability belongs to WHMCS core, the copy says
"integrated with", not "provides".

---

# Part 1 — Feature copy (accurate as of this commit)

### WHMCS Integrated

CloudHost247 runs as a native layer on top of WHMCS 8.x, so billing, invoicing, the client
account, domain registration, support tickets and the subscription lifecycle stay in the
system your staff already operate. The platform adds the provisioning and commerce on top:
dedicated servers and VPS via OVH / SoYouStart, RDP, email hosting, SMTP hosting, LTE proxies,
SMM services, phone numbers and digital products — each as a WHMCS provisioning or addon
module that reacts to the authoritative WHMCS order and payment state. Payments include
Bitcoin via Blockonomics alongside every gateway WHMCS already supports. No WHMCS core file is
modified anywhere in the platform.

### Multi-Language Support

The customer portal, billing pages, account dashboard and knowledge base localise through
WHMCS's own language system, with **27 language overrides shipped** — including Arabic,
Chinese, Croatian, Czech, Danish, Dutch, Estonian, Farsi, French, German, Hebrew, Hungarian,
Italian, Korean, Macedonian, Norwegian, Polish-family, Portuguese (BR and PT), Romanian,
Russian, Spanish, Swedish, Turkish, Ukrainian and more. Product and page content served
through the HostX page system honours WHMCS's translation setting, so catalogue names follow
the visitor's language.

> *Current limit — see Part 3: the CloudHost247-authored templates still contain some
> English explanatory copy. The services module now translates page titles, sidebar labels,
> selected mega-menu labels and several public titles through the existing WHMCS language
> selection; a complete content-key pass remains tracked work.*

### Fully Responsive

The HostX client-area theme is built on a responsive grid with a dedicated mobile navigation
drawer and roughly 170 breakpoint rules across its stylesheets, so the storefront, dashboards,
order forms and service pages stay usable from a 4K desktop down to a phone. Custom
CloudHost247 pages — domain search, valuation, auctions, the TLD directory, WHOIS, Logo
Studio, the broker desk — are authored against the same grid and components.

### Standards-Compliant

The frontend is Bootstrap-based, server-rendered Smarty with semantic markup, upgrade-safe
override files instead of patched vendor CSS, and no reliance on client-side code for
security. Backend code targets PHP 7.4 through 8.2, is ionCube-environment safe, uses
parameterised queries throughout, and ships with per-module offline test suites and syntax
linting that run without a WHMCS install.

### Highly Customizable

Branding, colours, navigation, headers, cookie-banner styling and sticky behaviour are driven
by theme settings rather than code edits. Pages and their SEO metadata are managed through the
HostX page system. `css/overrides/override.css` and `js/overrides/override.js` give you an
upgrade-safe place for local tweaks. Every CloudHost247 module exposes its own settings in the
WHMCS admin, with environment-variable overrides available for secrets so production
credentials never live in the repository. Order forms are swappable per product group (HostX
and OVH cart templates both ship).

### SEO & Performance Optimized

Per-page title, meta description, keywords, robots directives and Open Graph tags are
configurable per page and rendered by the theme. Analytics and tracker injection is centralised in one template and optional providers are
injected only after the HostX consent decision. `sitemap.xml` is regenerated on a schedule
from the canonical list of public pages and **only lists pages that actually exist on disk**,
so the sitemap can never advertise a dead link; a human-readable `sitemap.html` ships
alongside it. Stylesheets ship minified, and the service modules are built to keep WHMCS page
loads cheap — indexed queries, pagination, cached settings, and background cron work instead
of synchronous statistics.

> *Caching, compression and clean-URL rewrites are web-server and WHMCS configuration, covered
> in the deployment runbook rather than shipped in the overlay.*

### Mega Menu

A full mega-menu navigation system organises hosting, domains, VPS, dedicated servers, cloud
services, email hosting, security products, software, marketplace products and support
resources. Three desktop layouts (default, latest, dropdown) and a mobile off-canvas drawer
all render from a single menu data source, so a destination added once appears everywhere.
CloudHost247's own service modules contribute their entries programmatically — currently four
top-level categories spanning 33 destinations — which keeps the menu in step with whichever
modules are actually enabled.

### Privacy & Compliance Ready

The platform ships the legal surface a hosting business needs as first-class pages: privacy
policy, cookie policy, data-deletion request, data-privacy notice and consent form,
data-protection standards, terms of service, acceptable-use, fair-usage, backup, refund and
cancellation, cybercrime, trademark, domain registration and renewal policies, and a legal
notice. A configurable cookie-consent banner is included globally in the HostX shell, with
geo-aware EU cookie-law detection, opt-in behavior, configurable position, palette, message,
policy link, revoke control and a browser-local policy/version decision record. When the
CloudHost247 Services addon is active, the same-origin consent endpoint also stores a
pseudonymous, timestamped decision history using its additive consent-record migration.
Analytics, marketing pixels and tag-manager scripts are injected only after an allow decision.

> *Current limit — optional providers currently share one allow category, and the server
> history is a pseudonymous technical record rather than a legal compliance determination.
> Treat "compliance ready" as "configurable privacy building blocks", not "configured for
your jurisdiction".*

### RTL Support

The HostX theme ships a dedicated right-to-left stylesheet and RTL asset variants, and the
RTL languages themselves — Arabic, Farsi, Hebrew — are among the 27 shipped language files, so
the core customer portal, forms, invoices and account pages can be served right-to-left.

> *Current limit — see Part 3: RTL rules now cover the CloudHost247 Services, Domain Broker
> and Digital Products interfaces, while other custom page content still needs a page-by-page
> RTL review.*

---

# Part 2 — Evidence

| Feature | Evidence in this repository | Verdict |
|---|---|---|
| WHMCS Integrated | 7 provisioning modules (`modules/servers/`), 12 addons (`modules/addons/`), Blockonomics gateway (`modules/gateways/`), hooks on `OrderPaid`/`InvoicePaid`/`ClientAreaPage`/`DailyCronJob`, `localAPI()` + Capsule use throughout; `docs/MODULES.md` inventory | **Supported** |
| Multi-Language | `lang/overrides/` — 27 tracked files (`arabic.php` … `ukranian.php`), 45–83 KB each; landing pages check `EnableTranslations` and feed `$_LANG` into product rendering (`web-hosting.php:24`) | **Partial** — core plus module titles/navigation; template copy still needs a full key pass |
| Fully Responsive | 47 `@media` blocks in `templates/hostx/css/styles.css`, 123 in `all.css`; `hostx_includes/mobile-menu.tpl`; Bootstrap grid in all `chs-*.tpl` | **Supported** |
| Standards-Compliant | Smarty templates, override files (`css/overrides/override.css`), PHP 7.4-compatible module code, `tests/lint.php` + `tests/*Test.php` in three modules, parameterised queries / Capsule | **Supported, with caveats** — no automated HTML or a11y validation |
| Highly Customizable | `$hostx_theme_settings` across `hostx_includes/*.tpl`, `mod_hostx_pages` page system, `tbladdonmodules` settings per module, `Chs\Core\Settings` with `CHS_*` env overrides + `SECRET_KEYS`, `templates/orderforms/{hostx,ovh_cart}` | **Supported** |
| SEO & Performance | `hostx_includes/seo-meta-tags.tpl` (title, description, keywords, robots, OG), consent-gated `seo-trackers.tpl`, root `sitemap.xml` + `sitemap.html`, `Chs\Services\SitemapService::regenerate()` (skips pages missing on disk), `all.min.css` | **Partial** — metadata, consent gating and sitemap yes; caching/compression/clean URLs are host config |
| Mega Menu | `top-mega-menu-default.tpl`, `top-mega-menu-latest.tpl`, `top-menu-dropdown.tpl`, `mobile-menu.tpl`, `mega-menu-hover-setting.tpl`; `Chs\Http\HostxMenu` merges 4 categories / 33 destinations into `$topMenusData` via `ClientAreaPage` priority 90; covered by `tests/10_MenuTest.php` | **Supported** |
| Privacy & Compliance | 16 legal pages at the repo root; `js/cookies_library_hostx_file.js` (cookieconsent with EU-law + geolocation awareness); global `hostx_includes/cookie-offers.tpl`; `ConsentService` + migration `0011_consent_records.php`; consent-gated `seo-trackers.tpl` | **Partial** — pseudonymous server history when the addon is active, but no independent per-category controls |
| RTL Support | `templates/hostx/css/style-rtl.css` (1,178 lines), `images/*_rtl.png`; `lang/overrides/{arabic,farsi,hebrew}.php`; scoped RTL sheets in `cloudhost247services`, `domainbroker` and `digitalproducts` | **Partial** — theme and three custom module interfaces covered; remaining custom pages need review |

---

# Part 3 — Gaps to close

Ordered by how far the current claim is from the current code.

### P1 — Consent persistence and category controls remain limited

The global HostX footer include now initializes the cookie banner on every rendered page when
`enable_browser_cookies_hostx` is enabled. It uses the existing cookieconsent library in
explicit opt-in mode, exposes its revoke control, records the policy version and timestamp
in browser local storage, and emits a consent event. `seo-trackers.tpl` no longer executes
Google Analytics, Facebook Pixel or Google Tag Manager at parse time; it injects them only
after an `allow` decision.

**Remaining work:** split optional providers into independently configurable
analytics/marketing categories. The browser record and the pseudonymous module history are
technical controls, not a legal compliance record.

### P2 — CloudHost247-authored templates still have English copy

`cloudhost247services/lib/Core/I18n.php` now reads the existing WHMCS language/session
selection and supplies overlay-owned translations for German, French, Spanish, Portuguese,
Chinese, Arabic, Farsi and Hebrew, with English fallback. The services module applies these
to client page titles, sidebar labels, selected HostX mega-menu labels and selected public
landing titles. This improves navigation and orientation without introducing a second
language system.

**Remaining work:** route every explanatory string in the in-repo module templates, the
`domainbroker` templates and root landing templates through language keys. Keep this a
content/localisation pass over existing views, not a replacement translation framework.

### P2 — RTL still needs coverage outside the reviewed custom modules

The services, Domain Broker and Digital Products hooks now load scoped RTL stylesheets. They
use logical text alignment and explicit direction-aware overrides for cards, timelines, forms, navigation,
input groups, tables and badge positioning. HostX continues to provide the core RTL theme
and assets.

**Remaining work:** review any remaining custom module or page-system content with Arabic or
Hebrew active, and add scoped rules only where actual layout defects are found.

### P3 — Accessibility is asserted, not verified

The cookie banner carries ARIA labels and `chs-*` templates use `aria-current` on breadcrumbs,
but that is roughly three ARIA/role attributes per page and nothing enforces more. There is no
automated HTML validation, no axe/pa11y run, no keyboard-navigation or contrast check.

**Work:** either add a validation step (pa11y/axe over the rendered public pages) and fix what
it finds, or soften the claim to "clean, semantic, Bootstrap-based markup" — which Part 1
already does.

### P3 — Performance claims that live outside the overlay

Caching, gzip/brotli, image optimisation and clean-URL rewrites are not in this repository
(`.htaccess` and the web-server config are not part of the overlay).

**Work:** document the required host configuration in `docs/OPERATIONS.md` deploy checklist so
the claim is backed by the runbook, and keep the in-repo half honest — the `digitalproducts`
module is currently the one place that breaks the "cheap page loads" promise, and its rebuild
is specced in `docs/DIGITAL_PRODUCTS_REBUILD.md`.

### P3 — Data-retention story is ad-hoc

Retention is now configurable for the pseudonymous consent history (`consent_retention_days`,
with `0` meaning keep forever) and the existing lookup/WHOIS caches. The digitalproducts
module still has its own download-history policy, and audit/inbox retention is not yet
platform-wide or export-before-prune.

**Work:** define retention per remaining data class (download logs, audit events, inbox),
make each configurable, and add an operator-approved export/archive path before pruning.

---

# Part 4 — Claims to avoid until the code supports them

| Don't say | Why | Say instead |
|---|---|---|
| "GDPR compliant" / "fully compliant" | Compliance is a legal determination about an operator's configuration and processes, not a software property — and the consent banner currently misses most pages | "Configurable privacy and consent controls" |
| "Multi-language across every interface" | Some CloudHost247 template copy still uses English fallbacks | "27 shipped languages across the WHMCS customer portal and billing, with translated overlay navigation" |
| "RTL support across all dashboards and admin interfaces" | RTL review is complete for the core theme and three custom modules, not every custom view | "RTL theme support plus scoped RTL rules for the reviewed custom modules" |
| "WCAG / accessibility compliant" | Nothing verifies it | "Semantic, standards-based markup" |
| "Caching and compression built in" | Not in the overlay | "Performance-focused frontend; caching and compression configured at deployment" |
| "CloudHost247 provides billing, invoicing and ticketing" | WHMCS provides them | "Integrated with WHMCS billing, invoicing and ticketing" |

---

## Where this copy can live

The nine blocks in Part 1 are written as standalone prose and can be dropped into a homepage
features section (`templates/hostx/homepage.tpl`), a dedicated landing page in the HostX page
system, or the repository `README.md`. Parts 2–4 are internal: keep them in `docs/` and
revisit whenever a gap closes, so the published copy and the code never drift apart.
