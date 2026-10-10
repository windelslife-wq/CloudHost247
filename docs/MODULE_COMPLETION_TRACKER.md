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



## Module 3 — cloudhost247apps (hosting control plane)

| Field | Information |
|---|---|
| Module | `modules/addons/cloudhost247apps` (WHMCS addon: control plane, catalog, billing gate, deployments, agent, cPanel/WHM adapter boundary, cron) |
| Specification | `docs/HOSTING_CONTROL_PLANE_AUDIT.md` (post-audit status), `docs/APP_PLATFORM_PLAN.md` §5 (rules 5 and 7) and §20 (payment rule), `docs/PHASE2_…` to `docs/PHASE11_…`. Documented deliberate gaps: no production infrastructure adapter; customer VM provisioning disabled; Phase 3 is metadata-only; Phase 4–8 UAPI calls default off; cPanel staging runbook not executed. |
| Status | **Audit complete; code findings fixed and tested. Gate open pending two owner-held runs** (cPanel staging runbook, live WHMCS payment check) and your acceptance. Not closed by the agent. |

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
| A-8 | `install_requires_paid_order` (default `1`) is read by no code. Payment is enforced by price, not by this setting. The setting is misleading. | `grep install_requires_paid_order`: only `Settings.php` and tests | Low. **Not removed** (backward compatibility). Owner decision: delete or wire. Wiring it to disable payment would be a financial bypass, so the agent did not do so. |

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

## Module 4 — domainbroker — Not started

## Module 5 — dnschecker specification reconciliation — Not started

## Additional audit (after workstreams 1–5) — Not started

- `hostx_tools`, `customaffiliate`, `digitalproducts`, `hostx_email`, `phoneservices`, `smmaddon`
- `CloudHost247_tools`, `cloudhost247services`, `hostx`, announcement bar, `tools_center` against their specifications
