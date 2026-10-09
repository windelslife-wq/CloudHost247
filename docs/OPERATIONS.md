# CloudHost247 Operations Guide

Operator-facing runbook for the CloudHost247 services suite on top of
WHMCS + HostX. Everything here reflects what is actually wired in code —
no aspirational features.

## 1. Deploy checklist (new environment)

1. Upload the repository over the WHMCS web root (files under `templates/`,
   `modules/`, root landing pages, `sitemap.xml`, `sitemap.html`).
2. WHMCS admin → **Apps & Integrations** → enable **CloudHost247 Services**.
   The module migrator (0001–0014) creates all module tables and seeds club
   plans, TLD metadata, and inbox labels. Migration 0012 adds the domain
   platform tables (providers, transfers, DNS records, searches, jobs,
   renewals, events, expiration notices) — see
   [`DOMAIN_SERVICES.md`](DOMAIN_SERVICES.md). Migrations 0013–0014 add the
   infrastructure layer (OS catalog, provider image mappings, provisioning
   jobs, module servers, SSH keys) and seed the 12-OS catalog — see
   [`INFRASTRUCTURE.md`](INFRASTRUCTURE.md).
3a. Domain platform credentials: set the `CHS_CREDENTIALS_KEY` environment
   variable (32 random bytes, hex) **before** saving any HTTP registrar
   provider credential — saving fails closed without it. Registrar provider
   setup: admin → CloudHost247 Services → **Domain Providers**. Until a
   write-capable provider is configured, availability/WHOIS/pricing are real
   via the WHMCS chain, but register/transfer/renew/DNS writes fail honestly
   with `PROVIDER_OPERATION_UNSUPPORTED` and wait in **Operations (Jobs)**.
3. Module settings (admin → addon modules → CloudHost247 Services):
   - `service_enabled` = on
   - `system_url` = `https://<your-host>/` (drives sitemap.xml generation)
   - Registrar/AI credentials: without a registrar API key, domain search
     still uses the WHMCS cart chain (real availability), but transfer price
     quotes and valuation comparables degrade gracefully rather than pretend.
4. Cron (every 5 minutes):

   ```
   */5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247services/cron/cloudhost247services.php
   ```

   Runs auction heartbeat + invoice lapse, club expiry, sitemap
   regeneration, and the domain platform: expiration checks + auto-renew
   notices, provider/platform sync, reconciliation, and the job-queue drain.
   Auctions and domain renewals depend on this cadence; do not run it less
   often.
5. Confirm `sitemap.xml` is web-writable by the cron user if you want
   regenerated versions to land (the suite logs and skips on failure).

### Web-server performance and security baseline

The overlay does not modify WHMCS or the web server. Apply these settings in
staging and verify them with the deployment owner:

- enable Brotli (preferred) or gzip for HTML, CSS, JavaScript, JSON, SVG and
  XML; never compress already-compressed private downloads;
- serve immutable, versioned static assets with a long `Cache-Control` lifetime,
  while keeping HTML and account pages private/non-cacheable;
- use HTTPS, HSTS only after all subdomains are HTTPS, and a restrictive
  `Content-Security-Policy` reviewed against the configured payment, chat,
  analytics and CAPTCHA providers;
- route clean public URLs to the existing root landing files only when the
  rewrite preserves the canonical URL and does not expose module internals;
- disable directory indexes and deny access to module `install/`, `tests/`,
  runtime storage, logs and environment files;
- confirm the HostX cookie banner is enabled and configured as an opt-in
  banner before enabling analytics or marketing identifiers.

The HostX shell now loads the consent control on every page, stores a local
policy/version decision for the browser and injects optional trackers only
after an explicit allow action. When the Services addon is active, the endpoint records a
pseudonymous decision history in `consent_records`; it is still not a legal compliance
assessment or a replacement for an
operator-controlled retention and audit process. Configure **Consent history retention**
in the module Settings page; the daily hook prunes records older than that value, while `0`
keeps them indefinitely.

## 2. Top mega menu — how it's wired, and the manual fallback

### Automatic (shipped)

`modules/addons/cloudhost247services/hooks.php` registers a
`ClientAreaPage` hook at priority 90. On every client-area page it merges
the four suite categories (Domains / Websites & Builders / Marketing /
Hosting & Services, 33 destinations) into HostX's `$topMenusData`. Both
desktop menu layouts (dropdown/default/latest) and the mobile drawer render
from that same array, so one hook covers all three presentations. New-item
badges ride the menu item `name` field (HostX outputs it unescaped), using
Bootstrap `badge` classes — no extra CSS.

The merge is **name-idempotent**: re-running never duplicates, and entries
an admin created by hand with the same visible name are preserved (the
admin's entry wins, suite entries are skipped for that name).

### Manual fallback (only if automation is disabled)

If the module is deactivated or you prefer hand-configured menus:

1. Admin → **Settings → Theme Settings (HostX) → Menu Manager**.
2. Create four top-level entries of menu type **mega menu**:
   `Domains`, `Websites & Builders`, `Marketing`, `Hosting & Services`.
3. Under each, add groups and links matching
   `modules/addons/cloudhost247services/lib/Http/HostxMenu.php` — that file
   is the canonical source of destinations; every URL in it resolves to a
   real page (asserted by `10_MenuTest.php`).
4. Keep the badge markup in the label, e.g.
   `AI Website Builder <span class="badge badge-danger">NEW</span>`.
5. Mobile requires nothing extra — HostX's `mobile-menu.tpl` renders the
   same data as a drawer with accordions.

### Cleanup after enabling the module

If menus were configured by hand **before** the module shipped, delete the
hand-made top-level entries for the four categories in the HostX menu
manager. The hook supplies those categories now; leaving duplicates behind
shows two "Domains" menus (the admin's original is preserved by design, so
the hook will not overwrite it — clean-up is a human decision).

## 3. Auctions operations

- Listing states: `scheduled → live → ended → settled | expired | lapsed`.
- Settlement creates a real WHMCS invoice for the winner; if unpaid by the
  `auction_invoice_lapse` window, the listing lapses and the deposit policy
  (module setting) applies. No silent states — every transition lands in
  `audit_log`.
- Anti-manipulation: self-bidding, shill patterns (bidder/owner overlap),
  and extension sniping are blocked server-side, not just in the UI.

## 4. Valuation engine

Rules-based, deterministic, versioned (`valuation_engine_version` setting).
Output always carries the disclaimer that an estimate is **not** a binding
offer. To upgrade the engine: bump the version constant, keep the old
version for cached historical valuations, add tests — never mutate stored
valuations retroactively.

## 5. Sitemap

- `sitemap.xml` is regenerated by cron from the canonical page list in
  `lib/Services/SitemapService.php` — a URL is only emitted if the backing
  file exists, so the sitemap can never point at a dead page.
- `sitemap.html` is the human-readable mirror; update it when adding new
  public landing pages.

## 6. Digital Products Marketplace

The addon is upgraded in place at `modules/addons/digitalproducts/`; do not install a
second digital-products module. Configure `DIGITALPRODUCTS_STORAGE` to a writable path
outside the WHMCS document root and set `DIGITALPRODUCTS_ENCRYPTION_KEY` before creating
licenses. Activate it from WHMCS Addon Modules so its additive migrations can preserve
legacy products and files.

Run maintenance every five minutes:

```text
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php
```

The cron expires download tokens and rate-limit windows; it never silently deletes
download history. Verify a real paid staging order, a duplicate payment hook, a
cancellation, and a wrong-customer token before production release. See
[`DIGITAL_PRODUCTS.md`](DIGITAL_PRODUCTS.md) for storage, schema and incident response.

## 7. Domain Services platform (v1.1.0)

The domain platform lives inside this same addon — no second module, billing
or customer system. WHMCS stays the system of record; the module links to
`tbldomains`, invoices and clients and never duplicates them.

- **Customer surfaces:** `/domains` + `/domain.php` (3-column Domain Services
  section), `domain-search.php`, `bulk-domain-search.php` (results + CSV
  export), `domain-transfer.php`, plus client-area module pages
  (`action=search|bulk|bulkview|domains|domain|transfers|transfer`) with
  auto-renew, privacy, nameservers and DNS-record management.
- **Admin surfaces:** Domains, Domain Providers, Transfers and
  Operations (Jobs) sections; domain KPIs on the Overview page; domain
  settings on the Settings page.
- **Providers:** resolution is TLD mapping → default provider → WHMCS
  registrar chain → null. The default WHMCS provider answers
  availability/WHOIS/pricing for real and refuses writes honestly
  (`PROVIDER_OPERATION_UNSUPPORTED`). Add a write-capable HTTP provider on
  the Domain Providers page; credentials are sealed with AES-256-GCM under
  `CHS_CREDENTIALS_KEY` and never reach the browser.
- **Jobs:** cron enqueues `DOMAIN_EXPIRATION_CHECK`,
  `DOMAIN_PROVIDER_SYNC` and `DOMAIN_RECONCILIATION` (per-window idempotency
  keys) and drains the queue; `InvoicePaid` drives registration, transfer and
  renewal jobs after verifying the invoice is actually paid.
- **Honest failures:** unconfigured capabilities surface
  `DOMAIN_PROVIDER_NOT_CONFIGURED` / `DOMAIN_LOOKUP_UNAVAILABLE` /
  `PROVIDER_OPERATION_UNSUPPORTED` — never fabricated data.

Full runbook (schema, provider setup, settings, job table, audit events,
limitations): [`DOMAIN_SERVICES.md`](DOMAIN_SERVICES.md).

## 8. Testing & lint gates

From `modules/addons/cloudhost247services/`:

```bash
npm install               # test harness deps (php-wasm)
node tests/run.mjs        # 1062 assertions currently pass (21 suites)
node tests/lint.mjs       # module PHP parse gate: BAD=0 LINT_OK
node tests/lint-root.mjs  # root landing pages parse gate: ROOT_LINT_OK
```

All three must be green before any commit. The lint runners must exit
cleanly (the `process.exit(0)` at the end is load-bearing — the php-wasm
runtime keeps the Node event loop alive otherwise).

From `modules/addons/digitalproducts/`:

```bash
node tests/run.mjs        # 10 assertions currently pass
node tests/lint.mjs       # module PHP parse gate: BAD=0 LINT_OK
```

From `modules/addons/cloudhost247ai/`:

```bash
node tests/run.mjs        # 370 assertions / 10 suites (incl. adversarial fabrication suite)
node tests/lint.mjs       # 45 module PHP files load clean: BAD=0 LINT_OK
```

`node_modules/` is local-only and git-ignored on purpose.

## 8a. CloudHost247 AI control plane

Activate `modules/addons/cloudhost247ai/` from WHMCS Addon Modules (additive
migrations only — deactivation drops nothing). It is fully functional as a
read-only layer without any model provider; every AI surface then fails closed
with `CONFIGURATION_REQUIRED` instead of guessing. Configure the model
endpoint on its Settings page (self-hosted vLLM/Ollama keeps customer data
on-premise) and set `CH247AI_API_KEY` in the environment if the endpoint
requires a key — keys are never stored in the database.

Run the drain cron every five minutes:

```text
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/cloudhost247ai/cron/cloudhost247ai.php
```

The cron drains captured events, runs the scheduled agents at the configured
briefing hour, composes the daily briefing, expires stale approvals and prunes
per the retention settings. Web hooks never call a model. If AI behaviour is
ever suspect, engage the kill switch on the module's Settings page — it stops
every model call and tool execution immediately without deactivating the
module. Full runbook: [`AI_CONTROL_PLANE.md`](AI_CONTROL_PLANE.md).

## 9. Credentials matrix (what needs what)

| Feature | Works without credentials | Needs |
|---|---|---|
| Domain search / bulk | Yes (WHMCS cart chain via default provider) | Registrar API for live quotes beyond cart |
| Domain registration / renewal | Real availability + invoicing; registration job fails honestly | Write-capable provider + `CHS_CREDENTIALS_KEY` env (sealed credentials) |
| Transfers | EPP flow UI + state machine + real invoices | Registrar API to submit transfers (writes surface `PROVIDER_OPERATION_UNSUPPORTED` until then) |
| Domain management / DNS | Reads + honest capability refusals | Write-capable provider for nameserver/DNS writes |
| Auto-renewal | Notices + invoices via existing gateway | Registrar API to execute renewals |
| WHOIS lookup | Yes (port-43 whois) | — |
| Valuation | Yes (rules engine) | Comparable-sales feed (optional, marked) |
| Auctions | Yes (full lifecycle) | Payment gateway for settlement invoices |
| Discount Club | Yes | — |
| Logo maker | Yes (deterministic SVG studio) | — |
| AI website builder | Structure + draft UI | AI provider key (`ai_api_key` setting) — until set, the feature shows the integration contract, never fake output |
| Unified inbox | Yes (reads WHMCS tickets) | — |
| Digital marketing / Hire-an-expert | Brief → invoice flow | Consultant fulfilment (business process) |
| AI control plane (`cloudhost247ai`) | Yes — installs, audits, briefings metrics-only, shows clear config state | Model endpoint (+ `CH247AI_API_KEY` env if the endpoint needs one) for model-narrated answers; the CloudHost247_tools addon for live diagnostics |

Anything in the right-hand column is labelled in-product with exactly what
to configure; nothing shows fabricated availability or results.
