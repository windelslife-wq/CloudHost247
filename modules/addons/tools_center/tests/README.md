# Tools Center tests

Module 2 (tools_center). Run from the repository root:

```
node modules/addons/tools_center/tests/run-tests.js
```

Needs Node.js only. The decoding and static checks run with no install.

The UI wiring tests (10) also need jsdom, which is a test-only dependency kept out of the module:

```
cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
```

If jsdom is missing, the runner prints `[SKIP] UI wiring tests` and does not count them. Expected results: 41/41 with jsdom, 31/31 without.

## What is covered

- **QR generation**: codes generated for ECC L, M, Q and H (UTF-8 and a 604-character payload) are decoded back to the same text by the independent jsQR decoder. Also covered: SVG safety, size clamping, the 2000-character limit, and the explicit error for data that does not fit.
- **QR decoding**: three real QR codes (`fixtures/qr-fixtures.json`, generated with the Python `qrcode` package) are rendered to pixels and decoded by the vendored jsQR. The text must match exactly. Also covered: inverted images, HTML-like payloads (returned as plain text), blank images, invalid input, and a missing decoder.
- **File checks**: type and size validation (PNG/JPG/GIF/WebP/BMP, 5 MB max).
- **Vendored library**: the jsQR file matches its recorded SHA-256 and ships its licence.
- **Hardening (static)**: the API token is not assigned to the template; the outbound call does not follow redirects and is HTTPS only; the decoder loads before the tools script; the QR tool takes a file, not a URL; both submit paths decode locally before any request.
- **UI wiring (jsdom)**: the real `tool.tpl` definitions and `tools-center.js` run in a DOM. QR decoding (page and modal paths) and QR generation make no XHR request. Decoded text is shown as text. Non-local tools still POST to the server.

## Not covered

- Reading a real file in a browser (`FileReader`/`Image`/canvas). jsdom has no canvas, so `decodeFile` is stubbed in the UI tests. Needs a manual browser check.
- The WHMCS server path (`hooks.php` curl call, `clientarea.php` access check) against a live install.
