# Phase 13 — explicit App Cloud DNS inventory bridge

- **Status:** implemented; offline tests only
- **App Cloud addon version:** `1.9.1`
- **Cloudflare addon version:** `1.0.3`
- **Live Cloudflare calls:** none
- **Staging/production enablement:** none

Phase 13 defines a read-only DNS inventory boundary in App Cloud and an optional Cloudflare bridge to the Phase 12 service. This is deliberately separate from `InfrastructureProviderInterface` and its compute-provider registry.

## Contract and lifecycle

- `DnsInventoryProviderInterface` defines the customer-scoped `listForDomain` contract. Its result is a small provider/domain/records projection.
- `DnsInventoryProviderRegistry` is separate from the compute-provider registry. It starts empty, has no default provider, and only contains a provider after an explicit registration call. There is no bootstrap hook or automatic Cloudflare registration.
- `CloudflareDnsInventoryAdapter` wraps the Phase 12 `DnsInventoryService`. It resolves the Cloudflare service by the requested domain within the customer scope, requires a unique matching service, then calls the existing `forCustomer` owner check again before provider access. Ambiguous or absent service mappings fail before any Cloudflare call.
- The bridge depends on Phase 12's existing master switch, independent default-off inventory setting, active-service checks, DNS entitlement, linked-domain verification, Cloudflare-account check, provider zone validation and record validation.
- Results contain only `provider`, normalized `domain`, and the already validated record projection. The bridge does not write DNS, persist records, log record contents, or expose the Cloudflare service/zone identifiers to App Cloud callers.
- The Cloudflare addon autoloader is resolved lazily when the bridge is first invoked. Merely loading App Cloud or listing registry keys does not load Cloudflare credentials or call an API.
- No public/customer route, UI, queue worker, automatic provider registration, or infrastructure-provider capability was added.

## Offline validation

`modules/addons/cloudhost247apps/tests/12_CloudflareDnsBridgeTest.php` uses in-memory SQLite and a scripted transport to verify the empty registry, explicit registration, scoped/unique domain resolution, stable neutral output, read-only GET-only calls, ambiguity and not-found behavior, invalid-domain rejection, duplicate-registration failure, and separation from compute-provider registration.

Validation on 2026-10-08 from `modules/addons/cloudhost247apps/`:

- `npm test -- CloudflareDnsBridge` → `TOTAL PASS=21 FAIL=0`
- Full `npm test` → `TOTAL PASS=1508 FAIL=0`
- `npm run lint` → `FILES=93, BAD=0`
- Repository-root `git diff --check` → passed

No live Cloudflare call, staging run, or production enablement was performed. The explicit registry is not registered by the module at startup.
