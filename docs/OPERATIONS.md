# CloudHost247 Operations Guide

Operator-facing runbook for the CloudHost247 services suite on top of
WHMCS + HostX. Everything here reflects what is actually wired in code —
no aspirational features.

## 1. Deploy checklist (new environment)

1. Upload the repository over the WHMCS web root (files under `templates/`,
   `modules/`, root landing pages, `sitemap.xml`, `sitemap.html`).
2. WHMCS admin → **Apps & Integrations** → enable **CloudHost247 Services**.
   The module migrator (0001–0011) creates all module tables and seeds club
   plans, TLD metadata, and inbox labels.
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

   Runs auction heartbeat + invoice lapse, club expiry, and sitemap
   regeneration. Auctions depend on this cadence; do not run it less often.
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

## 7. Testing & lint gates

From `modules/addons/cloudhost247services/`:

```bash
npm install               # test harness deps (php-wasm)
node tests/run.mjs        # 704 assertions currently pass
node tests/lint.mjs       # module PHP parse gate: BAD=0 LINT_OK
node tests/lint-root.mjs  # root landing pages parse gate: ROOT_LINT_OK
```

All three must be green before any commit. The lint runners must exit
cleanly (the `process.exit(0)` at the end is load-bearing — the php-wasm
runtime keeps the Node event loop alive otherwise).

`node_modules/` is local-only and git-ignored on purpose.

## 7. Credentials matrix (what needs what)

| Feature | Works without credentials | Needs |
|---|---|---|
| Domain search / bulk | Yes (WHMCS cart chain) | Registrar API for live quotes beyond cart |
| Transfers | EPP flow UI + state machine | Registrar API to submit transfers |
| WHOIS lookup | Yes (port-43 whois) | — |
| Valuation | Yes (rules engine) | Comparable-sales feed (optional, marked) |
| Auctions | Yes (full lifecycle) | Payment gateway for settlement invoices |
| Discount Club | Yes | — |
| Logo maker | Yes (deterministic SVG studio) | — |
| AI website builder | Structure + draft UI | AI provider key (`ai_api_key` setting) — until set, the feature shows the integration contract, never fake output |
| Unified inbox | Yes (reads WHMCS tickets) | — |
| Digital marketing / Hire-an-expert | Brief → invoice flow | Consultant fulfilment (business process) |

Anything in the right-hand column is labelled in-product with exactly what
to configure; nothing shows fabricated availability or results.
