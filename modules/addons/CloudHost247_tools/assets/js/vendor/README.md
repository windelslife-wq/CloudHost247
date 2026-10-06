# Vendored third-party libraries

These files are bundled, unmodified, with a thin CloudHost247 wrapper
appended. They are loaded **lazily** and only by the QR tools, so no other
tool page downloads them.

| File | Library | Version | Licence |
|---|---|---|---|
| `qr-encoder.js` | [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase | 2.0.4 | MIT |
| `qr-decoder.js` | [jsQR](https://github.com/cozmo/jsQR) by Cosmo Wolfe | 1.4.0 | Apache-2.0 |

Both run entirely in the browser. The QR scanner never uploads the selected
image to CloudHost247, and the QR generator never transmits the payload.

Full licence text for jsQR is in `LICENSE-jsQR.txt`. qrcode-generator's MIT
header is retained inside `qr-encoder.js`.
