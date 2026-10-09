# Tools Center tests

Module 2 (tools_center). Run from the repository root:

```
node modules/addons/tools_center/tests/run-tests.js
```

Needs Node.js only. The decoding and static checks run with no install.

The UI wiring tests (8) also need jsdom, which is a test-only dependency kept out of the module:

```
cd modules/addons/tools_center/tests && npm install --no-save jsdom@24
```

If jsdom is missing, the runner prints `[SKIP] UI wiring tests` and does not count them.

## What is covered

- **QR decoding**: three real QR codes (`fixtures/qr-fixtures.json`, generated with the Python `qrcode` package) are rendered to pixels and decoded by the vendored jsQR. The text must match exactly. Also covered: inverted images, HTML-like payloads (returned as plain text), blank images, invalid input, and a missing decoder.
- **File checks**: type and size validation (PNG/JPG/GIF/WebP/BMP, 5 MB max).
- **Vendored library**: the jsQR file matches its recorded SHA-256 and ships its licence.
- **Hardening (static)**: the API token is not assigned to the template; the outbound call does not follow redirects and is HTTPS only; the decoder loads before the tools script; the QR tool takes a file, not a URL; both submit paths decode locally before any request.
- **UI wiring (jsdom)**: the real `tool.tpl` definitions and `tools-center.js` run in a DOM. The page path and the modal path both decode locally and make no XHR request. Decoded text is shown as text. Non-local tools still POST to the server.

## Not covered

- Reading a real file in a browser (`FileReader`/`Image`/canvas). jsdom has no canvas, so `decodeFile` is stubbed in the UI tests. Needs a manual browser check.
- The WHMCS server path (`hooks.php` curl call, `clientarea.php` access check) against a live install.
