# Module completion tracker

Tracks the sequential completion and verification of the modules flagged in the
repository audit. Each module is marked verified only when its own acceptance
criteria pass in executed tests. Anything not executed is marked **not run**.

Status values: *Not audited*, *Gaps identified*, *In progress*, *Blocked*, *Verified*.

Test runtimes: PHP is not installed in the sandbox, so PHP tests run under
php-wasm. Commands and results are in
`modules/servers/Smtphosting/tests/README.md`.

---

## Module 1 — Smtphosting

| Field | Information |
|---|---|
| Module | `modules/servers/Smtphosting` (ModulesGarden "Products Reseller" framework, v1.5.0 per `composer.json`) |
| Specification | None in the repository for the API, cron, or AppController hooks. No `docs/` entry or Build.txt for Smtphosting. The `Core/Api` framework expects `App/Config/api/routes.php`, `api/config.php`, `AltoRouter` and `App\Http\Api\*`, none of which exist. |
| Status | **Accepted by the user (gate closed) with owner follow-ups.** The user chose to accept now and keep the stubs as fail-closed placeholders (D-2). Owner follow-ups, not blocking: rotate the two upstream secrets and run the live check (see Remaining issues). Earlier status text follows for history: **Code fixed; not closed.** Stub-level defects (S-1, S-2, S-5), the S-6 server-side fix, and the low-severity items S-4, S-7, S-8, S-9 are implemented and tested (S-7 to S-9 by static checks only). Closure needs owner action: rotate the upstream secrets, configure them, and run the live check (see Remaining issues). Module 2 stays on hold until then. |

### Findings (verified by executing the code, not only by reading it)

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| S-1 | `AppControllers/Api.php`, `Cron.php`, `Hooks.php` do not extend the abstract `AppController`. `runController()` is undefined, so `Application::run()` would raise an uncaught `Error`. `getControllerInstanceClass()` silently returned `null`. | Baseline run: `Call to undefined method ...::runController()` | High (latent) |
| S-2 | `Instances/Api/ApiController.php` is a fatal error on load: `DefaultController` is not imported, and `runExecuteProcess()` is missing. | Baseline run: `Interface ...\Instances\Api\DefaultController not found` | High (latent) |
| S-3 | Nothing in the module reaches `Api`, `Cron`, or `Hooks` except `Application` mapping the `api` caller. No WHMCS function named `Smtphosting_api` exists. The cron queue framework (`Core/CommandLine`) has no registered jobs. | Repository search; `Smtphosting.php` lists all WHMCS functions | Info |
| S-4 | No scheduled jobs exist. `App/UI/Admin/ProductConfig/Pages/CronInfo.php` told the admin to run `<module>/cron/cron.php queue`, a file that does not exist. The page was never instantiated. | Repository search; `grep` for `CronInfo` | Medium (misleading, dead) → **fixed**: dead page removed; no references remain. |
| S-5 | `Instances/Addon/ConfigOptions.php` had `catch (\Excpetion $exc)`. The misspelled class never matches, so installer exceptions escaped the JSON error path. | Baseline regression test failure | Medium (real defect, fixed) |
| S-6 | **CRITICAL (fixed in code, secrets still need rotation): shared upstream secrets were exposed to every customer.** The original `smtp-api.php` hardcoded two upstream secrets. `clientarea.tpl` put them in client-area JavaScript together with `user_name` and `main_domain` from the browser, so any customer could read another customer's mail usage and logs. The secrets are also in git history. Secret comparison used `!==`, and `CURLOPT_FOLLOWLOCATION` could forward the secret on redirect. | Original lines 49-54 (secrets), 65 (`!==`), 90 (`FOLLOWLOCATION`); `clientarea.tpl` lines 422 and 493 (before the fix). | **Critical** → mitigated in code; open until the owner rotates the secrets |
| S-7 | `Synchronize` (admin button) swallows exceptions and returns a generic error without logging. | `App/Http/Actions/Synchronize.php` | Low → **fixed**: the failure is logged via `Core\HandlerError\Logger`; the generic return value is unchanged. |
| S-8 | `App/Hooks/ClientAreaPrimarySidebar.php` dereferenced `Hosting::find(...)->packageid` without a null check. It emits a PHP warning for an unknown service ID but is not fatal. | Code review | Low → **fixed**: null check added; the hook returns early. |
| S-9 | `App/Hooks/AdminProductConfigFieldsSave.php` caught all exceptions and did nothing, so save failures were silent. | Code review | Low → **fixed**: the failure is logged; the save still does not break. |

### Changes made

Hardening change set (third, S-4/S-7/S-8/S-9):

| File | Change |
|---|---|
| `App/Http/Actions/Synchronize.php` | Logs the exception message via `Core\HandlerError\Logger` before returning the unchanged generic error. Logging failures are caught. |
| `App/Hooks/ClientAreaPrimarySidebar.php` | Null check on `Hosting::find()` before reading `packageid`. |
| `App/Hooks/AdminProductConfigFieldsSave.php` | Logs the exception instead of swallowing it. The save still does not break. |
| `App/UI/Admin/ProductConfig/Pages/CronInfo.php` | Deleted. Dead page that pointed at a missing script; nothing referenced it. |
| `tests/hardening_tests.php` (new) | 4 static checks, one per finding. Each fails on the pre-fix code. |

S-6 fix (second change set, on top of commit 2baeebe):

| File | Change |
|---|---|
| `smtp-api.php` | Rewritten as a WHMCS-bootstrapped endpoint (`dirname(__DIR__, 3) . '/init.php'`). GET only. Requires `$_SESSION['uid']`. Accepts only `fn`, `serviceid`, `page`, `per_page`. Looks up `username` and `domain` in `tblhosting` where `id` and `userid` match. JSON error bodies. No secrets in the file. Exception details are never returned. |
| `Helpers/SmtpUsageProxy.php` (new) | Core logic: auth, `fn` and `serviceid` validation, ownership check, rate limit, upstream call, redaction, JSON validation, generic 502. `loadSecrets()` reads env vars with a file fallback. `curlTransport()` is HTTPS only, verifies TLS, and does not follow redirects. |
| `Helpers/SmtpUsageRateLimiter.php` (new) | File-backed fixed-window limiter under `storage/app/smtp-usage-ratelimit/`, with `flock`. Throws when storage is unavailable, so the proxy fails closed (503). |
| `templates/assets/tpl/DefaultSubmodule/clientarea.tpl` | Both XHR calls (lines 422 and 493) now send only `fn` and `serviceid` (plus `page` and `per_page` for logs). `secret`, `user_name`, and `main_domain` removed. |
| `tests/usage_proxy_tests.php` (new) | 24 tests: auth, 400/401/403/404/429/503/502 paths, identity from the service record, pagination clamping, redaction, limiter behaviour, secret precedence, and static regression checks (old secret digests absent, template has no `secret=`, curl options). |
| `tests/run.php` | Includes `usage_proxy_tests.php`. |
| `.gitignore` | Ignores `storage/config/*.php` (except `index.php`) and `storage/app/smtp-usage-ratelimit/`. |
| `storage/config/index.php` (new) | Placeholder that blocks directory listing. |
| `docs/SMTPHOSTING_USAGE_PROXY.md` (new) | Behaviour, secret setup, owner rotation steps, verification, limitations. |

Behaviour changes, all intentional: authentication is now required; the rate limit is per client, not per IP (same 100/300 s); `per_page` is capped at 100; upstream non-200 and non-JSON responses return a generic 502 instead of being passed through.

Earlier change set (commit 2baeebe):

| File | Change |
|---|---|
| `Core/App/Controllers/AppControllers/Api.php` | Extends abstract `AppController`. `getControllerInstanceClass()` throws `RuntimeException` ("not implemented in this build"). |
| `Core/App/Controllers/AppControllers/Cron.php` | Same pattern. States that no scheduled jobs are registered. |
| `Core/App/Controllers/AppControllers/Hooks.php` | Same pattern. Points to `App/Hooks/*.php` as the real hook mechanism. |
| `Core/App/Controllers/Instances/Api/ApiController.php` | Imports `DefaultController`. Implements `execute()` and `runExecuteProcess()`, both throwing `RuntimeException`. |
| `Core/App/Controllers/Instances/Addon/ConfigOptions.php` | `\Excpetion` → `\Exception` (one token). |
| `tests/` (new) | `run.php` (15 general tests, plus 24 S-6 and 4 hardening tests), `bootstrap.php`, `run-php-wasm.mjs`, `README.md`. |
| `docs/MODULE_COMPLETION_TRACKER.md` (new) | This tracker. |

No behaviour was invented. Nothing that previously worked changed. The routing
and Addon controller paths are covered by regression tests.

### Tests

| Command | Result |
|---|---|
| Suite before the S-6 fix, on the S-6 tests (8.3) | 15/39 passed; 24 failed, all in the new S-6 tests (expected: the classes and fixes did not exist yet) |
| Hardening checks against pre-fix code (8.3) | 39/43 passed; the 4 hardening checks (S-4, S-7, S-8, S-9) fail, as intended |
| Suite after the hardening fixes, PHP 8.3.33 (php-wasm) | **43/43 passed**, 0 failed |
| Suite after the hardening fixes, PHP 7.4.33 (php-wasm) | **43/43 passed**, 0 failed |
| Lint after the S-6 fix, PHP 8.3.33 and 7.4.33 | 643 files, 0 syntax errors on both |
| Endpoint smoke test (stub WHMCS `init.php` and `Capsule`, PHP 8.3, php-wasm) | 7 scenarios: not logged in → 401; POST → 405; not owned → 404; no username → 404; bad `fn` → 400; owned usage and logs with request-supplied `user_name`/`secret` ignored → generic 502 (no outbound transport in php-wasm). No secret in any output. |
| Live upstream call, live WHMCS database | **Not run.** No WHMCS install and no access to the Smtphosting API from the sandbox. |
| Local `php tests/run.php` | **Not run** (no local PHP binary) |

### Acceptance criteria against the directive

| Criterion | Status |
|---|---|
| Implement missing functionality per specification | **Not met, by design.** No specification exists. Behaviour is not invented. Owner decision D-2. |
| Auth, authorization, input validation, error handling, API responses | **Met in code for the usage/log proxy (S-6)**: WHMCS session auth, service ownership, strict input validation, generic error bodies, redaction. Verified by the 39-test suite and the endpoint smoke test. Not yet verified live. The four stubs fail closed. |
| Cron and hooks safe to repeat | **Not applicable to cron** (no jobs, S-3/S-4). Hooks not exercised against WHMCS. |
| Tests for success, failure, permission denial, invalid input, retry | Covered for the usage/log proxy: success (pass-through), failure (502/503), permission denial (401/404), invalid input (400), retry and limit (429). Stub API/cron retry **not applicable**. |
| Documentation | Done: this tracker, `docs/SMTPHOSTING_USAGE_PROXY.md` (setup, rotation, verification). |

### Remaining issues / blockers

1. **S-6 owner actions (blocking closure of Module 1).** The code fix is done, but the agent cannot do these:
   - Rotate both upstream secrets in the Smtphosting provider account and revoke the old ones. They are in git history and were visible in every customer's page source.
   - Configure the new values with environment variables (recommended) or `storage/config/smtp-usage-secrets.php`. Steps: `docs/SMTPHOSTING_USAGE_PROXY.md`.
   - Run the live check on a WHMCS install as a client who owns a service: expect JSON with no `secret` key, 404 for another client's service ID, and 401 without a session.
   - Optional: rewrite git history to remove the old values. Not required once they are revoked. Not done.
2. **D-2 (owner decision): what to do with the stub API/cron/hook classes.** Keep them as fail-closed placeholders (current state, user decision), delete them, or write a specification and implement them as new scope.
3. S-4, S-7, S-8, S-9: fixed in this change set. The hooks and the Synchronize action need WHMCS to run, so they are checked by static tests here, not executed. A live check is part of item 1.
5. WHMCS-level integration tests are not possible in this sandbox. They need a WHMCS install.
6. Limitations of the S-6 test evidence: php-wasm has no working outbound cURL, so `curlTransport()` is covered by static checks and not by a live request. The `tblhosting` query runs under a stub.

### Completion evidence

- Source: the files in *Changes made*, both change sets.
- Executed suite: `modules/servers/Smtphosting/tests/run.php`, 43/43 on PHP 8.3.33 and 7.4.33 (php-wasm), run after the hardening fixes.
- Lint: 643 non-vendor PHP files, 0 syntax errors on PHP 7.4 and 8.3.
- Endpoint smoke test: 7 scenarios, no secret in output (see Tests).
- Not yet evidence: live upstream call, live WHMCS run, owner rotation. Module 1 is not marked verified until these are done.

---

## Module 2 — tools_center (QR decoding and generation)

| Field | Information |
|---|---|
| Module | `modules/addons/tools_center` (WHMCS addon; PHP addon plus `external-api/` service, JS, templates) |
| Specification | No standalone Build.txt. Basis: the directive ("tools_center (QR decoding)"); `docs/MODULES.md` (alternative to `CloudHost247_tools`, proxies to a separate API); the module's own `API.md`/`README.md`; and the DNS Checker build spec, which lists **"QR Scanner (JS)"** under Productivity tools (`docs/All DNS Checker/All DNS Checker Build.txt`). The JS wording sets the requirement: decode in the browser. |
| Status | **Accepted by the user (gate closed) with owner follow-ups:** a browser check of file upload and a live WHMCS check. Findings T-1 to T-4 are fixed in code. |

### Audit findings

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| T-1 | **QR decoding was a placeholder.** The QR Scanner tool posted a URL to the external API, which returned a note and a third-party `decode_url`. It decoded nothing. | `external-api/tools/productivity.php` `qrScanner()` (line ~51-66) | High (functional gap) |
| T-2 | **Outbound API call followed redirects.** `tools_center_api_request()` used `CURLOPT_FOLLOWLOCATION => true` while sending the API token in an `X-API-Token` header. A redirect to another host could receive the token. The call also allowed any protocol. | `hooks.php` `tools_center_api_request()` | Medium (security) |
| T-3 | `apiToken` was assigned to the client-area template. No template used it, but it puts the secret where a future template could print it. | `clientarea.php` (removed) | Low (hygiene) |
| T-4 | The external `qrGenerator` builds an image URL on `api.qrserver.com` that contains the user's data. The browser then requests it, so the data goes to a third party. | `external-api/tools/productivity.php` `qrGenerator()` | Medium (privacy). **Fixed:** the page now generates the code in the browser (vendored MIT library); no request leaves the site. Owner decision: local generator, approved by the user. |
| T-5 | Token handling is otherwise sound. The browser never receives the token: the page posts to `index.php?m=tools_center`, and the server calls the API after the access check. | `clientarea.php` AJAX branch; `tools_center_check_access()` | Info (verified) |

### Changes made

| File | Change |
|---|---|
| `js/qr-scanner.js` (new) | Client-side QR decoding. `decodeImageData()` (pure, testable), `validateFile()` (PNG/JPG/GIF/WebP/BMP, max 5 MB), `decodeFile()` (browser: reads the file, downscales to 1600 px, decodes). Nothing is uploaded. |
| `js/vendor/jsQR-1.4.0.js` (new) | Vendored jsQR 1.4.0 (Apache-2.0, free, no runtime dependencies). SHA-256 recorded in `js/vendor/README.md`; licence in `jsQR-1.4.0.LICENSE`. |
| `js/tools-center.js` | `LOCAL_TOOL_HANDLERS` (qrScanner → local decode). Both submit paths (modal and page) check it before any XHR call. The `accept` attribute is passed to file inputs. Decoded text is rendered with the existing escaped `renderObject`. |
| `templates/tools/tool.tpl` | QR Scanner field changed from "QR Image URL" to a file input, with a note that decoding happens in the browser. |
| `hooks.php` | T-2: `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` set to HTTPS only. Loads jsQR, both qrcode-generator files, `qr-scanner.js`, `qr-generator.js`, then `tools-center.js`. |
| `clientarea.php` | T-3: `apiToken` no longer assigned to the template. |
| `API.md` | `qrScanner` and `qrGenerator` rows updated: neither is a server call from the page. |
| `js/qr-generator.js` (new) | Browser QR generation with the vendored qrcode-generator. Returns an SVG (no scripts, numbers only) and the module matrix. Limits: 2000 characters, size clamped to 100–1000 px, unknown ECC level falls back to M. |
| `js/vendor/qrcode-generator-2.0.4.js`, `…-utf8.js`, `…LICENSE` (new) | Vendored qrcode-generator 2.0.4, MIT (free, no runtime dependencies). The UTF-8 file makes non-ASCII text encode correctly. Checksums in `js/vendor/README.md`. |
| `js/tools-center.js` | `qrGenerator` added to `LOCAL_TOOL_HANDLERS`. Result view shows the SVG image and a download link. Only the local SVG data URI is accepted for display. Attribute escaping added. |
| `tests/` (new) | `run-tests.js` (runner), `ui-wiring.js` (jsdom UI tests), `fixtures/qr-fixtures.json`, `README.md`. |

Behaviour change: the QR generator input limit is now 2000 characters (was 4000 on the external API). 4000 characters cannot be encoded at any ECC level above L: the QR byte capacity is about 2300 bytes at ECC M. The new limit is the largest that fits reliably; longer or multi-byte input shows an explicit error.

Not changed: `external-api/tools/productivity.php` `qrScanner()` still returns its note. The page no longer calls it. Left in place to avoid changing the external API contract. It is listed as an open item.

### Tests

| Command | Result |
|---|---|
| `node modules/addons/tools_center/tests/run-tests.js` (no jsdom) | 31 unit/static tests passed; the 10 UI tests are **skipped** and printed as SKIP |
| Same, with jsdom 24 (`NODE_PATH` set to a jsdom install outside the repo) | **41/41 passed**, 0 failed |
| Same tests on the original shipped files (HEAD) | 25/41 passed, 16 failed. All 16 are the intended regressions. The non-local-tool regression test passes on both. |
| PHP lint, `tools_center` (18 files), PHP 8.3.33 and 7.4.33 (php-wasm) | 0 syntax errors on both |
| QR generation round trip | Generated codes (ECC L, M, Q, H; UTF-8 text; 604 characters) are decoded by the independent jsQR decoder back to the same text. |
| Real QR decoding | Three fixture codes (ECC L, M, H; versions 4 and 6) decode to the exact text. An inverted code decodes too. A blank image reports "no QR code". |
| Browser file reading (`FileReader`, `Image`, canvas) | **Not run.** No browser in the sandbox. The UI tests stub only this step. |
| UI wiring in jsdom (real `tool.tpl` definitions and `tools-center.js`) | Run: QR Scanner (page and modal), QR Generator (SVG image, download link), error cases, and non-local tools. |
| WHMCS live run (curl path, access check) | **Not run.** No WHMCS install. |

### Security review

- The API token is still never sent to the browser (T-5). The redirect and protocol fix (T-2) stops the token from being forwarded.
- QR decoding and generation both run in the browser. Uploaded images and typed data are never sent to a server or a third party (T-1 and T-4 closed).
- The generated image is an SVG built from numbers and fixed element names. It is shown in an `<img>` tag, so it cannot run script.
- Decoded text and any other displayed value are rendered as text (tested with an HTML payload). Attribute values use escaping.
- Upload limits: type allowlist, 5 MB, 1600 px downscale before decoding.
- The API token is still never sent to the browser, and the outbound call does not follow redirects.

### Remaining issues / blockers

1. **Browser check (owner follow-up):** in a real browser, upload a PNG and a JPG QR code to the QR Scanner and confirm the text appears. Confirm an oversized or non-image file is rejected. Generate a QR code and download the SVG.
2. **WHMCS check (owner follow-up):** on a live install, confirm the Tools Center page loads the scripts without console errors, and that a server-side tool still reaches the external API over HTTPS.
3. The external `qrScanner()` and `qrGenerator()` in `external-api/` are no longer called by the page. Remove them, or keep them for other clients. Owner decision.
4. Other tools in `external-api/` were not audited in this pass (scope: QR and the proxy path). They belong to the additional audit list.

### Completion evidence

- Source: the files in *Changes made*.
- Executed: `node modules/addons/tools_center/tests/run-tests.js`, 41/41 with jsdom 24; PHP lint 0 errors on 8.3 and 7.4.
- Not yet evidence: browser file reading and the live WHMCS run (items 1 and 2 above).



### Module 2 follow-up — `external-api/` audit (open item 4 closed in code)

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| T-6 | **SSRF.** The HTTP headers checker, the open-graph/broken-link checker, the link analyzer, the broken-link sample, and the port checker and SMTP test connected to any caller-supplied host, including private ranges and cloud metadata, and followed redirects. | `external-api/tools/developer.php`, `webmaster.php`, `network.php` (before the fix) | High |
| T-7 | **Unsafe action dispatch.** `api.php` called any method that `method_exists()` found. Private methods raised an uncaught `Error` (not an `Exception`), and magic methods were callable. | `external-api/api.php` | Medium |
| T-8 | Certificate verification is still off in the page-fetch tools (`SSL_VERIFYPEER => false`, kept to preserve behaviour for sites with broken certificates). Connections are pinned to the validated address, so this affects content integrity in transit only. | `developer.php`, `webmaster.php` | Low (accepted, documented) |

**Fix:** new `external-api/outbound.php`. It accepts only http/https on ports 80/443 with no credentials, resolves the host and rejects any non-public address (deny-list covers IPv4-mapped IPv6, CGNAT, NAT64 and the other reserved ranges), pins the connection to the validated IP, and re-validates every redirect hop (maximum 5). Sockets use `tc_open_public_socket()`. `api.php` dispatches only public, non-magic methods declared on the tool class. A link the guard refuses is reported as unreachable rather than aborting the report.

**Tests:** `tests/outbound-guard.php` (60 offline checks, run with `tests/run-php-guard.mjs` on php-wasm). The PHP syntax check passes on all 19 PHP files. Live DNS and network behaviour is **not run** in the sandbox.

**Still open:** the unused `qrScanner()` and `qrGenerator()` in `external-api/` (owner decision, unchanged); T-8 (owner decision on verification); the external API's wildcard CORS header (`Access-Control-Allow-Origin: *`), which is also unchanged.

## Module 3 — cloudhost247apps (hosting control plane)

| Field | Information |
|---|---|
| Module | `modules/addons/cloudhost247apps` (WHMCS addon: control plane, catalog, billing gate, deployments, agent, cPanel/WHM adapter boundary, cron) |
| Specification | `docs/HOSTING_CONTROL_PLANE_AUDIT.md` (post-audit status), `docs/APP_PLATFORM_PLAN.md` §5 (rules 5 and 7) and §20 (payment rule), `docs/PHASE2_…` to `docs/PHASE11_…`. Documented deliberate gaps: no production infrastructure adapter; customer VM provisioning disabled; Phase 3 is metadata-only; Phase 4–8 UAPI calls default off; cPanel staging runbook not executed. |
| Status | **Closed for code (owner, 2026-10-10): code-complete, owner items pending.** Owner-run checks remain: the cPanel staging runbook and the live WHMCS payment check. A-8 decided: keep, inert. Code findings A-1 to A-7 are fixed and tested. Suite re-run 2026-10-10: 2215 PASS, 0 FAIL. Out-of-scope features (customer account creation, SSO, panel installation, licensing) are not started. |

### Audit findings

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| A-1 | **CSRF token could be supplied in the URL.** `Csrf::matches()` fell back to `$_REQUEST`, and `InfrastructureApi` did the same. | `lib/Core/Csrf.php`; `lib/Api/InfrastructureApi.php`. A new test failed on the old code. | Medium (hygiene). **Fixed:** read from `$_POST` (form) or the `X-CSRF-Token` header (API). |
| A-2 | **Dead branch in `AdapterFactory::forEngine`.** `isDryRun() && $fake === null` can never be true. | `lib/Adapters/AdapterFactory.php` | Low. **Fixed:** removed; behaviour unchanged. |
| A-3 | **Legacy claim that `KubernetesAdapter` is implemented is false.** The class does not exist and has no git history. The `class_exists` guard fails closed with `ADAPTER_NOT_INSTALLED`. | Repository search; `AdapterFactory::forEngine` | Info. **Covered by a new test.** |
| A-4 | The "not implemented" messages in `lib/ControlPanels/CpanelWhmClient.php` are intended fail-closed allowlist rejections. | Source review | Info (verified) |
| A-5 | `FakeAdapter` is reachable only through `setFake`, which only tests call. `Settings::override` is in-memory, so a database setting cannot enable it. | `grep setFake`; `Settings::override` | Info (verified) |
| A-6 | **Provisioning could skip payment in two ways.** (a) An installation with no `plan_id` has no price, so `requiresPayment` was false and it provisioned with no invoice. This breaks §20 ("provisioning only from a server-verified invoice"). (b) `bypass_payment` in the input was honoured for any actor, including customers. No HTTP route currently exposes `InstallationService::create`, so this was latent, but it is one route away from being live. | `lib/Deployments/InstallationService.php` `create()` | **High (latent).** **Fixed:** customers must supply `plan_id` (`ValidationException`, `PLAN_REQUIRED`). `bypass_payment` is honoured only for `PLAN_MANAGE` actors. On the old code, four of the new checks fail, and so does one existing count check (\"provisions exactly one installation\"), which the old bypass broke. |
| A-7 | `DockerAdapter::health()` and `dispatch()` reported `attempts = 1` when the agent did not report a count, asserting a value nobody measured. Nothing in production reads it. | `lib/Adapters/DockerAdapter.php` lines 458 and 581 | Low. **Fixed:** reports `null` when not reported. Covered only by the full suite, not a dedicated assertion. |
| A-8 | `install_requires_paid_order` (default `1`) is read by no code. Payment is enforced by price, not by this setting. The setting is misleading. | `grep install_requires_paid_order`: only `Settings.php` and tests | Low. **Not removed** (backward compatibility). **Decision (owner, 2026-10-10): keep as-is, documented as inert.** Wiring it to disable payment would be a financial bypass and is not done. |

### Payment gate: verified end to end (source plus tests)

Order of checks on a provider webhook (`lib/Billing/PaymentGate.php`):
1. Signature: HMAC-SHA256 with `hash_equals`; timestamp window; missing or invalid signatures are rejected; a missing secret fails closed.
2. Replay: event ID recorded in `payment_events`.
3. Event type: only paid types confirm payment; failure types mark unpaid; other types are ignored.
4. **WHMCS authority:** `confirmInvoicePaid()` calls `gateway->isInvoicePaid()`. If WHMCS says no, or the call throws, it fails closed with `PAYMENT_NOT_CONFIRMED`.
5. Exactly once: an order-link compare-and-set on `provisioning_triggered`.
6. Provisioning: `markPaid()` then `provision()`. Installations that need approval stop at approval.

Creation-time rules after A-6: a customer install needs a plan. Free plans (price 0) provision without payment, by design. Priced plans wait for payment. A customer's `paid`, `payment_status` or `invoice_paid` input is ignored (tested). Administrators with `PLAN_MANAGE` may mark an install paid or bypass payment on purpose. That is an intentional, permissioned override, not a customer path.

### Health and metrics: no fabricated values (verified)

- Production health (`DockerAdapter::health`): any agent failure or unrecognised state returns `unknown`. Tested by suites 03 and 24.
- Production metrics (`DockerAdapter::metrics`): failure returns `null`. Server metrics use `isset ? round : null` (`ServerService`), and `metrics_known` is false until a real sample arrives. Tested in 03.
- A stale server reports `unknown`, not the last known state (tested in 03).
- `FakeAdapter` returns a fixed `healthy` state and CPU 4.2. It is installed only by tests (A-5), so it cannot reach production.

### Security review (source-verified)

- **Webhook verification:** see the payment gate section above.
- **API tokens** (`lib/Core/Identity.php`): stored as SHA-256 hashes. Expiry, revocation, IP allowlists and scopes enforced.
- **Ownership:** customer-facing Deployment, Environment and Installation services compare `customer_id` with the authenticated actor. Customer installs take their client ID from the actor.
- **CSRF:** enforced on state-changing admin POST handlers and non-bearer API writes. Bearer tokens are exempt by design.
- **Agent** (`lib/Servers/AgentAuthenticator.php`): HMAC over method, path, timestamp and nonce; nonce of 16–128 characters; nonce uniqueness inside the window, so captured requests cannot be replayed (suite 22).
- **Cron** (`cron/cloudhost247apps.php`): refuses non-CLI execution; runs as the SYSTEM actor, which may maintain but not approve, publish or refund.
- **Output and CORS:** no `Access-Control-Allow-Origin` wildcard. No unescaped `echo` found in `lib/Http`, `lib/Api` or `api/` by grep. This is a grep check, not a full template review.

### Changes made

| File | Change |
|---|---|
| `lib/Core/Csrf.php` | Token read from `$_POST` (not `$_REQUEST`) (A-1). |
| `lib/Api/InfrastructureApi.php` | Form-token fallback read from `$_POST` (A-1). |
| `lib/Adapters/AdapterFactory.php` | Dead branch removed (A-2). |
| `lib/Deployments/InstallationService.php` | Customers without `plan_id` refused (`PLAN_REQUIRED`). `bypass_payment` honoured only for `PLAN_MANAGE`. No-op `paid = false` block removed (A-6). |
| `lib/Adapters/DockerAdapter.php` | `attempts` is `null` when not reported (A-7). |
| `tests/06_ModuleBoundaryTest.php` | 3 checks: query-string CSRF rejected, POST-body CSRF accepted (A-1). |
| `tests/04_DeploymentTest.php` | 7 checks: plan-less customer refused and no row created; customer `bypass_payment` ignored; administrator bypass honoured (A-6). |
| `tests/26_AdapterFactoryTest.php` (new) | 8 checks: kubernetes and unknown engines fail closed; dry-run precedence (A-2, A-3). |

### Tests

| Command | Result |
|---|---|
| Full suite, before this pass | 2208 PASS, 0 FAIL |
| `node tests/run.mjs ModuleBoundary` (after A-1) | 49/49 |
| `node tests/run.mjs AdapterFactory` | 8/8 |
| `node tests/run.mjs Deployment` (after A-6) | 214/214 |
| Suite 04 against the **old** `InstallationService.php` | 5 FAIL, 209 PASS: four new checks plus one existing count check fail, confirming the defect; restored and re-run to 214/214 |
| **Full suite, after this pass** | **2215 PASS, 0 FAIL, exit 0** (17.5 s) |
| `node tests/lint.mjs` | FILES=110, BAD=0 |
| cPanel staging runbook | **Not run.** Needs a staging WHM and owner access. |
| Live WHMCS invoice and webhook run | **Not run.** Needs a WHMCS install with a test gateway. |

### Remaining issues / blockers (owner-held)

1. **cPanel staging runbook** (`docs/PHASE4`–`PHASE8`, runbook not executed). Blocker: needs a staging WHM host and credentials from the owner. Next step: the owner runs it, or provides access.
2. **Live WHMCS payment check.** Blocker: needs a WHMCS install with a test gateway. Next step: the owner runs it. Expected: a paid invoice provisions once; an unpaid or unverifiable one does not.
3. **A-8, owner decision:** delete `install_requires_paid_order` or wire it. Do not wire it to disable payment.
4. Documented gaps stay as designed: no production infrastructure adapter; customer VM provisioning disabled; Phase 3 metadata-only; Phase 4–8 UAPI calls default off.
5. Behaviour change for review: customer installs without `plan_id` are now refused. Admins are unaffected.

### Completion evidence

- Source: the files in *Changes made*.
- Executed: full suite 2215/0; lint 110/0; suite 04 confirmed to fail on the old code (5 failures, see Tests).
- Not yet evidence: the cPanel staging runbook and the live WHMCS payment check (see Remaining issues 1 and 2).

## Additional audit item 1 — customaffiliate (2026-10-10)

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| C-1 | **Self-referral payout.** When a client had no referrer, the lookup fell back to `tblaffiliates.clientid`, which is the affiliate account the client owns. Their own orders then earned commission. | `lib/CommissionManager.php` `getClientAffiliate()` | High (financial) → **fixed**: fallback removed. Test: "no commission to own affiliate account". |
| C-2 | **Recurring commissions were never reversed on refund**, although the README promised automatic reversal. | `handleInvoiceRefund()` reversed only the first commission. | High → **fixed**: `reverseRecurringForInvoice()`, idempotent. |
| C-3 | **Raw SQL `CONCAT(notes, …)` in five places.** A NULL `notes` made CONCAT return NULL, so the note was silently lost. The raw expressions also concatenated values into SQL. | `CommissionManager`, `UpgradeHandler`, `utilities.php` | Medium → **fixed**: notes appended in PHP (`appendNote()`); no raw SQL in the module. |

Tests: `tests/CommissionTest.php`, 22 checks, all pass; 10 of them failed on the old code. PHP parse check passes on all 8 PHP files. Not run: a live WHMCS invoice and refund flow, and hook order (AffiliateCommission vs InvoicePaid), which depends on WHMCS.

Open for the owner: partial refunds still reset the full first-commission flag (documented, conservative). `UpgradeHandler` and the upgrade/downgrade hooks only record notes; re-grouping logic runs only from the manual utility.

## Additional audit item 2 — hostx_email (2026-10-10)

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| H-1 | **Unauthenticated webhooks were processed.** The signature check ran only when a header was present. Microsoft 365 and Google returned `true` unconditionally. The Microsoft `clientState` check was skipped when no secret was set. Unsigned POSTs could set a customer's WHMCS service to `Terminated`. | `webhook.php`; `api.php` `verifyWebhookSignature()` | High | **Fixed** (`dee0cfe`). `authenticateWebhook()` is required for every request. Google needs `hostx_email_google_channel_token`; Microsoft needs `hostx_email_ms_client_state`; Professional needs a non-empty API key and a valid HMAC. |
| H-2 | **Google license assignment never worked.** The code sent `PUT .../product/Google-Apps/users/{sku}/{email}`. That path does not exist, and PUT reassigns an existing license, so a new user could not receive one. The create path ignored the failure. | `api.php` `assignGoogleWorkspaceLicense()`; Google Licensing API `licenseAssignments.insert` | High (functional) | **Fixed.** Now `POST .../product/Google-Apps/sku/{skuId}/user` with `{userId}`. 409 (already licensed) counts as success. Both create paths log `Create-LicenseFailed` on failure. Not run live. |
| H-3 | **A second, unused webhook implementation** in `api.php` (`handleWebhook` and three private handlers) kept the old fail-open auth. A future caller would bypass H-1. | `api.php` (no callers) | Medium (latent) | **Fixed:** removed (184 lines). `webhook.php` is the only entry point. |
| H-4 | **Stored credentials use a key that is not secret.** The key is `sha256(SystemURL . 'HostXEmail_v1.0.0')`. Anyone with DB read access who knows the SystemURL (usually public) can decrypt. Changing SystemURL makes all stored credentials unreadable. | `functions.php` `hostx_email_get_encryption_key()`, `hostx_email_encrypt/decrypt()` | Medium | **Open, owner decision.** Fix: move to WHMCS's own encryption key with a versioned ciphertext and a one-time migration. This is not a one-line change because existing rows must be migrated. |
| H-5 | **Rate limiter is dead code, but the README claimed rate limiting.** `hostx_email_check_rate_limit()` has no callers. It is file-based, not atomic, and fails open. | `functions.php` ~725 | Low | **Fixed:** dead function removed from `functions.php`; README corrected. Throttling, if wanted, belongs at the web server or WAF. |
| H-6 | **AES-256-CBC without a MAC.** Ciphertext can be modified without detection. Decrypt returns an empty string on failure, so there is no padding oracle. | `functions.php` encrypt/decrypt | Low | **Open**; address with H-4. |

Tests: `tests/WebhookAuthTest.php`, 13 checks, all pass (`node tests/run.mjs`). The test file loads `api.php`, so the edited class parses. PHP parse check passes on `webhook.php` and `api.php`. Not run: live provider webhooks, a live Google license assignment, and a before/after run on the old code.

Behaviour changes: (1) Google and Microsoft webhooks stop working until their secrets are set (H-1). (2) Google licences are now actually assigned on create (H-2), which is a real change for existing Google deployments.

## Additional audit item 3 — phoneservices (2026-10-10)

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| P-1 | **Provider webhooks accepted unsigned requests.** Anyone could post a fake inbound SMS into a customer's inbox (matched by number), or rewrite call status and cost. | `api/webhooks/twilio.php`, `api/webhooks/vonage.php` | High | **Fixed.** Twilio: `X-Twilio-Signature` (HMAC-SHA1, documented algorithm). Vonage: HS256 JWT in `Authorization: Bearer`, checked with the signature secret and `exp`. Both fail closed. |
| P-2 | **REST API and webhooks never loaded WHMCS.** Neither file included `init.php`, so `select_query()` and the session would be undefined. | `api/rest.php`, `api/webhooks/*.php` | High (functional) | **Fixed:** WHMCS bootstrap added; fails closed (500) if `init.php` is missing. Not run live. |
| P-3 | **Path-based auth bypass.** `AuthMiddleware` skipped auth for any path containing `webhooks`. No route used it, but any future route would be open. | `lib/API/Middleware/AuthMiddleware.php` | Medium (latent) | **Fixed:** bypass removed. |
| P-4 | **JWT with `sub` = 0 acted as "no user".** The ownership checks skip when the user ID is 0, so such a token saw all tenants. | `AuthMiddleware::validateJwt()`; `NumbersController` and others | Medium | **Fixed:** only `sub` > 0 is accepted. |
| P-5 | **The shared API key is all-tenant access.** `validateApiKey()` sets no user ID, so every ownership check is skipped. A key holder can list all customers' numbers (`getAllNumbers`) and suspend or release any number. This is undocumented, and the key cannot be set from the admin UI. | `lib/API/Middleware/AuthMiddleware.php`; `NumbersController`; `Config.php` | High | **Open, owner decision:** keep the key as an admin credential (document it and restrict it), or scope it to a user or role. Not changed. |
| P-6 | **Stored secrets may be encrypted.** `Config::get()` reads `tbladdonmodules` raw, and nothing decrypts. If WHMCS encrypts password-type addon settings, the provider credentials and the new webhook secrets read as ciphertext. The verifier then fails closed, and the existing Twilio/Vonage calls fail too. | `lib/Core/Config.php`; `phoneservices.php` | High, to verify | **Open:** check on a live install. |
| P-7 | **`mysql_fetch_assoc()` is used in `Database`, `Config`, `Logger` and three services.** This function was removed in PHP 7. It works only if the target WHMCS provides a compatibility shim. | `lib/Core/Database.php` and others | Medium, to verify | **Open:** check against the target WHMCS and PHP versions. |
| P-8 | **Status callbacks and DLRs only log.** Twilio `type=status` and Vonage `type=dlr` never update the message record. | `api/webhooks/*.php` | Low (functional) | **Open.** |

Tests: `tests/WebhookVerifierTest.php`, 20 checks, all pass (`node tests/run.mjs`). The Twilio known answer was computed independently (Node crypto) from Twilio's documented algorithm. The Vonage checks use real HS256 tokens. PHP parse check passes on all six changed PHP files.

Not run: live Twilio or Vonage requests, the WHMCS bootstrap, and a before/after run on the old code. Twilio's docs recommend the SDK's `RequestValidator` over hand-written validation. The SDK is already declared in `composer.json`, so switching is an option if you prefer it.

## Additional audit item 4 — smmaddon (2026-10-10)

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| M-1 | **Stored XSS in the admin area.** The client-supplied order link (a custom field), provider error text, the flash message and provider names were echoed raw into admin pages. A client could run script in an admin's browser. | `templates/admin/*.php` (79 echoes) | High | **Fixed:** every template echo is escaped with `htmlspecialchars`. Tested with hostile values (`AdminSecurityTest.php`). |
| M-2 | **No CSRF token on admin POST actions.** Settings (including API URL and key), order cancel and refresh, service mapping and log clearing all accepted any POST. A forged settings POST could point the module at an attacker's API URL, which would then receive the real key. Modern SameSite defaults limit this, but the module must not rely on them. | `lib/AdminDispatcher.php` `dispatch()`; 7 forms | High | **Fixed:** per-session token, checked before any admin POST; hidden field in all 7 forms. **Open:** AJAX actions (`ajax=1`) still accept GET and include state changes (`sync_services`, `refresh_order`). |
| M-3 | **The API key was written to the log table in plaintext** when debug mode was on (the request parameters include `key`). | `lib/ApiClient.php` `request()` | Medium | **Fixed:** logged as `***`. |
| M-4 | **Duplicate provider orders.** `AfterModuleCreate` placed an order each time it ran, with no check for an existing order for the service. A provisioning retry could buy twice. | `hooks.php` `AfterModuleCreate` | Medium | **Fixed:** skip when a non-error order already exists for the service. Retries after an error still place the order. |
| M-5 | **Client controls the order quantity.** The quantity comes from a custom field that the client can edit. The provider order uses it, not the price paid. When `smm_max` is 0 there is no cap. The minimum clamp can raise it above what the client asked for. | `hooks.php` `AfterModuleCreate`; `smm_max` from `AdminDispatcher` sync | High (financial) | **Open, owner decision:** derive quantity from the product or config option that was paid for, and enforce `smm_max` even when it is 0. |
| M-6 | **`api_url` is not restricted to HTTPS.** It is sanitised with `FILTER_SANITIZE_URL` only. The request uses cURL with peer verification on, but other URL schemes are not blocked. | `lib/AdminDispatcher.php` settings; `lib/ApiClient.php` | Low (admin only) | **Open:** require `https://` and restrict cURL to HTTP(S). |
| M-7 | **Provider API key stored in `mod_smm_config` and read raw.** Same question as P-6 in phoneservices: confirm how WHMCS stores addon password fields, and whether the key should be encrypted at rest. | `lib/Helper.php` `getServerConfig()` | Medium, to verify | **Open:** check on a live install. |

Tests: `tests/AdminSecurityTest.php`, 13 checks, all pass (`node tests/run.mjs`). PHP parse check passes on the changed PHP files and all admin templates.

Not run: live admin sessions and a real CSRF attempt, a live SMM provider, and a before/after run on the old code.

## Module 4 — domainbroker (Domain Broker Service)

| Field | Information |
|---|---|
| Module | `modules/addons/domainbroker` (WHMCS addon), public page `domain-broker.php`, template `templates/hostx/domainbroker-landing.tpl` |
| Specification | `docs/DOMAIN_BROKER.md` (the module's completion report, §1–§10 and the stated limitations). Governing rules from the directive: do not represent a registrar transfer as completed without registrar evidence; never enable unverified financial actions; no fake completion. |
| Status | **Accepted (you said "continue") with owner items pending:** B-9 (`rdap_enabled` default), a live WHMCS run, and a real RDAP lookup. Implementation: D-1 option C. |

### Audit findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| B-1 | **Transfer completion rests on a broker's free-text note.** `TransferService::markCompleted()` set the transfer to COMPLETED when `evidence` (or `note`) was a non-empty string. Nothing checked the registry or the WHMCS domain record. | `lib/Services/TransferService.php` `markCompleted()`; `tests/05_TransferTest.php`; `tests/10_TransferCompletionTest.php` | High, policy (D-1) | **Fixed (source and tests).** With RDAP on, completion needs the registry to show the gaining registrar as sponsor. With RDAP off, only `admin_finance` or `admin_super` may attest, with a registrar reference. A broker is refused on the attested path. |
| B-2 | **Fund release depended on the same attested status.** `PaymentService::releaseFunds()` checked only status and verification items. | `lib/Services/PaymentService.php` `releaseFunds()` | High, policy (D-1) | **Fixed (source and tests).** Release is refused with `COMPLETION_BASIS_MISSING` when `completion_basis` is empty. Release still needs `PAYMENT_RELEASE` (finance). |
| B-3 | **Registrar verification was a human checkbox, not tied to the transfer.** | `lib/Services/VerificationService.php` `ALWAYS_REQUIRED`; `lib/Services/DomainIntelService.php` | Info, part of D-1 | **Addressed.** The required checklist item still exists. Completion now also needs the registry check (RDAP on) or a finance attestation with reference (RDAP off). |
| B-4 | **Reflected XSS on the public landing page.** `?domain=` was echoed into the form's `value` attribute without escaping. | `domain-broker.php`; `templates/hostx/domainbroker-landing.tpl` | Medium | **Fixed** (commit `e2f2922`). Only hostname-shaped input is accepted, and the template escapes the value. Verified by ad hoc checks only (see Tests). |
| B-5 | The internal escrow provider records the operator's own release and makes no external call. | `lib/Escrow/InternalEscrowProvider.php` | Info | Open: UI should say "release recorded", not "paid to seller". |
| B-6 | Escrow webhook: HMAC over timestamp and body, tolerance window, `hash_equals`, replay deduplication. | `lib/Escrow/HttpEscrowProvider.php`; `lib/Services/PaymentService.php` | Info (verified) | No change needed. |
| B-7 | Cron entry point refuses non-CLI execution. Refund and release are idempotent and permissioned. | `cron/domainbroker.php`; suites 06, 09 | Info (verified) | No change needed. |
| B-8 | `lint.php` reads `$argv` on the web-style runtime and emits a warning. | Lint output | Low | Open, cosmetic. Lint still passes. |
| B-9 | **`rdap_enabled` default conflicts with the spec.** `Settings.php` seeds `rdap_enabled='1'`, and migrations seed nothing, so fresh installs make live RDAP lookups. The spec said "off by default", and the test bootstrap sets `'0'`. | `lib/Core/Settings.php` line 63; `tests/bootstrap.php` line 182; `docs/DOMAIN_BROKER.md` (corrected in this change) | Medium, owner decision | **Open.** The code default was not changed. Owner to choose: keep on (live lookups by default) or set to off (completion then uses the attestation path). |

### Decision D-1 (decided by you: option C)

RDAP sponsoring-registrar match when `rdap_enabled` is on. Otherwise an admin-only attestation fallback (finance-level, with registrar reference). Each completion records its basis. Refuse on RDAP mismatch. Fail closed when RDAP is enabled but inconclusive.

Implementation details, as built:
- `DomainIntelService::registryRegistrar()` returns `checked=false` when RDAP is disabled, the domain is invalid, or the registry is unreachable. A 404 returns `checked=true, registered=false`.
- Match rule: IANA IDs decide when both are known. Otherwise normalised names must be equal. Unknown on either side is a mismatch (fail closed).
- Unreachable or inconclusive registry with RDAP on: `ConflictException` (`REGISTRY_UNCONFIRMED`). Nothing is completed.
- Registry mismatch: `ConflictException` (`REGISTRAR_MISMATCH`). Nothing is completed.
- With RDAP on, a broker may complete when the registry matches. Funds are still released only by finance (`PAYMENT_RELEASE`). The broker's role is the milestone, not the money.
- Attestation fallback (RDAP off): `PAYMENT_RELEASE` plus a non-empty `registrar_reference`. Basis recorded as `attested_finance`.
- Migration `0007_transfer_completion_basis.php` adds `completion_basis`, `registrar_reference`, `registry_check` to `transfers`.

### Changes made

| File | Change |
|---|---|
| `lib/Services/TransferService.php` | `markCompleted()` basis branching (registry or attestation); `BASIS_REGISTRY`/`BASIS_ATTESTED`; `registrarMatches()`; `normaliseRegistrarName()`. Top permission accepts `MILESTONE_MARK` or `PAYMENT_RELEASE`. |
| `lib/Services/DomainIntelService.php` | `parseRegistrar()` helper extracted; `registryRegistrar($domain)` added. `applyRdap()` uses the helper. |
| `lib/Services/PaymentService.php` | `releaseFunds()` refuses on empty `completion_basis`. |
| `install/migrations/0007_transfer_completion_basis.php` | New columns on `transfers`. |
| `tests/05_TransferTest.php` | Broker-only completion replaced: broker refused on attested path; finance without reference refused; finance with reference completes as `attested_finance`. |
| `tests/08_ApiTest.php` | Broker completion is refused with 403. Completion goes through the admin path with a registrar reference. |
| `tests/10_TransferCompletionTest.php` | New (16 checks): RDAP match; name mismatch; unreachable registry; unregistered domain; unnamed gaining registrar (fail closed); broker refused on attested path; finance attestation; release refused without basis. |
| `domain-broker.php`, `templates/hostx/domainbroker-landing.tpl` | B-4 fix (commit `e2f2922`). |
| `docs/DOMAIN_BROKER.md` | Corrected RDAP default statement to match code; B-9 referenced. |

### Tests

| Command | Result |
|---|---|
| `node tests/run.mjs` (module suite, run with a symlinked `node_modules`, removed afterwards) | **1031 PASS, 0 FAIL, exit 0** across 10 suites. Baseline before this change: 1009 / 0 across 9 suites. |
| `node tests/lint.mjs` | **FILES=86, BAD=0** (one cosmetic `$argv` warning, B-8). |
| Suite 10 and 05 against the HEAD versions of the three service files | Both fail. Suite 05 aborts at the broker-attestation check (the B-1 bug). Suite 10 fatals on the missing `BASIS_REGISTRY` constant. This shows the tests detect the old behaviour. It is not a per-behaviour comparison. |
| Landing page prefill (B-4) | Ad hoc only: Python `re` check on 11 inputs (3 hostnames kept; 8 hostile or malformed dropped), and grep confirming the regex and `|escape`. The root page sits outside the PHP runner's mount, so it is not in a persisted suite. A PHP-runtime run of the regex hung in the sandbox and was not completed. |
| Live WHMCS run, escrow provider, RDAP endpoint | **Not run.** No WHMCS install, escrow endpoint, or RDAP access from the sandbox. |

### Security review

- Completion cannot be claimed from free text alone (B-1). Broker completion requires the registry to confirm the gaining registrar when RDAP is on.
- Release cannot happen without a recorded completion basis (B-2).
- Registry failure fails closed (no completion).
- B-4 XSS fixed; landing page input is validated and escaped.
- Webhook, cron and idempotency checks (B-6, B-7) reviewed and unchanged.

### Remaining issues / blockers (owner-held)

1. **B-9 (owner decision):** keep `rdap_enabled` on by default, or set it off. The spec now reflects the code, not the old claim.
2. **Live checks:** a WHMCS install with a test gateway; and an RDAP endpoint check (`https://rdap.org` by default) from a machine with internet access.
3. **B-5:** UI wording for the internal escrow provider.
4. **B-8:** cosmetic lint warning.
5. The registry lookup depends on a single RDAP endpoint (`rdap.org`); no failover is configured.

### Completion evidence

- Source: the files in *Changes made*.
- Executed: module suite 1031 PASS / 0 FAIL (10 suites); lint 86 files, 0 bad.
- Not yet evidence: live WHMCS run, real RDAP lookup, and the B-4 landing-page check in a persisted suite.


## Module 5 — dnschecker specification reconciliation

| Field | Information |
|---|---|
| Module | The DNS checker is delivered inside `modules/addons/CloudHost247_tools` (`docs/MODULES.md`: the standalone `dnschecker` addon was not installed, because its functionality is contained in `CloudHost247_tools`). Changed: `includes/DnsPropagation.php` (new), `includes/tools/dns_tools.php`, `CloudHost247_tools.php` (admin setting), `assets/js/tools/dns-propagation-checker.js`, `assets/js/tools/dns-lookup.js`, `tests/DnsPropagationTest.php` (new). |
| Specification | `docs/All DNS Checker/DNS Checker Build.txt` (the propagation checker, the primary spec here) and the DNS category of `docs/All DNS Checker/All DNS Checker Build.txt`. Two specs name different modules (`dnschecker`, `hostx_tools`); the reconciliation maps both onto the shipped module. |
| Status | **Accepted (you said "continue") with owner items pending:** the live UDP resolver check on a production host, the caching decision, and a browser check. Suite and PHP syntax check pass. |

### Spec reconciliation (`DNS Checker Build.txt`)

| # | Requirement | Before | After |
|---|---|---|---|
| 1 | Domain input, sanitised and validated | Met | Met |
| 2 | Check A, MX, NS, TXT, CNAME propagation | **Broken.** The form sent `record_type`; the handler read `type`, so every check ran as **A**. The form also offered SOA, SRV, CAA, PTR and ANY, which the handler rejected. | **Fixed.** Handler reads `record_type` (with `type` as fallback). Form offers the six supported types. |
| 3 | Results from multiple global DNS servers | Met (10 resolvers) | Met (same 10 resolvers, queried in parallel) |
| 4 | Show status: propagated / not propagated | **Missing.** Per-resolver rows only. | **Added.** `status`, `propagated`, `summary`. Four states: `propagated`, `partial`, `not_propagated`, `unknown`. Two states would misreport resolver failures as "not propagated", so `partial` and `unknown` are added. |
| 5 | Use Capsule or safe PHP functions; no `shell_exec` | **Not met.** Ran `dig` via `shell_exec`. If `dig` is missing, the shell's "dig: not found" text counted as a resolved record (**false positive**). If `shell_exec` is disabled, every resolver showed "not resolved" (**false negative**). | **Fixed.** Pure-PHP DNS client over sockets (UDP, with TCP for truncated answers). No shell. Errors are reported as errors, never as records. |
| 6 | Admin option to select record types | **Missing.** | **Added:** `propagation_record_types` (comma-separated text; WHMCS addon config has no multi-select). Unknown names are ignored; an empty list means all six. |
| 7 | Clean responsive UI, AJAX, loading indicator | Present (`ToolPage`, unchanged) | Unchanged. **Not browser-checked in this sandbox.** |
| 8 | Module loads without errors; no redeclaration | Met | Met. New functions are prefixed `CloudHost247_dns_`; `DnsPropagation.php` is loaded with `require_once`. |
| 9 | Admin enable/disable | Met (existing tool status table) | Met |

### Spec reconciliation (`All DNS Checker Build.txt`, category A: DNS tools)

All 14 named DNS tools exist in the catalog. Four names differ only in wording: "DMARC Lookup & Validator" = `DMARC Checker`; "DNS Health Checker" = `Domain DNS Health Checker`; "DMARC Generator" = `DMARC Record Generator`; "DS Record Lookup" = `DS Lookup`.

Also fixed in this module: **DNS Lookup** had the same `record_type` bug (every lookup ran as A), and its form offered `ANY`, which the handler rejects. Fixed both.

### Changes made

| File | Change |
|---|---|
| `includes/DnsPropagation.php` (new) | Query builder; name and RDATA decoder with pointer-loop guard; response parser (id check, RCODE, truncation, bounds checks); outcome mapping; classifier; UDP fan-out with one shared deadline; TCP retry for truncated answers. Compatible with PHP 7.4. |
| `includes/tools/dns_tools.php` | Propagation handler rewritten (type allowlist, status fields, no shell). DNS Lookup reads `record_type`. |
| `CloudHost247_tools.php` | Admin setting `propagation_record_types` (default `A,AAAA,MX,TXT,NS,CNAME`). |
| `assets/js/tools/dns-propagation-checker.js` | Record-type options trimmed to the six supported types. |
| `assets/js/tools/dns-lookup.js` | Removed `ANY` (handler rejects it). |
| `tests/DnsPropagationTest.php` (new) | 95 checks. Real captured DNS responses from 8.8.8.8 (UDP and TCP), decoded by a separate Python implementation, plus malformed, looping, mismatched and error packets. |

### Security review

- **Shell removed** from the propagation path. The input reaches no command line.
- **Input:** domain validated (`validate_domain`) and labels re-validated when building the packet (length, character set). Record type must be in the allowlist. Admin setting parsed through the same allowlist.
- **Response trust:** 16-bit random query id is checked. The UDP socket is connected (`udp://`), so the kernel discards datagrams from other sources.
- **Parser:** every offset is bounds-checked. Compression-pointer chains are capped at 20 hops. Malformed packets become errors, not warnings or crashes (tested).
- **Resource bounds:** at most 10 UDP sockets, one shared 3-second deadline, TCP retry at most once per resolver with a 2-second timeout. Worst case is about 23 seconds if every resolver is silent and truncates.
- **Output:** the UI renders records via `textContent` (unchanged), so resolver data is not HTML.
- **Rate limit:** the tool stays in the "heavy" bucket (`RateLimiter::HEAVY_TOOLS`).

### Tests

| Command | Result |
|---|---|
| `node tests/run.mjs` (PHP suites, php-wasm 8.3) | **TOTAL PASS=464, FAIL=0** across 7 suites (baseline 369 across 6; new suite `DnsPropagationTest.php` adds 95). |
| `node tests/core.test.mjs` | PASS=68, FAIL=0 (unchanged) |
| `node tests/tools.test.mjs` | PASS=845, FAIL=0. Needs a catalog dump to `/tmp/tools.json`, generated from `includes/Catalog.php` via php-wasm (the repo has no step that writes it). |
| `node tests/qr.test.mjs` | PASS=36, FAIL=0 (unchanged) |
| PHP syntax check (`token_get_all` with `TOKEN_PARSE`) on the five changed or related PHP files | FILES=5, BAD=0. This module has no lint script. |
| Mutation check: TC-flag mask changed in a temporary copy | **Caught:** 3 failures. File restored, verified byte-for-byte. |
| Live TCP fallback (php-wasm, real network): `cloudflare.com` TXT via 8.8.8.8 over TCP | **29 records returned** in ~0.1 s. The truncated-answer path works end to end. |
| Live UDP path (php-wasm, 3 resolvers) | **Not verified.** php-wasm UDP never delivers a reply here, so every resolver reports "no reply before the timeout" after 3.0 s. This confirms the timeout and error reporting, not a successful UDP query. |
| Native UDP from this sandbox (Python) | Reachable to 8.8.8.8 only; 1.1.1.1 and 9.9.9.9 time out from here. Used only to capture fixtures. |

### Remaining issues / blockers

1. **Owner live check (accepted as an owner item):** on the production host, run the propagation checker for a known domain and confirm the 10 resolvers answer over UDP, and that a truncated TXT returns records. This sandbox cannot run PHP UDP sockets.
2. **Caching decision (owner):** the All-DNS spec asks for DNS results cached 5–15 minutes. The propagation checker is not cached, because a cached answer would hide the change being checked. Other DNS tools use the existing cache. Say if you want the propagation results cached.
3. **Worst-case latency:** the TCP fallback runs one resolver at a time. Parallelising it would cut the worst case from about 23 s to about 5 s. Not done yet.
4. **CAA on older PHP (unverified):** `dns_query()` uses `constant('DNS_' . $type)`. If `DNS_CAA` is undefined on a host's PHP version, CAA lookups fail with an error (the runner catches it; no crash). Not checked on PHP 7.4.
5. **Browser check:** the propagation form, its loading state and the new status line are not checked in a browser.
6. **Spec items not covered by this module:** the rest of the All-DNS list (IP, developer, designer and other categories) belongs to the "Additional audit" workstream.

### Completion evidence

- Source: the files in *Changes made*.
- Executed: PHP suites 464/0; `core` 68/0; `tools` 845/0; `qr` 36/0; PHP syntax check 5/0; mutation check caught; live TCP fallback returned records.
- Not yet evidence: live UDP resolver queries, browser UI, PHP 7.4 runtime.

## Module 6 — digitalproducts (Digital Products Marketplace)

| Field | Information |
|---|---|
| Module | `modules/addons/digitalproducts/` (WHMCS addon: products, versioned releases, entitlements, licensing, private downloads, JSON API) |
| Specification | `docs/DIGITAL_PRODUCTS.md` (operator runbook), `docs/DIGITAL_PRODUCTS_REBUILD.md` (audit and rebuild record), `docs/WHMCS Digital Product Module/Build.txt` (original brief), `SECURITY.md`, `API.md`. |
| Status | **Audited and code-fixed; owner decision pending on D-6 (activation limits).** Findings G-1 to G-3 fixed in code and covered by tests. G-4 and G-5 are recorded, not changed. Suite went from 17 assertions (2 files) to **225 assertions (8 files, 0 failures)**. No live WHMCS run. |

### Why this module was re-audited

It was the last never-started item from the first five workstreams that had a
complete rebuild record (`DIGITAL_PRODUCTS_REBUILD.md`) but only 17 offline
assertions. The gap was assurance, not obviously broken code, so the pass built
a database-backed harness first and then looked for defects with it.

### Test harness added

There was no way to execute the module's services offline, because every service
goes through `WHMCS\Database\Capsule` and no test stub existed.

| File | Change |
|---|---|
| `tests/CapsuleShim.php` (new) | Offline `WHMCS\Database\Capsule` backed by in-memory SQLite. Compiles the query-builder subset the module uses (select/aliases, joins with aliases, where/whereIn/whereNull/whereDate/nested closures, orderBy, forPage, insert/insertGetId/update/delete/increment/updateOrInsert/paginate, `raw()`, schema DDL) to real SQL. Unsupported builder methods **throw** rather than being ignored, so a test cannot pass by accident. |
| `tests/bootstrap.php` | Boots the module against the **shipped migrations** (`0001`–`0005`) on SQLite, recreates the WHMCS core tables the module reads (`tblhosting`, `tblorders`, `tblproducts`, `tblclients`, `tblconfiguration`, `tbladdonmodules`), and adds `DPDb` fixtures. |

Migrations run for real, so the suites exercise the production schema — not a
hand-written approximation of it.

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| G-1 | **The permitted-release rule had no shared definition.** `DownloadAuthorizer::versionNotAllowed()` was `protected`, so the client area (`lib/Client.php`) and the API (`api.php`) each re-derived the version themselves and only checked that the requested version was *active and on the product*. Under `purchase_version` mode a client could obtain a download token for any newer active release. The download endpoint then refused it, so nothing leaked — but the module handed out links it knew would fail, and the rule existed in three places. | `lib/Client.php` (`access_mode === 'purchase_version' ? (int) $entitlement->purchase_version_id : …`), `api.php` (same expression) | Medium (consistency, dead links) | **Fixed.** `DownloadAuthorizer::allowedVersionId()` is now the single definition; `versionNotAllowed()` delegates to it, and both callers resolve through it and refuse a requested release that is not the permitted one. |
| G-2 | **Backslash traversal was not rejected in upload filenames.** The check was `preg_match('#(^|[\/])\.\.?([\/]|$)#', …)`. Inside a character class `[\/]` is only `/`, so `..\..\evil.zip` passed. Separately, a name that was nothing but an extension (`.zip`) was accepted, producing an empty basename. | `lib/Security/UploadValidator.php` `validate()` | Low (defence in depth; the stored object uses an opaque random key, and the original name is only a download filename) | **Fixed.** Both separators are recognised, and an empty basename is rejected. |
| G-3 | **A legacy `disabled` release could never be published.** Migration `0002_versions_from_files` writes `status = 'disabled'` for legacy rows that were not active. The admin screen only offered *Publish* when `status === 'draft'`, so such a release was undownloadable, invisible in the client area (`whereIn('status', ['active','retired'])`) and could only be retired — never restored to service. | `install/migrations/0002_versions_from_files.php`; `lib/Admin.php` `versions()` | Low (functional gap) | **Fixed.** Publish is offered for any state that is neither active nor retired. |
| G-4 | **License activation limits are enforced but unreachable.** `License::activateLicense()` honours `domain_limit`/`activations_limit`, but `generateLicense()` is only ever called from `EntitlementService` **without** either value, and there is no admin field, product column or addon setting for them. Every license is therefore issued with unlimited activations. | `lib/License.php` `generateLicense()` / `activateLicense()`; `lib/Services/EntitlementService.php`; grep for `activation_limit` — schema + `api.php` + `Client.php` only, no admin surface | Medium (incomplete feature) | **Open, owner decision (D-6).** Not changed: adding a cap would change business behaviour. Recorded, not silently wired. |
| G-5 | **The ZIP symlink guard is environment-dependent.** It reads `ZipArchive::statIndex()['external_attributes']`, which this PHP build does not expose at all; `isset()` then skips the check silently. The module never extracts archives (objects are stored as opaque blobs and streamed), so this is defence in depth for downstream consumers. | `lib/Security/UploadValidator.php` `validateArchive()`; probe: `setExternalAttributesName()` with mode `0120777` → `statIndex()` returns no `external_attributes` key | Low, and **not verifiable here** | **Open, recorded.** Deliberately not made fail-closed: on a build without the field that would reject every archive. The suite prints `SKIP` rather than claiming a defence it cannot see. |

### Checked and found sound

- **Download authorisation** (`DownloadAuthorizer::resolve()`): wrong client, expired token, replayed single-use token, revoked/suspended entitlement, inactive product, unpublished version, version from another product, suspended/cancelled service, service owned by another client, service on another package, cancelled order — every path refused, and the version-binding rule refuses superseded and newer releases under both access modes. 26 assertions.
- **Entitlement lifecycle** (`EntitlementService`): grant is idempotent, pending/cancelled services and draft/unpublished/unlinked products are not granted, suspend/restore/revoke/reactivate all behave, order-level grant and refund revoke work, and grants write audit rows with correlation ids. 24 assertions.
- **Licensing** (`License`): the raw key is never stored (SHA-256 hash plus an encrypted copy), generation is idempotent per service, unknown/empty/over-long/near-miss keys are refused, suspended and expired keys are refused, expired keys are marked expired, domain binding and activation limits are enforced, and legacy plaintext rows still validate through their hash. 42 assertions.
- **Tokens and rate limiting**: raw tokens are never stored, forged/malformed/expired/spent tokens do not resolve, single-use replay is refused, multi-use tokens survive consumption, purge keeps live tokens, the limiter allows up to the budget and blocks after it, windows reset, and a missing rate-limit table **fails closed**. 40 assertions.
- **Storage and uploads**: a storage root inside the document root is refused, keys are opaque, and ZIP traversal (parent, absolute, Windows drive), corrupt archives and a declared 2 GB zip bomb are all refused.
- **Output escaping**: both client templates escape every user- and database-derived field; `Admin.php` escapes throughout via `e()`.
- **Endpoint hygiene**: all three entry points include the WHMCS `init.php` at the correct depth (verified by resolving each path — `download.php` and `api.php` use `__DIR__ . '/../../../init.php'`, cron uses `dirname(__DIR__, 4)`, all correct); the API rejects `?api_token=`, emits no CORS wildcard, and sets `nosniff`; `download.php` consumes the single-use token *before* claiming the download limit and marks the response `no-store`.

### Changes made

| File | Change |
|---|---|
| `lib/Security/DownloadAuthorizer.php` | New public `allowedVersionId()` — the single permitted-release rule. `versionNotAllowed()` now delegates to it, so download-time behaviour is unchanged. |
| `lib/Client.php` | Resolves the release through `allowedVersionId()`; an explicit `version_id` is honoured only when it is the permitted release. |
| `api.php` | Same rule, returning `422 invalid_version` instead of issuing a link `download.php` would reject. |
| `lib/Security/UploadValidator.php` | Backslash treated as a separator in the filename check; empty basename rejected. |
| `lib/Admin.php` | Publish offered for every non-active, non-retired release (G-3). `allowed_extensions` is now stored through the same allowlist the validator uses, so a hostile value cannot loosen it. |
| `tests/CapsuleShim.php` (new) | SQLite-backed `WHMCS\Database\Capsule` for offline execution. |
| `tests/bootstrap.php` | Boots the shipped migrations and WHMCS core tables; adds `DPDb` fixtures. |
| `tests/00_HarnessTest.php` (new) | 17 checks: every shipped table is created and the grant path works end to end. |
| `tests/02_DownloadAuthorizerTest.php` (new) | 26 checks: happy path, 14 denial paths, version binding, download limits. |
| `tests/03_EntitlementTest.php` (new) | 24 checks: grant, guards, lifecycle, order level, counter, audit. |
| `tests/04_LicenseTest.php` (new) | 42 checks: generation, validation, domain binding, activation limits, legacy keys, crypto. |
| `tests/05_TokenAndLimitsTest.php` (new) | 40 checks: token issuance/lookup/replay/expiry/purge, rate limiter, permitted-release rule. |
| `tests/07_UploadAndStaticTest.php` (new) | 59 checks: real ZIP attacks, extension allowlist, and static regression checks on the security wiring. |

No behaviour was invented and no default was changed. The download-time rule is
byte-for-byte the same decision as before; only its location moved, and the two
callers that previously re-derived it now use it.

### Tests

| Command | Result |
|---|---|
| `node tests/run.mjs` before this pass (2 suites) | 17 PASS, 0 FAIL |
| `node tests/run.mjs` after this pass (8 suites, PHP 8.3.33, php-wasm) | **225 PASS, 0 FAIL** |
| Same suites, PHP 7.4.33 (php-wasm) | **225 PASS, 0 FAIL** |
| `node tests/lint.mjs` | **FILES=35, BAD=0** on PHP 8.3.33 and on PHP 7.4.33 |
| Mutation check A: pre-fix `UploadValidator` filename logic restored | **Caught:** `backslash traversal is refused`, `empty basename is refused` fail. Files restored and verified byte-identical. |
| Mutation check B: pre-fix version selection restored in `Client.php` and `api.php` | **Caught:** 4 static wiring checks fail. Files restored and verified byte-identical. |
| Live WHMCS run (payment hook, email template, real download, admin screens) | **Not run.** No WHMCS install. |
| Browser check of the client area and admin screens | **Not run.** No browser in the sandbox. |

### Remaining issues / blockers

1. **D-6 (owner decision): license activation limits.** Enforcement exists; configuration does not. Choose one: (a) leave unlimited and document it; (b) add a global default in the addon settings (default 0 = unlimited, so nothing changes until an operator sets it); (c) add a per-product column via a migration plus an admin field. Option (b) or (c) is needed before the feature can be called complete.
2. **Live WHMCS staging sign-off**, per `docs/DIGITAL_PRODUCTS.md`: a paid order end to end, a duplicate payment hook, a new release, refund/cancellation, a wrong-customer token, a forged/expired/replayed token, a download limit, a missing file, license validation throttling, and direct HTTP access to the private storage directory.
3. **G-5** stays open and unverifiable here (see Findings).
4. The suite runs on SQLite, not MySQL. MySQL-only DDL in migrations `0004`/`0005` (`ALTER TABLE … MODIFY`) is skipped by the migrations' own `try/catch`, exactly as on a host where it is unsupported; the resulting column types are therefore not identical to production.
5. `Settings::validate()` is called before storage-path validation but does not validate `allowed_extensions`; the admin save path now normalises it through `Settings::extensions()`. A direct call to `Settings::set()` elsewhere could still store an unvalidated value.

### Completion evidence

- Source: the files in *Changes made*.
- Executed: 225/0 on PHP 8.3.33 and PHP 7.4.33; lint 35/0 on both; both mutation checks caught and files restored byte-identical.
- Not yet evidence: the live WHMCS run and the browser check (items 1–2 above).

## Module 7 — CloudHost247_tools (Tools Platform)

| Field | Information |
|---|---|
| Module | `modules/addons/CloudHost247_tools/` (30 PHP files, the authoritative 91-tool registry in `config/cloudhost247-tools.php`, 91 route-split browser modules in `assets/js/tools/`, `includes/{Catalog,Router,Runner,Security,RateLimiter,DnsPropagation}.php`). |
| Specification | `docs/MODULES.md`, `config/cloudhost247-tools.php` (91 tools, 9 categories, `exec` = client/server/hybrid), `includes/Catalog.php`, `bin/verify-catalog.php`. |
| Status | **Audited and code-fixed; owner decision D-7 taken (A-6, 2026-10-11 — the `/tools/*` surface is live, see below).** Findings T2-1 to T2-3 fixed in code and pinned by tests; T2-6 (test reproducibility) fixed. Suite went from **1419 to 1481 assertions, 0 failures** at audit time. No live WHMCS or browser run. |

### Why this module was audited

It is item 7 in the audit order below, and it is the largest tools module in the
repo: 91 tools, a purpose-built security layer and a hardened execution gate
(`CloudHost247ToolsRunner`) that had already been written and heavily tested.
The question for this pass was whether that gate is what actually executes a
tool.

It is not.

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| T2-1 | **The live execution path bypassed the hardened Runner.** `index.php?m=CloudHost247_tools&action=ajax` reaches `CloudHost247ToolsClient::handleAjax()`, which called `call_user_func($handler, $_POST)` directly. `hooks.php` loads `assets/js/CloudHost247-tools.js` on every addon page and `templates/client/tool.tpl` calls `CloudHost247RenderToolForm`, whose submit handler posts to this endpoint — so it is the live path, **not** dead code. It skipped every Runner guarantee: the 1 MB request-size cap, the tiered quotas and global per-IP ceiling, the `exec=client` rejection, input redaction, generic error messages, the execution/socket timeout, and the `nosniff` / `no-store` / `Referrer-Policy` headers. | `includes/classes.php` `handleAjax()` (pre-fix); `assets/js/CloudHost247-tools.js:1111` | High | **Fixed.** `handleAjax()` now delegates to `CloudHost247ToolsRunner::run()` and emits through `Runner::respondLegacy()`. |
| T2-2 | **The privacy guarantee for `exec=client` tools was contradicted by the live path.** The catalog registers 46 tools as `exec=client`, and the Runner tells the user *"This tool runs entirely in your browser and has no server endpoint. Your data is never transmitted to CloudHost247."* All 46 nevertheless have a server handler function, and the legacy bundle has **no client-side execution at all** (no `run()` in the bundle; it posts every form). So all 46 ran on the server. 39 are reachable from the legacy UI's `toolFields`, including **`credit_card_validator`**. Worse, the legacy path logged **raw `$_POST` with no redaction** into `mod_CloudHost247_tools_logs`, so card numbers and passwords were retained in plaintext in the database. | probe: 46 client tools, 46 with a server handler; `toolFields` ∩ client-exec = 39; `CloudHost247_tools_log($toolId, $_POST, …)` (pre-fix) | High (privacy + data retention) | **Fixed.** The server refuses all 46 by slug *and* by legacy handler id; the 39 exposed by the legacy UI now execute in the browser via their existing route-split module; all logging goes through `Runner::redact()`. |
| T2-3 | **Raw exception messages were returned to the browser.** The legacy `catch` echoed `$e->getMessage()`, exposing internals (paths, SQL, driver text) to any caller. The Runner already logged detail server-side and returned a generic message. | `includes/classes.php` (pre-fix) | Medium (information disclosure) | **Fixed** — the delegation inherits the Runner's generic `server_error` text. |
| T2-4 | **The `/tools/<slug>` surface was built and tested but had no request entry point in this repo.** `CloudHost247ToolsRouter` and `CloudHost247ToolsRunner` were referenced only by their own definitions, `tests/`, and `bin/verify-catalog.php`. `api/index.php` was a `die()` placeholder, and there was no `.htaccess` or front controller for the pretty routes. | `grep -rn CloudHost247ToolsRunner\|Router` across the repo (at audit time) | Medium (incomplete wiring) | **Fixed by A-6 (2026-10-11).** Root `.htaccess` rule plus `front.php` added; D-7 decided (proceed). |
| T2-5 | **A cross-module note in this tracker was wrong.** It recorded the legacy AJAX path as *"reachable but not used by the front end."* It is used by every tool page. | `hooks.php:20`, `templates/client/tool.tpl` | Low (documentation) | **Corrected** by this section. |
| T2-6 | **`tests/tools.test.mjs` was not reproducible on a clean checkout.** It reads the 91-tool registry as JSON from `/tmp/tools.json`, and nothing in the repo produced that file, so the 845-assertion suite could only run on a machine where the file already existed. | `tests/tools.test.mjs` | Low (assurance) | **Fixed** — `tests/dump-catalog.php` + `tests/dump-catalog.mjs` generate it, and the suite generates it on demand when absent. |

### Checked and found sound

- **H-1 (carried over from `hostx_tools`) is already fixed here.** `CloudHost247_tools_get_client_ip()` ignores `X-Forwarded-For` / `CF-Connecting-IP` unless `REMOTE_ADDR` is in `CLOUDHOST247_TRUSTED_PROXIES`; `tests/ClientIpTest.php` pins it with 6 assertions, including that three spoofed requests collapse to one rate-limit bucket. Verified, **not** re-fixed.
- **SSRF and URL rules** (`includes/Security.php`, 703 lines): 95 assertions cover the IPv4/IPv6 CIDR blocklist, internal-hostname rejection, scheme/port/credential rejection, IDN normalisation and output escaping.
- **Catalog integrity**: 57 assertions over 91 tools and 9 categories — unique ids, slugs, routes and handlers, route format, SEO field lengths, no "coming soon" placeholders, sensitive tools pinned client-side.
- **Routing**: 84 assertions — all 100 routes resolve, alias 301s, API parsing, fuzzy 404s, canonical/robots, JSON-LD with no fabricated ratings, sitemap exclusions.
- **Handlers**: 72 assertions prove all 91 catalog handlers resolve to a callable, plus behaviour of the newly written ones.
- **Rate limiting**: 17 assertions over per-tool and per-IP buckets, tiers, the global ceiling, bucket isolation and CSRF issue/validate/reject.
- **DNS propagation and IPv6**: 95 and 44 assertions respectively.
- **The 91 browser modules**: `tools.test.mjs` (845 assertions) executes all 46 client `run()` functions and exercises all 45 server `render()` paths.

### Changes made

| File | Change |
|---|---|
| `includes/Runner.php` | New `respondLegacy()` — emits the legacy `{success,data}` / `{success,message}` shape with the same security headers as `respond()`, so the existing bundle keeps working while inheriting every Runner protection. |
| `includes/classes.php` | `handleAjax()` now verifies the legacy CSRF token, honours the admin enable/disable switch, then delegates to `CloudHost247ToolsRunner::run()` and `respondLegacy()`. `renderToolPage()` resolves the catalog record and exposes `tool_exec` / `tool_slug` / `tool_id` to the template. |
| `templates/client/tool.tpl` | Loads `assets/js/tools-core.js` and declares `CloudHost247ToolExec`, `CloudHost247ToolSlug`, `CloudHost247AssetsUrl`. |
| `assets/js/CloudHost247-tools.js` | New `CloudHost247LoadToolModule()` (loads `assets/js/tools/<slug>.js` once, stubbing `ToolPage`/`ready` during load so nothing re-renders the page) and `CloudHost247RunClientTool()`. `CloudHost247SubmitTool` branches on `exec=client` and runs those tools locally **before** any XHR, so their input never leaves the device. |
| `tests/LegacyAjaxTest.php` | New — 62 assertions (see below). |
| `tests/dump-catalog.php`, `tests/dump-catalog.mjs` | New — render the registry to JSON so `tools.test.mjs` is reproducible. |
| `tests/tools.test.mjs` | Generates `/tmp/tools.json` on demand when it is absent. |
| `tests/README.md` | Coverage row for `LegacyAjaxTest.php` and fixture-regeneration instructions. |
| `.gitignore` | Ignore the `CloudHost247_tools/node_modules` symlink. |

### What `LegacyAjaxTest.php` pins (62 assertions)

- All 46 `exec=client` tools are refused by slug **and** by the legacy handler id the bundle posts — asserted per tool, so a regression names the tool.
- The refusal is HTTP 400 / `client_only`, states the browser guarantee, and carries no `data`.
- Every client tool ships a browser module at `assets/js/tools/<slug>.js`.
- `Runner::redact()` masks password / pass / passphrase / cvv / card_number / cc / private_key / api_key / token, recurses into arrays, truncates long values, drops `csrf_token` entirely, and leaves no plaintext card number or password in the encoded payload.
- `respondLegacy()` preserves the `{success,data}` / `{success,message}` contract and never emits internals.
- Static guarantees on the live path: it delegates to `Runner::run` and `respondLegacy`, verifies the legacy CSRF token, honours the admin enable/disable switch, passes the resolved client IP, and contains no `call_user_func`, no `$e->getMessage()`, no unredacted logging of raw `$_POST`, no flat rate limit and no direct handler-file include.
- Static guarantees on the front end: the tool page loads `tools-core.js` and declares the exec mode; the submit handler's client branch runs **before** `xhr.send`; the loader restores `CH.ToolPage` after load and handles an async `run()`.

### Behaviour changes worth knowing

- **Rate limits are now tiered, not flat.** The `rate_limit_requests` addon setting (default 60/min) no longer applies to this endpoint; the Runner's tiers do — server 30/min, hybrid 60/min, heavy 10/min (`traceroute`, `ping`, `dns-propagation-checker`, `blacklist-check`, `broken-links-checker`, `image-to-text`, `website-crawl-test`, `port-checker`, `smtp-test`, `page-rank`, `website-status`, `reverse-image-search`, `speed-test`), with a 120/min global ceiling per IP.
- **Request bodies are capped at 1 MB** (`Runner::MAX_REQUEST_BYTES`).
- **The 39 client-exec tools now render through their modern module**, not the legacy `resultRenderers` — richer output, and no server round trip.
- **Errors returned to the browser are generic**; the detail is logged server-side only.

### Completion evidence

- Executed: **1481 assertions, 0 failures** — PHP suites 532 (9 files), `core.test.mjs` 68, `tools.test.mjs` 845, `qr.test.mjs` 36. The PHP suites were re-run on **PHP 7.4.33** as well as 8.3: 532/0 on both.
- Lint: 31 PHP files clean on 8.3 and 7.4 (`token_get_all(…, TOKEN_PARSE)`), plus `node --check` on both changed JS files.
- Mutation checks, each caught and each restored byte-identical: allowing client-only tools to execute (6 failures), disabling `Runner::redact()` (14), and calling handlers directly again (1).
- Not evidence: no live WHMCS run, no browser run. The browser-execution wiring in particular is verified only by static assertions and by the existing module tests — it needs a click-through on staging.

### Still open (owner)

- **D-7** — decided 2026-10-11 (A-6): the root-level rewrite and front controller are added, the `/tools/*` surface is live, and `api/index.php` is a real endpoint. Nothing still open here.

## Module 8 — cloudhost247services (Domain & Infrastructure Platform)

| Field | Information |
|---|---|
| Module | `modules/addons/cloudhost247services/` — 87 lib files, ~21k lines. Domain platform (search, bulk search, transfers, client DNS CRUD, auto-renewal, registration, auctions, valuation, club, TLD catalog, WHOIS, service requests, Logo Studio, AI builder shell, unified inbox) plus the v1.2.0 infrastructure layer (OS catalog, provider image mappings, server provisioning, reinstall, server actions). |
| Specification | `docs/DOMAIN_SERVICES.md`, `docs/INFRASTRUCTURE.md`, `docs/OPERATIONS.md`, `docs/MODULES.md`. |
| Status | **Audited. No functional defect found; one latent hardening issue fixed (S-1).** This is the best-built module reviewed so far: it already carried 1,396 assertions across 29 suites, all green before any change. Suite now **1,428 assertions (30 files, 0 failures)**. No live WHMCS run. |

### Why this module is different from the previous seven

The prior audits each found the live path bypassing or mis-implementing its own
guard. This module does not have that shape. Its request layer is sound, so the
pass produced one hardening fix rather than a set of severity findings. A clean
result is only meaningful if the checks were real, so the deliverable here is
mostly **regression tests that pin what was verified by reading** — 32 new
assertions covering the surfaces that had no dedicated suite.

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| S-1 | **JSON-LD was emitted without `JSON_HEX_TAG`.** `Landing::headMarkup()` encoded with `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` only. Neither flag escapes `<` or `>`, so a payload containing `</script>` is emitted literally and closes the tag. **Not exploitable today**: `seo['jsonld']` is never populated anywhere in the module — it is dead configuration. It is a latent trap waiting for the first person to assign user-derived SEO data. | `lib/Http/Landing.php` `headMarkup()`; probe: `json_encode(['name'=>'</script><script>alert(1)</script>'], JSON_UNESCAPED_SLASHES\|JSON_UNESCAPED_UNICODE)` emits the tag literally | Low (latent), defence in depth | **Fixed.** Added `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT`, keeping `UNESCAPED_SLASHES`/`UNICODE` so URLs and non-ASCII SEO text stay readable (verified: `héllo` survives). |

### Checked and found sound

- **Admin portal** (1,055 lines, previously untested): `render()` requires `Identity::adminId()` before anything else and returns *"Administrator session required"*; `checkToken()` runs **before** `handlePost()` for **any** POST, so every mutation is covered by one central call rather than per-case discipline; `checkToken()` prefers WHMCS's `check_token('WHMCS.admin.default')` and falls back to `Csrf::verifyRequest()`; hand-built error/success boxes escape through `chs_h()`; mutations write `Audit::admin()` rows.
- **Customer portal** (1,140 lines): every handler that reads `Http::post()`/`$_POST` calls `Csrf::verifyRequest()`, and does so **before** the first state-changing service call (verified per-handler, 19 handlers). The 12 handlers without a verify call are provably read-only — GET-only listings, ownership-scoped lookups, or JSON config endpoints.
- **IDOR**: every `*Detail` handler passes the session `$clientId` into the service call (`detail($id, $clientId)`, `placeBid($clientId, $id, …)`, `export($clientId, …)`, `requestAction($clientId, $id, $do)`), so one client cannot address another's auction, request, server or domain.
- **Output escaping**: notification `subject`/`body` are escaped in both feeds (`dashboard.tpl`, `notifications.tpl`) — worth pinning because those strings embed user-supplied domain names, and the pre-existing `28_TemplateEscapeTest` only greps `flash`/`prefill`. Every mutation form carries `{$csrf_field}`. The unescaped `{$var}` occurrences are trusted literals (`$modulelink`, `$csrf_field`, `$WEB_ROOT`), integer ids, or loop variables over literal arrays and server-defined enums.
- **Notification links** are not user-controlled: every `notify()` call passes either an internal `index.php?m=…&id=<int>` URL or `Platform::gateway()->invoiceUrl(<int>)`.
- **`WhmcsGateway`** (460 lines, untested — the real money path) fails closed outside WHMCS (`function_exists('localAPI')` → throws) and builds invoice amounts from integer minor units via `Money::toDecimal((int) $item['amount_minor'], …)`.
- **`Controller::guard()`** maps each domain exception to an honest screen and collapses `\Throwable` to a generic message with the detail logged server-side — the same discipline the other modules needed adding.

### Remaining coverage gaps (recorded, not fixed)

These files have **no test coverage at all** and were reviewed by reading only. They are the natural next pass if this module is revisited:

`Http/AdminPortal.php` (1,055), `Http/Controller.php`, `Http/Landing.php` (now partly pinned by `30_PortalSecurityTest`), `Http/functions.php`, `Providers/Whmcs/WhmcsGateway.php` (460 — real invoice/order creation; tests use `FakeGateway`), `Providers/Infrastructure/HttpInfrastructureProvider.php` (357 — real provisioning; tests use `FakeInfrastructureProvider`), `Workflow/InfraWorker.php`, `Core/{Audit,Blueprint,I18n,Logger}.php`, `Admin/BlockonomicsFactory.php`, `Services/{DomainEventService,SitemapService}.php`.

The two that matter most are **`WhmcsGateway`** and **`HttpInfrastructureProvider`**: both are the *real* implementations behind fakes, both touch money or external infrastructure, and neither has a single assertion.

### Changes made

| File | Change |
|---|---|
| `lib/Http/Landing.php` | `json_encode()` for the JSON-LD payload now sets `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` (S-1). Slashes and Unicode remain unescaped. |
| `tests/30_PortalSecurityTest.php` | New — 32 assertions covering the four surfaces above (see below). |

### What `30_PortalSecurityTest.php` pins (32 assertions)

- **Customer portal CSRF**: every handler reading POST input calls `Csrf::verifyRequest()`, and does so before its first state-changing service call (a mutator-name regex, so it cannot pass off a comment); the GET-only handlers are enumerated; `pageLogoApi` and `pageOrderConfig` are asserted to be read-only JSON endpoints with no write call.
- **IDOR**: every `*Detail` handler passes `$clientId` into a service call.
- **Admin portal**: the staff gate precedes `handlePost`, `checkToken()` precedes `handlePost`, both token paths exist, and the hand-built HTML escapes through `chs_h()`.
- **JSON-LD (S-1)**: all four `JSON_HEX_*` flags present **inside the `json_encode()` call** (not merely in the docblock), plus behavioural proof that `</script><script>alert(1)</script>` is escaped, that non-ASCII SEO text survives, and that a meta description containing `"quoted" & <tagged>` is HTML-escaped.
- **Notification escaping** in both templates, and CSRF fields on both mutation forms.
- **Gateway fail-closed** on `WhmcsGateway`.

### Completion evidence

- Executed: **1,428 assertions, 0 failures** (30 files) — up from 1,396/0 before any change.
- Lint: `node tests/lint.mjs` → 105 files, 0 bad.
- Mutation checks on S-1, each caught and each restored byte-identical (`diff -q` verified): removing all four `JSON_HEX_*` flags (5 failures) and removing only `JSON_HEX_TAG` (3 failures).
- Not evidence: no live WHMCS run. The portal assertions are static/wiring checks plus the JSON-LD behavioural test — the admin portal and `WhmcsGateway` are verified by reading, not execution.

## Module 9 — hostx (HostX theme companion)

| Field | Information |
|---|---|
| Module | `modules/addons/hostx/` — 63 PHP files, 47,701 lines. Page builder, blocks, settings, menus, SEO content, theme assets. `docs/MODULES.md` records it as **required** by the custom landing pages and by `templates/hostx`. |
| Specification | None in-repo. It is third-party commercial software. |
| Status | **Cannot be audited — 100% of the source is ionCube-encrypted.** Recorded as **owner decision D-8**. This is a finding about the project, not about the module: it is the largest single block of code in the repository and the only one that no review, test or lint can reach. |

### Finding

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| H-1 | **All 63 PHP files are ionCube-encoded bytecode.** Each file opens with a plain-PHP guard that prints an *"ionCube Loader needs to be installed"* notice and calls `exit(199)` when the Loader extension is missing, followed by base64 ciphertext. There is **no readable source anywhere in the module** — not in `hooks.php`, `includes/`, `classes/`, or the root files. | `for f in $(find modules/addons/hostx -name '*.php'); do head -c 400 "$f" \| grep -q ionCube; done` → **63 of 63** | Informational for the module; **material for the project** | **Open, owner decision (D-8).** Not a defect to fix — a boundary to accept or act on. |

What this means concretely:

- **No source review is possible.** Every technique used in the previous eight audits — reading the request layer, tracing ownership, checking CSRF and escaping — has nothing to read here.
- **No tests and no lint are possible.** This fully explains why `hostx` was one of the four addons with no test suite; it is not a gap in discipline, it is a property of the artifact.
- **It is a hard runtime dependency.** The module cannot run at all without the `ionCube Loader` PHP extension on the server. The guard fails loudly and correctly (`exit(199)` with an explanatory message) rather than silently misbehaving, which is the right behaviour for encoded software.
- **It is 47,701 lines — the largest attack surface in the repo**, larger than `cloudhost247services` (≈21k) and `CloudHost247_tools` (≈4k) combined, and it is entirely outside every assurance process the project has.

### The same applies to `xtreme_currency_rates`

While confirming the extent of the encoding, a second fully-encoded module was found: **`xtreme_currency_rates`, 18 of 18 PHP files**. It was earlier listed (in the coverage survey) as one of four addons with no tests; that is explained by the same cause, not by neglect. It is not on this audit list, so it is recorded here rather than audited.

### What was verified despite the encryption

- The loader guard is present in every file and **fails closed and loudly** — an operator who deploys without the extension gets a clear message and a non-zero exit, not a blank page.
- No readable PHP anywhere in the module, so no unencoded side-car code was missed.

### Owner decision D-8

Choose one:

1. **Accept** — document that `hostx` and `xtreme_currency_rates` are opaque third-party components, treat them as trusted binaries, and rely on the vendor for fixes. Cheapest, and probably correct: they are commercial products where the source was never available.
2. **Replace `hostx` with first-party code** — it is a *required* dependency of `templates/hostx` and the custom landing pages, so this is a large project, not a swap.
3. **Reduce the dependency** — determine exactly which landing pages and template hooks need `hostx`, and decide whether any of that surface can be served without it.

Recommended: **option 1**, with two owner actions — confirm the licences are current and that a vendor support channel exists, and confirm the production PHP runtime has the ionCube Loader installed (otherwise the module is dead weight that fails at runtime).

### Completion evidence

- Executed: the encoding scan above (63/63 and 18/18).
- Not evidence, and not possible: source review, tests, lint, mutation checks, live run.

## Module 10 — Announcement Bar

| Field | Information |
|---|---|
| Deliverable | `templates/hostx/includes/announcementbar.tpl`, integrated into `templates/hostx/header.tpl` immediately after `<body>` (above the navbar). |
| Specification | `docs/Announcement Bar/Build.txt` (13 numbered requirements) plus `Announcement Bar.pdf`. |
| Status | **Complete against the specification; two defects found and fixed (AB-1, AB-2).** One informational item recorded (AB-3). No automated tests — see note below. |

### Spec conformance

| Requirement | Met | Evidence |
|---|---|---|
| File at `/templates/hostx/includes/announcementbar.tpl` | Yes | File present |
| Smarty + HTML + CSS only, no JS | Yes | No `<script>` in the template |
| Integrated into the layout above the navbar | Yes | `header.tpl:14`, first include after `<body>` |
| Multiple messages from an array, text + optional link | Yes | `{foreach from=$announcements item=announcement}` |
| Horizontal scroll, smooth infinite loop, CSS `@keyframes` only | Yes | `@keyframes announcement-scroll`, `translateX(0 → -50%)` |
| No `<marquee>`, no external slider library | Yes | None present |
| Seamless looping, no gaps or jumps | Yes | Items rendered twice via `{section name=loop loop=2}` with a `-50%` translate |
| Pause on hover | Yes | `.announcement-bar:hover .announcement-bar__track { animation-play-state: paused; }` |
| Clickable when a URL is present | Yes | `<a href>` branch vs `<span>` branch |
| Responsive (mobile / tablet / desktop) | Yes | Breakpoints at 767.98px and 575.98px |
| Prevent text overflow / layout breaking | Yes | `overflow: hidden`, `white-space: nowrap`, `flex-shrink: 0` |

Good practice already present: every interpolation is escaped (`|escape:'html'` on `url`, `text` and `icon`), external links carry `rel="noopener noreferrer"`, decorative dots and icons are `aria-hidden="true"`, the region has `role="region"` + `aria-label`, and `prefers-reduced-motion` disables the animation.

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| AB-1 | **The separator bullet anchored to the wrong element.** `.announcement-bar__item::after` used `position: absolute; right: 0`, but `.announcement-bar__item` has no `position` of its own. The nearest positioned ancestor is `.announcement-bar` (`position: relative`), so every copy of the bullet stacked at the bar's right edge instead of sitting after its item. | `.announcement-bar__item` rule has no `position`; `::after` has `position: absolute; right: 0` | Low (cosmetic) | **Fixed** by deleting the vestigial rule. Adding `position: relative` to the item would have been the wrong fix: the `.announcement-bar__dot` span already renders the intended separator, so keeping the `::after` would have doubled it. The matching `display: none` override in the `prefers-reduced-motion` block was removed with it. |
| AB-2 | **Screen readers announced every message twice.** The seamless loop renders the item list twice (`{section name=loop loop=2}`), and both copies were exposed to assistive technology. | `{section name=loop loop=2}` wrapping the `{foreach}` | Low (accessibility) | **Fixed** — the second pass is marked `aria-hidden="true"`, so each message is announced once while the visual loop is unchanged. |
| AB-3 | **`href` accepts any URI scheme.** `\|escape:'html'` does not stop a `javascript:` URL. Not exploitable today: the `$announcements` array is assigned server-side (by a hook, an addon, or statically), not from user input. | `href="{$announcement.url\|escape:'html'}"` | Informational | **Recorded, not changed.** Worth revisiting if announcements ever become admin- or user-editable through a form. |

### Testing note

There is no test harness for `templates/hostx/` anywhere in the repository, and none was added for this item. The template is Smarty rendered inside WHMCS, so both fixes are **static-only and unverified by execution** — they need a visual check on staging (confirm one separator dot between messages, and that the loop is still seamless). This is the weakest assurance of the ten modules and is called out rather than glossed over.

## Module 11 — tools_center (second pass over `external-api/`)

| Field | Information |
|---|---|
| Module | `modules/addons/tools_center/` — `external-api/` (api.php, auth, cache, config, rate-limit, outbound guard) plus 10 tool classes totalling 5,848 lines. |
| Specification | `API.md`, `INSTALL.md`, `README.md`. Module 2 covered QR; this is the second pass over the remaining `external-api/` surface. |
| Status | **Second pass complete; two findings fixed (TC-1, TC-2).** Suite went from **91 to 93 assertions, 0 failures**. No live run. |

### Baseline

| Suite | Result |
|---|---|
| `node tests/run-tests.js` | 31 PASS / 0 FAIL (1 SKIP — jsdom not installed) |
| `node tests/run-php-guard.mjs` (SSRF guard) | 60 PASS / 0 FAIL |

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| TC-1 | **Reflected XSS: `data.error` was written to `innerHTML` unescaped.** `displayResults()` rendered the failure banner as `'<div class="tc-alert tc-alert-danger">…' + (data.error \|\| 'An error occurred') + '</div>'`. The API reflects caller-controlled input into that string — `apiError('Tool category not found: ' . $category)` and `apiError('Tool action not found: ' . $action)`, where both values come straight from `$input`/`$_GET`. So `?category=<img src=x onerror=…>` is echoed into the page. Every *other* interpolation in this file already escapes via `escapeHtml`/`escapeAttr`; this was the one path that did not. | `js/tools-center.js:301` `displayResults()`; `external-api/api.php` `apiError('Tool category not found: ' . $category, …)` | Medium | **Fixed** — `escapeHtml(data.error \|\| 'An error occurred')`. |
| TC-2 | **Raw exception messages were returned to the caller.** The catch block replied `'Tool execution failed: ' . $e->getMessage()`, exposing internal paths, driver text and SQL to anyone holding the API token — and, via TC-1, rendering it into the page. The detail was already being written to the on-disk error log. | `external-api/api.php` catch block | Medium (information disclosure) | **Fixed** — the caller now gets `'Tool execution failed. The error has been logged.'`; the detail still goes to the log file. |

### Severity note on TC-1

Authentication runs **before** category/action resolution, so all three reflected error strings are reachable only with a valid API token — an anonymous attacker gets a static `401 Unauthorized`. That caps this at Medium rather than High. Two things still make it worth fixing promptly:

- The token is a single shared static credential (the WHMCS module calls its own external API with it), so anyone who obtains it gets the XSS.
- `api.php` sets **`Access-Control-Allow-Origin: *`**, which widens who can drive an authenticated request from a browser context. That wildcard is a pre-existing open item carried from Module 2 and is **unchanged** here — see the note below.

### Checked and found sound

- **SSRF guard** (`outbound.php`): 60 assertions cover `tc_is_public_ip`, `tc_validate_outbound_url`, `tc_resolve_public_ips`, `tc_fetch_url` (the guard runs before any connection), `tc_open_public_socket` and `tc_resolve_redirect`. cURL is pinned to `CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS`, so the token is never forwarded across a redirect.
- **Action dispatch**: only public, non-magic, non-static methods **declared on the tool class itself** are reachable — verified with `ReflectionMethod::getDeclaringClass()`, which correctly blocks inherited and magic methods. The category filename is filtered through `preg_replace('/[^a-z0-9_-]/i', '', $category)`.
- **Escaping generally**: the tool classes mostly return data rather than HTML, which is correct — the API emits JSON (`json_encode` + `nosniff`) and the client escapes. Where a class *does* build HTML (`GamingTools::formatMinecraftText()`), it escapes first with `htmlspecialchars($text, ENT_QUOTES)`.
- **Response hygiene**: `display_errors` is off, `error_reporting(E_ALL)`, and errors are logged to a dated file rather than displayed.

### Pre-existing items referenced, not re-raised

- The external API's `Access-Control-Allow-Origin: *` wildcard (Module 2 open item) — unchanged.
- The unused `qrScanner()` / `qrGenerator()` in `external-api/` (owner decision) — unchanged.

### Changes made

| File | Change |
|---|---|
| `js/tools-center.js` | `displayResults()` escapes `data.error` through `escapeHtml()` (TC-1). |
| `external-api/api.php` | The execution catch returns a generic message and logs the detail server-side (TC-2). |
| `tests/run-tests.js` | Two new assertions: the error banner escapes `data.error`, and the API does not echo `$e->getMessage()`. |

### Completion evidence

- Executed: **93 assertions, 0 failures** — `run-tests.js` 33 (was 31), `run-php-guard.mjs` 60. One SKIP remains (jsdom not installed).
- Mutation checks, each caught and each restored byte-identical (`diff -q` verified): removing `escapeHtml()` from the error banner (1 failure) and restoring the exception echo (1 failure).
- Not evidence: no live run. Both fixes are verified by static assertion only; the XSS needs a staging click-through to confirm end to end.

## Module 12 — cloudhost247cloudflare

| Field | Information |
|---|---|
| Module | `modules/addons/cloudhost247cloudflare/` — 35 PHP files, 3,176 lines. Encrypted Cloudflare accounts, product mappings, linked customer services, zone/DNS CRUD. Documented in `docs/MODULES.md` (Phases 11–13) and `docs/PHASE1{1,2,3,4}_CLOUDFLARE_*.md`. |
| Specification | `docs/MODULES.md` lines 26 and 43; `docs/PHASE11_CLOUDFLARE_COMPATIBILITY.md`, `PHASE12_CLOUDFLARE_DNS_INVENTORY.md`, `PHASE13_CLOUDFLARE_DNS_BRIDGE.md`, `PHASE14_CLOUDFLARE_DNS_API.md`. |
| Status | **Audited; one real defect found and fixed (CF-1).** This module shipped with **no test suite at all**; the pass built one. Suite now **60 assertions, 0 failures**; lint 37 files clean. No live run. |

This module was **not** on the original 11-item list. It is one of six addons that had no completion record anywhere in the tracker, so it counts as unfinished under the original definition ("any non-complete status in the tracker **plus** modules in `MODULES.md` with no completion record").

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| CF-1 | **The API endpoint allowlist did not constrain the port.** `validateBaseUrl()` pinned scheme, host, user, pass, query and fragment — but `parse_url()` returns the port in its own `$parts['port']` key, which the check never examined. So `https://api.cloudflare.com:22/client/v4` passed validation, and the account's Cloudflare API token would have been sent to port 22 on that host. | `lib/Provider/CloudflareClient.php` `validateBaseUrl()`; probe: `new CloudflareClient('https://api.cloudflare.com:22/client/v4', 'tok')` was accepted | Low (host is still pinned, so this is not open SSRF — but the module's own error text claims to pin the endpoint, and the token is what traverses it) | **Fixed** — an explicit port is now refused unless it is 443. `:443` is still accepted, so nothing legitimate breaks. |

CF-1 was found by the new assertion suite rather than by reading: the first version of the "alternative port" test passed for the wrong reason (it had no valid path, so the path check rejected it), which is exactly why the tests were rewritten to give every hostile endpoint a valid `/client/v4` path.

### Checked and found sound

- **Credential encryption** (`Core/Crypto.php`) is exemplary, and notably **does not repeat the `hostx_email` H-4 mistake**: AES-256-GCM with a 12-byte random nonce, a 16-byte tag, AAD bound to `cloudhost247-cloudflare-v1`, and key material taken from `CLOUDFLARE_ENCRYPTION_KEY` or WHMCS's real secret `$cc_encryption_hash` — not from the public SystemURL. It **never falls back to plaintext**: `open()` throws on a missing `cfenc:v1:` prefix, a truncated payload, a tampered ciphertext, or the wrong key.
- **Outbound transport** (`Provider/CurlTransport.php`): TLS verification on (`VERIFYPEER`, `VERIFYHOST` = 2), connect/response timeouts, and **no `CURLOPT_FOLLOWLOCATION`** — so the bearer token cannot be forwarded to a redirect target.
- **Path and parameter safety** (`Provider/CloudflareApi.php`): resource ids are matched against `/^[A-Za-z0-9_-]{1,128}$/` and then `rawurlencode`d; zone settings are checked against a 19-item allowlist.
- **Both portals**: the admin portal calls `Csrf::verifyRequest()` before `Identity::requireAdmin()` and any mutation, and audits every write; the client portal gates on `Identity::clientId()` first, then verifies CSRF, scopes lookups through `ServiceRepository::forCustomer($serviceId, $clientId)`, and rate-limits writes (30/60s).
- **DNS validation** (`Service/DnsRecordValidator.php`): 8-type allowlist, per-type content rules (IPv4/IPv6 via `filter_var`, CAA and SRV regexes with numeric ranges), TTL 60–86400 or Automatic, priority 0–65535, length caps, and `firewallRule()` validates the IP/CIDR with `inet_pton` **before** embedding it in the rule expression — which is what prevents expression injection.

### Changes made

| File | Change |
|---|---|
| `lib/Provider/CloudflareClient.php` | `validateBaseUrl()` now refuses any explicit port other than 443 (CF-1). |
| `tests/run.mjs` (new) | php-wasm runner, matching the convention used by the other modules. |
| `tests/lint.php`, `tests/lint.mjs` (new) | Parse-check all 37 PHP files. |
| `tests/01_SecurityCoreTest.php` (new) | 60 assertions — see below. |

### What `01_SecurityCoreTest.php` pins (60 assertions)

- **Crypto**: round trip, prefix, ciphertext never contains the plaintext, randomised nonce, empty-in/empty-out, and refusal of plaintext / foreign-prefix / truncated / garbage / tampered / wrong-key values.
- **Endpoint pinning**: the official endpoint is accepted, and nine hostile endpoints are refused — each carrying a **valid `/client/v4` path** so only the host/port/scheme checks can reject them.
- **DNS validation**: all 8 record types; per-type content rejection (A with IPv6, AAAA with IPv4, bad CAA, unsupported types); TTL, priority, comment and TXT bounds; proxying forced to TTL Automatic for A/AAAA/CNAME and dropped elsewhere.
- **Firewall rules**: invalid IP, invalid CIDR prefix, unknown action, empty description, and expression injection.
- **Domain names**: normalisation (case, trailing dot, whitespace) and zone containment, including the `example.com.evil.test` suffix lookalike.

### Completion evidence

- Executed: **60 assertions, 0 failures**. Lint: 37 files, 0 bad.
- Mutation checks, each caught and each restored byte-identical (`diff -q` verified): weakening the host check (6 failures, up from 1 after the tests were tightened) and adding a plaintext fallback to `Crypto::open()` (2 failures).
- Not evidence: no live run. The DNS and crypto assertions execute real code; the endpoint-pinning assertions construct `CloudflareClient` but never issue a request.

### Remaining coverage gap

The module's DB-backed surface has **no tests**: `Http/AdminPortal.php`, `Http/ClientPortal.php`, `Service/ProvisioningService.php`, `Service/Worker.php`, `Service/JobQueue.php`, and the three repositories. There is no offline `Db` shim for this module (unlike `digitalproducts`' `CapsuleShim`), so covering them means building one first.

## Module 13 — soyoustart (WGS OVH / SoYouStart admin addon)

| Field | Information |
|---|---|
| Module | `modules/addons/soyoustart/` — 34 PHP files, 8,857 lines. **Vendored third-party code** (`WGS-OVH-v8.0.8-Sourcecode.zip`): OVH API consumer setup, product/price settings, order management, existing-server import, server status, email templates. |
| Specification | `docs/MODULES.md` lines 29, 37, 49, 52. |
| Status | **Audited; two findings recorded and NOT patched (SO-1, SO-2).** No test suite exists and none was added. No live run. |

This is the second of the six addons with no completion record. It was prioritised because it is the largest of them with **zero tests** and it handles OVH API credentials and server provisioning.

### A deliberate decision: record, do not patch

`soyoustart` is **vendored third-party code**, unlike every module audited before it. Editing it means forking upstream, and the next vendor drop silently reverts or conflicts with any local fix. Both findings below are therefore **recorded rather than patched**, for owner action. This is a change of approach from Modules 6–12 and is stated so the difference is visible rather than looking like an omission.

If the owner prefers fixes in-tree, the work is small and described per finding — but it should be a conscious choice to fork this module.

### Findings

| ID | Finding | Evidence | Severity | Status |
|---|---|---|---|---|
| SO-1 | **Hardcoded OVH application keys in source.** Two literal application keys are committed: `t7r8jC5iiznmTNNm` in **7** header constructions, and `iE3vL3mgAtLZg00l` in a further one (`classes/ApiCall.php:264`). The module has a correct, configuration-driven path — `createHeader()` reads `application_key` from the `mod_soyoustart` table — but these call sites bypass it and send a fixed key instead. | `classes/ApiCall.php` lines 142, 163, 185, 207, 237, 251, 328, and 264; contrast with line 380 `trim($authData->application_key)` | Medium | **Open, recorded.** Rotation requires a code change, and these calls ignore the operator's configured OVH application entirely — so they are also very likely *broken*, not merely untidy. |
| SO-2 | **`$endPoint` is interpolated into a URL without validation.** `getOs($endPoint)` builds `"https://ca.api.ovh.com/1.0/dedicated/installationTemplate/{$endPoint}"` with no format check, so a value containing `../`, `?` or `#` alters the request path. | `classes/ApiCall.php:265` | Low | **Open, recorded.** The host is a hardcoded literal, so this cannot reach a non-OVH host — it is path manipulation within OVH's API, not SSRF. |

**Important qualifier on SO-1:** the **signing secret is not exposed.** It is read from the database (`$authData->secret_key`) and never hardcoded, and `generateSignature()` implements OVH's scheme correctly (`'$1$' . sha1(secret + consumer + method + url + data + time)`). OVH's application key is an identifier, not a signing credential, so possession of these literals alone does **not** permit forging signed requests or taking over an account. The severity rests on **non-rotation and bypass of configuration**, not on credential compromise.

### Verified, including a documentation claim

`docs/MODULES.md` states that *"the legacy OVH transport verifies TLS and restricts signed requests to trusted OVH API endpoints."* That claim was checked against the code and **holds**:

- `CURLOPT_SSL_VERIFYPEER => true` and `CURLOPT_SSL_VERIFYHOST => 2` — TLS is verified.
- `CURLOPT_FOLLOWLOCATION => false` — redirects are not followed, so the `X-Ovh-Signature` header cannot be forwarded to a redirect target.
- `CURLOPT_CONNECTTIMEOUT => 20`, `CURLOPT_TIMEOUT => 60`.
- API hosts are hardcoded OVH literals (`api.us.ovhcloud.com`, `eu.api.ovh.com`, `ca.api.ovh.com`, `api.ovh.com`); no request host is derived from user input.

Also checked and sound:

- **No SQL injection surface anywhere in the module.** There is no raw SQL string interpolation and no direct `mysql_*`/`PDO::query()` call; all persistence goes through the WHMCS `Capsule` schema and query builders (`classes/CustomDatabase.php` is schema DDL only).
- **Credential storage**: the application key, consumer key and secret are held in the `mod_soyoustart` table and read per request, rather than being derived from a public value — so this module does **not** repeat the `hostx_email` H-4 pattern.

### Testing note

No test suite exists and **none was added.** Every other module audited in this programme got one, and the omission is deliberate for two reasons: this is vendored code that the owner may replace wholesale, and a meaningful suite would need an offline OVH API harness plus a `Capsule` shim. That is a real gap and is recorded as such — the assertions that would matter most are "no hardcoded credentials" and "every request host is an OVH literal", both of which are cheap to add if the owner wants them.

### Recommended owner action

1. Rotate the OVH application credentials, and confirm whether the 7 hardcoded call sites are currently failing (they bypass the configured application, so they may already be broken).
2. Decide fork-vs-replace for this module. If replacing, the findings resolve themselves; if forking, both are small, well-localised changes.
3. Confirm with the vendor whether a newer WGS-OVH release already addresses SO-1.

## Module 14 — the last four addons (baseline verified, targeted review)

| Module | Lines | Assertions | Ratio | Baseline |
|---|---|---|---|---|
| `cloudhost247ai` | 13,989 | 1,126 (15 suites) | 1 per 12 | **PASS / 0 FAIL** |
| `cloudhost247marketing` | 15,039 | 674 (8 suites) | 1 per 22 | **PASS / 0 FAIL** |
| `cloudhost247passkey` | 11,871 | 200 (11 phases) | 1 per 59 | **PASS / 0 FAIL** |
| `cloudhost247_cart_recovery` | 4,653 | 61 | 1 per 76 | **PASS / 0 FAIL** |

**Total: 2,061 assertions, 0 failures.** All four were the remaining addons with no completion record. With this entry, **every addon in `MODULES.md` now has a completion record.**

### Scope — stated precisely

These four already shipped substantial test suites, so they did **not** need suites built from scratch. What they needed was a verification pass. Given that they total ~45,500 lines, this entry is:

- **Baseline verification** — all four suites executed and confirmed green.
- **A targeted security review of the highest-risk surface in the thinnest-covered module** (`cloudhost247_cart_recovery`, see below).

It is **not** a line-by-line audit of 45,500 lines comparable to Modules 6–13. That is recorded rather than implied. If the owner wants that depth, `cloudhost247passkey` (authentication) and `cloudhost247ai` (agent/tool execution over live data) are the two worth prioritising.

### `cloudhost247_cart_recovery` — recovery-link security (checked, sound)

Chosen because it has the weakest coverage ratio (1 assertion per 76 lines) and handles the module's highest-risk asset: hashed cart-recovery links.

- **Generation**: `bin2hex(random_bytes(32))` — a 256-bit token.
- **Storage**: only `hash('sha256', 'cloudhost247-cart-recovery:' . $token)` is persisted (`RecoveryService.php:117`); the raw token is never stored.
- **Lookup**: records are found by `token_hash` (`RecoveryService.php:239`), not by the raw token, so a 256-bit value must be guessed — enumeration is not feasible.
- **Comparison**: `hash_equals()` (constant time) via `TokenService::equals()` (`lib/TokenService.php:47`).
- **Expiry**: enforced on both the recovery path (`RecoveryService.php:248`) and the reminder path (`ReminderService.php:72`), against `token_expires_at` set from `SettingsRepository::int('token_lifetime')`.
- **Domain separation**: unsubscribe tokens use distinct hash prefixes (`cloudhost247-cart-unsubscribe:`, `…-lookup:`), so a recovery token cannot be replayed as an unsubscribe token or vice versa.

No defect found. The design matches what `docs/MODULES.md` describes (256-bit hashed links with expiry).

### Follow-up (2026-10-10): `cloudhost247passkey` authentication core — audited at depth, sound

Taken first of the four because authentication is the highest-consequence surface in the module. Scope: the WebAuthn ceremony and challenge lifecycle (`lib/Core/WebAuthnService.php`, `lib/Core/CeremonyChallengeStore.php`). **No defect found.** The controls that matter are all present:

- **Cryptography is delegated** to the audited `web-auth/webauthn-lib` (`loadAndCheckAttestationResponse` / `loadAndCheckAssertionResponse`), with the algorithm set constrained to `['ES256','RS256']`. Not hand-rolled.
- **Fails closed when dependencies are absent** — `assertRuntimeAvailable()` throws unless the library classes exist.
- **Single-use challenge, consumed atomically.** `consume()` performs a compare-and-swap (`UPDATE … SET consumed_at = ? WHERE consumed_at IS NULL`) and rejects when `$changed !== 1`, so two concurrent assertions cannot both succeed. This closes the TOCTOU replay race that simpler `SELECT`-then-`DELETE` implementations leave open.
- **Challenge is bound to everything that matters**, each compared with `hash_equals`: challenge type, `user_type`, `user_id`, a **session binding hash**, the RP ID and the origin. TTL is 300 seconds. Stored options are additionally cross-checked against the raw challenge.
- **Ownership is re-verified *after* signature verification**, not assumed from it: `findOwnerByHandle($source->getUserHandle())` must resolve, and the owner's `user_type` must match the scope and (when a specific user was requested) the `user_id` must match. This is the control that stops a valid credential for one account authenticating as another, and it is applied on both the registration and authentication paths.
- Usernameless (discoverable) login is handled by the same post-verification owner check rather than by a separate weaker path.
- Registration requires `none` attestation, and the returned user handle must match the local identity.

Not covered by this pass: `lib/Admin.php` (1,534 lines), `lib/Http/PasskeyHttpKernel.php` (1,052), `lib/Core/ExternalIdentityLinkService.php`, `lib/Core/PasskeyActionConfirmationService.php`, and the client templates.

### Follow-up (2026-10-10): `cloudhost247ai` tool-execution layer — audited at depth, sound

Second of the four. Scope: the agent tool-execution pipeline — the surface that lets an LLM read and write live WHMCS data (`lib/Tools/ToolExecutor.php`, `lib/Tools/ToolDefinition.php`, `lib/Tools/ToolRegistry.php`, `lib/Tools/Readers/*`, `lib/Approval/ApprovalEngine.php`). **No defect found.**

**The pipeline fails closed at every step.** `ToolExecutor::doExecute()` applies, in order: kill-switch → tool exists and is enabled → per-agent tool grant (allowlist, and an unknown agent or a DB error both `return false`) → actor authority via `Rbac` (no group ⇒ no access) → approval gate → parameter validation → redaction → call → audit. Refusals are audited as well as executions, so blocked attempts leave a trail.

**Multi-tenant isolation holds on all nine client-reachable tools.** Client scope is structurally restricted to `risk === 'READ' && $tool->clientBound`, so a customer cannot reach a write tool at all. The nine client-bound readers then isolate by one of two enforced mechanisms:

- **Eight billing readers** force the session's client id first and make the caller-supplied `client_id` unreachable. The shape is `if ($client = ch247ai_scope_client($ctx)) { bind forced } elseif (!empty($args['client_id'])) { bind arg }` — the `elseif` is the control. Because `ch247ai_scope_client()` returns the session id whenever scope is `client`, the argument branch is only reachable when the forced value is 0 (admin scope). **This was the specific pattern checked for IDOR; it is correct, and deliberately uses `elseif` rather than a separate `if`.**
- **One knowledge reader** (`read_knowledge`) has no per-client rows to filter on, so it isolates by visibility instead: `ch247ai_knowledge_search()` appends a hardcoded `AND ks.visibility = 'public'` when `$onlyPublic` is true. Critically, `$onlyPublic` is derived from `$ctx['scope'] === 'client'` — a server-side value, never from a tool argument — and the filter is a literal, not interpolated input. It is applied on both the MySQL FULLTEXT path and the LIKE fallback, so the fallback is not a weaker parallel route.

**Write execution is gated three ways**: the global `writes_enabled` flag defaults to `false`; non-READ tools additionally require an approval row that is approved, unexpired, *and* whose argument digest matches this exact call (`assertExecutable` + `assertArgumentsMatch`), so an approval cannot be replayed against different arguments.

Supporting controls observed: every query is parameterised with bound values (no SQL string interpolation anywhere in the readers), row limits are clamped (`ch247ai_clamp_limit` to 1–50, knowledge to 1–10), tables are checked with `ch247ai_require_tables()` which fails loudly with `DATA_UNAVAILABLE` rather than letting the model answer from memory, results pass through `Redaction::clean()` on the way out, and each tool returns `_citations` for grounding.

Not covered by this pass: `lib/Http/AdminPortal.php` (1,116 lines), `lib/Agents/AgentRuntime.php` (439), `lib/SupportOperator/` (operator engine, conversation and escalation services), `lib/Board/`, `lib/Model/ModelRouter.php` and the outbound provider calls.

### Follow-up (2026-10-10): `cloudhost247marketing` delivery path — audited at depth, sound

Third of the four. Scope: outbound message construction and delivery — `lib/Transport/Message.php`, `lib/Transport/SmtpTransport.php`, `lib/Delivery/TrackingService.php`, `lib/Http/TrackingEndpoint.php`, and the unsubscribe path in `lib/Audience/SubscriberService.php`. **No defect found.** This is the surface where a marketing module is most often exploitable, so each classic finding was tested for specifically.

- **SMTP command and header injection — closed.** `SmtpTransport` interpolates `$message->toEmail` and `$from` straight into `MAIL FROM:`/`RCPT TO:` with no escaping of its own, which would normally be the finding. It is safe because `Message::clean()` strips `\r`, `\n` and `\0` at construction, and is applied to every address, display name, subject and header value. Header *names* are restricted to `[A-Za-z0-9-]`, so no new header can be introduced. Two things make this hold rather than merely look right: `Message::make()` is the **only** construction path — a search for direct assignment to the public `$toEmail`/`$fromEmail`/`$subject`/`$replyTo`/`$headers` across `lib/`, `api.php` and `hooks.php` returns nothing, so the sanitiser cannot be bypassed — and `rfc822()` re-sanitises through `encodeHeader()`/`address()` at build time, giving defence in depth. `dotStuff()` correctly doubles leading dots for the DATA phase.
- **Click tracking is not an open redirect.** The destination is resolved from the database by link token (`TrackingService::recordClick()`/`linkUrl()`), never from a query parameter, so a caller cannot supply an arbitrary target. The 302 also sets `Referrer-Policy: no-referrer`, and the HTML fallback escapes with `ch247m_h()`.
- **Tracking tokens are cryptographically strong.** `uniqueLinkToken()` uses `Str::token(18)` — base64url of 18 bytes from `random_bytes()`, i.e. 144 bits. The `uniqid()` line beneath it is a fallback reachable only after ten consecutive collisions and is effectively dead code.
- **Self-service opt-out is scoped to the caller.** `SubscriberService::optOutClient()` iterates `forClient($clientId)` and only unsubscribes rows belonging to that client, so it cannot be pointed at another tenant's subscriptions.
- **Transport security is sound for the `tls` mode**: STARTTLS is required and the connection is torn down if the server does not advertise it or refuses the upgrade; `peer_name` is bound to the configured host for certificate verification, and PHP's default `verify_peer` applies. EHLO/HELO names are filtered to `[A-Za-z0-9.-]`.

**Two observations that are not defects, recorded so they are not rediscovered:** (1) `encryption` permits a `none` value, which allows cleartext submission — an operator configuration choice, but one worth an admin-UI warning. (2) `CampaignService.php:933` and `:944` fall back to `substr(hash('sha256', uniqid(..., true)), 0, 32)` if `random_bytes()` throws; `uniqid()` with `more_entropy` is not a CSPRNG, so on a host without a working random source these degrade. They are fallbacks, not the live path.

Not covered by this pass: `lib/Http/AdminPortal.php` (2,015 lines — the largest file in the addon), `lib/Campaign/CampaignService.php` (946), `lib/Automation/AutomationService.php` (739), `lib/Audience/SegmentService.php` and `lib/Campaign/Renderer.php` (template rendering of subscriber-supplied fields).

### Follow-up (2026-10-11): `cloudhost247_cart_recovery` full audit — 8 findings, all fixed

Last of the four, and the only module that remained at baseline depth. Unlike
the three passes above, this one covered the whole module line by line (~3,100
lines of `lib/` plus hooks, cron, the public recover/unsubscribe endpoint, the
admin screen and both migrations), because the module is small enough to make
that economical. **8 findings, all fixed, each with a regression test.** Suite
grew 61 → 70 assertions, green, plus lint and the 15 static checks. A negative
control (new tests against the pre-fix `lib/`) fails exactly the 7
behaviour-changing tests and nothing else.

**Fixed:**

- **Unsubscribe regressed terminal records (data integrity, medium).**
  `RecoveryService::unsubscribe()` transitioned the clicked record
  unconditionally, so a converted customer clicking an old unsubscribe link
  flipped `converted` → `unsubscribed`, silently deleting a conversion and
  orphaning its revenue from analytics. Fix: new
  `RecoveryService::suppressRecipient()` records the suppression but only
  transitions *open* records; the public endpoint and the admin action both
  use it. The refactor also closed a minor inconsistency where the admin
  action stopped one record while the public path stopped all sibling carts
  for the same recipient.
- **Stuck `sending` claims wedged records until token expiry (robustness,
  medium).** `deliver()` refuses rows in `sending`, but a worker that died
  between the claim and the status update (OOM-kill, timeout, SIGKILL) left
  the row there forever: no further reminder, no schedule advance, silent
  until the 7-day token expiry. Fix: `process()` now reaps claims untouched
  for 30+ minutes back to `failed` (logged, counted as `reaped`). The reset
  is idempotent and the send still goes through the single-winner claim, so
  overlapping runs cannot double-send; `attempts` is preserved so the retry
  budget still bounds poison records.
- **Guest-controlled names merged raw into HTML reminder bodies (email HTML
  injection, low-medium).** Guest checkout supplies both the recipient
  address (unauthenticated) and the name, and both reached the HTML body
  unescaped — an attacker could send arbitrary markup (tracking/phishing
  content; scripts are neutered by clients) to any address. Fix: new
  `EmailService::htmlVars()` escapes every merge variable at the HTML sinks
  (guest body, client `customvars`), except intentionally-HTML `cart_items`.
- **CR/LF in merge variables reached the guest subject (header injection,
  low).** Defence in depth on top of whatever the mailer strips: new
  `EmailService::headerVars()` removes line breaks from subject and display
  name values.
- **LIKE-escape missed the backslash (minor correctness).** The admin search
  escaped `%`/`_` but not `\`, so a backslash in the query changed the
  meaning of the next character. One-line fix, plus the test fake's LIKE now
  implements true MySQL escape semantics (it previously treated `\%` as a
  wildcard too).
- **`Lock::release()` cleared the lease unconditionally (minor hardening).**
  The ownership re-read closed most of the race, but the final UPDATE is now
  conditional on the full value read, so a lease stolen in the microsecond
  window is never cleared from under its new owner.
- **Dashboard summed revenue in PHP (perf).** `Analytics::summary()` loaded
  every converted row to total `recovered_revenue`; it is now a SQL `SUM()`.
  Behaviour-identical, O(1) memory.

**Checked and sound (no change):** token lifecycle (Module 14's conclusions
re-verified, including that `TokenService::equals()` is genuinely used by the
decrypt-verify in `deliver()`); snapshot allow-list + deny pattern + depth
and length caps + re-sanitise on restore (session poisoning closed; WHMCS
recalculates prices at checkout anyway); the idempotent send claim
(UNIQUE key + conditional UPDATE, still the backstop under overlapping
workers); suppression honoured at capture and send time; admin auth
(Foundation guard + `Manage Addon Modules` permission), CSRF (`check_token`
on every POST, no state-changing GET); every admin echo escaped (also
enforced by the static suite); no raw SQL, no `mail()`/SMTP stack, no
dynamic code execution, no weak randomness anywhere in the module; cron is
CLI-only via a server-set SAPI check; recovery redirect targets the
server-configured SystemURL (no open redirect); response codes on the public
endpoint (404/410) leak nothing enumerable against 256-bit tokens; reusable
recovery links until expiry and fail-open client lookup are accepted design,
reviewed, not findings.

**Observations recorded, not defects:** (1) concurrent first captures (two
devices, same client) can insert duplicate open records — PHP session
locking makes this rare and the consequence is a duplicate reminder, not a
security issue; (2) `OrderRevenue` falls back to the client's latest order
when WHMCS omits both ids, which can misattribute revenue in a case the
hooks should never produce; (3) `MigrationRunner` can throw a create-table
race if two first-install crons overlap — self-heals on the next run;
(4) info-level logs are silent when Foundation is absent (errors still reach
`logModuleCall`/`error_log`); (5) `tests/*.php` execute over HTTP like every
other module suite in this repo — benign output (pass/fail against in-memory
fakes), but a repo-wide `deny` for `*/tests/` at the webserver layer would
apply to all 17 suites, not just this one.

Not covered by this pass: live WHMCS runtime behaviour (E-2); the README
was re-read and remains accurate, no changes needed.

### Note on the four node_modules symlinks

`cloudhost247ai`, `cloudhost247_cart_recovery`, `cloudhost247marketing` and `cloudhost247passkey` each needed `node_modules` for the php-wasm runners. That directory is a symlink to `cloudhost247services/node_modules` and is **gitignored for `CloudHost247_tools` and `cloudhost247cloudflare` only**. The four symlinks created here are untracked and were not committed — a fresh clone needs `npm i @php-wasm/node` in each module. Worth adding to `.gitignore` alongside the existing two entries.

## Additional audit (after workstreams 1–5)

Order: `hostx_tools`, `customaffiliate`, `digitalproducts`, `hostx_email`, `phoneservices`, `smmaddon`, `CloudHost247_tools`, `cloudhost247services`, `hostx`, announcement bar, `tools_center`. Each item follows the same workflow and needs approval before the next one starts.

**Progress: the original 11-item list is complete, and every addon in `MODULES.md` now has a completion record.** Six addons had **no completion record at all** and so were unfinished under the original definition — `cloudhost247ai`, `cloudhost247cart_recovery`, `cloudhost247cloudflare`, `cloudhost247marketing`, `cloudhost247passkey`, `soyoustart` (~57,600 lines between them). All six are now recorded: `cloudhost247cloudflare` (Module 12), `soyoustart` (Module 13), and the remaining four (Module 14).

**Depth varies, and is stated per module rather than averaged away:** Modules 6–13 were audited line by line against their specs. Module 14's four addons (~45,500 lines) initially received **baseline verification plus a targeted review of the highest-risk surface** — not a full audit.

**Now upgrading those four one by one.** `cloudhost247passkey`'s authentication core (WebAuthn ceremonies + challenge lifecycle) has since been audited at full depth and found **sound** — atomic single-use challenge consumption, session/RP/origin/identity binding, and post-verification ownership checks. See the Module 14 follow-up. **Now upgrading those four one by one.** `cloudhost247passkey`'s authentication core (WebAuthn ceremonies + challenge lifecycle) has since been audited at full depth and found **sound** — atomic single-use challenge consumption, session/RP/origin/identity binding, and post-verification ownership checks. `cloudhost247ai`'s tool-execution layer has likewise been audited at depth and found **sound** — the pipeline fails closed at every step, and all nine client-reachable tools enforce tenant isolation. See the Module 14 follow-ups.

`cloudhost247marketing`'s delivery path has also been audited at depth and found **sound** — SMTP injection closed at the `Message` boundary with no bypass path, click-tracking destinations resolved from the DB rather than the query string, 144-bit CSPRNG tracking tokens, and self-service opt-out scoped to the caller.

**Remaining at baseline depth: `cloudhost247_cart_recovery` only** (4,653 lines). Its recovery-link security was already reviewed and found sound, so what remains is the rest of the module rather than a known-weak area.

**Open owner decisions:** D-2 (deactivation, partially done), D-6 (`digitalproducts` activation limits), D-7 (routing `/tools/<slug>` — decided via A-6 2026-10-11), D-8 (policy for the two 100% ionCube-encoded modules). Plus the pre-existing High items: **P-5** (`phoneservices` all-tenant API key) and **M-5** (`smmaddon` client-controlled order quantity).

**Not done anywhere in this programme:** no CI (fifteen suites, nothing runs them), and no live WHMCS or browser run.

**Note on method:** `soyoustart` is vendored third-party code, so its findings are **recorded rather than patched** (forking upstream creates upgrade pain). That is a deliberate departure from Modules 6–12 and is stated in the Module 13 section.

Original 11-item list — all done: `hostx_tools` (decision D-2 = retire, deactivation pending), `customaffiliate`, `digitalproducts` (Module 6), `hostx_email`, `phoneservices`, `smmaddon`, `CloudHost247_tools` (Module 7), `cloudhost247services` (Module 8 — cleanest module reviewed: no functional defect, one latent JSON-LD hardening fixed), `hostx` (Module 9 — **cannot be audited**, 63/63 files ionCube-encoded; decision D-8), announcement bar (Module 10 — spec-complete, two cosmetic/a11y fixes), `tools_center` (Module 11 — second pass, reflected XSS and exception-leak fixed).

**Two cross-cutting discoveries from the final items, recorded for follow-up:**

1. **`hostx` (63/63) and `xtreme_currency_rates` (18/18) are 100% ionCube-encrypted** — no readable source, so no review, test or lint is possible for either. `hostx` alone is 47,701 lines: the largest single block of code in the repository and entirely outside every assurance process the project has. Owner decision **D-8**.
2. **No CI exists.** Fifteen modules have test suites and nothing runs them. See the recommendation in the audit summary.

**Open owner decisions now:** D-2 (deactivation, partially done), D-6 (digitalproducts activation limits), D-7 (routing `/tools/<slug>` — decided via A-6 2026-10-11), D-8 (policy for the two encoded modules). Plus the pre-existing High items still open in other modules: **P-5** (phoneservices all-tenant API key) and **M-5** (smmaddon client-controlled order quantity).

> The earlier "1 of 11" line understated progress: `customaffiliate`, `hostx_email`,
> `phoneservices` and `smmaddon` were audited on 2026-10-10 as additional audit
> items 1–4, above. Corrected 2026-10-10 when `digitalproducts` was added.

### 1. hostx_tools — audit complete, decision needed

| Field | Information |
|---|---|
| Module | `modules/addons/hostx_tools` (21 files, about 3,800 lines PHP, **no tests directory**). |
| Specification | `docs/All DNS Checker/All DNS Checker Build.txt` names this module. It asks for about 100 tools across nine categories (DNS, IP, developer, designer, webmaster, network, security, productivity, gaming). |
| Status | **Decision D-2 = A (retire), approved.** H-1 fixed in code (`SecurityManager::getClientIp()` now trusts forwarding headers only from `CLOUDHOST247_TRUSTED_PROXIES`); 6 regression checks in `tests/ClientIpTest.php` (fail on the old code). Owner action pending: deactivate the addon in WHMCS. No other code removed. |

**Scope.** The module's own README lists four tools: domain WHOIS, IP lookup, DNS lookup, domain availability. The spec asks for about 100. That is a scope gap of about 96 tools.

**Duplication.** All four tools are already in `CloudHost247_tools`, which has 91 tools and is the recommended tools addon in `docs/MODULES.md`: `domain-whois`, `ip-whois`, `dns-lookup`, `domain-search`. Running both gives two WHMCS addons doing the same job, which the directive rules out.

### Findings

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| H-1 | **Rate limit can be bypassed by spoofing the client IP.** `SecurityManager::getClientIp()` takes the first address from `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR` and similar headers, before `REMOTE_ADDR`. Each request can pick a new "client", so the per-IP limit (default 30/min) does not apply. The limit protects paid API quotas (IPinfo, WhatIsMyIP). | `includes/SecurityManager.php` lines 273–302. **Reproduced in the PHP runtime:** three requests with three spoofed `X-Forwarded-For` values gave three rate-limit keys; the real address was `203.0.113.50`. | Medium |
| H-2 | **Two specs share the name.** `docs/MODULES.md` maps the `WHMCS Domain Lookup` zip to `hostx_tools`, and the module matches that spec (WHOIS, IP WHOIS, availability, DNS). The `All DNS Checker Build.txt` spec also names `hostx_tools` and asks for about 100 tools. Corrected: the module is not short of its own spec; the 100-tool spec is a different requirement and duplicates `CloudHost247_tools`. | `docs/MODULES.md` line 17; `docs/WHMCS Domain Lookup/Build.txt`; `docs/All DNS Checker/All DNS Checker Build.txt` | Info (corrected), decision D-2 |
| H-3 | Duplicates `CloudHost247_tools` (four of its tools). | Catalog slugs `domain-whois`, `ip-whois`, `dns-lookup`, `domain-search` | Policy, decision D-2 |
| H-4 | No automated tests. Nothing in this module can be verified by a suite. | No `tests/` directory | Medium |

**Checked and found sound:**
- **CSRF:** a random 32-byte token per session, compared with `hash_equals`. POST only.
- **No shell execution.** The only `exec` hits are `curl_exec`.
- **Output escaping:** the client script escapes server data (`escapeHtml`) in the WHOIS, DNS and availability tables. The DNS form posts `type`, which matches the handler, so the `record_type` bug from Module 5 does not occur here.
- **Server-side rendering:** the templates are static shells. The client script fills them.
- **WHOIS:** the server for each TLD comes from a fixed map, so user input cannot choose a host.
- **File cache:** keys are MD5 hashes, so no user input reaches a file name.
- **Input:** domains and IPs are validated before use.

### Cross-module note (for the `CloudHost247_tools` item)

`CloudHost247_tools` has the same IP spoofing problem in its **legacy** AJAX path (`includes/classes.php`, `CloudHost247ToolsClient::handleAjax()`, reached via `index.php?m=CloudHost247_tools&action=ajax`). It is keyed on `CloudHost247_tools_get_client_ip()`, which trusts `X-Forwarded-For`. Reproduced: three spoofed headers gave three keys. The legacy path also calls tool handlers directly with raw `$_POST`, so it skips the runner's request-size cap, heavy-tier limit and global per-IP ceiling. The current front end posts to `/tools/api/<slug>`, which goes through the runner and is safe. The legacy path is reachable but not used by the front end. The safe function `CloudHost247ToolsSecurity::clientIp()` already exists and only trusts forwarded headers behind a configured trusted proxy (`CLOUDHOST247_TRUSTED_PROXIES`).

### Decision D-2 — decided: option A (retire)

You chose A. Recorded as done in documentation only. The Cloudflare question was answered "not sure", so the H-1 fix (if ever needed) defaults to the connection address with a trusted-proxy setting.

Owner action: deactivate HostX Tools in WHMCS (System Settings > Addon Modules). Until then H-1 remains exploitable.

Original options, for reference:

- **A. Retire `hostx_tools`.** Deactivate it and document it as superseded by `CloudHost247_tools`. No new code. The H-1 issue then needs no fix here. Recommended by the duplication rule. Reversible, nothing is deleted.
- **B. Finish `hostx_tools` to the spec.** About 96 more tools, duplicating `CloudHost247_tools`. Large, and the duplication stays.
- **C. Keep it as it is.** Fix H-1 and add tests. The duplication stays and is documented.

**H-1 fix design (needed for option B or C):** trust forwarded headers only when `REMOTE_ADDR` is in a configured trusted-proxy list, the same rule as `CloudHost247ToolsSecurity::clientIp()`. Default: `REMOTE_ADDR` only. Behind Cloudflare, `REMOTE_ADDR` is a Cloudflare edge address, so without the Cloudflare ranges configured every visitor would share one rate-limit bucket. That is a deployment decision.

### Remaining issues / blockers

1. **D-2 decision** (A, B or C), and the trusted-proxy list if B or C.
2. The `CloudHost247_tools` legacy AJAX path (cross-module note above), to be fixed in that item.

### Completion evidence

- Source read: `hostx_tools.php`, `includes/*.php`, `api/*.php` (search for execution, escaping and CSRF), `assets/js/hostx-tools.js`, templates.
- Executed: the IP-spoofing reproduction for both modules (PHP 8.3 via php-wasm). No change made in this item.
- Not run: no suite exists for this module.

---

## E-1 — CI pipeline (project gap, closed 2026-10-11)

| Field | Information |
|---|---|
| Gap | `docs/UNFINISHED_MODULES.md` E-1: 17 test suites existed and nothing ran them — no `.github/` directory, no workflow files. |
| Status | **Complete.** `.github/workflows/ci.yml` runs on every push and pull request: full PHP matrix on 8.3 (17 suites, one step per module, plus load/parse gates and the root-landing check), legacy 7.4 leg for the three 7.4-supported modules, Node 22 suites incl. jsdom UI wiring, a repo-wide `php -l` sweep (ionCube modules excluded, D-8), and the Python static checks. Runbook: `docs/CI.md`. |

### Design

CI executes the same `tests/*.php` files as the committed `run.mjs`
wrappers, but on native PHP instead of php-wasm: faster, more faithful
(real SQLite/OpenSSL/ZIP), no npm dependency. The wasm wrappers keep
working — every touched suite resolves paths absolute-mount-first with a
checkout-relative fallback. Drivers live in `ci/` (`run-php.sh`,
`run-js.sh`, `php-lint.sh`) and are also the local entry points, so CI
and local runs cannot drift.

### Test-only changes (no production code)

- Path fallbacks: `cloudhost247apps` tests 10/11/12/13/17 + `lint.php`,
  `cloudhost247services` test 21.
- Fixed dead fallback: `cloudhost247services` tests 11/12 used
  `dirname(__DIR__, 3)` (the `modules/` dir) instead of the repo root —
  now `CHS_ROOT`. Only reachable on native runs, so invisible until now.
- Exit codes: 13 files that printed `FAIL=` but always exited 0 now
  `exit($fail ? 1 : 0)`.
- Flake fix: `cloudhost247apps` test 25 freezes `Clock` around the
  retention section (cutoff-edge fixture + second boundary = observed
  34/3 failure in pre-push validation).

### Completion evidence

- Pre-push validation in-sandbox (php-wasm, committed-runner-identical
  mounts): **126/126 suite files green**, plus Node `core` 68/0, `qr`
  36/0, `tools` 845/0, `tools_center` 43/43 with jsdom 24, Python
  static 15/15 — every count matches this tracker.
- Post-push: GitHub Actions run `38096863878` is green 5/5 on real PHP
  8.3/7.4 + Node 22 + Python. The first CI run already paid for itself:
  `php -l` caught a PHP 8 production fatal no suite exercised —
  unparenthesized `a ? b : c ?: d` in Smtphosting
  `Packages/WhmcsService/Service.php:112` (file could not load at all on
  PHP 8) — fixed with behavior-preserving parens (`9f12bc0`).
  TOKEN_PARSE-based lints cannot see this class of error (it is raised
  at compile time, and `token_get_all(TOKEN_PARSE)` only parses), which
  is why the native `php -l` sweep exists. `ci/php-lint.sh` also emits
  file-level `::error::` annotations so future failures name the file.
- Not covered (unchanged): E-2 live runs; group C modules with no
  suites; D-8 encoded modules.

## A-8 — empty theme templates (closed 2026-10-11)

| Field | Information |
|---|---|
| Gap | `docs/UNFINISHED_MODULES.md` A-8: 4 zero-byte `templates/hostx` templates (`all-elements.tpl`, `clientareacreditcard.tpl`, `creditcard.tpl`, `pwreset.tpl`), feared to render blank pages. |
| Status | **Complete.** No zero-byte `.tpl` remains. Three files are legacy WHMCS names retired in 8.x and now carry non-rendering marker comments; the fourth was an orphaned rename leftover and is deleted. |

### Resolution

- `pwreset.tpl`, `creditcard.tpl`, `clientareacreditcard.tpl`: verified
  against upstream `WHMCS/templates-six`, which ships them empty /
  intentionally-blank — the flows they once served moved to the
  `password-reset-*` route templates, `checkout.tpl`, and the
  `account-paymentmethods` templates (all present and current in this
  theme, which requires WHMCS 8.x). The feared blank-page impact does
  not apply; filling them with legacy forms would have added dead,
  untestable code. Each file now holds a `{* *}` Smarty comment
  recording why it is blank, so no future audit re-flags them.
- `all-elements.tpl`: not a WHMCS template name, referenced nowhere in
  the repo, no matching custom page — a leftover of the rename to
  `all-element-hostx.tpl` (the 104 KB showcase rendered by root
  `all-element-hostx.php`). Deleted.

### Completion evidence

- `find templates/ -name '*.tpl' -size 0` → no output.
- Comment-only `.tpl` edits render byte-identical output to empty files
  (Smarty `{* *}` comments produce no output), so no visual regression
  is possible; CI re-runs green on push.

## A-5 — Image to Text OCR placeholder (closed 2026-10-11)

| Field | Information |
|---|---|
| Gap | `docs/UNFINISHED_MODULES.md` A-5: `CloudHost247_tool_image_to_text()` returned `'status' => 'placeholder'` — server-side OCR never implemented, while the catalog promised an opt-in server path. |
| Status | **Complete.** The handler performs real OCR via OCR.space; the opt-in is enforced server-side and gated in both frontends; the catalog describes the shipped behaviour. |

### Resolution

- Server (`includes/tools/productivity_tools.php`): the handler now
  requires the explicit `server_ocr` opt-in flag (re-checked here so
  direct API calls cannot bypass consent), accepts the image as a
  multipart upload or base64, validates the bytes in memory (finfo
  MIME + `getimagesize`, ≤ 1 MB, ≤ 8000 px per side, JPG/PNG/GIF/BMP/
  TIFF only — the raster formats the provider accepts), and POSTs to
  `https://api.ocr.space/parse/image` with the key from the existing
  `ocr_api_key` module setting. The transport is a dedicated cURL
  block with TLS verification on (the shared module helper disables
  it), a 20 s timeout inside the Runner's 25 s budget, and provider
  failures mapped to user-safe errors (invalid key, usage limit,
  timeout, unreadable image). Images are never written to disk and
  never logged. A missing key yields a requires-configuration error,
  not a placeholder.
- Legacy bundle (`assets/js/CloudHost247-tools.js`): precise file
  `accept` list, an opt-in checkbox in the form def, a submit gate
  that refuses to upload until consent is ticked, and a renderer for
  the extracted text with stats.
- Route-split module (`assets/js/tools/image-to-text.js`): rewritten
  for the JSON page transport — the picked file is read to a data URL
  in a `mount()` hook (700 KB client cap so base64 fits the 1 MB
  request limit), `validate()` blocks submits without a file or
  without consent, and the module is marked `sensitive` so image data
  stays out of localStorage history and share links.
- Catalog (`config/cloudhost247-tools.php`): `exec` `hybrid` →
  `server`, `inputs` updated, new `sensitive` flag, and the
  description rewritten to the shipped behaviour. The `hybrid` value
  promised a browser-local OCR engine that exists nowhere in the
  tree; no code ships that promise any more. A vendored in-browser
  engine (Tesseract-style, cf. the vendored QR decoder) remains a
  possible future enhancement — it would be a new feature, not a
  stub completion.

### Completion evidence

- New `tests/OcrTest.php`: 69 assertions covering opt-in allowlist,
  both intake paths, validation, key configuration, the stubbed
  success path (text/stats/payload shape), and every mapped failure —
  69/69 green, full PHP suite 601/601.
- JS suites green (`tools` 845 incl. the new `sensitive` parity
  assertion, `core` 68, `qr` 36); `node --check` clean on both
  edited bundles.
- Manual QA path for the site admin: set `OCR API Key` in the module
  settings (free keys at ocr.space/ocrapi/freekey), upload a clear
  photo with the consent box ticked, and expect extracted text; the
  provider's shared `helloworld` key works for a one-off smoke test.

## A-6 — `/tools/*` request entry point (closed 2026-10-11)

| Field | Information |
|---|---|
| Gap | `docs/UNFINISHED_MODULES.md` A-6 / finding T2-4: the Router/Runner surface had no request entry point — `api/index.php` was a `die()` placeholder, no `.htaccess`, no front controller — so hundreds of assertions guarded traffic that never arrived. Owner decision D-7. |
| Status | **Complete. D-7 decided: proceed.** The surface is live behind a minimal rewrite rule; all route types dispatch, execute and render through one tested front controller. |

### Resolution

- New `modules/addons/CloudHost247_tools/front.php`: bootstraps WHMCS
  (`init.php`, when deployed over WHMCS) and emits the response. The
  route comes from `?route=` (the rewrite target), `PATH_INFO`, or the
  raw request URI, in that order.
- New `includes/Front.php` (`CloudHost247ToolsFront::dispatch`, pure
  and offline-testable): resolves via `Router::resolve` and serves
  every route type — tool/category/index/search/disclaimer pages,
  sitemap XML, 301 aliases, 404s with suggestions, and the POST-only
  `/tools/api/<slug>` JSON endpoint through `Runner::run` with
  `require_csrf`. Page shells carry the ToolPage mount nodes, the
  session CSRF token, per-route assets, breadcrumbs, JSON-LD, and
  absolute canonicals (WHMCS SystemURL preferred, strictly validated
  request host as fallback, relative URLs when neither is safe).
- Both enable switches are honoured on pages and the API: the
  registry `enabled` flag and the Tools-Manager switch in
  `mod_CloudHost247_tools_status` (fail-closed, like the legacy path;
  the registry decides alone without a database).
- `api/index.php` is now a real endpoint: a thin alias that delegates
  to the same dispatch (`?tool=<slug>` + JSON/form body).
- New root `.htaccess` (the repo had none): a single self-contained
  `/tools/*` rewrite block, documented to stay above any WHMCS
  friendly-URL rules. Without mod_rewrite the same pages work via
  `front.php?route=`. Nginx equivalent (server config, not shippable
  from this repo):
  `location /tools { try_files $uri $uri/ /modules/addons/CloudHost247_tools/front.php?route=$uri&$args; }`
- Found and fixed while wiring: `Router::assets()` listed a
  `tools-browse.js` that never existed (404 on every browse page).
  Removed — `tools-core.js` already initialises the filter, FAQ and
  nav on every page. A test now asserts every emitted asset URL
  exists on disk, for all 91 tool routes plus the browse pages.
- Known limitation (pre-existing Router design, out of scope):
  generated links and canonicals are root-absolute, so pretty URLs
  assume a root WHMCS install, not a subdirectory.

### Completion evidence

- New `tests/FrontTest.php`: 88 assertions — dispatch for every route
  type, page shells (mount nodes, CSRF shape, module scripts, badges,
  related/suggestions), API success through a real handler plus every
  failure envelope (405/400/419/422/404), enablement matrix, site-URL
  derivation incl. Host-header rejection, `jsonResponse` byte-parity
  with `Runner::respond`, live execution of both entry files with
  output capture, and static pins on the rewrite rule, the api alias
  and on-disk assets. Full PHP suite 689/689; JS suites unchanged and
  green (845/68/36). One mutation probe (forced enablement) failed
  exactly the disabled-path tests; file restored byte-identical.
- Manual QA path: with the checkout deployed over WHMCS, visit
  `/tools`, `/tools/dns`, `/tools/dns-lookup` (run a lookup),
  `/tools/search?q=ssl`, `/tools/disclaimer`, `/tools/sitemap.xml`,
  a renamed URL (301) and a bogus URL (404 with suggestions); POST to
  `/tools/api/my-ip` without a token (419) and via a page submit
  (200). If mod_rewrite is off, the same pages answer at
  `modules/addons/CloudHost247_tools/front.php?route=<path>`.
