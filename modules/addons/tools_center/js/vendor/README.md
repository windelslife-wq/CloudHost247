# Vendored third-party JavaScript

## jsQR 1.4.0 (`jsQR-1.4.0.js`)

- Purpose: decode QR codes from pixel data in the browser (Tools Center, QR Scanner tool).
- Source: npm package `jsqr@1.4.0`, file `dist/jsQR.js`. Fetched with `npm pack jsqr@1.4.0`.
- Licence: Apache-2.0 (`jsQR-1.4.0.LICENSE`, copied from the package).
- SHA-256 of `jsQR-1.4.0.js`: `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859`
- Cost: free, no runtime dependencies, no network calls. The image never leaves the browser.

To upgrade: fetch the new version, replace the file, update the checksum here and in
`tests/run-tests.js`, and rerun the tests.
