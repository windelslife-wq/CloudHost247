# CloudHost247 — module inventory

Every module below was delivered as a ZIP archive in the repository root. The
archives have been extracted into their proper WHMCS locations and the archives
themselves removed.

## Addon modules — `modules/addons/`

| Directory | Source archive | Notes |
|---|---|---|
| `hostx` | `modules.zip` | HostX theme companion addon (page builder, blocks, settings, assets). Required by the custom landing pages and by `templates/hostx`. |
| `CloudHost247_tools` | `All CloudHost247 DNS Checker.zip` | CloudHost247 Tools Platform v2.2.6 — self-contained DNS / IP / network / developer / security tool suite. **Recommended tools addon** (no external service required). |
| `tools_center` | `cloudhost247_lteproxy.zip` → `Use this All DNS Checker/whmcs-tools-center` | Alternative "Tools Center" addon. Identical problem space to `CloudHost247_tools` but it *proxies* all work to a separate API service (`modules/addons/tools_center/external-api/`, deploy on its own host and set the endpoint + token in the module settings). Enable **either** this or `CloudHost247_tools`, not both. |
| `hostx_tools` | `WHMCS Domain Lookup.zip` | Domain availability / WHOIS / DNS lookup widgets for the HostX theme. |
| `customaffiliate` | `customaffiliate.zip` and `WHMCS Affiliate Commission Logic.zip` (identical payloads) | Custom affiliate commission logic. Import `schema.sql` on first install. |
| `digitalproducts` | `WHMCS Digital Product Module.zip` | Digital / downloadable product delivery & licensing. |
| `phoneservices` | `WHMCS Phone Number Platform.zip` | Virtual numbers, VoIP, SMS, eSIM, usage analytics. Optional Composer deps — see below. |
| `smmaddon` | `smm_whmcs_module.zip` | SMM panel admin area (orders, services, logs, settings). Import `schema.sql`. |
| `xtreme_currency_rates` | `xtreme_currency_rates_6.0.zip` | Automatic currency exchange rates. ionCube-encoded; requires the ionCube Loader. |

## Provisioning (server) modules — `modules/servers/`

| Directory | Source archive | Notes |
|---|---|---|
| `RDP` | `RDP.zip` | RDP/VPS reseller provisioning (`WHMCS\Module\Server\RDP\Helper`). |
| `hostx_email` | `WHMCS Email Hosting Module.zip` | Email hosting provisioning + webhook endpoint. |
| `cloudhost247_lteproxy` | `cloudhost247_lteproxy.zip` | CloudHost247 LTE proxy reseller provisioning, with AJAX endpoints under `ajax/`. |
| `smmprovisioning` | `smm_whmcs_module.zip` | SMM order provisioning. Shares `modules/addons/smmaddon/lib/` (`Helper`, `ApiClient`), so `smmaddon` must be present. |
| `Smtphosting` | `smtphosting-whmcs-v3.zip` | ModulesGarden-style SMTP hosting reseller module (ships its own `vendor/`). |

## Payment gateways — `modules/gateways/`

| Path | Source archive |
|---|---|
| `blockonomics.php`, `blockonomics/`, `callback/blockonomics.php` | `blockonomics.zip` |

## Theme & order forms

| Path | Source archive(s) |
|---|---|
| `templates/hostx/` | `3dsecure.zip` (`.tpl` files, `images/`, `theme.yaml`) **+** `fonts.zip` (`css/`, `js/`, `img/`, `fonts/`, `webfonts/`, `includes/`, `hostx_includes/`, `banners/`, `flags/`, `marketconnect/`, `store/`, …) **+** `pages.zip` (`TPL/`) **+** `Announcement Bar CloudHost247.zip` |
| `templates/orderforms/hostx/` | `orderforms.zip` |
| root `*.php` legal/info pages | `pages.zip` (`PHP/`) |

> `3dsecure.zip` was misleadingly named: it contains the complete **Hostx**
> client-area theme (`theme.yaml` → `name: "Hostx"`), not a 3-D Secure gateway.
> `fonts.zip` likewise held the theme's asset/include directories, not just
> fonts. The two were merged into the single `templates/hostx/` theme directory.

## Duplicate resolution

* `cloudhost247_lteproxy.zip` turned out to be a **delivery bundle** that also
  re-contained `All CloudHost247 DNS Checker.zip`, `Announcement Bar
  CloudHost247.zip`, `WHMCS Affiliate Commission Logic.zip`, `WHMCS Digital
  Product Module.zip`, `WHMCS Domain Lookup.zip`, `WHMCS Email Hosting
  Module.zip`, `WHMCS Phone Number Platform.zip` and `smm_whmcs_module.zip`.
  Those payloads were verified byte-for-byte identical to the stand-alone
  archives and were therefore extracted only once. Only the bundle-unique parts
  were kept: the `cloudhost247_lteproxy` module, `whmcs-tools-center`
  (→ `tools_center`) and the written build/install notes (→ `docs/`).
* `customaffiliate.zip` and `WHMCS Affiliate Commission Logic.zip` carried the
  same `customaffiliate/` payload — extracted once.
* `dnschecker` (inside `cloudhost247_lteproxy.zip` →
  `All DNS Checker/DNS Checker/`, present twice there) is the first, minimal
  iteration of the DNS checker. Its functionality is fully contained in
  `CloudHost247_tools` (`includes/tools/dns_tools.php`), so it was **not**
  installed as a third competing addon.
* `3dsecure.zip`'s `termsofservice.tpl` was a short placeholder; the much richer
  `pages.zip` version (driven by the `$termsSections` array assigned in
  `terms-of-service.php`) was kept.
* Root pages `dedeicated-server.php` and `refund-and-vancellation-policy.php`
  were misspelled byte-duplicates of `dedicated-server.php` and
  `refund-and-cancellation-policy.php` and were removed; the remaining pages and
  the footer block were repointed at the correctly spelled names, and
  `templates/hostx/refund-and-vancellation-policy.tpl` was renamed to
  `refund-and-cancellation-policy.tpl`.
* The identical `get_currency()`, `wgs_fetch_product_detail_according_to_language_hostx()`,
  `wgs_get_dynmic_translation_page()` and `wgs_pricing_format_data()` copies that
  were duplicated across 11 landing pages are now a single guarded
  implementation in `includes/hostx_page_functions.php`. (`tables.php` had an
  older variant that called `count()` on a `stdClass` — a PHP 8 fatal — and now
  uses the corrected shared version.)

## Path / integration fixes applied

* `modules/servers/cloudhost247_lteproxy/ajax/*.php` — `init.php` was included
  with three `../` instead of four; added the missing
  `use WHMCS\Database\Capsule;`; guarded the duplicated `getCh247Config()`.
* `modules/servers/hostx_email/webhook.php` — `init.php` was included with four
  `../` instead of three.
* `modules/servers/smmprovisioning/smmprovisioning.php` — now resolves
  `../../addons/smmaddon/lib/…` (was `../addons/…`).
* `modules/addons/smmaddon/lib/AdminDispatcher.php` — admin page templates now
  resolve to `../templates/admin/…`.
* `modules/addons/phoneservices` — added `autoload.php` (uses Composer's
  autoloader when `vendor/` exists, otherwise registers a PSR-4 fallback for
  `PhoneServices\` → `lib/`); all five entry points now include it instead of a
  non-existent `vendor/autoload.php`, and the over-escaped PSR-4 prefix in
  `composer.json` was corrected.
* `modules/servers/Smtphosting/autoload.php` — `require_once "Loader.php"` made
  `__DIR__`-relative.
* `templates/hostx/header.tpl` — announcement bar wired in right after `<body>`;
  it is a no-op until an `$announcements` array is assigned.
* `templates/hostx/{backuppolicy,cybercrimepolicy,refundpolicy,trademarkpolicy,datadeletion,domainrenewalpolicy,dataprivacynoticeandconsentform}.tpl`
  — these shipped with full-page `<body>` wrappers and `{include}`s of
  `includes/common/head.tpl`, `includes/header.tpl`, `pageheader.tpl` etc. that
  do not exist in the Hostx theme. They are now content-only fragments, matching
  every other page template, so WHMCS wraps them with the real
  `header.tpl` / `footer.tpl`.
* Broken image references repointed at real files:
  `assets/img/inner-bg.png` and `assets/images/banner-bg.jpg` →
  `templates/hostx/images/term_bg_1.jpg`; added the `images/blog-3.jpg` fallback
  used by `blog.tpl`.
* Created `templates/hostx/css/overrides/override.css` and
  `templates/hostx/js/overrides/override.js` from the shipped `*.new` seeds —
  `includes/head.tpl` / `footer.tpl` reference the non-`.new` names.
