# CloudHost247 Digital Products — operator runbook

`modules/addons/digitalproducts/` is upgraded in place. There is no `digitalproducts_v2`
or second download table family. This document describes the repository's actual
architecture; the WHMCS installation supplies `init.php`, Capsule, authentication,
email and payment state.

## Architecture

```text
WHMCS product / invoice / service
              │ WHMCS lifecycle hooks
              ▼
    EntitlementService (idempotent grant/revoke)
              ├── License
              └── My Downloads → TokenService
                                      ▼
                         DownloadAuthorizer → private Storage
                                      └── downloads + audit log
```

The module deliberately does not introduce an API credential vault, payment system,
user system or WHMCS core patch. The repository does not contain the CloudHost247
Foundation named in older generic briefs; it follows the existing per-addon
`domainbroker`/`cloudhost247services` conventions and keeps its audit primitives
inside this addon until a shared foundation is extracted repository-wide.

## Install and release checklist

1. Deploy over a supported WHMCS 8.x installation running PHP 7.4–8.2.
2. Configure `DIGITALPRODUCTS_STORAGE` outside the document root and
   `DIGITALPRODUCTS_ENCRYPTION_KEY` in the process environment.
3. Activate the addon and check the activation result/activity log.
4. Confirm all migrations are present in `mod_digitalproducts_migrations`.
5. Create the WHMCS email template `Digital Product Download Info`.
6. Link a real WHMCS product, edit its digital policy, upload a release and publish it.
7. Place a small staging order and verify payment → entitlement → email → client download.
8. Test a different customer's token and a cancelled service; both must fail.

Never upload product archives to this Git repository. Runtime storage is ignored by
`.gitignore` and must be included in the infrastructure backup instead.

## Storage and upload safety

The path resolution order is addon `storage_path`, `DIGITALPRODUCTS_STORAGE`, then a
private directory beside `ROOTDIR`. A configured path below `ROOTDIR` is rejected.
The local adapter writes opaque keys under `product-{id}/version-{id}/`, uses `0600`
files and adds web-server deny files as defence in depth. HTTP never receives the
physical path.

The upload validator checks the configured size and extension, MIME content, null or
traversal filename characters, ZIP absolute/parent paths, symlinks, entry count and
compression ratio. `tar.gz` archives are inspected when `PharData` is available.
Uploaded bytes are hashed after they land. A version row is inserted only after the
stored object is verified; failures clean up both metadata and blobs.

## Entitlement rules

The grant path is called by `OrderPaid`, `InvoicePaid`, `AfterModuleCreate` and
`AcceptOrder`. Revocation is called by cancellation, refund, fraud and termination;
suspension hooks suspend access and unsuspend restores it. A grant is keyed by
`client_id + service_id + product_id` and a license by `service_id + product_id`.
Email delivery is best-effort and recorded on the entitlement; it cannot roll back a
successful payment.

At download time the entitlement is not trusted alone. The authorizer checks:

```text
hashed token → expiry/single-use → entitlement/client
             → active product + active matching version
             → service userid + packageid + active WHMCS state
             → current/purchased version policy
             → atomic downloads_used limit claim
             → private stream + audit row
```

The token lifetime is independent from the entitlement. A customer may request a new
token while access remains active.

## Schema migration notes

Migrations are additive and tracked. `0002_versions_from_files` copies the original
`mod_digitalproducts_files` records into `mod_digitalproducts_versions`, copies readable
legacy files into private storage, backfills `current_version_id` and leaves the legacy
table untouched. `0003_entitlements` backfills currently active WHMCS services so
existing customers do not lose access. `0004_hardening` hashes legacy license/API
secrets and adds denial/audit fields.

Do not run `DROP TABLE`, delete a version referenced by an entitlement, or delete the
legacy files table during an upgrade. Retire and replace a release instead.

## API and rate limiting

Use `Authorization: Bearer <token>`, never `?api_token=...`. Tokens are hashed in
`mod_digitalproducts_api_tokens`; no wildcard CORS is emitted. Same-origin client
requests can use the WHMCS session and CSRF token. Public license validation and
activation are rate-limited by pseudonymised IP and return generic failure data.

## Operations and incident response

- **Public file exposure:** stop serving the affected path, move storage outside the
  web root, rotate any exposed product secrets, and review `downloads` and web logs.
- **Unexpected entitlement:** revoke the entitlement in the addon, preserve the audit
  record, and inspect the WHMCS order/service state and hook logs.
- **License key exposure:** rotate `DIGITALPRODUCTS_ENCRYPTION_KEY` only with a planned
  re-encryption procedure; changing it blindly makes encrypted customer keys unreadable.
  Existing legacy hashes remain valid for validation.
- **Failed email:** payment and access remain valid. Inspect the WHMCS mail log and
  resend through the client area after fixing the template.
- **Backup:** back up the WHMCS database and the configured private storage root as one
  application unit. Restore the encryption key with them.

## Verification commands

```bash
node modules/addons/digitalproducts/tests/lint.mjs
```

A staging sign-off should cover a paid order, duplicate payment hook, new release,
refund/cancellation, wrong-customer token, forged/expired/replayed token, download
limit, missing file, license validation throttling and direct HTTP access to the
private storage directory.
