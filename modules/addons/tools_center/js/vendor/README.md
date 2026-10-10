# Vendored third-party JavaScript

All files here are free software used without network calls. The page loads them itself;
nothing is fetched from a CDN at runtime.

## jsQR 1.4.0 (`jsQR-1.4.0.js`)

- Purpose: decode QR codes from pixel data in the browser (QR Scanner tool).
- Source: npm package `jsqr@1.4.0`, file `dist/jsQR.js` (`npm pack jsqr@1.4.0`).
- Licence: Apache-2.0 (`jsQR-1.4.0.LICENSE`, copied from the package).
- SHA-256 of `jsQR-1.4.0.js`: `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859`

## qrcode-generator 2.0.4 (`qrcode-generator-2.0.4.js`, `qrcode-generator-2.0.4-utf8.js`)

- Purpose: generate QR codes in the browser (QR Generator tool). Replaces the earlier call to api.qrserver.com, which sent the user's data to a third party.
- Source: npm package `qrcode-generator@2.0.4`, files `dist/qrcode.js` and `dist/qrcode_UTF8.js` (`npm pack qrcode-generator@2.0.4`). The UTF-8 file must load after the main file.
- Licence: MIT (`qrcode-generator-2.0.4.LICENSE`).
- SHA-256 of `qrcode-generator-2.0.4.js`: `79ec86f82856005b1c887905cfccfcfbec3821ca61c7fd5a952faa5f778f791c`
- SHA-256 of `qrcode-generator-2.0.4-utf8.js`: `e522d64003b332e29271fdce4993ed3ae2934c8947f41654bd324ddcfa2de301`

To upgrade a library: fetch the new version, replace the file, update the checksum here and in
`tests/run-tests.js`, and rerun the tests.
