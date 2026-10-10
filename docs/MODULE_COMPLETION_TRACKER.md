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



## Module 3 — cloudhost247apps — Not started

## Module 4 — domainbroker — Not started

## Module 5 — dnschecker specification reconciliation — Not started

## Additional audit (after workstreams 1–5) — Not started

- `hostx_tools`, `customaffiliate`, `digitalproducts`, `hostx_email`, `phoneservices`, `smmaddon`
- `CloudHost247_tools`, `cloudhost247services`, `hostx`, announcement bar, `tools_center` against their specifications
