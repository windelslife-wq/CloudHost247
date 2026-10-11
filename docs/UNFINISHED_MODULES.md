# Unfinished modules / build code — inventory

Generated 2026-10-10 from the working tree plus `docs/MODULE_COMPLETION_TRACKER.md`.
Two independent methods were used, and a module/artifact appears below if **either** flags it:

1. **Code scan** — every `not implemented`, `placeholder`, `stub`, `not started` marker
   in `modules/`, `crons/`, `includes/`, `templates/` (vendored `node_modules` excluded).
2. **Tracker scan** — every non-`Verified` status, open finding, and owner decision (D-1…D-8)
   recorded in `docs/MODULE_COMPLETION_TRACKER.md`, plus modules in `docs/MODULES.md`
   that have **no completion record at all**.

Summary: **5 stub-code artifacts** (2 missing adapter classes done via A-9 2026-10-11; 4 empty theme templates done via A-8 2026-10-11; OCR placeholder done via A-5 2026-10-11; tools entry point done via A-6 2026-10-11), **17 partially complete modules** (B-2, B-3, B-5, B-9, B-13, B-15 done 2026-10-11), **7 modules with no completion record** (C-6, C-7 done 2026-10-11), **2 unauditable modules**, **1 project-level gap** (E-1 done 2026-10-11; E-2 live validation remains).

---

## A. Stub build code — explicit "not implemented" shipped in the tree

| # | Module | Unfinished code | Evidence | State |
|---|---|---|---|---|
| A-1 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Api.php` — throws `Smtphosting API is not implemented in this build` | line 21 | Fail-closed stub. Owner decision D-2 (accepted: keep as placeholder, gate closed) |
| A-2 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Cron.php` — `cron jobs are not implemented in this build: no scheduled jobs are registered` | line 21 | Same; no scheduled jobs exist (finding S-4: the admin page pointing at a missing `cron/cron.php` was deleted) |
| A-3 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Hooks.php` — `AppController hooks are not implemented in this build` | line 21 | Same; real hooks live in `App/Hooks/` |
| A-4 | `modules/servers/Smtphosting` | `Core/App/Controllers/Instances/Api/ApiController.php` — `API actions are not implemented in this build: no API specification is configured` | lines 18–24 | Same; `Core/Api` framework is orphaned/unconfigured |
| A-5 | `modules/addons/CloudHost247_tools` | **Done 2026-10-11 — real server OCR with enforced opt-in.** `CloudHost247_tool_image_to_text()` now performs OCR via OCR.space (key from the existing `ocr_api_key` setting) instead of returning `'status' => 'placeholder'`. The `server_ocr` opt-in flag is required and re-checked server-side; both frontends gate the submit on it so no bytes leave without consent. Images are validated in memory (type/size/dimensions) and never persisted; the transport is TLS-verified with mapped provider errors. Catalog `exec` is now `server` with honest copy — the unbuilt browser-OCR half is documented as a future enhancement, not shipped as a promise. | `tests/OcrTest.php` 69/69 + full suites green | — |
| A-6 | `modules/addons/CloudHost247_tools` | **Done 2026-10-11 — the `/tools/*` surface is live.** New `front.php` entry point (pure `includes/Front.php` dispatch + page rendering, covered by `tests/FrontTest.php`, 88 assertions) behind a minimal root `.htaccess` rule; `api/index.php` is now a real JSON endpoint delegating to the same dispatch. All pages, the POST-only `/tools/api/<slug>` endpoint, CSRF/rate-limit/enablement wiring, sitemap, 301 aliases and 404s work; the dead `tools-browse.js` asset reference (404 on every browse page) is removed. Owner decision **D-7** decided: proceed. | `tests/FrontTest.php` 88/88 + full suites green | — |
| A-7 | `modules/addons/cloudhost247ai` | **16 roadmap agent stubs** in `lib/Agents/AgentRegistry.php` `roadmap()` — registered but permanently inert (`CONFIGURATION_REQUIRED`), each naming the collector it lacks | lines ~188–246 | Declared, not built (see list below) |
| A-9 | `modules/addons/cloudhost247apps` | **Done 2026-10-11 — both App Cloud adapters implemented.** `lib/Adapters/CpanelAdapter.php` provisions cPanel accounts (deterministic 8-char usernames, idempotent create/verify, domain binding, gated MySQL with sealed outputs, suspend/terminate lifecycle, convergent rollback/destroy) through the WHM/UAPI panel layer, which gained the four missing `Mysql::*` wrappers. `lib/Adapters/KubernetesAdapter.php` + `KubernetesManifests.php` (pure renderer: Namespace/Secret/Deployment/Service/Ingress/PVC only) + `KubernetesClient.php` (worker-only server-side-apply transport, strict kubeconfig/TLS handling) cover rollout waits, scaling, restart, status/logs/health/metrics, guarded deletes and rollback. The suite 26/08 fail-closed pins are flipped to resolution pins. | `tests/27_CpanelAdapterTest.php` 114/114, `tests/28_KubernetesAdapterTest.php` 162/162, suites 08+26 green | — |
| A-8 | `templates/hostx` | **Done 2026-10-11 — 3 legacy templates blank by design, 1 orphan deleted.** `pwreset.tpl`, `creditcard.tpl`, `clientareacreditcard.tpl` are retired WHMCS 8.x template names (flows moved to the `password-reset-*` route templates, `checkout.tpl` and the `account-paymentmethods` templates); upstream `WHMCS/templates-six` ships them empty / intentionally-blank too, so the blank-page risk does not apply on this 8.x theme. Each now carries a non-rendering `{* *}` marker comment saying so. `all-elements.tpl` was not a WHMCS name at all — an unreferenced rename leftover (the live showcase is `all-element-hostx.tpl`, rendered by `all-element-hostx.php`) — deleted. No zero-byte `.tpl` remains. | upstream Six + `find templates/ -name '*.tpl' -size 0` → empty | — |

### A-7 detail — the 16 unbuilt AI agents (`cloudhost247ai`)

`infrastructure_guardian` · `server_health_agent` · `provisioning_agent` · `deployment_agent` ·
`security_sentinel` · `fraud_abuse_guardian` · `vulnerability_analyst` · `incident_commander` ·
`root_cause_analyst` · `cloud_cost_guardian` · `pricing_analyst` · `account_expansion_agent` ·
`internal_it_agent` · `hr_assistant` · `recruitment_assistant` · `asset_inventory_agent`

(Tier A/B ships 12 working agents; the 16 above are the "Tier C — declared, permanently inert
roadmap seats". Each needs a data collector the platform does not have: server telemetry, cost
feed, software inventory, HR/IT/ATS systems, asset register, etc.)

---

## B. Modules with a non-complete status (open findings, pending decisions, pending owner checks)

| # | Module | What is unfinished | Ref |
|---|---|---|---|
| B-1 | `modules/addons/hostx_tools` | **Retired (D-2 = A), deactivation pending in WHMCS.** Built ~4 tools; the spec asked for ~100 → **~96 tools never built**. No test suite. H-1 (rate-limit bypass by IP spoofing) fixed in code but exploitable until the addon is deactivated. | D-2, H-1…H-4 |
| B-2 | `modules/addons/digitalproducts` | **Done 2026-10-11 — D-6 decided (b): global issuance defaults.** New `license_default_domain_limit` / `license_default_activations_limit` addon settings (default 0 = unlimited) are stamped onto every license at grant time and managed on the admin settings screen; explicit per-license values still win and pre-existing licenses keep their stored limits. Suite 225 → 261 assertions, green. G-5 (ZIP symlink guard unverifiable) recorded, not changed. | Module 6 + suite |
| B-3 | `modules/addons/CloudHost247_tools` | **Done 2026-10-11 — verified, no code needed.** Both halves were already fixed: the `/tools/<slug>` entry point shipped via A-6 (`front.php` + root `.htaccess`, D-7 decided), and the legacy AJAX path delegates to `Runner::run()`/`respondLegacy()` (T2-1) with trusted-proxy-only client-IP resolution (H-1 carryover). Verified in code and pinned by `LegacyAjaxTest` + `ClientIpTest`; full tools suite 689/0 green. The inventory row was stale. | Module 7 + A-6 + suite |
| B-4 | `modules/addons/cloudhost247apps` | Out-of-scope features **not started**: customer account creation, SSO/login handoff, panel installation, licensing, product-to-package entitlement mapping. Provider catalog entries **unimplemented** (OVHcloud and AWS are catalog-only; source registers Hetzner, DigitalOcean, Vultr create-capable + Contabo read-only adoption). All four UAPI capabilities default-off; **no live cPanel staging run** (runbook prepared, not executed) and no live payment check. | Module 3, Phase 4/5 docs |
| B-5 | `modules/servers/hostx_email` | **Done 2026-10-11 — v1 encryption + one-time migration.** New writes are `hxem:v1:` (AES-256-GCM under WHMCS's own `$cc_encryption_hash`, fail-closed without a secret); reads dual-read v1 + legacy CBC; `upgrade/migrate-credentials.php` migrates `tblservers`/`tblhosting`/`tblproducts` (dry-run default, re-wraps as found, idempotent, never writes unclassifiable cells) and encrypts fresh secrets via `--encrypt-stdin`. The SystemURL key survives read-only for pre-v1 rows. Suite 13 → 53 checks, green. (Row also fixes the module path: `servers`, not `addons`.) | Audit item 2 + suite |
| B-6 | `modules/addons/phoneservices` | P-5 **open (High)**: shared API key is all-tenant access (can list/suspend/release any customer's numbers) and cannot be set from the admin UI. P-6 (addon password-field encryption), P-7 (`mysql_fetch_assoc()` used in 4+ classes), P-8 (status callbacks/DLRs only log, never update message records) all open. | P-5…P-8 |
| B-7 | `modules/addons/smmaddon` | M-2 partially fixed: AJAX actions still accept GET and include state changes (`sync_services`, `refresh_order`). M-5 **open (High, financial)**: client controls order quantity via an editable custom field; `smm_max = 0` enforces no cap. M-6 (no HTTPS requirement on `api_url`), M-7 (provider key stored plaintext) open. | M-2, M-5…M-7 |
| B-8 | `modules/addons/soyoustart` (+ `modules/servers/soyoustart`, `soyoustart_vps`) | SO-1 **open, recorded not patched**: 8 hardcoded OVH application keys in `classes/ApiCall.php` bypassing the configured application (so those call sites are likely already broken). SO-2: unvalidated `$endPoint` interpolated into the OVH URL path. **No addon test suite** (the C-5 server-module suite does not test these addon findings). | SO-1, SO-2 |
| B-9 | `modules/addons/customaffiliate` | **Done 2026-10-11 — proportional refunds + automatic regrouping.** Refunds reverse the refunded share (hook amount, else booked money-out, else full), capped cumulatively per entry; the first-paid flag resets only on full reversal. Upgrade/downgrade hooks run the package-change rules (regroup onto the live product, re-arm the first rate on moves into hosting); the audit regroups stale products. Suite 22 → 69 checks, green. | Audit item 1 + suite |
| B-10 | DNS checker spec (delivered inside `CloudHost247_tools`) | The standalone `dnschecker` addon was **never installed**; functionality folded in. Owner items pending: live UDP resolver check on production, caching decision, browser check. | Module 5 |
| B-11 | `modules/addons/tools_center` | `external-api/tools/productivity.php` `qrScanner()`/`qrGenerator()` remain (unused, no longer called — owner decision to remove or keep). T-8: TLS verification off in page-fetch tools (accepted). Wildcard CORS header (`Access-Control-Allow-Origin: *`) unchanged. Browser + live WHMCS checks pending. | Module 2/11 |
| B-12 | `modules/addons/cloudhost247passkey` | Documented limitations: secondary WHMCS users (`tblusers`) login handoff returns `SERVICE_UNAVAILABLE` until verified per-install; **Passkey + 2FA in one flow not implemented**; interactive Entra ID sign-in not enabled; no remember-me cookies; per-role staff permissions "arrive in a later phase". | PASSKEY.md §18 |
| B-13 | `modules/addons/cloudhost247_cart_recovery` | **Done 2026-10-11 — deep audit complete.** Full line-by-line audit of the module (~3,100 lines lib + entry points); 8 findings, all fixed with regression tests (suite 61 → 70 assertions, green). No module remains at baseline audit depth. | Module 14 follow-up |
| B-14 | `modules/addons/domainbroker` | Owner items pending: B-9 (`rdap_enabled` default), a live WHMCS run, and a real RDAP lookup. | Module 4 |
| B-15 | Announcement Bar (`templates/hostx/includes/announcementbar.tpl`) | **Done 2026-10-11 — 43 automated checks green.** First `templates/hostx/` harness (`templates/hostx/tests/announcementbar.test.mjs`, wired into `ci/run-js.sh`) mirrors the Module 10 conformance table, pins escaping/a11y/reduced-motion notes, regresses AB-1/AB-2, and guards AB-3's server-assigned-data precondition (static snippet data, safe example URL schemes, tripwire on repo PHP feeds). Test-writing also found **AB-4** (bar hijacked core `$announcements` on `/announcements.php`, duplicating page bodies into the marquee) — fixed by renaming the bar's variable to `$announcementBarMessages`. | Module 10 + suite |
| B-16 | `modules/addons/cloudhost247cloudflare` (+ `modules/servers/cloudhost247cloudflare`) | No live run; test suite was created by the audit pass (60 assertions). Remaining coverage gap recorded. | Module 12 |
| B-17 | `modules/addons/cloudhost247services` | Cleanest module reviewed (no functional defect) but **no live WHMCS run**; 12 read-only handlers documented as not carrying CSRF verify calls; remaining coverage gaps recorded, not fixed. | Module 8 |
| B-18 | OVH / SoYouStart crons (`crons/priceSync.php`, `getServer.php`, `getIpStatus.php`, `emailSend.php`) | No completion record and no tests anywhere in the tracker. | — |

---

## C. Modules with NO completion record and NO tests (never audited)

These ship in the repo and in `docs/MODULES.md` but appear nowhere in
`docs/MODULE_COMPLETION_TRACKER.md` — i.e. outside every assurance pass so far.
The tracker's own rule counts them as unfinished.

| # | Module | Size | Tests |
|---|---|---|---|
| C-1 | `modules/servers/RDP` | 7 PHP files, 707 lines. **Done 2026-10-11 — audited + 121-check suite.** Seventeen findings fixed: PHP 8 fatal on panel errors (RD-1), plaintext credential storage (RD-2, now `encrypt()`), passwords in module log (RD-3, masked), missing create idempotency (RD-4), admin-tab + template XSS (RD-5/RD-6), ipaddress copy-paste (RD-7), TestConnection/renew/clientarea shapes (RD-8–RD-13), orphan-row guards (RD-14/RD-15), 10000 s timeout → 30 s + TLS pins (RD-16), hook log var (RD-17). Open: suspend/terminate are local-only (vendor gap), SSO labels w/o handlers. Separator-less endpoint concatenation deliberately preserved (evidence: tested live integration) + statically pinned. New `mod_rdp` CI step. | Suite 121 checks, green |
| C-2 | `modules/servers/cloudhost247_lteproxy` | 27 PHP files, ~6,300 lines. **Done 2026-10-11 — audited + 430-check suite.** Twenty-seven findings fixed: ajax IDOR across every order-scoped handler (LP-1, now `proxyBelongsToOrder` + 404s), PHP 8 total client-area fatal via `random_bytes(float)` (LP-2, `(int)` cast), dead hooks with no bootstrap (LP-3, new `hooks.php`), quantity falling back to the API timeout (LP-4), cron terminating zero-date services (LP-5, raw-value guard), retries replaying POST purchases (LP-6, idempotent-only gate), client-JS XSS sweep incl. selector sink (LP-7), template escapes (LP-8), create double-order (LP-9), wrong client-IP whitelisting (LP-10), log credential leak (LP-11), `unserialize` cache → JSON (LP-12), raw `$_GET` action var (LP-13), +30d due dates → cycle table (LP-14), notes clobbering (LP-15), duplicate mails (LP-16), dead sidebar (LP-17), CSRF-via-GET (LP-18), exception-message disclosure (LP-19), unsafe HTTP codes (LP-20), vendor-code TypeErrors (LP-21), US-only carrier check (LP-22), proxy-URL encoding (LP-23), duration/quality formatting (LP-24), runtime-dir guards + silencers (LP-25), input-hardening bundle (LP-26), AjaxHandlers extraction (LP-27). Open: hooks.php needs a manual copy to /includes/hooks (LP-O1), suspend is local-only (LP-O2, vendor gap). New `mod_lteproxy` CI step. | Suite 430 checks, green |
| C-3 | `modules/servers/smmprovisioning` | 1 PHP file, 396 lines. **Done 2026-10-11 — audited + 103-check suite.** Six findings fixed: CreateAccount idempotency mirroring M-4 (SP-1, Medium — double-spend), ignored cancel results (SP-2), fuzzy field-extraction misfires (SP-3), service-notes clobber (SP-4), endpoint fail-fast (SP-5), template XSS + `javascript:` href (SP-6). Quantity-trust stays under open owner decision M-5; two addon-scope sibling observations flagged, not patched. New `mod_smmprovisioning` CI step. | Suite 103 checks, green |
| C-4 | `modules/servers/soyoustart_vps` | 16 PHP files, 4,139 lines. **Done 2026-10-11 — audited + 165-check suite.** Eighteen findings fixed: dead InvoicePaid debug gate (VS-2, High), client→admin stored XSS (VS-3, Medium), backup multi-order/price bug (VS-11, Medium), fatal undefined IP-details call (VS-23, Medium), MetaData typo (VS-1), power/IP/task path validation (VS-4/13/14/15), currency-key display bug (VS-24), plus typo/guard/robustness hardening (VS-5–10/12/16). Six vendor/owner-side items recorded open (VS-17–VS-22: growl HTML, CSRF, ACL enforcement, CDN SRI, license ping, ipinfo leak). New `mod_soyoustart_vps` CI step. | Suite 165 checks, green |
| C-5 | `modules/servers/soyoustart` | 18 original PHP files, ~5,134 lines. **Done 2026-10-11 — targeted audit/patch + 131-check suite.** SD-1 client IP ownership, SD-2 additional-IP invoice/price-before-checkout, SD-3 undefined IP-details builder, SD-4 dropped location custom field, SD-5 stored XSS escapes, SD-6 path validation, SD-7–10 typo/robustness, SD-11 unauthenticated standalone admin AJAX (admin+POST+service binding), SD-12 hardcoded cancel task, SD-13 lib guards, SD-14 shared VPS hook payment-method selection. **Open:** CSRF/ACL + vendor JS/CDN/ipinfo/license, non-atomic OVH checkout → WHMCS invoice, shared addon Snapshot-on-IP-invoice hook, no-op Renew; SO-1/SO-2 still open in the addon. New `mod_soyoustart` CI step. | Suite 131 checks, green; no live OVH/WHMCS run |
| C-6 | `modules/servers/cloudhost247cloudflare` | 1 PHP file, 139 lines. **Done 2026-10-11 — audited + 51-check suite.** Two findings fixed: unescaped admin-tab values (CF-S1, Low) and a dead import + missing `??` guard (CF-S2, Trivial). Contracts, idempotent terminate, fail-closed logging and the escaped client template all pinned; wired into the `mod_cloudflare` CI step. | Suite 51 checks, green |
| C-7 | `modules/gateways/blockonomics` + `blockonomics.php` + `callback/blockonomics.php` | 17 PHP files, ~3,700 lines. **Done 2026-10-11 — audited + 247-check suite.** Eleven findings fixed: garbage-hash guard (BN-1), hash MAC (BN-2), `->name` reads (BN-3), poller CLI-only + single-flight (BN-4, Medium), curl timeouts (BN-5), USDT Test Setup (BN-6), USDT attempt isolation (BN-7), JSON/`is_countable`/`Throwable` hardening (BN-8/9/10), output escaping (BN-11). New `mod_blockonomics` CI step. | Suite 247 checks, green |

---

## D. Cannot be audited, tested or linted — 100% ionCube-encoded

| # | Module | Size | Ref |
|---|---|---|---|
| D-1 | `modules/addons/hostx` | **63 of 63 PHP files encoded**, 47,701 lines — the largest single block of code in the repo | D-8 (open owner decision) |
| D-2 | `modules/addons/xtreme_currency_rates` | **18 of 18 PHP files encoded**, 4,478 lines | D-8 (open owner decision) |

---

## E. Project-level gaps (not a module, but unfinished build infrastructure)

| # | Gap | Evidence |
|---|---|---|
| E-1 | **Done 2026-10-11 — CI added.** `.github/workflows/ci.yml` runs all 17 PHP suites (8.3 full matrix + 7.4 legacy leg), the Node suites, a repo-wide `php -l` sweep and the Python static checks on every push/PR. See `docs/CI.md`. | workflow + `ci/` drivers + this line |
| E-2 | **No live validation anywhere.** Every module's record ends with "no live WHMCS run" / "no browser run" / "no live provider call"; the sandbox cannot reach a WHMCS install, cPanel staging host, or any provider API. Owner-run checks are the standing blocker for 10+ modules. | tracker "Tests" sections |

---

## Open owner decisions carried in the tracker

| ID | Decision | Module |
|---|---|---|
| D-2 | Retire `hostx_tools` — approved, **deactivation in WHMCS still pending** | `hostx_tools` |
| D-6 | License activation limits — **decided 2026-10-11: (b) global addon default**, implemented via B-2 | `digitalproducts` |
| D-7 | Add root rewrite + front controller to make `/tools/<slug>` live | `CloudHost247_tools` |
| D-8 | Policy for the two ionCube-encoded modules | `hostx`, `xtreme_currency_rates` |
| P-5 | Keep or scope the all-tenant `phoneservices` API key | `phoneservices` |
| M-5 | Derive SMM order quantity from the paid product/config option; enforce `smm_max` even at 0 | `smmaddon` |

---

## F. Re-scan 2026-10-11 — new findings not yet in sections A–E

A fresh code scan on 2026-10-11 (excluding vendored libraries and `node_modules`) confirmed
the sections above and found these additional items. None is completed yet; each needs an
owner decision or a live check first.

| # | Location | Finding | Needs |
|---|---|---|---|
| F-1 | `templates/hostx/js/overrides/override.js` | **Zero-byte**, but loaded on every page by `templates/hostx/footer.tpl:38`. Sits next to an orphan zero-byte `override.js.new`. | Owner: keep as an empty custom-JS hook (add an intent comment) or delete the reference. |
| F-2 | `modules/servers/Smtphosting/templates/{admin,client/default}/pages/home/home.tpl` | **Zero-byte.** `Core/Http/Response.php:149` uses `home` as the default page, so the default Smtphosting page renders blank. The `EasyDCIM/home.tpl` copy in `templates/assets/tpl/` is also empty. | Owner: what the home page should show. |
| F-3 | `modules/addons/cloudhost247apps/lib/ControlPanels/CpanelWhmClient.php` | Many UAPI bandwidth/quota/database/privilege operations throw "not implemented". These are allowlist guards on a deliberately narrow adapter, not missing code. | Owner: confirm which operations must be supported. |
| F-4 | `modules/servers/Smtphosting/Core/...` | Unresolved `@TODO` notes in the vendored framework (e.g. `CommandLine/CommandLoop.php` "remove me", `Tasks/Manager.php` pagination). | Low priority; framework code. |
| F-5 | `modules/addons/hostx` (63 files) and `modules/addons/xtreme_currency_rates` (18 files) | Still ionCube-encoded; no source to audit. | Already D-8. |

Zero-byte files under `node_modules` (in `cloudhost247services`) are third-party packages and are not counted.

---

## What this list is not

- It is **not** a list of bugs: most findings behind section B are already fixed in code; the
  entries are the parts still open (owner decisions, live checks, unimplemented scope).
- "Unfinished" here includes both *unwritten code* (section A, C, D) and *unverified code*
  (section B, E), because the tracker's own definition of "complete" requires executed
  acceptance criteria, not just shipped source.
