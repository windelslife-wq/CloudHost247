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
| `HandlerTest.php` | 72 | Every one of the 91 catalog handlers resolves to a callable function, plus behaviour of the newly written handlers: text/binary in four bases with UTF-8 round trips, email syntax paths, three runic alphabets with digraph precedence, invisible-character generate/detect/clean, Wi-Fi QR build/parse with escaping round trips, speed-test clamping |
