# API reference

Endpoint: `/modules/addons/digitalproducts/api.php?endpoint=...`

Use `Authorization: Bearer <token>`; tokens are issued from the admin API Tokens screen and are shown once. Query-string credentials are rejected. Same-origin client-area calls may use the WHMCS session plus CSRF for POST requests.

- `GET products` — public catalogue metadata.
- `GET product/{slug}` — public product metadata.
- `GET versions/{productId}` — authenticated entitlement release metadata.
- `GET my-downloads` / `GET my/downloads` — the authenticated customer's entitlements.
- `GET my-licenses` / `GET my/licenses` — the authenticated customer's license metadata.
- `POST download-token` with `entitlement_id` and optional `version_id` — issue an expiring URL.
- `POST validate-license` with `license_key` and optional `domain` — generic valid/invalid result.
- `POST activate-license` with `license_key` and `domain` — rate-limited domain activation.

Responses use a JSON envelope with `status` and either `data` or `error`. License failures never reveal customer data or distinguish a guessed key beyond a generic invalid response.