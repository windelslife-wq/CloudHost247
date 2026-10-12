# Tools Center tests

Module 2 (tools_center). Run from the repository root:

```
node modules/addons/tools_center/tests/run-tests.js
```

Needs Node.js only. The static checks run with no install.

The UI wiring tests (3) also need jsdom, which is a test-only dependency kept out of the module:

```
cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
```

If jsdom is missing, the runner prints `[SKIP] UI wiring tests` and does not count them. Expected results: 12/12 with jsdom, 9/9 without.

## What is covered

- **Hardening (static)**: the 81 registered category/action pairs each map to a corresponding public API method; the API token is not assigned to the template; the outbound call does not follow redirects and is HTTPS only; IP command, SSL socket, WHOIS, and geolocation paths use the guarded outbound helpers; DNS propagation bounds/allowlists its `dig` call; the SSL checker no longer reports an invented grade/score; the API error banner escapes `data.error`; exception messages stay server-side; CORS is exact-origin allowlisted; only POST is enabled; query/cookie credentials are rejected; the WHMCS AJAX path checks a session CSRF token before proxying.
- **UI wiring (jsdom, optional)**: the real `tool.tpl` definitions and `tools-center.js` run in a DOM. Server-backed tools still POST, both page and modal requests send the session CSRF header, the API credential is not added to form requests, and removed QR tools stay out of the definitions.

The QR Code Generator and QR Scanner were removed (F-3). Their tests, fixtures and vendored libraries (jsQR, qrcode-generator) went with them.

## Not covered

- Reading a real file in a browser (`FileReader`/`Image`/canvas). jsdom has no canvas, so `decodeFile` is stubbed in the UI tests. Needs a manual browser check.
- The WHMCS server path (`hooks.php` curl call, `clientarea.php` access check) against a live install.

## API auth, CSRF and rate-limit unit tests

Run the PHP-WASM suite for header/body-only POST authentication, query/cookie rejection, fail-closed placeholder credentials, HMAC freshness and single-use nonce behavior, WHMCS CSRF comparison, persistent rate-limit blocks, independent client buckets, corrupt-store failure behavior, non-instantiating cache reads, username-format boundaries, PageRank's no-score response, rejected client-area route parameters, and PHP syntax parsing:

```
node modules/addons/tools_center/tests/run-php-security.mjs
```

These are deterministic local tests; they do not exercise a live WHMCS session, web-server CORS behavior, or concurrent PHP-FPM workers.

## Host-directed IP, SSL, WHOIS and DNS propagation regression tests

```
node modules/addons/tools_center/tests/run-php-tool-outbound.mjs
```

The PHP-WASM test uses synthetic DNS record fixtures, protected resolver seams, a mocked command executor, mocked provider JSON, mocked WHOIS responses, and a mocked TLS transport. It checks private/link-local rejection before command execution, pinned IPv4/IPv6 command targets, count/timeout/hop bounds, non-public geolocation privacy, disabled provider redirects, IPv4/IPv6/ASN WHOIS dispatch, SSL-checker host/port/SNI behavior, DNS propagation type/resolver allowlists, special-use/local DNS suffix rejection, public-only PTR/DNSBL/ASN lookups, and refusal to follow CNAME/MX/NS answers into local resolver namespaces. **No shell command, live DNS lookup, provider request, socket, or TLS handshake is made.** The shared resolver/socket/fetch guards themselves are separately tested by `run-php-guard.mjs`.

## Latest local verification (2026-10-12)

- `run-tests.js` with jsdom 24: **12/12** (9 static hardening checks, including the 81-action handler crosswalk and guarded outbound-transport checks, + 3 UI wiring); without jsdom: **9/9**.
- `run-php-security.mjs`: auth/CSRF/rate limit/input checks **37/37**, domain search **20/20**, client-area routes **4/4**.
- `run-php-tool-outbound.mjs`: host-directed IP, SSL, geolocation, WHOIS, DNS namespace, and DNS propagation regression tests **28/28**; external commands/provider/socket calls are mocked.
- `run-php-guard.mjs`: outbound SSRF/redirect/socket/TLS and DNS-name guard checks **89/89**.

PHP-WASM 8.3 and jsdom were used from temporary/test-only locations; no production dependencies were added. Native `php -l`, live WHMCS/browser checks, concurrent-worker checks and provider calls were not performed.

## External API SSRF guard (T-6, T-7)

`external-api/outbound.php` is covered by `tests/outbound-guard.php` (89 offline checks: address deny-list, public DNS-name validation including special-use/local suffixes, URL and scheme rules, redirect resolution, and guard-before-connect for HTTP, TCP, and TLS transports). Run it with:

```
node modules/addons/tools_center/tests/run-php-guard.mjs
```

It uses the php-wasm runtime that is already vendored under `cloudhost247services/node_modules`. Live DNS and network checks are not covered.

