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

Tests: `tests/CommissionTest.php`, 22 checks, all pass; 12 of them failed on the old code. PHP parse check passes on all 8 PHP files. Not run: a live WHMCS invoice and refund flow, and hook order (AffiliateCommission vs InvoicePaid), which depends on WHMCS.

Open for the owner: partial refunds still reset the full first-commission flag (documented, conservative). `UpgradeHandler` and the upgrade/downgrade hooks only record notes; re-grouping logic runs only from the manual utility.

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


## Additional audit (after workstreams 1–5)

Order: `hostx_tools`, `customaffiliate`, `digitalproducts`, `hostx_email`, `phoneservices`, `smmaddon`, `CloudHost247_tools`, `cloudhost247services`, `hostx`, announcement bar, `tools_center`. Each item follows the same workflow and needs approval before the next one starts.

**Progress:** 1 of 11 audited (`hostx_tools`, awaiting a decision). Items 2–11 not started.

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
