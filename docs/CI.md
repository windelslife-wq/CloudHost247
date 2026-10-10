# CloudHost247 CI

Closes gap **E-1** (`docs/UNFINISHED_MODULES.md`): every offline suite in the
repo now runs on every push and pull request via
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

## What runs

| Job | Runtime | Covers |
|---|---|---|
| PHP suites (8.3) | PHP 8.3 + extensions (`pdo_sqlite`, `mbstring`, `curl`, `openssl`, `intl`, `bcmath`, `simplexml`, `zip`) | All 17 PHP suites natively, one step per module, plus the 9 load/parse gates and the root-landing existence check (`ci/run-php.sh`) |
| PHP suites (7.4) | PHP 7.4 | Only the modules with documented 7.4 support: `CloudHost247_tools`, `digitalproducts`, `Smtphosting` (`ci/run-php.sh legacy74`) |
| JS suites (Node 22) | Node 22 + PHP 8.3 (catalog dump only) + jsdom 24 | `core` / `qr` / `tools` suites, `tools_center` incl. UI wiring, `node --check` on the shipped bundles (`ci/run-js.sh`) |
| PHP syntax sweep | PHP 8.3 | `php -l` over every shipped PHP file except the two ionCube-encoded modules (`hostx`, `xtreme_currency_rates` — owner decision D-8) and `vendor/` trees (`ci/php-lint.sh`) |
| Python static checks | Python 3 | `cloudhost247_cart_recovery` static invariants |

A suite fails the build when its process exits non-zero **or** its output
reports a non-zero `FAIL=` / `FAILURES=` / `BAD=` counter
(`ci/run-php.sh` enforces both, so a suite that forgets `exit(1)` still
fails CI).

## Why native PHP instead of php-wasm

The committed `tests/run.mjs` wrappers execute the suites inside php-wasm for
developers without a local PHP binary. CI has real PHP, so it runs the same
`tests/*.php` files directly: faster, more faithful (real SQLite, real
OpenSSL, real ZIP), and with no npm dependency. The wasm wrappers keep
working unchanged — every suite file resolves sibling/repo paths with an
absolute-mount-first, relative-path fallback.

## Local runs

```bash
# Everything (needs PHP 8.x with pdo_sqlite; passkey also needs its vendor/):
composer install --working-dir modules/addons/cloudhost247passkey
ci/run-php.sh all

# One module / legacy 7.4 set / gates only:
ci/run-php.sh apps
ci/run-php.sh legacy74
ci/run-php.sh lint

# JS suites (optional jsdom enables the tools_center UI wiring tests):
npm install --prefix /tmp/tc-jsdom --no-save jsdom@24
NODE_PATH=/tmp/tc-jsdom/node_modules ci/run-js.sh

# Syntax sweep / python checks:
ci/php-lint.sh
python3 modules/addons/cloudhost247_cart_recovery/tests/test_static.py
```

`PHP_BIN` overrides the PHP binary (`PHP_BIN=php8.3 ci/run-php.sh all`).

## Test-only changes shipped with E-1

No production code was touched. To make the suites runnable under both
runtimes and fail loudly:

- **Path fallbacks** (absolute wasm mount first, checkout-relative second):
  `cloudhost247apps` tests 10/11/12/13/17 + `lint.php`,
  `cloudhost247services` test 21.
- **Fixed dead fallback**: `cloudhost247services` tests 11/12 referenced
  `dirname(__DIR__, 3)` (the `modules/` dir) instead of the repo root.
  Now `CHS_ROOT`. The old path only worked via the `/repo` mount, so the
  bug was invisible until native runs.
- **Exit codes**: 13 suite files that printed `FAIL=` but always exited 0
  now `exit($fail ? 1 : 0)` (8× `CloudHost247_tools`, `phoneservices`,
  `smmaddon`, `hostx_email`, `cloudhost247cloudflare` 01 + `lint.php`).
- **Flake fix**: `cloudhost247apps` test 25 froze `Clock` around the
  retention section — the cutoff-edge fixture is exactly 30 days old, so a
  second boundary crossed mid-test pushed it over the edge (observed 34/3
  once in pre-push validation).

## Not covered (unchanged)

- Live WHMCS, browser, provider-API, and cPanel staging runs (gap E-2).
- `soyoustart`, `RDP`, `cloudhost247_lteproxy`, `smmprovisioning`,
  `soyoustart_vps`, `blockonomics` gateway: no suites exist yet (group C).
- `hostx`, `xtreme_currency_rates`: ionCube-encoded, unauditable (D-8).
