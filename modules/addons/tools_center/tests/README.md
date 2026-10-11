# Tools Center tests

Module 2 (tools_center). Run from the repository root:

```
node modules/addons/tools_center/tests/run-tests.js
```

Needs Node.js only. The static checks run with no install.

The UI wiring tests (2) also need jsdom, which is a test-only dependency kept out of the module:

```
cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
```

If jsdom is missing, the runner prints `[SKIP] UI wiring tests` and does not count them. Expected results: 41/41 with jsdom, 31/31 without.

## What is covered

- **Hardening (static)**: the API token is not assigned to the template; the outbound call does not follow redirects and is HTTPS only; the API error banner escapes `data.error`; the API does not echo internal exception text.
- **UI wiring (jsdom, optional)**: the real `tool.tpl` definitions and `tools-center.js` run in a DOM. A server-backed tool still POSTs to the server, and the removed QR tools are gone from the definitions.

The QR Code Generator and QR Scanner were removed (F-3). Their tests, fixtures and vendored libraries (jsQR, qrcode-generator) went with them.

## Not covered

- Reading a real file in a browser (`FileReader`/`Image`/canvas). jsdom has no canvas, so `decodeFile` is stubbed in the UI tests. Needs a manual browser check.
- The WHMCS server path (`hooks.php` curl call, `clientarea.php` access check) against a live install.

## External API SSRF guard (T-6, T-7)

`external-api/outbound.php` is covered by `tests/outbound-guard.php` (60 offline checks: address deny-list, URL and scheme rules, redirect resolution, and guard-before-connect for fetches and sockets). Run it with:

```
node modules/addons/tools_center/tests/run-php-guard.mjs
```

It uses the php-wasm runtime that is already vendored under `cloudhost247services/node_modules`. Live DNS and network checks are not covered.

