# CloudHost247 — module inventory

All **20** modules below were delivered as ZIP archives in the repository root.
The archives have been extracted into their proper WHMCS locations and the
archives themselves removed. One further addon, `domainbroker`, was written
for this repository rather than imported — see `docs/DOMAIN_BROKER.md`.

## Addon modules — `modules/addons/`

| Directory | Source archive | Notes |
|---|---|---|
| `hostx` | `modules.zip` | HostX theme companion addon (page builder, blocks, settings, assets). Required by the custom landing pages and by `templates/hostx`. |
| `CloudHost247_tools` | `All CloudHost247 DNS Checker.zip` | CloudHost247 Tools Platform v2.2.6 — self-contained DNS / IP / network / developer / security tool suite. **Recommended tools addon** (no external service required). |
| `tools_center` | `cloudhost247_lteproxy.zip` → `Use this All DNS Checker/whmcs-tools-center` | Alternative "Tools Center" addon. Identical problem space to `CloudHost247_tools` but it *proxies* all work to a separate API service (`modules/addons/tools_center/external-api/`, deploy on its own host and set the endpoint + token in the module settings). Enable **either** this or `CloudHost247_tools`, not both. |
| `hostx_tools` | `WHMCS Domain Lookup.zip` | Domain availability / WHOIS / DNS lookup widgets for the HostX theme. |
| `customaffiliate` | `customaffiliate.zip` and `WHMCS Affiliate Commission Logic.zip` (identical payloads) | Custom affiliate commission logic. Import `schema.sql` on first install. |
| `digitalproducts` | `WHMCS Digital Product Module.zip` | CloudHost247 Digital Products: WHMCS-linked releases, private expiring downloads, entitlements, licenses and audit logging. Upgraded in place; see [`DIGITAL_PRODUCTS.md`](DIGITAL_PRODUCTS.md). |
| `phoneservices` | `WHMCS Phone Number Platform.zip` | Virtual numbers, VoIP, SMS, eSIM, usage analytics. Optional Composer deps — see below. |
| `smmaddon` | `smm_whmcs_module.zip` | SMM panel admin area (orders, services, logs, settings). Import `schema.sql`. |
| `xtreme_currency_rates` | `xtreme_currency_rates_6.0.zip` | Automatic currency exchange rates. ionCube-encoded; requires the ionCube Loader. |
| `domainbroker` | *written in-repo* | **Domain Broker Service** — brokered domain acquisition: negotiation, escrowed payment, transfer tracking, verification, disputes, fees, reporting, REST API. Customer portal, broker desk and admin console. Activate to run its migrations. Full write-up in `docs/DOMAIN_BROKER.md`. |
| `cloudhost247ai` | *written in-repo* | **CloudHost247 AI control plane** — one shared AI operating layer: 9 Tier-A agents, read-only grounded tools over live WHMCS data + the real platform diagnostics, dual-RBAC permissions, approval engine (dormant in Phase 1), hash-chained audit, INSERT-only event capture with cron drain, RAG knowledge base, deterministic daily briefings, fail-closed model router (self-hosted vLLM/Ollama supported). Runbook: `docs/AI_CONTROL_PLANE.md`; architecture: `docs/AI_PLATFORM_PLAN.md`. |
| `cloudhost247marketing` | *written in-repo* | **CloudHost247 Email Marketing** — campaign builder and sender: subscribers, lists, tags, CSV import and dynamic WHMCS segments; 14 content blocks and 14 seeded templates rendered to Outlook-safe HTML; merge-tag personalisation validated before send; queue + cron delivery with retries, backoff, rate limits, leases and a kill switch; SMTP / HTTP-provider / WHMCS / dry-run transports; open, click, bounce, unsubscribe and complaint tracking with a click map and per-recipient reports; suppression list, one-click unsubscribe and a hash-chained audit trail; event-triggered automations including abandoned-cart recovery. Runbook: `docs/EMAIL_MARKETING.md`. |
| `soyoustart` | `WGS-OVH-v8.0.8-Sourcecode.zip` | WGS OVH / SoYouStart **admin** addon: API consumer setup, product & price settings, order management, existing-server import, server status, email templates. |
| `cloudhost247passkey` | *written in-repo* | **CloudHost247 Passkey** — native WebAuthn/FIDO2 passwordless login for clients and administrators: registration, multi-device management, enforcement policies, step-up sensitive-action confirmation, Passkey-assisted password reset, activity logs, login notifications, admin dashboard, and optional Entra ID settings. Disabled by default; password login and 2FA remain. Full write-up in `docs/PASSKEY.md`. |
| `cloudhost247_cart_recovery` | *written in-repo* | **CloudHost247 Cart Recovery** — abandoned-cart capture (authenticated + guest), sanitized snapshots, 256-bit hashed recovery links with 7-day expiry, 1h/24h/72h reminder sequence through WHMCS `SendEmail`, guest delivery via the WHMCS mailer, suppression/unsubscribe, AfterShoppingCartCheckout + OrderPaid conversion with authoritative order/invoice revenue, admin dashboard with analytics, CLI cron with lease locking and idempotent delivery. Tests: `npm test`, `npm run lint`, `test_static.py` in the addon dir; runbook in the addon `README.md`. |

## Provisioning (server) modules — `modules/servers/`

| Directory | Source archive | Notes |
|---|---|---|
| `RDP` | `RDP.zip` | RDP/VPS reseller provisioning (`WHMCS\Module\Server\RDP\Helper`). |
| `hostx_email` | `WHMCS Email Hosting Module.zip` | Email hosting provisioning + webhook endpoint. |
| `cloudhost247_lteproxy` | `cloudhost247_lteproxy.zip` | CloudHost247 LTE proxy reseller provisioning, with AJAX endpoints under `ajax/`. |
| `smmprovisioning` | `smm_whmcs_module.zip` | SMM order provisioning. Shares `modules/addons/smmaddon/lib/` (`Helper`, `ApiClient`), so `smmaddon` must be present. |
| `Smtphosting` | `smtphosting-whmcs-v3.zip` | ModulesGarden-style SMTP hosting reseller module (ships its own `vendor/`). |
| `soyoustart` | `WGS-OVH-v8.0.8-Sourcecode.zip` | OVH / SoYouStart **dedicated server** provisioning. |
| `soyoustart_vps` | `WGS-OVH-v8.0.8-Sourcecode.zip` | OVH / SoYouStart **VPS** provisioning. |

> `modules/addons/soyoustart` and `modules/servers/soyoustart` share a name but
> are **not** a conflict: WHMCS resolves addon and provisioning modules in
> separate namespaces, and the OVH product is designed as an addon (admin
> tooling) plus two provisioning modules.

## Payment gateways — `modules/gateways/`

| Path | Source archive |
|---|---|
| `blockonomics.php`, `blockonomics/`, `callback/blockonomics.php` | `blockonomics.zip` |

## Theme & order forms

| Path | Source archive(s) |
|---|---|
| `templates/hostx/` | `3dsecure.zip` (`.tpl` files, `images/`, `theme.yaml`) **+** `fonts.zip` (`css/`, `js/`, `img/`, `fonts/`, `webfonts/`, `includes/`, `hostx_includes/`, `banners/`, `flags/`, `marketconnect/`, `store/`, …) **+** `pages.zip` (`TPL/`) **+** `Announcement Bar CloudHost247.zip` |
| `templates/orderforms/hostx/` | `orderforms.zip` |
| `templates/orderforms/ovh_cart/` + `templates/orderforms/index.php` | `WGS-OVH-v8.0.8-Sourcecode.zip` |
| `crons/` (`emailSend.php`, `getIpStatus.php`, `getServer.php`, `priceSync.php`) | `WGS-OVH-v8.0.8-Sourcecode.zip` |
| `lang/overrides/english.php` (48 OVH strings merged in) | `WGS-OVH-v8.0.8-Sourcecode.zip` |
| root `*.php` legal/info pages | `pages.zip` (`PHP/`) |

> `3dsecure.zip` was misleadingly named: it contains the complete **Hostx**
> client-area theme (`theme.yaml` → `name: "Hostx"`), not a 3-D Secure gateway.
> `fonts.zip` likewise held the theme's asset/include directories, not just
> fonts. The two were merged into the single `templates/hostx/` theme directory.

## WGS OVH v8.0.8 — nesting correction

The archive was double-nested as
`WGS-OVH-v8.0.8-Sourcecode/whmcs/<real tree>`. Both wrapper levels were stripped
so the payload lands on the real WHMCS paths
(`modules/addons/soyoustart`, `modules/servers/soyoustart`,
`modules/servers/soyoustart_vps`, `templates/orderforms/ovh_cart`, `crons`,
`lang/overrides`) instead of an archive-named folder.

`templates/orderforms/ovh_cart` declares `config: parent: standard_cart`, so the
templates it does not override are inherited from WHMCS core.

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
* `WGS-OVH-v8.0.8-Sourcecode.zip` shipped five stray working copies next to the
  real files — `classes/ConsumerSetting.php__`, `assets/images/imap.svg_bkp`,
  `assets/images/logo.svg__`, `templates/clientareanew.tpl_bk` and
  `assets/js/script.js__`. The live versions were installed; the backups were
  not.
* `templates/orderforms/hostx/configureproduct.tpl_ovh` was an older OVH variant
  sitting beside the maintained `configureproduct.tpl` (which already contains
  the SoYouStart logic plus `{$WEB_ROOT}`-qualified asset URLs and the `w-hidden`
  class). The stale `.tpl_ovh` copy was removed.
* `lang/overrides/english.php` existed in both the repo (590 keys) and the OVH
  archive (48 keys). Key sets were compared — **zero collisions** — so the 48 OVH
  strings were appended under a marked section rather than overwriting the file.
  A pre-existing in-file duplicate (`$_LANG['domainregister']` defined twice,
  the second winning) was commented out so the file now has 638 unique keys.
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
* `templates/orderforms/hostx/configureproduct.tpl` loaded its OVH spinner from
  `modules/addons/soyoustart/images/30.gif`, which does not exist in the OVH
  package; repointed at the real
  `modules/addons/soyoustart/templates/assets/images/loading.gif`.
* `templates/orderforms/ovh_cart/*.tpl` referenced all 50 of its CSS/image
  assets with root-relative `templates/orderforms/{$carttpl}/…` URLs, which
  break under friendly URLs. Rewritten to `{$WEB_ROOT}/templates/orderforms/{$carttpl}/…`,
  matching the convention already used by the hostx order form.
* `templates/orderforms/index.php` shipped with `header("Location: ../../../../index.php")`
  — four levels up from `templates/orderforms/`. Corrected to `../../index.php`.
* Created `templates/hostx/css/overrides/override.css` and
  `templates/hostx/js/overrides/override.js` from the shipped `*.new` seeds —
  `includes/head.tpl` / `footer.tpl` reference the non-`.new` names.

## Expected (non-resolvable) duplicate content

A content hash sweep found 309 groups of byte-identical files. They are all
required by WHMCS / module conventions and were deliberately **not** merged:

* `templates/hostx/fonts` ↔ `templates/orderforms/hostx/fonts` and
  `templates/hostx/images` ↔ `templates/orderforms/hostx/images` ↔
  `templates/orderforms/ovh_cart/images` — each theme/order form is served from
  its own URL path and must carry its own assets.
* `modules/servers/soyoustart/assets/images` ↔
  `modules/servers/soyoustart_vps/assets/images` — two independent provisioning
  modules.
* `modules/servers/Smtphosting/templates/admin/**` ↔
  `.../templates/client/default/**` — ModulesGarden ships parallel admin/client
  UI trees.
