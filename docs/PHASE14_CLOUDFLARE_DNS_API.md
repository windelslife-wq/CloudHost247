# Phase 14 — default-off App Cloud DNS inventory API

- **Status:** implemented; offline tests only
- **App Cloud addon version:** `1.9.2`
- **Cloudflare addon version:** `1.0.3` (unchanged)
- **Live Cloudflare calls:** none
- **Staging/production enablement:** none

Phase 14 exposes the Phase 13 App Cloud DNS inventory bridge through a narrow, authenticated, read-only API route. The endpoint remains disabled by default and does not alter either Cloudflare feature gate.

## API contract

```text
GET /v1/domains/{domain_id}/dns-inventory
```

- The caller must be an authenticated WHMCS customer or staff actor. Customers must pass `DOMAIN_VIEW_OWN` (`domain.view.own`); staff must pass `DOMAIN_VIEW_ALL` (`domain.view.all`). `InfrastructureApi::authorize()` also requires a bearer token's scopes to contain the same permission when the token is scoped.
- Customer lookups include both the requested domain ID and the authenticated WHMCS client ID in the database query. Missing, deleted, foreign-owned, and non-customer domains return not-found responses. The domain must have `verification_status=verified` before any provider is resolved.
- The route accepts no input fields: query parameters cannot change the target or scope, and any fields supplied to the dispatcher are rejected. The endpoint performs no DNS writes, queue jobs, cache writes, or record persistence.
- A successful response contains only `provider`, normalized `domain`, and the allowlisted DNS record fields (`id`, `type`, `name`, `content`, `ttl`, `proxied`, `priority`, `comment`). Provider-internal response fields are projected out at the API boundary.
- App Cloud records a hash-chained `DNS_INVENTORY_READ` audit event with the domain/client reference and summary-only provider, record-count, and type-count metadata. It does not place record content, names, comments, or record IDs in the App Cloud audit metadata.

## Independent gates and failure behavior

- `dns_inventory_api_enabled` is a new App Cloud setting with a documented default of `0` and an unchecked WHMCS addon field. When off, the endpoint returns `503 DNS_INVENTORY_API_DISABLED`; it does not register or resolve a provider.
- Only after that App Cloud switch passes and the caller/domain checks succeed does the route explicitly register the lazy Cloudflare bridge if it is not already registered.
- The Cloudflare addon remains independently gated by its master switch and Phase 12 DNS-inventory setting. The Phase 12 service still performs customer-service ownership and DNS-entitlement checks, domain/service resolution, account/zone verification, strict provider response validation, and its own summary-only audit. App Cloud does not bypass or modify those checks.
- Cloudflare authorization/not-found failures are translated to safe App Cloud errors. Other Cloudflare failures are mapped to a generic DNS-inventory-unavailable response rather than exposing provider messages or configuration details.

## Offline validation

`modules/addons/cloudhost247apps/tests/13_CloudflareDnsInventoryApiTest.php` uses the App Cloud in-memory test harness and an explicitly registered fake DNS provider. It covers default-off behavior and no registration while disabled, customer/staff authorization, SQL ownership scope, verified/customer-domain checks, bearer-token scopes, read-only request behavior, response projection, summary-only audit, safe provider-error mapping, and a Phase 12 master-gate failure that cannot reach the provider transport. No credentials or network transport are used by the test.

Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`:

- `npm test -- CloudflareDnsInventoryApi` → `TOTAL PASS=36 FAIL=0`
- Full `npm test` → `TOTAL PASS=1545 FAIL=0`
- `npm run lint` → `FILES=93, BAD=0`
- Repository-root `git diff --check` → passed

No live Cloudflare call, staging run, or production enablement was performed. Keep both the App Cloud API switch and Phase 12 Cloudflare gates off until separate staging and production reviews authorize them.
