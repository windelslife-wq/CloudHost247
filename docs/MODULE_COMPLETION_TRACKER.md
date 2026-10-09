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
| Status | **Blocked** (two owner decisions required, see Remaining issues). Stub-level defects are fixed and verified. |

### Findings (verified by executing the code, not only by reading it)

| ID | Finding | Evidence | Severity |
|---|---|---|---|
| S-1 | `AppControllers/Api.php`, `Cron.php`, `Hooks.php` do not extend the abstract `AppController`. `runController()` is undefined, so `Application::run()` would raise an uncaught `Error`. `getControllerInstanceClass()` silently returned `null`. | Baseline run: `Call to undefined method ...::runController()` | High (latent) |
| S-2 | `Instances/Api/ApiController.php` is a fatal error on load: `DefaultController` is not imported, and `runExecuteProcess()` is missing. | Baseline run: `Interface ...\Instances\Api\DefaultController not found` | High (latent) |
| S-3 | Nothing in the module reaches `Api`, `Cron`, or `Hooks` except `Application` mapping the `api` caller. No WHMCS function named `Smtphosting_api` exists. The cron queue framework (`Core/CommandLine`) has no registered jobs. | Repository search; `Smtphosting.php` lists all WHMCS functions | Info |
| S-4 | No scheduled jobs exist. `App/UI/Admin/ProductConfig/Pages/CronInfo.php` tells the admin to run `<module>/cron/cron.php queue`, but that file does not exist, and `CronInfo` is never instantiated. | `find` for `cron.php`; `grep` for `CronInfo` | Medium (misleading UI, dead) |
| S-5 | `Instances/Addon/ConfigOptions.php` had `catch (\Excpetion $exc)`. The misspelled class never matches, so installer exceptions escaped the JSON error path. | Baseline regression test failure | Medium (real defect, fixed) |
| S-6 | **CRITICAL: shared secrets are exposed to every customer.** `smtp-api.php` hardcodes two upstream secrets. `templates/assets/tpl/DefaultSubmodule/clientarea.tpl` renders them into client-area JavaScript (`xhr.open('GET', ...secret=...&user_name={$username}&main_domain={$domain})`). The endpoint trusts `user_name` and `main_domain` from the request, so any customer can read another customer's mail usage and logs if they know that customer's username and domain. The secrets are also in git history. Secret comparison uses `!==` (not constant-time). `CURLOPT_FOLLOWLOCATION` is on, so the secret could be forwarded on redirect. | `grep` results above; `smtp-api.php` lines 50 and 54 (secrets), 65 (`!==` comparison), 90 (`CURLOPT_FOLLOWLOCATION`); `clientarea.tpl` lines 422 and 493 | **Critical** |
| S-7 | `Synchronize` (admin button) swallows exceptions and returns a generic error without logging. | `App/Http/Actions/Synchronize.php` | Low |
| S-8 | `App/UI` `ClientAreaPrimarySidebar` hook dereferences `Hosting::find(...)->packageid` without a null check. This emits a PHP warning for an unknown service ID but is not fatal. | Code review only (not executed against WHMCS) | Low |
| S-9 | `App/Hooks/AdminProductConfigFieldsSave.php` catches all exceptions and does nothing ("do nothing on save"), so save failures are silent. | Code review only | Low |

### Changes made

| File | Change |
|---|---|
| `Core/App/Controllers/AppControllers/Api.php` | Extends abstract `AppController`. `getControllerInstanceClass()` throws `RuntimeException` ("not implemented in this build"). |
| `Core/App/Controllers/AppControllers/Cron.php` | Same pattern. States that no scheduled jobs are registered. |
| `Core/App/Controllers/AppControllers/Hooks.php` | Same pattern. Points to `App/Hooks/*.php` as the real hook mechanism. |
| `Core/App/Controllers/Instances/Api/ApiController.php` | Imports `DefaultController`. Implements `execute()` and `runExecuteProcess()`, both throwing `RuntimeException`. |
| `Core/App/Controllers/Instances/Addon/ConfigOptions.php` | `\Excpetion` → `\Exception` (one token). |
| `tests/` (new) | `run.php` (15 tests), `bootstrap.php`, `run-php-wasm.mjs`, `README.md`. |
| `docs/MODULE_COMPLETION_TRACKER.md` (new) | This tracker. |

No behaviour was invented. Nothing that previously worked changed. The routing
and Addon controller paths are covered by regression tests.

### Tests

| Command | Result |
|---|---|
| `run-php-wasm.mjs 8.3 <module>` (tests + lint) | Suite 15/15 passed; lint 639 files, 0 errors |
| `run-php-wasm.mjs 7.4 <module>` (tests + lint) | Suite 15/15 passed; lint 639 files, 0 errors |
| Same suite on unmodified code (8.3) | 2/15 passed; 13 failed, each matching S-1, S-2, or S-5 |
| Local `php tests/run.php` | **Not run** (no local PHP binary) |

### Acceptance criteria against the directive

| Criterion | Status |
|---|---|
| Implement missing functionality per specification | **Not met, by design.** No specification exists. Behaviour is not invented. Owner decision D-2. |
| Auth, authorization, input validation, error handling, API responses | **Not met for the public proxy (S-6).** The four stubs fail closed, but there is no API to validate. |
| Cron and hooks safe to repeat | **Not applicable to cron** (no jobs, S-3/S-4). Hooks not exercised against WHMCS. |
| Tests for success, failure, permission denial, invalid input, retry | Failure and regression paths covered. Success, permission denial, and retry **not applicable** without functionality. |
| Documentation | Done for tests and this tracker. |

### Remaining issues / blockers

1. **D-1 (critical, owner decision): fix S-6 before anything else.** Options I recommend:
   - (a) Replace the browser-side call with a WHMCS-authenticated server-side endpoint. It derives `user_name` and `main_domain` from the logged-in client's own service (`tblhosting`) and checks ownership server-side. The secrets never reach the browser.
   - (b) Move the two secrets to WHMCS configuration (not the source tree), add constant-time comparison, and disable redirect following.
   - Whichever option is chosen, **rotate both secrets**, because they are already in git history and in every customer's page source.
   - I have not changed this code, because it needs the upstream provider's rotated credentials and a decision on the client-area flow.
2. **D-2 (owner decision): what to do with the stub API/cron/hook classes.**
   - Keep them as fail-closed placeholders (current state), or
   - delete them and their references, or
   - write a specification and implement a real API, which would be new scope.
3. S-4: remove or fix the `CronInfo` instruction, which points to a missing script (cosmetic and dead today).
4. S-7 to S-9: low-severity hardening, not started.
5. WHMCS-level integration tests are not possible in this sandbox. They need a WHMCS install.

### Completion evidence

- Source: the five files in *Changes made*.
- Executed suite: `modules/servers/Smtphosting/tests/run.php`, 15/15 on PHP 7.4 and 8.3 (php-wasm).
- Lint: 639 non-vendor PHP files, 0 syntax errors on PHP 7.4 and 8.3.

---

## Module 2 — tools_center — Not started
Blocked by the sequencing rule: Module 1 must be closed or agreed first.

## Module 3 — cloudhost247apps — Not started

## Module 4 — domainbroker — Not started

## Module 5 — dnschecker specification reconciliation — Not started

## Additional audit (after workstreams 1–5) — Not started

- `hostx_tools`, `customaffiliate`, `digitalproducts`, `hostx_email`, `phoneservices`, `smmaddon`
- `CloudHost247_tools`, `cloudhost247services`, `hostx`, announcement bar, `tools_center` against their specifications
