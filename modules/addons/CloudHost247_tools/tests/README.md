# CloudHost247 Tools — test suite

Plain-PHP assertion tests (no Composer / PHPUnit dependency, so they run
anywhere the WHMCS install runs).

## Running

With a native PHP binary:

```bash
php modules/addons/CloudHost247_tools/tests/SecurityTest.php
php modules/addons/CloudHost247_tools/tests/RateLimiterTest.php
php modules/addons/CloudHost247_tools/tests/CatalogTest.php
php modules/addons/CloudHost247_tools/tests/Ipv6Test.php
php modules/addons/CloudHost247_tools/tests/HandlerTest.php
php modules/addons/CloudHost247_tools/tests/ClientIpTest.php
php modules/addons/CloudHost247_tools/tests/LegacyAjaxTest.php
```

Or run the whole suite through the bundled runner, which uses a real
PHP 8.3 runtime via `php-wasm` and therefore also works on machines with no
PHP installed:

```bash
npm i @php-wasm/node
node modules/addons/CloudHost247_tools/tests/run.mjs           # all
node modules/addons/CloudHost247_tools/tests/run.mjs Security  # one
```

Exit code is non-zero if any assertion fails, so the suite is CI-ready.

## Coverage

| File | Assertions | What it proves |
|---|---|---|
| `SecurityTest.php` | 95 | SSRF blocklist (IPv4 + IPv6 CIDR maths), internal-hostname rejection, URL scheme/port/credential rejection, hostname normalisation incl. IDN, port allowlist, output escaping |
| `RateLimiterTest.php` | 17 | Per-tool and per-IP buckets, heavy/server/client tiers, global ceiling, bucket isolation between IPs, CSRF token issue/validate/reject |
| `CatalogTest.php` | 57 | Registry integrity: 91 tools, 9 categories and their exact counts, unique ids/slugs/routes/handlers, route format, SEO field lengths, related-slug resolution, no "coming soon" placeholders, sensitive tools pinned client-side |
| `Ipv6Test.php` | 44 | Range-to-CIDR minimal cover (alignment, contiguity, exact coverage, `::/0`), address expansion, `ip6.arpa` pointers, IPv4 extraction for mapped / 6to4 / NAT64 / Teredo / compatible forms, bit-level helpers |
| `RouterTest.php` | 84 | All 91 tool routes plus 9 category routes resolve, category/slug alias 301s, API endpoint parsing, fuzzy 404 suggestions, breadcrumb depth, 91 unique SEO titles, canonical and robots values, JSON-LD shape with no fabricated ratings, sitemap excluding unconfigured/search/API routes, route-split asset lists |
| `ClientIpTest.php` | 6 | H-1 regression: the legacy client-IP helper ignores spoofed `X-Forwarded-For` / `CF-Connecting-IP` unless `REMOTE_ADDR` is listed in `CLOUDHOST247_TRUSTED_PROXIES` |
| `HandlerTest.php` | 72 | Every one of the 91 catalog handlers resolves to a callable function, plus behaviour of the newly written handlers: text/binary in four bases with UTF-8 round trips, email syntax paths, three runic alphabets with digraph precedence, invisible-character generate/detect/clean, Wi-Fi QR build/parse with escaping round trips, speed-test clamping |
| `LegacyAjaxTest.php` | 61 | The live `action=ajax` endpoint delegates to `CloudHost247ToolsRunner`; all 46 `exec=client` tools are refused server-side by slug **and** by the legacy handler id the bundle posts; the refusal states the privacy guarantee; every client tool ships a browser module; `Runner::redact()` masks password/cvv/card/token input and drops `csrf_token`; `respondLegacy()` preserves the `{success,data}` / `{success,message}` contract and never emits internals |

## Regenerating the catalog fixture

`tools.test.mjs` needs the tool registry as JSON at `/tmp/tools.json`. It is
generated on demand, but you can build it explicitly:

```bash
node modules/addons/CloudHost247_tools/tests/dump-catalog.mjs /tmp/tools.json
```
