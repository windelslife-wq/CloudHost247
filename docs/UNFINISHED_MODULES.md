# Unfinished modules / build code — inventory

Generated 2026-10-10 from the working tree plus `docs/MODULE_COMPLETION_TRACKER.md`.
Two independent methods were used, and a module/artifact appears below if **either** flags it:

1. **Code scan** — every `not implemented`, `placeholder`, `stub`, `not started` marker
   in `modules/`, `crons/`, `includes/`, `templates/` (vendored `node_modules` excluded).
2. **Tracker scan** — every non-`Verified` status, open finding, and owner decision (D-1…D-8)
   recorded in `docs/MODULE_COMPLETION_TRACKER.md`, plus modules in `docs/MODULES.md`
   that have **no completion record at all**.

Summary: **6 stub-code artifacts** (incl. 2 missing adapter classes; 4 empty theme templates done via A-8 2026-10-11; OCR placeholder done via A-5 2026-10-11), **17 partially complete modules** (B-13 done 2026-10-11), **7 modules with no completion record**, **2 unauditable modules**, **1 project-level gap** (E-1 done 2026-10-11; E-2 live validation remains).

---

## A. Stub build code — explicit "not implemented" shipped in the tree

| # | Module | Unfinished code | Evidence | State |
|---|---|---|---|---|
| A-1 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Api.php` — throws `Smtphosting API is not implemented in this build` | line 21 | Fail-closed stub. Owner decision D-2 (accepted: keep as placeholder, gate closed) |
| A-2 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Cron.php` — `cron jobs are not implemented in this build: no scheduled jobs are registered` | line 21 | Same; no scheduled jobs exist (finding S-4: the admin page pointing at a missing `cron/cron.php` was deleted) |
| A-3 | `modules/servers/Smtphosting` | `Core/App/Controllers/AppControllers/Hooks.php` — `AppController hooks are not implemented in this build` | line 21 | Same; real hooks live in `App/Hooks/` |
| A-4 | `modules/servers/Smtphosting` | `Core/App/Controllers/Instances/Api/ApiController.php` — `API actions are not implemented in this build: no API specification is configured` | lines 18–24 | Same; `Core/Api` framework is orphaned/unconfigured |
| A-5 | `modules/addons/CloudHost247_tools` | **Done 2026-10-11 — real server OCR with enforced opt-in.** `CloudHost247_tool_image_to_text()` now performs OCR via OCR.space (key from the existing `ocr_api_key` setting) instead of returning `'status' => 'placeholder'`. The `server_ocr` opt-in flag is required and re-checked server-side; both frontends gate the submit on it so no bytes leave without consent. Images are validated in memory (type/size/dimensions) and never persisted; the transport is TLS-verified with mapped provider errors. Catalog `exec` is now `server` with honest copy — the unbuilt browser-OCR half is documented as a future enhancement, not shipped as a promise. | `tests/OcrTest.php` 69/69 + full suites green | — |
| A-6 | `modules/addons/CloudHost247_tools` | `api/index.php` — literal placeholder (`Placeholder for future API endpoints`), and the `/tools/<slug>` Router/Runner surface has **no request entry point** (no `.htaccess`, no front controller) | file body; finding **T2-4**; owner decision **D-7** | 531 PHP assertions currently guard traffic that never arrives |
| A-7 | `modules/addons/cloudhost247ai` | **16 roadmap agent stubs** in `lib/Agents/AgentRegistry.php` `roadmap()` — registered but permanently inert (`CONFIGURATION_REQUIRED`), each naming the collector it lacks | lines ~188–246 | Declared, not built (see list below) |
| A-9 | `modules/addons/cloudhost247apps` | **Two adapter classes are referenced but do not exist**: `Ch247Apps\Adapters\CpanelAdapter` (mapped to the `cpanel` / `whm` / `uapi` engines) and `Ch247Apps\Adapters\KubernetesAdapter` (mapped to `kubernetes`) in `lib/Adapters/AdapterFactory.php` `ENGINES`. No `class CpanelAdapter` / `class KubernetesAdapter` anywhere in the repo, no git history. The factory fails closed with `ADAPTER_NOT_INSTALLED`. | `lib/Adapters/AdapterFactory.php` lines 28–34; tracker finding **A-3** | Declared engines with no implementation |
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
| B-2 | `modules/addons/digitalproducts` | **D-6 open — license activation limits are unreachable.** `License::activateLicense()` honours `domain_limit`/`activations_limit`, but nothing ever supplies them, so every license issues with unlimited activations. No admin/product surface exists. G-5 (ZIP symlink guard unverifiable) recorded, not changed. | G-4, G-5, D-6 |
| B-3 | `modules/addons/CloudHost247_tools` | **D-7 open — `/tools/<slug>` routing.** Router/Runner built and tested but no rewrite rule/front controller; the modern surface guards no live traffic. Cross-module note: the **legacy AJAX path** (`includes/classes.php`, `CloudHost247ToolsClient::handleAjax()`) trusts `X-Forwarded-For` for rate limiting and bypasses the runner's request-size cap, heavy-tier limit and global per-IP ceiling. | T2-4, D-7 |
| B-4 | `modules/addons/cloudhost247apps` | Out-of-scope features **not started**: customer account creation, SSO/login handoff, panel installation, licensing, product-to-package entitlement mapping. Provider catalog entries **unimplemented** (OVHcloud and AWS are catalog-only; source registers Hetzner, DigitalOcean, Vultr create-capable + Contabo read-only adoption). All four UAPI capabilities default-off; **no live cPanel staging run** (runbook prepared, not executed) and no live payment check. | Module 3, Phase 4/5 docs |
| B-5 | `modules/addons/hostx_email` | H-4 **open**: stored credentials encrypted under `sha256(SystemURL . 'HostXEmail_v1.0.0')` — not a secret key, no migration path yet. H-6 open: AES-256-CBC without a MAC. | H-4, H-6 |
| B-6 | `modules/addons/phoneservices` | P-5 **open (High)**: shared API key is all-tenant access (can list/suspend/release any customer's numbers) and cannot be set from the admin UI. P-6 (addon password-field encryption), P-7 (`mysql_fetch_assoc()` used in 4+ classes), P-8 (status callbacks/DLRs only log, never update message records) all open. | P-5…P-8 |
| B-7 | `modules/addons/smmaddon` | M-2 partially fixed: AJAX actions still accept GET and include state changes (`sync_services`, `refresh_order`). M-5 **open (High, financial)**: client controls order quantity via an editable custom field; `smm_max = 0` enforces no cap. M-6 (no HTTPS requirement on `api_url`), M-7 (provider key stored plaintext) open. | M-2, M-5…M-7 |
| B-8 | `modules/addons/soyoustart` (+ `modules/servers/soyoustart`, `soyoustart_vps`) | SO-1 **open, recorded not patched**: 8 hardcoded OVH application keys in `classes/ApiCall.php` bypassing the configured application (so those call sites are likely already broken). SO-2: unvalidated `$endPoint` interpolated into the OVH URL path. **No test suite**, none added (vendored third-party code). | SO-1, SO-2 |
| B-9 | `modules/addons/customaffiliate` | Partial refunds still reset the full first-commission flag (documented, conservative). `UpgradeHandler` + upgrade/downgrade hooks only record notes — re-grouping runs only from the manual utility. | C-1…C-3 |
| B-10 | DNS checker spec (delivered inside `CloudHost247_tools`) | The standalone `dnschecker` addon was **never installed**; functionality folded in. Owner items pending: live UDP resolver check on production, caching decision, browser check. | Module 5 |
| B-11 | `modules/addons/tools_center` | `external-api/tools/productivity.php` `qrScanner()`/`qrGenerator()` remain (unused, no longer called — owner decision to remove or keep). T-8: TLS verification off in page-fetch tools (accepted). Wildcard CORS header (`Access-Control-Allow-Origin: *`) unchanged. Browser + live WHMCS checks pending. | Module 2/11 |
| B-12 | `modules/addons/cloudhost247passkey` | Documented limitations: secondary WHMCS users (`tblusers`) login handoff returns `SERVICE_UNAVAILABLE` until verified per-install; **Passkey + 2FA in one flow not implemented**; interactive Entra ID sign-in not enabled; no remember-me cookies; per-role staff permissions "arrive in a later phase". | PASSKEY.md §18 |
| B-13 | `modules/addons/cloudhost247_cart_recovery` | **Done 2026-10-11 — deep audit complete.** Full line-by-line audit of the module (~3,100 lines lib + entry points); 8 findings, all fixed with regression tests (suite 61 → 70 assertions, green). No module remains at baseline audit depth. | Module 14 follow-up |
| B-14 | `modules/addons/domainbroker` | Owner items pending: B-9 (`rdap_enabled` default), a live WHMCS run, and a real RDAP lookup. | Module 4 |
| B-15 | Announcement Bar (`templates/hostx/includes/announcementbar.tpl`) | Complete against spec but **no automated tests**; AB-3 informational item recorded. | Module 10 |
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
| C-1 | `modules/servers/RDP` | 7 PHP files, 707 lines | none |
| C-2 | `modules/servers/cloudhost247_lteproxy` | 15 PHP files, 5,876 lines | none |
| C-3 | `modules/servers/smmprovisioning` | 1 PHP file, 396 lines | none |
| C-4 | `modules/servers/soyoustart_vps` | 16 PHP files, 4,139 lines | none |
| C-5 | `modules/servers/soyoustart` | 18 PHP files, 5,134 lines | none (audited as Module 13, findings **not** patched) |
| C-6 | `modules/servers/cloudhost247cloudflare` | 1 PHP file, 139 lines | none |
| C-7 | `modules/gateways/blockonomics` + `blockonomics.php` + `callback/blockonomics.php` | 15 PHP files, 3,042 lines | none |

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
| D-6 | License activation limits: (a) document unlimited, (b) global addon default, or (c) per-product column | `digitalproducts` |
| D-7 | Add root rewrite + front controller to make `/tools/<slug>` live | `CloudHost247_tools` |
| D-8 | Policy for the two ionCube-encoded modules | `hostx`, `xtreme_currency_rates` |
| P-5 | Keep or scope the all-tenant `phoneservices` API key | `phoneservices` |
| M-5 | Derive SMM order quantity from the paid product/config option; enforce `smm_max` even at 0 | `smmaddon` |

---

## What this list is not

- It is **not** a list of bugs: most findings behind section B are already fixed in code; the
  entries are the parts still open (owner decisions, live checks, unimplemented scope).
- "Unfinished" here includes both *unwritten code* (section A, C, D) and *unverified code*
  (section B, E), because the tracker's own definition of "complete" requires executed
  acceptance criteria, not just shipped source.
