# Domain Broker — tests

The sandbox CloudHost247 is developed in has no native `php` binary, no
Composer and no PHPUnit, so the suite runs against a real PHP 8.3 runtime
compiled to WebAssembly. Everything here also works unchanged against a native
binary if you have one.

## Running

```bash
cd modules/addons/domainbroker

# one-time: make @php-wasm/node resolvable from this directory
npm install @php-wasm/node        # or symlink an existing node_modules here

node tests/run.mjs                # every suite
node tests/run.mjs Payment        # only files whose name matches
node tests/lint.mjs               # load/syntax gate over every shipped file
```

With a native binary each suite is a plain script:

```bash
php tests/01_CoreTest.php
php tests/lint.php
```

## What is here

| File | Purpose |
| --- | --- |
| `bootstrap.php` | `Harness` (in-memory SQLite, real migrations, fake gateway, temp storage) and the `T::` assertions |
| `0*_*Test.php` | The nine suites — core, requests, negotiation, payment, transfer, security, fees/reports, API, HTTP |
| `run.mjs` | Runs every `*Test.php` and prints a combined `TOTAL PASS/FAIL` |
| `lint.php` / `lint.mjs` | Includes every library file (proving it parses and its parents resolve) and syntax-checks the WHMCS entry points |

## Writing a new suite

```php
require_once __DIR__ . '/bootstrap.php';

$gateway = Harness::boot();
Harness::relaxRateLimits();   // required in every suite except 01

section('What this group proves');
T::is('a label', $expected, $actual);

Harness::shutdown();
T::summary();
```

`Harness::relaxRateLimits()` matters: the production rate limits are real, and
a suite that drives many writes will otherwise trip them and abort mid-file.

Useful fixtures: `Harness::client()`, `broker()`, `brokerActor()`, `admin()`,
`acceptedRequest()`, `securedRequest()`. Test seams on the production classes
(`Clock::freeze`, `Http::overrideIp`, `Settings::overrideMany`,
`Identity::override`, `Gateway::set`, `EscrowManager::force`,
`Controller::$testMode`, …) are listed in each class's docblock.
