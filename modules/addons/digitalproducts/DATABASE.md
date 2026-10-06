# Database model

The module uses one table family prefixed `mod_digitalproducts_`:

- `products`: linked WHMCS id, slug, release policy and license/download policy.
- `versions`: opaque private storage key, original filename, checksum, release notes and compatibility.
- `entitlements`: client/service/order ownership, purchase version, status and atomic counter.
- `licenses`: service/product unique license, hash, encrypted display value, status and activations.
- `download_tokens`: token hash, entitlement/version binding, expiry and usage state.
- `downloads`: success and denial delivery audit rows.
- `api_tokens`: client-scoped bearer token hashes and scopes.
- `audit`, `rate_limits`, `migrations`: module support tables.

Migrations are `install/migrations/0001_schema.php` through `0005_schema_relaxation.php`. Existing `files` and legacy credential columns are preserved for backward compatibility, but application reads and writes use versions, hashed tokens and entitlements. Deactivation drops nothing.