# Smtphosting module tests

Dependency-free tests for the Smtphosting WHMCS server module. They run without
WHMCS, a database, or PHPUnit.

## What is covered

`run.php` contains 39 tests: 15 general tests, plus `usage_proxy_tests.php` (24 tests for the authenticated usage/log proxy, S-6).

| Area | Tests |
|---|---|
| `AppControllers\Api`, `Cron`, `Hooks` (previously unimplemented stubs) | Extend the abstract `AppController` so `runController()` exists; `runController()` and `getControllerInstanceClass()` fail closed with an explicit `RuntimeException`, never a silent `null` or a fatal `Error` |
| `Instances\Api\ApiController` (previously fatal on load) | Loads, implements `DefaultController`, `execute()` and `runExecuteProcess()` fail closed |
| Regression: `AppControllers\Addon` | Still an `AppController` with `runController()` |
| Regression: `Application::getControllerClass()` | `api` → `Api`; `output` / `clientarea` → `Http`; everything else → `Addon` |
| Regression: source typo guard | No fully-qualified global `catch (\Name $e)` in module source may name a non-existent class (this catches `\Excpetion`, fixed in `Instances/Addon/ConfigOptions.php`) |

Before the fixes, 13 of the 15 tests failed on the unmodified code. Each failure
matched a real defect. After the fixes, all 15 pass.

## How to run

### With a local PHP (7.2+)

```sh
php modules/servers/Smtphosting/tests/run.php
```

Exit code 0 means all tests passed.

### Without a local PHP (php-wasm)

This is how the results below were produced in a sandbox with no PHP binary:

```sh
mkdir -p /tmp/php-wasm-runner && cd /tmp/php-wasm-runner
npm init -y >/dev/null && npm install @php-wasm/node @php-wasm/universal
node <repo>/modules/servers/Smtphosting/tests/run-php-wasm.mjs 8.3 <repo>/modules/servers/Smtphosting
node <repo>/modules/servers/Smtphosting/tests/run-php-wasm.mjs 7.4 <repo>/modules/servers/Smtphosting
```

The runner copies the module (excluding `vendor/`) into the php-wasm virtual
file system, runs `tests/run.php`, and runs a whole-module syntax lint
(`token_get_all(..., TOKEN_PARSE)` over every non-vendor `.php` file).

## Results (executed)

| Runtime | Suite | Syntax lint |
|---|---|---|
| PHP 8.3.33 (php-wasm) | 39/39 passed | 643 files, 0 syntax errors |
| PHP 7.4.33 (php-wasm) | 39/39 passed | 643 files, 0 syntax errors |
| Unmodified code, PHP 8.3 (before fixes) | 2/15 passed, 13 failed | 637 files, 0 errors |

## Not covered (limitations)

- **No WHMCS integration tests.** Behaviour that needs WHMCS (`Capsule`, hooks
  running inside WHMCS, `ConfigOptions` AJAX flow, `Synchronize` admin action,
  `ClientAreaPrimarySidebar`) is not exercised. The `ConfigOptions` typo fix is
  verified by the source-level regression test, not by a behavioural test.
- **`smtp-api.php` is not tested.** It is a public proxy with a shared-secret
  design that is currently blocked (see the completion tracker).
- **Other PHP versions** (7.2, 7.3, 8.0–8.2, 8.4) were not executed.
- **Retry and idempotency** tests do not apply: the module has no scheduled jobs
  and no API routes in this build.
