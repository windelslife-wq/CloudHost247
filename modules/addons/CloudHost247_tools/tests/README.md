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
php modules/addons/CloudHost247_tools/tests/OcrTest.php
php modules/addons/CloudHost247_tools/tests/FrontTest.php
php modules/addons/CloudHost247_tools/tests/RunnerLoggingTest.php
php modules/addons/CloudHost247_tools/tests/WhoisFallbackTest.php
php modules/addons/CloudHost247_tools/tests/PingTest.php
php modules/addons/CloudHost247_tools/tests/TracerouteTest.php
php modules/addons/CloudHost247_tools/tests/Ipv6CompatibilityTest.php
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
| `SecurityTest.php` | 118 | SSRF blocklist (IPv4 + IPv6 CIDR maths), internal-hostname and local/special-use DNS namespace rejection, URL scheme/port/credential rejection, hostname normalisation incl. IDN and punycode TLDs, strict port parsing, public socket-target pinning, explicit IPv4/IPv6 selection, port allowlist, output escaping |
| `RateLimiterTest.php` | 17 | Per-tool and per-IP buckets, heavy/server/client tiers, global ceiling, bucket isolation between IPs, CSRF token issue/validate/reject |
| `CatalogTest.php` | 57 | Registry integrity: 91 tools, 9 categories and their exact counts, unique ids/slugs/routes/handlers, route format, SEO field lengths, related-slug resolution, no "coming soon" placeholders, sensitive tools pinned client-side |
| `Ipv6Test.php` | 44 | Range-to-CIDR minimal cover (alignment, contiguity, exact coverage, `::/0`), address expansion, `ip6.arpa` pointers, IPv4 extraction for mapped / 6to4 / NAT64 / Teredo / compatible forms, bit-level helpers |
| `Ipv6CompatibilityTest.php` | 12 | IPv6-only HTTPS probes confirm the connected family; unavailable/mismatched transport and partial AAAA observations are fail-closed and accurately described; local-use NS/MX targets are not recursively queried |
| `RouterTest.php` | 84 | All 91 tool routes plus 9 category routes resolve, category/slug alias 301s, API endpoint parsing, fuzzy 404 suggestions, breadcrumb depth, 91 unique SEO titles, canonical and robots values, JSON-LD shape with no fabricated ratings, sitemap excluding unconfigured/search/API routes, route-split asset lists |
| `ClientIpTest.php` | 6 | H-1 regression: the legacy client-IP helper ignores spoofed `X-Forwarded-For` / `CF-Connecting-IP` unless `REMOTE_ADDR` is listed in `CLOUDHOST247_TRUSTED_PROXIES` |
| `HandlerTest.php` | 146 | Every one of the 91 catalog handlers resolves to a callable function; outbound-network regression guards cover SSRF, diagnostic-port allowlists, raw-shell deprecation, and WHOIS input safety; malformed domain characters are preserved for rejection instead of being stripped into a different lookup; DNS tools, email checks, and the shared DNS helper reject local/special-use suffixes before resolver access; private PTR lookup is refused and a loopback client IP is not reverse-queried; domain WHOIS classification returns only explicit record/no-match/unconfirmed states, while PageRank and social-user checks emit no fabricated scores or availability; BIN lookup rejects full card numbers and labels provider/fallback provenance; MAC lookup labels its bundled sample as partial/unversioned and makes no vendor claim for locally administered addresses; the IPv4-only DNSBL checker rejects IPv6/private/reserved inputs before lookup; selected handlers are tested for text/binary in four bases with UTF-8 round trips, email syntax paths, three runic alphabets with digraph precedence, invisible-character generate/detect/clean, Wi-Fi QR build/parse with escaping round trips, and speed-test clamping |
| `LegacyAjaxTest.php` | 62 | The live `action=ajax` endpoint delegates to `CloudHost247ToolsRunner`; all 46 `exec=client` tools are refused server-side by slug **and** by the legacy handler id the bundle posts; the refusal states the privacy guarantee; every client tool ships a browser module; `Runner::redact()` masks password/cvv/card/token input and drops `csrf_token`; `respondLegacy()` preserves the `{success,data}` / `{success,message}` contract and never emits internals |
| `OcrTest.php` | 69 | Image-to-Text behaviour, offline via a stubbed OCR transport and injected test settings: the opt-in allowlist, multipart and base64 intake, byte validation (type/size/dimensions), key configuration, the success payload shape, and every mapped provider/transport failure |
| `FrontTest.php` | 88 | The `/tools/*` entry point: pure dispatch for every route type, page shells with CSRF/module wiring, the POST-only JSON API with all failure envelopes, enablement, site-URL derivation, `jsonResponse` parity with `Runner::respond`, live execution of both entry files, and static pins on the rewrite rule, the api alias and on-disk existence of every emitted asset URL |
| `RunnerLoggingTest.php` | 14 | Runner logging writes to the installed plural table with matching columns, honours `enable_logs`, redacts passwords, email, pasted headers and free-form text, omits CSRF tokens, records client metadata, and does not retain tool results |
| `WhoisFallbackTest.php` | 53 | Deterministic mocked RDAP and WHOIS transport paths: matching/mismatched RDAP objects, 404 and network failures, WHOIS record/no-match/truncated/foreign responses, no-registration caveats, generic errors, and no network or database use |
| `PingTest.php` | 36 | ICMP summary parsing for IPv4/IPv6 and loss cases, packet-detail validation, sub-millisecond reporting, bounded allowlisted command arguments, public-IP pinning, and honest handling of missing or unparseable command output; mocked command transport only |
| `TracerouteTest.php` | 22 | Numbered UDP traceroute output parsing, timeouts and sub-millisecond reporting, private/reserved intermediate-hop redaction, bounded allowlisted command arguments, public-target pinning, and honest missing/unparseable output handling; mocked command transport only |

## Browser-module tests

| File | Latest assertions | What it exercises |
|---|---:|---|
| `core.test.mjs` | 68 | Shared browser-tool helpers, escaping, field handling, and common rendering behaviour. |
| `qr.test.mjs` | 36 | QR payload building/parsing, SVG/PNG download plumbing, safe URL normalization, and Wi-Fi payload round trips. |
| `tools.test.mjs` | 867 | All 91 route-split modules load and register; 46 client `run()` functions execute representative input and 45 server `render()` functions are exercised. These are deterministic DOM-shim tests, not full browser/viewport or live-provider validation. |

## Latest verification

On 2026-10-12, `node modules/addons/CloudHost247_tools/tests/run.mjs` completed against PHP 8.3 via `php-wasm`: **923 passed, 0 failed** across 16 PHP test files. The latest run includes public/local DNS namespace rejection, no recursive lookup of DNS-derived local NS/MX names, local-email DNS suppression, public-only PTR/DNSBL lookups, and the earlier IPv4-only DNSBL guards. The run used the repository's existing `cloudhost247services/node_modules` through a temporary sibling symlink; the symlink was removed immediately afterward. On the same date, `core.test.mjs` passed **68/68**, `qr.test.mjs` **36/36**, and `tools.test.mjs` **867/867**. These are offline/mocked or local-DOM-shim results, not a live WHMCS, provider, desktop/tablet/mobile, or browser-console test.

## Regenerating the catalog fixture

`tools.test.mjs` needs the tool registry as JSON at `/tmp/tools.json`. It is
generated on demand, but you can build it explicitly:

```bash
node modules/addons/CloudHost247_tools/tests/dump-catalog.mjs /tmp/tools.json
```
