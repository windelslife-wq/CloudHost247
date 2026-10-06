# CloudHost247 Digital Products

The existing `modules/addons/digitalproducts/` addon is the single digital-product
implementation for this repository. It links WHMCS products to private releases,
grants customer entitlements after payment, streams expiring token downloads, and
optionally issues licenses. It does not replace WHMCS checkout, invoices, users,
products or payment gateways.

## Deployment and activation

This repository is a WHMCS overlay. Copy the repository over the WHMCS document
root, then:

1. Create the WHMCS email template **Digital Product Download Info**. The template
   can use `{$product_name}`, `{$product_version}`, `{$purchase_date}`,
   `{$download_link}`, `{$client_area_link}` and `{$license_key}`. The link is an
   expiring token, never a permanent file URL.
2. Configure the addon under **Configuration → System Settings → Addon Modules**.
3. Set `DIGITALPRODUCTS_STORAGE` (recommended) to a writable directory outside
   the web root, or set the equivalent `storage_path` addon setting. The addon
   refuses a path inside `ROOTDIR`.
4. Activate the addon. Activation and upgrades run the numbered, additive
   migrations. Deactivation preserves all tables and files.
5. Give the WHMCS admin role access to the addon. The module also checks admin
   authentication and CSRF on every state-changing admin request.

Example private storage and cron:

```bash
export DIGITALPRODUCTS_STORAGE=/home/cloudhost247/private-storage/digital-products
*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/digitalproducts/cron/digitalproducts.php
```

Set `DIGITALPRODUCTS_ENCRYPTION_KEY` to a long, deployment-specific secret before
creating licenses. It is used only to encrypt license keys that must be shown to
customers; it is never stored in the database.

## Product setup

1. Create the product and price in WHMCS as usual.
2. In the addon, link that WHMCS product. This stores the WHMCS product id; it
   does not duplicate product pricing.
3. Edit the digital product, choose its type, status, download policy, access
   mode and license policy.
4. Upload a release. The addon validates the extension, MIME, size and archive
   paths, calculates SHA-256, stores a random private object key, verifies the
   stored bytes, and only then creates the version record.
5. Publish the release and set it current. Existing entitlements use the current
   release by default; `purchase_version` locks them to the release bought.

Supported types include module, plugin, theme, script, software, template, API,
document, media and other. The default extension allowlist is configurable and
includes `zip`, `tar.gz`, `pdf`, `js`, `css`, `php`, `json`, `xml`, `txt` and `md`.
Uploaded PHP is data for download; it is never included from the private store.

## Payment lifecycle

`OrderPaid`, `InvoicePaid`, `AfterModuleCreate` and `AcceptOrder` use the same
idempotent grant path. A unique `(client_id, service_id, product_id)` key prevents
duplicate entitlements, and `(service_id, product_id)` prevents duplicate licenses.
Cancellation, refund, fraud, suspension and termination revoke or suspend access.
The final download check still validates the current WHMCS service state, so an old
entitlement cannot bypass a cancellation. Email failure is logged and does not
roll back a paid order or entitlement.

## Client area and downloads

Customers use **My Downloads** in the HostX/WHMCS client area. A click submits a
CSRF-protected request that derives the customer and entitlement server-side, then
redirects to:

```text
/modules/addons/digitalproducts/download.php?token=<64-byte-random-token>
```

`download.php` accepts only the token. It hashes it for lookup, validates expiry,
single-use state, customer/session binding, entitlement, WHMCS service ownership,
product/version state and an atomic download limit claim, then streams from private
storage. It records successes and denials without storing raw tokens, license keys
or API secrets. Failures are controlled HTML responses, never a white screen.

## API

The JSON front controller is `/modules/addons/digitalproducts/api.php`. Use a
Bearer token stored as a SHA-256 hash in `mod_digitalproducts_api_tokens`.
Query-string credentials are rejected. Same-origin client-area requests may use
the WHMCS session and CSRF token; wildcard CORS is not enabled.

| Method | `endpoint` | Auth | Purpose |
|---|---|---|---|
| GET | `products` | public | Catalogue metadata only |
| GET | `product/{slug}` | public | Product metadata |
| GET | `versions/{productId}` | Bearer/client | Entitled release metadata |
| GET | `my-downloads` or `my/downloads` | Bearer/client | Own entitlements |
| GET | `my-licenses` or `my/licenses` | Bearer/client | Own license metadata |
| POST | `download-token` | Bearer/client | Issue an expiring download URL |
| POST | `validate-license` | rate limited | Generic license result |
| POST | `activate-license` | rate limited | Add a normalised domain activation |

All API responses use `{ "status": "success|error", "data": ... }`. License
validation never discloses another customer's identity or distinguishes unknown
keys beyond a generic invalid result. Do not put bearer tokens in URLs.

## Schema and upgrades

The final model is:

- `mod_digitalproducts_products` — linked WHMCS product and policy.
- `mod_digitalproducts_versions` — releases, checksums and compatibility.
- `mod_digitalproducts_entitlements` — paid customer/service access and counters.
- `mod_digitalproducts_licenses` — hashed/encrypted licenses.
- `mod_digitalproducts_download_tokens` — hashed, expiring token records.
- `mod_digitalproducts_downloads` — success and denial audit trail.
- `mod_digitalproducts_api_tokens` — hashed API credentials.
- `mod_digitalproducts_audit` and `mod_digitalproducts_rate_limits` — module support tables.

Existing `mod_digitalproducts_files` data is copied into `versions`; the legacy
table is not dropped so an operator can verify the migration. Existing `DP-...`
licenses remain valid and are hashed during migration. Every migration is additive,
re-runnable and tracked in `mod_digitalproducts_migrations`. Never delete the
legacy table or customer history manually.

## Security model

- WHMCS/Capsule parameterised queries; output escaping in admin and client views.
- Admin role gate, server-side capability checks and CSRF on every POST.
- Private storage outside `ROOTDIR`; opaque random storage keys and download
  streaming through PHP.
- Secure random tokens, SHA-256 at rest, expiry and atomic single-use claims.
- Cross-product checks bind service → WHMCS product → digital product → version.
- Archive traversal, absolute paths, symlinks, entry-count and compression-ratio
  checks where the relevant PHP archive extension is available.
- Rate limiting and generic responses for license abuse.
- Append-only module audit events with redacted context.

## Additional documentation

- [`INSTALL.md`](INSTALL.md) — deployment and activation checklist.
- [`UPGRADE.md`](UPGRADE.md) — migrations and rollback safety.
- [`SECURITY.md`](SECURITY.md) — threat model and controls.
- [`API.md`](API.md) — endpoint reference.
- [`DATABASE.md`](DATABASE.md) — schema and compatibility model.

## Tests

The module includes a PHP 8.3 php-wasm syntax/load gate that does not require a
native PHP binary:

```bash
node modules/addons/digitalproducts/tests/lint.mjs
```

Run it after changes. End-to-end purchase and web-server checks still require a
staging WHMCS installation because this repository intentionally does not contain
`init.php`, WHMCS vendor code, or the live database.

## Troubleshooting and recovery

- **Storage warning:** configure an absolute writable path outside the document
  root and restart the upload request. Do not make `public_html/storage` writable.
- **No access after payment:** confirm the WHMCS service is Active/Completed, the
  linked WHMCS product id matches, and the payment hook ran. Run the normal WHMCS
  invoice/order state rather than inserting an entitlement by hand.
- **Missing file:** replace the release with a new version; retire the broken one.
  Physical deletion is intentionally not part of normal product management.
- **Restore:** restore the database and the private storage directory together,
  preserving the configured encryption key. A database-only restore cannot stream
  files; a storage-only restore cannot reconstruct entitlement history.
